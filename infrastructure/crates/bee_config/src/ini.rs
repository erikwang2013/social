// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
use std::collections::HashMap;

pub struct IniParser;

impl IniParser {
    pub fn parse(content: &str) -> HashMap<String, HashMap<String, String>> {
        let mut result: HashMap<String, HashMap<String, String>> = HashMap::new();
        let mut current_section = String::from("default");

        for line in content.lines() {
            let trimmed = line.trim();
            if trimmed.is_empty() || trimmed.starts_with(';') || trimmed.starts_with('#') {
                continue;
            }
            if trimmed.starts_with('[') && trimmed.ends_with(']') {
                current_section = trimmed[1..trimmed.len() - 1].to_string();
                continue;
            }
            if let Some((key, value)) = trimmed.split_once('=') {
                result
                    .entry(current_section.clone())
                    .or_default()
                    .insert(key.trim().to_string(), value.trim().to_string());
            }
        }
        result
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_parse_simple() {
        let content = "app_name = test-app\nhttp_port = 8080\n";
        let parsed = IniParser::parse(content);
        let default = &parsed["default"];
        assert_eq!(default["app_name"], "test-app");
        assert_eq!(default["http_port"], "8080");
    }

    #[test]
    fn test_parse_sections() {
        let content = "app_name = test-app\n\n[database]\nhost = localhost\nport = 5432\n";
        let parsed = IniParser::parse(content);
        assert_eq!(parsed["default"]["app_name"], "test-app");
        assert_eq!(parsed["database"]["host"], "localhost");
    }
}
