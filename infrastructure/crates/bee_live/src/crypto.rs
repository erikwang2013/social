// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
//! erikwang2013/encryptable FORMAT_V1 解密（对齐 PHP `PHPEncrypter`）。
//! admin 用 Encryptable 加密存储服务商 key/secret；Rust 直读 open_admin 库时需先解密。
//! 非 V1 或验签失败按 PHP 非严格模式原样返回；密钥/算法配置错误则报错，让 provider 明确失败。

use aes::Aes128;
use aes::cipher::block_padding::Pkcs7;
use aes::cipher::{BlockDecrypt, BlockDecryptMut, KeyInit, KeyIvInit};
use base64::Engine;
use cbc::Decryptor;
use hmac::{Hmac, Mac};
use sha2::{Digest, Sha256};

type HmacSha256 = Hmac<Sha256>;
type Aes256CbcDec = Decryptor<aes::Aes256>;

pub const DEFAULT_KEY: &str = "open-admin-db-encryption-key-32b";
pub const DEFAULT_CIPHER: &str = "aes-256-cbc";

#[derive(Debug)]
pub enum CryptoError {
    InvalidKeyLength { cipher: String, expected: usize, got: usize },
    UnsupportedCipher(String),
}

impl std::fmt::Display for CryptoError {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            Self::InvalidKeyLength { cipher, expected, got } => {
                write!(
                    f,
                    "ENCRYPTABLE_KEY 长度 {} 与 cipher {} 要求的 {} 字节不符",
                    got, cipher, expected
                )
            }
            Self::UnsupportedCipher(cipher) => write!(f, "不支持的 ENCRYPTABLE_CIPHER: {}", cipher),
        }
    }
}

impl std::error::Error for CryptoError {}

pub fn decrypt_encryptable(value: &str) -> Result<String, CryptoError> {
    let key = std::env::var("ENCRYPTABLE_KEY").unwrap_or_else(|_| DEFAULT_KEY.into());
    let cipher = std::env::var("ENCRYPTABLE_CIPHER").unwrap_or_else(|_| DEFAULT_CIPHER.into());
    decrypt_with(value, &key, &cipher)
}

pub fn decrypt_with(value: &str, key: &str, cipher: &str) -> Result<String, CryptoError> {
    let cipher = cipher.to_ascii_lowercase();
    let iv_len = match cipher.as_str() {
        "aes-256-cbc" => 16,
        "aes-128-ecb" => 0,
        other => return Err(CryptoError::UnsupportedCipher(other.to_string())),
    };
    let expected_key = if cipher.contains("256") { 32 } else { 16 };
    if key.len() != expected_key {
        return Err(CryptoError::InvalidKeyLength {
            cipher,
            expected: expected_key,
            got: key.len(),
        });
    }

    // PHP 非严格模式：不是 V1 密文（含空串/明文）就原样返回
    let Ok(decoded) = base64::engine::general_purpose::STANDARD.decode(value) else {
        return Ok(value.to_string());
    };
    if decoded.first() != Some(&0x01) || decoded.len() < 1 + iv_len + 32 + 1 {
        return Ok(value.to_string());
    }
    let iv = &decoded[1..1 + iv_len];
    let body = &decoded[1 + iv_len..decoded.len() - 32];
    let mac = &decoded[decoded.len() - 32..];

    let hmac_key = Sha256::digest([key.as_bytes(), b":hmac"].concat());
    let mut h =
        <HmacSha256 as Mac>::new_from_slice(&hmac_key).expect("HMAC accepts any key length");
    h.update(iv);
    h.update(body);
    if h.verify_slice(mac).is_err() {
        eprintln!("encryptable: HMAC 校验失败，按明文处理");
        return Ok(value.to_string());
    }

    let plain = match cipher.as_str() {
        "aes-256-cbc" => {
            let Ok(dec) = Aes256CbcDec::new_from_slices(key.as_bytes(), iv) else {
                return Ok(value.to_string());
            };
            let mut buf = body.to_vec();
            match dec.decrypt_padded_mut::<Pkcs7>(&mut buf) {
                Ok(p) => p.to_vec(),
                Err(_) => return Ok(value.to_string()),
            }
        }
        _ => {
            // aes-128-ecb：逐 16 字节块解密，再按 PKCS7 去填充（与 openssl_decrypt 一致）
            if body.len() % 16 != 0 {
                return Ok(value.to_string());
            }
            let Ok(aes) = Aes128::new_from_slice(key.as_bytes()) else {
                return Ok(value.to_string());
            };
            let mut buf = body.to_vec();
            for chunk in buf.chunks_exact_mut(16) {
                aes.decrypt_block(aes::Block::from_mut_slice(chunk));
            }
            match unpad_pkcs7(&buf) {
                Some(p) => p.to_vec(),
                None => return Ok(value.to_string()),
            }
        }
    };

    let Ok(text) = String::from_utf8(plain) else {
        return Ok(value.to_string());
    };
    let Some(text) = text.strip_prefix("crypt:") else {
        eprintln!("encryptable: 解密结果缺少 crypt: 标记，按明文处理");
        return Ok(value.to_string());
    };
    Ok(unserialize_string(text))
}

fn unpad_pkcs7(buf: &[u8]) -> Option<&[u8]> {
    let pad = *buf.last()? as usize;
    if pad == 0 || pad > 16 || pad > buf.len() {
        return None;
    }
    if !buf[buf.len() - pad..].iter().all(|&b| b as usize == pad) {
        return None;
    }
    Some(&buf[..buf.len() - pad])
}

/// PHP Serializer 的 "string:<value>" / "integer:..." / "NULL:" 格式
fn unserialize_string(text: &str) -> String {
    match text.split_once(':') {
        Some((ty, value)) if matches!(ty, "string" | "integer" | "double" | "boolean") => {
            value.to_string()
        }
        Some(("NULL", _)) => String::new(),
        _ => text.to_string(),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    // php -r 用默认 key + aes-256-cbc 加密 "s3-secret" 的结果
    const FIXTURE: &str = "AY0ebUNg6dhV7QJGCj/vAg16uvMmtpoVjxe/j5jr1EUkg0aMlOadflzydzNO/+FodA8BUh4Yl9BDhLg5CanO0Jv+L/3WvSnrDOVFJhBAm2Pk";

    #[test]
    fn php_fixture_decrypts() {
        assert_eq!(decrypt_with(FIXTURE, DEFAULT_KEY, DEFAULT_CIPHER).unwrap(), "s3-secret");
    }

    #[test]
    fn wrong_key_passes_ciphertext_through() {
        assert_eq!(
            decrypt_with(FIXTURE, "open-admin-db-encryption-key-32x", DEFAULT_CIPHER).unwrap(),
            FIXTURE
        );
    }

    #[test]
    fn plaintext_passes_through() {
        assert_eq!(decrypt_with("", DEFAULT_KEY, DEFAULT_CIPHER).unwrap(), "");
        assert_eq!(
            decrypt_with("plain-secret", DEFAULT_KEY, DEFAULT_CIPHER).unwrap(),
            "plain-secret"
        );
    }

    #[test]
    fn invalid_key_length_is_error() {
        assert!(decrypt_with(FIXTURE, "short", DEFAULT_CIPHER).is_err());
    }
}
