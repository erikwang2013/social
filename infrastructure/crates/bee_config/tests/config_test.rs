// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
// The load-path registry is keyed by TypeId with one path per type, so each
// test uses its own struct to stay deterministic under parallel execution.
use bee_config::{Config, ConfigSource};
use serde::Deserialize;

#[derive(Deserialize, Debug, PartialEq, Config)]
struct AppConfig {
    app_name: String,
    http_port: u16,
    run_mode: String,
}

#[derive(Deserialize, Debug, PartialEq, Config)]
struct ReloadConfig {
    app_name: String,
    http_port: u16,
    run_mode: String,
}

#[derive(Deserialize, Debug, PartialEq, Config)]
struct WatchConfig {
    app_name: String,
    http_port: u16,
    run_mode: String,
}

#[derive(Deserialize, Debug, PartialEq, Config)]
struct UnloadedConfig {
    app_name: String,
}

#[derive(Deserialize, Debug, PartialEq, Config)]
struct DualPathConfig {
    app_name: String,
    http_port: u16,
    run_mode: String,
}

#[derive(Deserialize, Debug, PartialEq, Config)]
struct RetryConfig {
    app_name: String,
    http_port: u16,
    run_mode: String,
}

fn write_test_conf(path: &std::path::Path, port: u16, mode: &str) {
    std::fs::write(path, format!("app_name = test-app\nhttp_port = {port}\nrun_mode = {mode}\n"))
        .unwrap();
}

fn temp_dir(name: &str) -> (std::path::PathBuf, std::path::PathBuf) {
    let dir = std::env::temp_dir().join(format!("bee_config_{name}_{}", std::process::id()));
    std::fs::create_dir_all(&dir).unwrap();
    (dir.clone(), dir.join("test.conf"))
}

#[test]
fn test_load_ini_config() {
    let cfg = AppConfig::load("tests/fixtures/test.conf").unwrap();
    assert_eq!(cfg.app_name, "test-app");
    assert_eq!(cfg.http_port, 8080);
    assert_eq!(cfg.run_mode, "dev");
}

#[test]
fn test_reload_updates_values() {
    let (dir, path) = temp_dir("reload");
    write_test_conf(&path, 8080, "dev");

    let mut cfg = ReloadConfig::load(&path).unwrap();
    assert_eq!(cfg.http_port, 8080);

    write_test_conf(&path, 9090, "prod");
    cfg.reload().unwrap();
    assert_eq!(cfg.http_port, 9090);
    assert_eq!(cfg.run_mode, "prod");

    std::fs::remove_dir_all(&dir).ok();
}

#[test]
fn test_reload_without_load_errors() {
    let mut cfg = UnloadedConfig { app_name: "x".into() };
    assert!(matches!(cfg.reload(), Err(bee_config::ConfigError::NotFound(_))));
}

#[test]
fn test_same_type_dual_path_conflict() {
    let (dir, path_a) = temp_dir("conflict_a");
    let path_b = dir.join("other.conf");
    write_test_conf(&path_a, 8080, "dev");
    write_test_conf(&path_b, 9090, "prod");

    DualPathConfig::load(&path_a).unwrap();
    let err = DualPathConfig::load(&path_b).unwrap_err();
    assert!(matches!(err, bee_config::ConfigError::PathConflict(_, _, _)));
    assert!(err.to_string().contains("already loaded from"));

    // Registry still points at the first path: reload() must not silently
    // pick up the second one.
    let mut cfg = DualPathConfig::load(&path_a).unwrap();
    assert_eq!(cfg.http_port, 8080);
    cfg.reload().unwrap();
    assert_eq!(cfg.http_port, 8080);

    std::fs::remove_dir_all(&dir).ok();
}

#[test]
fn test_reload_retries_on_half_written_file() {
    let (dir, path) = temp_dir("retry");
    write_test_conf(&path, 8080, "dev");

    let mut cfg = RetryConfig::load(&path).unwrap();
    assert_eq!(cfg.http_port, 8080);

    // Simulate an in-place editor write: file is momentarily truncated
    // (no [default] section -> load fails), then fully rewritten shortly
    // after. reload() must retry instead of surfacing the transient error.
    std::fs::write(&path, "partially written\n").unwrap();
    let path = path.clone();
    let writer = std::thread::spawn(move || {
        std::thread::sleep(std::time::Duration::from_millis(10));
        write_test_conf(&path, 9090, "prod");
    });

    cfg.reload().unwrap();
    writer.join().unwrap();
    assert_eq!(cfg.http_port, 9090);
    assert_eq!(cfg.run_mode, "prod");

    std::fs::remove_dir_all(&dir).ok();
}

#[test]
fn test_reload_and_watch() {
    let (dir, path) = temp_dir("watch");
    write_test_conf(&path, 8080, "dev");

    let mut cfg = WatchConfig::load(&path).unwrap();
    assert!(cfg.reload().is_ok());

    // watch() blocks until the file changes; modify it from another thread.
    let path = path.clone();
    let writer = std::thread::spawn(move || {
        std::thread::sleep(std::time::Duration::from_millis(300));
        write_test_conf(&path, 9090, "prod");
    });
    assert!(cfg.watch().is_ok());
    writer.join().unwrap();

    cfg.reload().unwrap();
    assert_eq!(cfg.http_port, 9090);
    assert_eq!(cfg.run_mode, "prod");

    std::fs::remove_dir_all(&dir).ok();
}
