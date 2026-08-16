// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
use std::collections::HashMap;
use std::path::Path;

use serde::Serialize;
use tera::{Context, Tera};

#[derive(Debug, thiserror::Error)]
pub enum TemplateError {
    #[error("template not found: {0}")]
    NotFound(String),
    #[error("render error: {0}")]
    RenderError(String),
}

pub struct TemplateEngine {
    tera: Tera,
}

impl TemplateEngine {
    /// Create a new `TemplateEngine` by loading all `*.html` templates from the given directory.
    pub fn new(template_dir: &Path) -> Result<Self, TemplateError> {
        let pattern = template_dir
            .join("*.html")
            .to_str()
            .ok_or_else(|| TemplateError::NotFound(template_dir.display().to_string()))?
            .to_string();

        let tera = Tera::new(&pattern).map_err(|e| {
            TemplateError::NotFound(format!(
                "failed to load templates from '{}': {}",
                template_dir.display(),
                e
            ))
        })?;

        Ok(Self { tera })
    }

    /// Render a template by name with the given data.
    pub fn render(
        &self,
        template: &str,
        data: &HashMap<String, serde_json::Value>,
    ) -> Result<String, TemplateError> {
        let mut context = Context::new();

        for (key, value) in data {
            // Insert each value as a JSON value — Tera handles serde_json::Value natively
            context.insert(key, value);
        }

        self.tera.render(template, &context).map_err(|e| {
            TemplateError::RenderError(format!("failed to render '{}': {}", template, e))
        })
    }
}

/// Macro to create a `HashMap<String, serde_json::Value>` from key-value pairs.
///
/// # Panics
///
/// Panics if a value cannot be serialized to JSON, e.g. non-finite floats
/// (`NaN`, `Infinity`, `-Infinity`).
///
/// # Examples
///
/// ```
/// use bee_template::context;
///
/// let name = "Alice";
/// let age = 30u32;
/// let ctx = context! {
///     "name": &name,
///     "age": &age,
/// };
/// assert_eq!(ctx["name"], serde_json::Value::String("Alice".to_string()));
/// assert_eq!(ctx["age"], serde_json::json!(30));
/// ```
#[macro_export]
macro_rules! context {
    ($($key:literal : $value:expr),* $(,)?) => {{
        let mut map = ::std::collections::HashMap::new();
        $(
            map.insert(
                $key.to_string(),
                $crate::_to_json_value(&$value),
            );
        )*
        map
    }};
}

/// Helper to convert any serializable reference into `serde_json::Value`.
#[doc(hidden)]
pub fn _to_json_value<T: Serialize>(val: &T) -> serde_json::Value {
    serde_json::to_value(val).expect("context! macro values must be serializable")
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::io::Write;

    #[test]
    fn test_context_macro() {
        let name = "Alice";
        let age = 30u32;

        let ctx = context! {
            "name": &name,
            "age": &age,
        };

        assert_eq!(ctx["name"], serde_json::Value::String("Alice".to_string()));
        assert_eq!(ctx["age"], serde_json::json!(30));
        assert_eq!(ctx.len(), 2);
    }

    #[test]
    fn test_render() {
        // Create a temporary directory with a template file
        let dir = tempfile::tempdir().unwrap();
        let template_path = dir.path().join("hello.html");
        let mut f = std::fs::File::create(&template_path).unwrap();
        writeln!(f, "Hello, {{{{ name }}}}! You are {{{{ age }}}} years old.").unwrap();

        let engine = TemplateEngine::new(dir.path()).unwrap();

        let data = context! {
            "name": &"Bob",
            "age": &25u32,
        };

        let result = engine.render("hello.html", &data).unwrap();
        assert_eq!(result.trim(), "Hello, Bob! You are 25 years old.");
    }
}
