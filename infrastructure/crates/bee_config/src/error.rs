// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
use std::path::PathBuf;

use thiserror::Error;

#[derive(Error, Debug)]
pub enum ConfigError {
    #[error("file not found: {0}")]
    NotFound(String),

    #[error("parse error in {file}: {message}")]
    ParseError { file: String, message: String },

    #[error("missing required key: {0}")]
    MissingKey(String),

    #[error("invalid type for key {key}: expected {expected}, got {actual}")]
    TypeMismatch { key: String, expected: String, actual: String },

    #[error("io error: {0}")]
    Io(#[from] std::io::Error),

    #[error("deserialization error: {0}")]
    Deserialize(String),

    #[error("config type {0} already loaded from {1}; cannot load from {2}")]
    PathConflict(String, PathBuf, PathBuf),
}
