// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
//! 语音上传存储：ffmpeg 转 m4a（AAC 32k 单声道）+ ffprobe 时长，移植自 PHP `app\storage\VoiceStorage`。
//! 文件名 = md5(16 随机字节).m4a，与 PHP 一致；静态服务白名单 `^[a-f0-9]{32}\.m4a$` 防路径穿越。
//! M6c3: 落盘改 object_store（s3 兼容桶 / local 降级 InMemory），活动服务商来自 open_admin.erik_storage_provider。

use std::path::Path;
use std::process::Command;
use std::sync::Arc;

use mysql_async::prelude::Queryable;
use object_store::path::Path as ObjPath;
use object_store::{memory::InMemory, aws::AmazonS3Builder, ObjectStore, PutPayload};
use thiserror::Error;

use crate::store::open_admin_opts;

pub const MAX_BYTES: u64 = 2 * 1024 * 1024; // ≤2MB
pub const MAX_SECONDS: u64 = 60; // ≤60s

#[derive(Debug, Error)]
pub enum UploadError {
    #[error("voice.too_large")]
    TooLarge,
    #[error("voice.transcode_failed")]
    TranscodeFailed,
    #[error("voice.too_long")]
    TooLong,
    #[error("voice.provider_unavailable")]
    Provider,
    #[error("voice.not_found")]
    NotFound,
}

impl UploadError {
    /// (http status, body code, lang_key) — 对齐 PHP 的 RuntimeException code 语义
    pub fn status(&self) -> (u16, i32, &'static str) {
        match self {
            UploadError::TooLarge => (413, 413, "voice.too_large"),
            UploadError::TranscodeFailed => (500, 500, "voice.transcode_failed"),
            UploadError::TooLong => (400, 400, "voice.too_long"),
            UploadError::Provider => (500, 500, "voice.provider_unavailable"),
            UploadError::NotFound => (404, 404, "voice.not_found"),
        }
    }
}

pub struct StoredVoice {
    pub name: String,
    pub url: String,
    pub duration: u64,
}

pub struct VoiceStorage {
    store: Arc<dyn ObjectStore>,
}

impl VoiceStorage {
    /// 测试/降级路径：直接注入 store（如 object_store::memory::InMemory）
    pub async fn new(store: Arc<dyn ObjectStore>) -> Self {
        Self { store }
    }

    /// 生产：从 MySQL 读活动服务商构建 store；local 服务商降级 InMemory，
    /// s3 兼容桶（R2/OSS/COS/B2）走 AmazonS3Builder（endpoint 直连）。
    pub async fn from_active_provider() -> Result<Self, UploadError> {
        let pool = mysql_async::Pool::new(open_admin_opts());
        let mut conn = pool.get_conn().await.map_err(|_| UploadError::Provider)?;
        let row: Option<(String, String, String, String, String, String)> = conn
            .exec_first(
                "SELECT driver, endpoint, region, `key`, `secret`, bucket \
                 FROM erik_storage_provider WHERE is_active = 1 LIMIT 1",
                (),
            )
            .await
            .map_err(|_| UploadError::Provider)?;
        drop(conn);
        let _ = pool.disconnect().await;
        let Some((driver, endpoint, region, key, secret, bucket)) = row else {
            return Err(UploadError::Provider);
        };
        if driver == "local" {
            return Ok(Self::new(Arc::new(InMemory::new())).await);
        }
        let store = AmazonS3Builder::new()
            .with_endpoint(endpoint)
            .with_region(region)
            .with_access_key_id(key)
            .with_secret_access_key(secret)
            .with_bucket_name(bucket)
            .with_allow_http(true)
            .build()
            .map_err(|_| UploadError::Provider)?;
        Ok(Self::new(Arc::new(store)).await)
    }

    /// 校验 → 转 m4a（AAC 32k 单声道）→ 存 store（voice/{name}）→ {name, url, duration}
    pub async fn ingest(&self, src: &Path) -> Result<StoredVoice, UploadError> {
        if src.metadata().map(|m| m.len()).unwrap_or(u64::MAX) > MAX_BYTES {
            return Err(UploadError::TooLarge);
        }
        let name = voice_name();
        let tmp = std::env::temp_dir().join(format!("bee_voice_{}_{}", std::process::id(), name));
        let ok = Command::new("ffmpeg")
            .args(["-y", "-i"])
            .arg(src)
            .args(["-c:a", "aac", "-b:a", "32k", "-ac", "1", "-vn"])
            .arg(&tmp)
            .stdout(std::process::Stdio::null())
            .stderr(std::process::Stdio::null()) // PHP exec 同样丢弃 stderr
            .status()
            .map(|s| s.success())
            .unwrap_or(false);
        if !ok {
            return Err(UploadError::TranscodeFailed);
        }
        let duration = probe(&tmp)?;
        if duration > MAX_SECONDS {
            let _ = std::fs::remove_file(&tmp);
            return Err(UploadError::TooLong);
        }
        let bytes = std::fs::read(&tmp).map_err(|_| UploadError::TranscodeFailed)?;
        let _ = std::fs::remove_file(&tmp);
        self.store
            .put(&ObjPath::from(format!("voice/{name}")), PutPayload::from(bytes))
            .await
            .map_err(|_| UploadError::Provider)?;
        Ok(StoredVoice { name: name.clone(), url: format!("/voice/{}", name), duration })
    }

    /// 读语音文件（替代 path_of），GetVoiceFile 用
    pub async fn read(&self, name: &str) -> Result<Vec<u8>, UploadError> {
        let bytes = self
            .store
            .get(&ObjPath::from(format!("voice/{name}")))
            .await
            .map_err(|e| match e {
                object_store::Error::NotFound { .. } => UploadError::NotFound,
                _ => UploadError::Provider,
            })?
            .bytes()
            .await
            .map_err(|_| UploadError::Provider)?;
        Ok(bytes.to_vec())
    }
}

/// 静态语音文件白名单（PHP: `^[a-f0-9]{32}\.m4a$`）
pub fn valid_file_name(name: &str) -> bool {
    name.len() == 36
        && name.ends_with(".m4a")
        && name.as_bytes()[..32].iter().all(|b| b.is_ascii_digit() || (b'a'..=b'f').contains(b))
}

fn voice_name() -> String {
    let mut buf = [0u8; 16];
    let _ = getrandom::fill(&mut buf); // Linux 上失败概率可忽略
    format!("{:x}.m4a", md5::compute(buf)) // PHP: md5(random_bytes(16)) . '.m4a'
}

fn probe(file: &Path) -> Result<u64, UploadError> {
    let out = Command::new("ffprobe")
        .args(["-v", "error", "-show_entries", "format=duration", "-of", "csv=p=0"])
        .arg(file)
        .output()
        .map_err(|_| UploadError::TranscodeFailed)?;
    let s = String::from_utf8_lossy(&out.stdout);
    Ok(s.trim().parse::<f64>().map(|d| d.round() as u64).unwrap_or(0))
}

#[cfg(test)]
mod tests {
    use super::*;

    fn ffmpeg_ok() -> bool {
        Command::new("ffmpeg").arg("-version").stdout(std::process::Stdio::null()).status().is_ok()
    }

    fn wav_silence(rate: u32, secs: u16) -> Vec<u8> {
        let n = rate * secs as u32;
        let mut w = Vec::new();
        w.extend_from_slice(b"RIFF");
        w.extend_from_slice(&(36 + n * 2).to_le_bytes());
        w.extend_from_slice(b"WAVEfmt ");
        w.extend_from_slice(&16u32.to_le_bytes());
        w.extend_from_slice(&1u16.to_le_bytes());
        w.extend_from_slice(&1u16.to_le_bytes());
        w.extend_from_slice(&rate.to_le_bytes());
        w.extend_from_slice(&(rate * 2).to_le_bytes());
        w.extend_from_slice(&2u16.to_le_bytes());
        w.extend_from_slice(&16u16.to_le_bytes());
        w.extend_from_slice(b"data");
        w.extend_from_slice(&(n * 2).to_le_bytes());
        w.extend(vec![0u8; (n * 2) as usize]);
        w
    }

    #[test]
    fn file_name_whitelist() {
        assert!(valid_file_name("0123456789abcdef0123456789abcdef.m4a"));
        assert!(!valid_file_name("ABCDEF0123456789abcdef0123456789.m4a")); // 大写拒绝（[a-f0-9]）
        assert!(!valid_file_name("0123456789abcdef0123456789abcdE.m4a"));
        assert!(!valid_file_name("0123456789abcdef0123456789abcd.m4a")); // 长度不足
        assert!(!valid_file_name("0123456789abcdef0123456789abcde.mp3"));
        assert!(!valid_file_name("../0123456789abcdef0123456789abcd.m4a"));
    }

    #[tokio::test]
    async fn ingest_roundtrip() {
        if !ffmpeg_ok() {
            eprintln!("skipped: ffmpeg/ffprobe 不在 PATH");
            return;
        }
        let st = VoiceStorage::new(Arc::new(InMemory::new())).await;
        let src = std::env::temp_dir().join(format!("bee_live_in_{}.wav", std::process::id()));
        std::fs::write(&src, wav_silence(44100, 1)).unwrap();
        let v = st.ingest(&src).await.expect("转码应成功");
        assert!(valid_file_name(&v.name));
        assert_eq!(v.url, format!("/voice/{}", v.name));
        assert!(v.duration >= 1);
        // store 可读回（替代旧 path_of().is_file() 断言）
        let read = st.read(&v.name).await.expect("应能读回");
        assert!(!read.is_empty());
        std::fs::remove_file(&src).ok();
    }

    #[tokio::test]
    async fn reject_too_long() {
        if !ffmpeg_ok() {
            eprintln!("skipped: ffmpeg/ffprobe 不在 PATH");
            return;
        }
        // 120s 8kHz 单声道 = 1.92MB < 2MB（尺寸检查先行），转码成功但超时 → TooLong
        let st = VoiceStorage::new(Arc::new(InMemory::new())).await;
        let src = std::env::temp_dir().join(format!("bee_live_in_long_{}.wav", std::process::id()));
        std::fs::write(&src, wav_silence(8000, 120)).unwrap();
        match st.ingest(&src).await {
            Err(UploadError::TooLong) => {}
            r => panic!("应拒绝超长音频: {:?}", r.map(|v| v.duration)),
        }
        std::fs::remove_file(&src).ok();
    }

    #[tokio::test]
    async fn read_missing_returns_not_found() {
        let st = VoiceStorage::new(Arc::new(InMemory::new())).await;
        // 与 PHP 一致：缺文件 → 404 voice.not_found（而非 500）
        assert!(matches!(st.read("0123456789abcdef0123456789abcdef.m4a").await, Err(UploadError::NotFound)));
    }
}
