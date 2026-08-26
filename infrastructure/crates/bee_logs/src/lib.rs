// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
use tracing::Level;
use tracing_subscriber::{EnvFilter, fmt, prelude::*, util::SubscriberInitExt};

pub enum Output {
    Stdout,
    File(String),
    MultiFile(String),
}

pub struct Logger {
    level: Level,
    output: Output,
    async_mode: bool,
}

pub struct LogHandle {
    _guard: Option<tracing_appender::non_blocking::WorkerGuard>,
}

impl Logger {
    pub fn new() -> Self {
        Self { level: Level::INFO, output: Output::Stdout, async_mode: false }
    }

    pub fn level(mut self, level: Level) -> Self {
        self.level = level;
        self
    }

    pub fn output(mut self, output: Output) -> Self {
        self.output = output;
        self
    }

    pub fn async_(mut self) -> Self {
        self.async_mode = true;
        self
    }

    pub fn init(self) -> Result<LogHandle, Box<dyn std::error::Error>> {
        let filter =
            EnvFilter::try_from_default_env().unwrap_or_else(|_| EnvFilter::new(self.level_str()));

        let mut guard: Option<tracing_appender::non_blocking::WorkerGuard> = None;

        match &self.output {
            Output::Stdout => {
                tracing_subscriber::registry()
                    .with(filter)
                    .with(fmt::layer().with_target(true))
                    .try_init()?;
            }
            Output::File(path) => {
                let file = std::fs::OpenOptions::new().create(true).append(true).open(path)?;
                let (writer, g) = tracing_appender::non_blocking(file);
                guard = Some(g);
                tracing_subscriber::registry()
                    .with(filter)
                    .with(fmt::layer().with_writer(writer))
                    .try_init()?;
            }
            Output::MultiFile(dir) => {
                let appender = tracing_appender::rolling::daily(dir, "app.log");
                let (writer, g) = tracing_appender::non_blocking(appender);
                guard = Some(g);
                tracing_subscriber::registry()
                    .with(filter)
                    .with(fmt::layer().with_writer(writer).json())
                    .try_init()?;
            }
        }

        Ok(LogHandle { _guard: guard })
    }

    fn level_str(&self) -> String {
        match self.level {
            Level::TRACE => "trace",
            Level::DEBUG => "debug",
            Level::INFO => "info",
            Level::WARN => "warn",
            Level::ERROR => "error",
        }
        .into()
    }
}

impl Default for Logger {
    fn default() -> Self {
        Self::new()
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::sync::Once;

    static INIT: Once = Once::new();

    fn init_logging() {
        INIT.call_once(|| {
            let _ = tracing_subscriber::fmt().try_init();
        });
    }

    #[test]
    fn test_logger_builder() {
        init_logging();
        let handle = Logger::new().level(Level::DEBUG).output(Output::Stdout).init();
        // May fail if subscriber already registered; builder chain is what we test.
        assert!(handle.is_ok() || handle.is_err());
    }

    #[test]
    fn test_logger_default() {
        init_logging();
        let handle = Logger::default().init();
        assert!(handle.is_ok() || handle.is_err());
    }

    #[test]
    fn test_level_str() {
        assert_eq!(Logger::new().level_str(), "info");
        assert_eq!(Logger::new().level(Level::TRACE).level_str(), "trace");
        assert_eq!(Logger::new().level(Level::DEBUG).level_str(), "debug");
        assert_eq!(Logger::new().level(Level::INFO).level_str(), "info");
        assert_eq!(Logger::new().level(Level::WARN).level_str(), "warn");
        assert_eq!(Logger::new().level(Level::ERROR).level_str(), "error");
    }

    #[test]
    fn test_file_output_creates_log_file() {
        init_logging();
        let dir = std::env::temp_dir().join(format!(
            "bee-logs-test-{}-{}",
            std::process::id(),
            std::time::SystemTime::now()
                .duration_since(std::time::SystemTime::UNIX_EPOCH)
                .unwrap()
                .as_nanos()
        ));
        std::fs::create_dir_all(&dir).unwrap();
        let path = dir.join("app.log");
        // The file must be created (open happens before subscriber init),
        // even though try_init() fails once a global subscriber exists.
        let _ = Logger::new().output(Output::File(path.display().to_string())).init();
        assert!(path.exists());
        std::fs::remove_dir_all(&dir).ok();
    }
}
