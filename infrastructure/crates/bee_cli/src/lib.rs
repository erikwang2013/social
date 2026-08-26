// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
use std::fs;
use std::path::{Path, PathBuf};
use std::process::{Child, Command};
use std::thread;
use std::time::{Duration, SystemTime};

pub type CliResult = Result<(), String>;

/// Scaffold a new bee-rust project directory with a runnable template.
pub fn new_project(name: &str) -> CliResult {
    validate_name(name)?;
    let dir = Path::new(name);
    if dir.exists() {
        return Err(format!("directory `{name}` already exists"));
    }
    let pkg = dir
        .file_name()
        .and_then(|s| s.to_str())
        .filter(|s| !s.is_empty())
        .ok_or_else(|| format!("invalid project name `{name}`"))?;
    fs::create_dir_all(dir.join("src")).map_err(io_err)?;
    fs::create_dir_all(dir.join("src/controllers")).map_err(io_err)?;
    fs::create_dir_all(dir.join("src/models")).map_err(io_err)?;

    let cargo_toml = format!(
        r#"[package]
name = "{pkg}"
version = "0.1.0"
edition = "2024"

[dependencies]
bee-rust = "1"
tokio = {{ version = "1", features = ["full"] }}
axum = "0.8"
serde_json = "1"
tracing = "0.1"
"#
    );
    let main_rs = r#"use bee_rust::prelude::*;

mod controllers;
mod models;

async fn health() -> &'static str {
    "OK"
}

#[tokio::main]
async fn main() -> bee_rust::Result<()> {
    let _log_handle = bee_rust::init()?;
    let router = Router::new().ns("/api/v1", |ns| ns.get("/health", health));
    let app = router.build();
    let listener = tokio::net::TcpListener::bind("0.0.0.0:8080").await?;
    tracing::info!("bee-rust app running on http://localhost:8080");
    axum::serve(listener, app).await?;
    Ok(())
}
"#;

    fs::write(dir.join("Cargo.toml"), cargo_toml).map_err(io_err)?;
    fs::write(dir.join("src/main.rs"), main_rs).map_err(io_err)?;
    fs::write(dir.join("src/controllers/mod.rs"), "").map_err(io_err)?;
    fs::write(dir.join("src/models/mod.rs"), "").map_err(io_err)?;
    fs::write(dir.join(".gitignore"), "/target\n").map_err(io_err)?;
    println!("Created project `{name}`. Next: cd {name} && cargo run");
    Ok(())
}

/// Generate a controller file implementing the `bee_router::Controller` trait.
pub fn generate_controller(name: &str) -> CliResult {
    validate_name(name)?;
    ensure_module_dir("controllers")?;
    let file = format!("controllers/{name}.rs");
    if Path::new(&file).exists() {
        return Err(format!("`{file}` already exists"));
    }
    let class = pascal_case(name);
    let content = format!(
        r#"use bee_rust::prelude::*;
use bee_rust::bee_router::RouterError;

pub struct {class}Controller;

impl Controller for {class}Controller {{
    async fn handle(&self, ctx: &mut Context) -> Result<(), RouterError> {{
        ctx.json(&serde_json::json!({{ "message": "{class}Controller" }}))?;
        Ok(())
    }}
}}
"#
    );
    fs::write(&file, content).map_err(io_err)?;
    println!("Generated controller `{file}`");
    Ok(())
}

/// Generate a model file with the `bee_orm::Model` derive macro, from field
/// specs like `name:string,age:int`.
pub fn generate_model(name: &str, fields: Option<&str>) -> CliResult {
    validate_name(name)?;
    ensure_module_dir("models")?;
    let file = format!("models/{name}.rs");
    if Path::new(&file).exists() {
        return Err(format!("`{file}` already exists"));
    }
    let class = pascal_case(name);
    let mut members = vec!["    id: i64".to_string()];
    if let Some(specs) = fields {
        for spec in specs.split(',') {
            let spec = spec.trim();
            if spec.is_empty() {
                continue;
            }
            let (field, ty) = spec
                .split_once(':')
                .ok_or_else(|| format!("invalid field spec `{spec}` (expected name:type)"))?;
            members.push(format!("    {field}: {}", rust_type(ty.trim())?));
        }
    }
    let content = format!(
        r#"use bee_rust::bee_orm::Model;

#[derive(Model)]
#[allow(dead_code)]
pub struct {class} {{
{}
}}
"#,
        members.join(",\n")
    );
    fs::write(&file, content).map_err(io_err)?;
    println!("Generated model `{file}`");
    Ok(())
}

/// Run the bee-rust app in the current directory via `cargo run`.
/// With `watch`, restart the process when source files change.
pub fn run_server(watch: bool) -> CliResult {
    let mut child = spawn_cargo_run()?;
    if !watch {
        return child_wait(child);
    }
    let mut snapshot = source_snapshot()?;
    loop {
        thread::sleep(Duration::from_millis(500));
        let new_snapshot = source_snapshot()?;
        if new_snapshot != snapshot {
            println!("source changed, restarting...");
            let _ = child.kill();
            let _ = child.wait();
            child = spawn_cargo_run()?;
            snapshot = new_snapshot;
        }
        match child.try_wait() {
            Ok(Some(status)) if status.success() => return Ok(()),
            Ok(Some(_)) => return Err("cargo run exited with an error".into()),
            Ok(None) => {}
            Err(e) => return Err(format!("failed to poll server process: {e}")),
        }
    }
}

/// Build a release binary and copy it into `dist/`.
pub fn pack(target: &str) -> CliResult {
    println!("building release binary...");
    let status = Command::new("cargo")
        .arg("build")
        .arg("--release")
        .arg("--target")
        .arg(target)
        .status()
        .map_err(|e| format!("failed to run cargo build --release --target {target}: {e}"))?;
    if !status.success() {
        return Err("cargo build --release failed".into());
    }
    let bin = find_bin_name()?;
    let src = release_bin_path(target, &bin);
    if !src.exists() {
        return Err(format!("release binary `{}` not found", src.display()));
    }
    fs::create_dir_all("dist").map_err(io_err)?;
    let dst = format!("dist/{bin}");
    fs::copy(&src, &dst).map_err(io_err)?;
    println!("packaged `{}` -> `{dst}`", src.display());
    Ok(())
}

/// Database migrations are not yet implemented.
pub fn migrate() -> CliResult {
    Err("migrate is not implemented yet — use your ORM's migration tooling instead".into())
}

fn spawn_cargo_run() -> Result<Child, String> {
    Command::new("cargo")
        .arg("run")
        .spawn()
        .map_err(|e| format!("failed to start `cargo run`: {e}"))
}

fn child_wait(mut child: Child) -> CliResult {
    match child.wait() {
        Ok(status) if status.success() => Ok(()),
        Ok(status) => Err(format!("cargo run exited with {status}")),
        Err(e) => Err(format!("failed to wait for server process: {e}")),
    }
}

/// Recursively collect mtime-modified paths of source files under `src/`,
/// plus `Cargo.toml`.
fn source_snapshot() -> Result<Vec<(PathBuf, SystemTime)>, String> {
    let mut entries = Vec::new();
    collect_sources(Path::new("src"), &mut entries)?;
    if let Ok(meta) = fs::metadata("Cargo.toml")
        && let Ok(modified) = meta.modified()
    {
        entries.push(("Cargo.toml".into(), modified));
    }
    entries.sort();
    Ok(entries)
}

fn collect_sources(dir: &Path, out: &mut Vec<(PathBuf, SystemTime)>) -> Result<(), String> {
    if !dir.is_dir() {
        return Ok(());
    }
    for entry in fs::read_dir(dir).map_err(io_err)? {
        let path = entry.map_err(io_err)?.path();
        if path.is_dir() {
            collect_sources(&path, out)?;
        } else if path.extension().is_some_and(|e| e == "rs") {
            let modified = fs::metadata(&path).and_then(|m| m.modified()).map_err(io_err)?;
            out.push((path, modified));
        }
    }
    Ok(())
}

/// Resolve the binary name: first `name = ` entry in Cargo.toml
/// (package or bin target).
fn find_bin_name() -> Result<String, String> {
    let manifest = fs::read_to_string("Cargo.toml").map_err(io_err)?;
    for line in manifest.lines() {
        if line.trim_start().starts_with("name = ") {
            let value = line.split_once('=').map(|(_, v)| v.trim()).unwrap_or("");
            return Ok(value.trim_matches('"').to_string());
        }
    }
    Err("could not find a package name in Cargo.toml".into())
}

/// Reject names that escape the intended directory (`../x`, `/abs`,
/// `a/b`): only a single non-empty path segment is allowed.
fn validate_name(name: &str) -> CliResult {
    let ok = !name.is_empty()
        && name != "."
        && name != ".."
        && !name.contains(['/', '\\'])
        && Path::new(name).file_name().and_then(|s| s.to_str()) == Some(name);
    if ok {
        Ok(())
    } else {
        Err(format!("invalid name `{name}`: must be a single path segment (no `/` or `\\`)"))
    }
}

/// Cross-compiled binaries land under `target/<target>/release/`.
fn release_bin_path(target: &str, bin: &str) -> PathBuf {
    Path::new("target").join(target).join("release").join(bin)
}

fn ensure_module_dir(dir: &str) -> CliResult {
    if Path::new(dir).exists() && !Path::new(dir).is_dir() {
        return Err(format!("`{dir}` exists and is not a directory"));
    }
    fs::create_dir_all(dir).map_err(io_err)
}

fn pascal_case(name: &str) -> String {
    name.split('_')
        .filter(|s| !s.is_empty())
        .map(|s| {
            let mut chars = s.chars();
            match chars.next() {
                Some(first) => first.to_uppercase().collect::<String>() + chars.as_str(),
                None => String::new(),
            }
        })
        .collect()
}

fn rust_type(ty: &str) -> Result<&'static str, String> {
    match ty {
        "string" | "text" | "str" => Ok("String"),
        "int" | "integer" | "i64" => Ok("i64"),
        "i32" => Ok("i32"),
        "float" | "f64" => Ok("f64"),
        "bool" | "boolean" => Ok("bool"),
        _ => Err(format!("unknown type: {ty}")),
    }
}

fn io_err(e: std::io::Error) -> String {
    e.to_string()
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::sync::Mutex;

    // CWD is process-global; tests that chdir must run exclusively or they
    // clobber each other when the test binary runs tests in parallel.
    static CWD_LOCK: Mutex<()> = Mutex::new(());

    fn temp_dir(tag: &str) -> PathBuf {
        let dir = std::env::temp_dir().join(format!(
            "bee-cli-test-{}-{}-{}",
            tag,
            std::process::id(),
            SystemTime::now().duration_since(SystemTime::UNIX_EPOCH).unwrap().as_nanos()
        ));
        fs::create_dir_all(&dir).unwrap();
        dir
    }

    #[test]
    fn new_project_scaffolds_runnable_template() {
        let _guard = CWD_LOCK.lock().unwrap();
        let dir = temp_dir("new");
        let old = std::env::current_dir().unwrap();
        std::env::set_current_dir(&dir).unwrap();
        new_project("myapp").unwrap();
        assert!(Path::new("myapp/Cargo.toml").exists());
        assert!(Path::new("myapp/src/main.rs").exists());
        assert!(Path::new("myapp/src/controllers/mod.rs").exists());
        assert!(Path::new("myapp/src/models/mod.rs").exists());
        let manifest = fs::read_to_string("myapp/Cargo.toml").unwrap();
        assert!(manifest.contains("name = \"myapp\""));
        assert!(manifest.contains("bee-rust = \"1\""));
        let main = fs::read_to_string("myapp/src/main.rs").unwrap();
        assert!(main.contains("bee_rust::init()"));
        assert!(main.contains("mod controllers;"));
        assert!(main.contains("mod models;"));
        // Re-creating the same project must fail cleanly.
        assert!(new_project("myapp").is_err());
        std::env::set_current_dir(old).unwrap();
        fs::remove_dir_all(&dir).unwrap();
    }

    #[test]
    fn generate_controller_writes_file() {
        let _guard = CWD_LOCK.lock().unwrap();
        let dir = temp_dir("controller");
        let old = std::env::current_dir().unwrap();
        std::env::set_current_dir(&dir).unwrap();
        generate_controller("user").unwrap();
        let content = fs::read_to_string("controllers/user.rs").unwrap();
        assert!(content.contains("UserController"));
        assert!(content.contains("impl Controller for UserController"));
        assert!(generate_controller("user").is_err());
        std::env::set_current_dir(old).unwrap();
        fs::remove_dir_all(&dir).unwrap();
    }

    #[test]
    fn generate_model_parses_fields() {
        let _guard = CWD_LOCK.lock().unwrap();
        let dir = temp_dir("model");
        let old = std::env::current_dir().unwrap();
        std::env::set_current_dir(&dir).unwrap();
        generate_model("post", Some("title:string,views:int,active:bool")).unwrap();
        let content = fs::read_to_string("models/post.rs").unwrap();
        assert!(content.contains("pub struct Post"));
        assert!(content.contains("    id: i64,\n    title: String"));
        assert!(content.contains("views: i64"));
        assert!(content.contains("active: bool"));
        std::env::set_current_dir(old).unwrap();
        fs::remove_dir_all(&dir).unwrap();
    }

    #[test]
    fn generate_model_without_fields_has_only_id() {
        let _guard = CWD_LOCK.lock().unwrap();
        let dir = temp_dir("model-nofields");
        let old = std::env::current_dir().unwrap();
        std::env::set_current_dir(&dir).unwrap();
        generate_model("user", None).unwrap();
        let content = fs::read_to_string("models/user.rs").unwrap();
        assert!(content.contains("id: i64"));
        assert!(!content.contains("name:"));
        std::env::set_current_dir(old).unwrap();
        fs::remove_dir_all(&dir).unwrap();
    }

    #[test]
    fn generate_model_rejects_bad_field_spec() {
        let _guard = CWD_LOCK.lock().unwrap();
        let dir = temp_dir("model-bad");
        let old = std::env::current_dir().unwrap();
        std::env::set_current_dir(&dir).unwrap();
        assert!(generate_model("user", Some("title")).is_err());
        std::env::set_current_dir(old).unwrap();
        fs::remove_dir_all(&dir).unwrap();
    }

    #[test]
    fn migrate_reports_not_implemented() {
        assert!(migrate().unwrap_err().contains("not implemented"));
    }

    #[test]
    fn names_with_path_separators_are_rejected() {
        let _guard = CWD_LOCK.lock().unwrap();
        let dir = temp_dir("traversal");
        let old = std::env::current_dir().unwrap();
        std::env::set_current_dir(&dir).unwrap();
        for bad in ["../x", "/abs/path", "a/b", ".", "..", "sub\\file"] {
            assert!(generate_controller(bad).is_err(), "{bad} controller");
            assert!(generate_model(bad, None).is_err(), "{bad} model");
            assert!(new_project(bad).is_err(), "{bad} new");
        }
        assert!(!Path::new("../x.rs").exists());
        assert!(!Path::new("models/x.rs").exists());
        std::env::set_current_dir(old).unwrap();
        fs::remove_dir_all(&dir).unwrap();
    }

    #[test]
    fn generate_model_rejects_unknown_type() {
        let _guard = CWD_LOCK.lock().unwrap();
        let dir = temp_dir("model-unknown-type");
        let old = std::env::current_dir().unwrap();
        std::env::set_current_dir(&dir).unwrap();
        let err = generate_model("user", Some("age:innt")).unwrap_err();
        assert!(err.contains("unknown type: innt"), "{err}");
        assert!(!Path::new("models/user.rs").exists());
        std::env::set_current_dir(old).unwrap();
        fs::remove_dir_all(&dir).unwrap();
    }

    #[test]
    fn generate_model_uses_meta_crate_orm_path() {
        let _guard = CWD_LOCK.lock().unwrap();
        let dir = temp_dir("model-orm-path");
        let old = std::env::current_dir().unwrap();
        std::env::set_current_dir(&dir).unwrap();
        generate_model("post", None).unwrap();
        let content = fs::read_to_string("models/post.rs").unwrap();
        assert!(content.contains("use bee_rust::bee_orm::Model;"));
        assert!(!content.contains("use bee_orm::Model;"));
        std::env::set_current_dir(old).unwrap();
        fs::remove_dir_all(&dir).unwrap();
    }

    #[test]
    fn release_bin_path_respects_target() {
        assert_eq!(
            release_bin_path("linux/aarch64", "app"),
            PathBuf::from("target/linux/aarch64/release/app")
        );
    }

    #[test]
    fn pascal_case_converts_snake() {
        assert_eq!(pascal_case("user"), "User");
        assert_eq!(pascal_case("blog_post"), "BlogPost");
        assert_eq!(pascal_case("API"), "API");
    }

    #[test]
    fn rust_type_maps_aliases() {
        for (input, expected) in [
            ("string", "String"),
            ("text", "String"),
            ("str", "String"),
            ("int", "i64"),
            ("integer", "i64"),
            ("i64", "i64"),
            ("i32", "i32"),
            ("float", "f64"),
            ("f64", "f64"),
            ("bool", "bool"),
            ("boolean", "bool"),
        ] {
            assert_eq!(rust_type(input).unwrap(), expected, "alias {input}");
        }
        assert!(rust_type("datetime").unwrap_err().contains("unknown type: datetime"));
    }

    #[test]
    fn find_bin_name_parses_package_name() {
        let _guard = CWD_LOCK.lock().unwrap();
        let dir = temp_dir("find-bin");
        let old = std::env::current_dir().unwrap();
        std::env::set_current_dir(&dir).unwrap();
        fs::write("Cargo.toml", "[package]\nname = \"my-server\"\nversion = \"0.1.0\"\n").unwrap();
        assert_eq!(find_bin_name().unwrap(), "my-server");
        fs::write("Cargo.toml", "[dependencies]\n").unwrap();
        assert!(find_bin_name().unwrap_err().contains("could not find"));
        std::env::set_current_dir(old).unwrap();
        fs::remove_dir_all(&dir).unwrap();
    }

    #[test]
    fn validate_name_rejects_path_separators_and_dots() {
        for bad in ["", ".", "..", "../x", "a/b", "sub\\file", "/abs"] {
            assert!(validate_name(bad).is_err(), "{bad:?}");
        }
        for good in ["app", "my-app", "my_app", "App1"] {
            assert!(validate_name(good).is_ok(), "{good:?}");
        }
    }
}
