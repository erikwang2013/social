// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
//! 语音上传存储：ffmpeg 转 m4a（AAC 32k 单声道）+ ffprobe 时长，移植自 PHP `app\storage\VoiceStorage`。
//! 文件名 = md5(16 随机字节).m4a，与 PHP 一致；静态服务白名单 `^[a-f0-9]{32}\.m4a$` 防路径穿越。

use std::path::{Path, PathBuf};
use std::process::Command;
use thiserror::Error;

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
}

impl UploadError {
    /// (http status, body code, lang_key) — 对齐 PHP 的 RuntimeException code 语义
    pub fn status(&self) -> (u16, i32, &'static str) {
        match self {
            UploadError::TooLarge => (413, 413, "voice.too_large"),
            UploadError::TranscodeFailed => (500, 500, "voice.transcode_failed"),
            UploadError::TooLong => (400, 400, "voice.too_long"),
        }
    }
}

pub struct StoredVoice {
    pub name: String,
    pub url: String,
    pub duration: u64,
}

pub struct VoiceStorage {
    dir: PathBuf,
}

impl VoiceStorage {
    pub fn new(dir: impl Into<PathBuf>) -> Self {
        let dir = dir.into();
        let _ = std::fs::create_dir_all(&dir);
        Self { dir }
    }

    pub fn path_of(&self, name: &str) -> PathBuf {
        self.dir.join(name)
    }

    /// 校验 → 转 m4a（AAC 32k 单声道）→ 落盘 → {name, url, duration}
    pub fn ingest(&self, src: &Path) -> Result<StoredVoice, UploadError> {
        if src.metadata().map(|m| m.len()).unwrap_or(u64::MAX) > MAX_BYTES {
            return Err(UploadError::TooLarge);
        }
        let name = voice_name();
        let dst = self.dir.join(&name);
        let ok = Command::new("ffmpeg")
            .args(["-y", "-i"])
            .arg(src)
            .args(["-c:a", "aac", "-b:a", "32k", "-ac", "1", "-vn"])
            .arg(&dst)
            .stdout(std::process::Stdio::null())
            .stderr(std::process::Stdio::null()) // PHP exec 同样丢弃 stderr
            .status()
            .map(|s| s.success())
            .unwrap_or(false);
        if !ok {
            return Err(UploadError::TranscodeFailed);
        }
        let duration = probe(&dst)?;
        if duration > MAX_SECONDS {
            let _ = std::fs::remove_file(&dst);
            return Err(UploadError::TooLong);
        }
        let url = format!("/voice/{}", name);
        Ok(StoredVoice { name, url, duration })
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

    #[test]
    fn ingest_roundtrip() {
        if !ffmpeg_ok() {
            eprintln!("skipped: ffmpeg/ffprobe 不在 PATH");
            return;
        }
        let dir = std::env::temp_dir().join(format!("bee_live_upload_{}", std::process::id()));
        let st = VoiceStorage::new(&dir);
        let src = dir.join("in.wav");
        std::fs::write(&src, wav_silence(44100, 1)).unwrap();
        let v = st.ingest(&src).expect("转码应成功");
        assert!(valid_file_name(&v.name));
        assert_eq!(v.url, format!("/voice/{}", v.name));
        assert!(st.path_of(&v.name).is_file());
        assert!(v.duration >= 1);
        std::fs::remove_dir_all(&dir).ok();
    }

    #[test]
    fn reject_too_long() {
        if !ffmpeg_ok() {
            eprintln!("skipped: ffmpeg/ffprobe 不在 PATH");
            return;
        }
        // 120s 8kHz 单声道 = 1.92MB < 2MB（尺寸检查先行），转码成功但超时 → TooLong
        let dir = std::env::temp_dir().join(format!("bee_live_upload_long_{}", std::process::id()));
        let st = VoiceStorage::new(&dir);
        let src = dir.join("in.wav");
        std::fs::write(&src, wav_silence(8000, 120)).unwrap();
        match st.ingest(&src) {
            Err(UploadError::TooLong) => {}
            r => panic!("应拒绝超长音频: {:?}", r.map(|v| v.duration)),
        }
        std::fs::remove_dir_all(&dir).ok();
    }
}
