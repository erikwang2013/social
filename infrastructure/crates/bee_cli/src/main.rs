// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
use clap::{Parser, Subcommand};

#[derive(Parser)]
#[command(name = "bee-rust", about = "bee-rust framework CLI tool")]
struct Cli {
    #[command(subcommand)]
    command: Commands,
}

#[derive(Subcommand)]
enum Commands {
    /// Create a new bee-rust project
    New { name: String },
    /// Generate a controller or model
    Generate {
        #[command(subcommand)]
        kind: GenerateKind,
    },
    /// Run the development server
    Run {
        #[arg(long)]
        watch: bool,
    },
    /// Run database migrations
    Migrate {
        #[command(subcommand)]
        direction: MigrateDirection,
    },
    /// Package for deployment
    Pack {
        #[arg(long, default_value = "linux/x86_64")]
        target: String,
    },
}

#[derive(Subcommand)]
enum GenerateKind {
    Controller {
        name: String,
    },
    Model {
        name: String,
        #[arg(long)]
        fields: Option<String>,
    },
}

#[derive(Subcommand)]
enum MigrateDirection {
    Up,
    Down,
}

fn main() {
    let cli = Cli::parse();
    let result = match cli.command {
        Commands::New { name } => bee_cli::new_project(&name),
        Commands::Generate { kind } => match kind {
            GenerateKind::Controller { name } => bee_cli::generate_controller(&name),
            GenerateKind::Model { name, fields } => {
                bee_cli::generate_model(&name, fields.as_deref())
            }
        },
        Commands::Run { watch } => bee_cli::run_server(watch),
        Commands::Migrate { .. } => bee_cli::migrate(),
        Commands::Pack { target } => bee_cli::pack(&target),
    };
    if let Err(message) = result {
        eprintln!("error: {message}");
        std::process::exit(1);
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use clap::Parser;

    #[test]
    fn test_new_command() {
        let cli = Cli::try_parse_from(["bee-rust", "new", "myapp"]).unwrap();
        match cli.command {
            Commands::New { name } => assert_eq!(name, "myapp"),
            _ => panic!("expected New command"),
        }
    }

    #[test]
    fn test_generate_controller() {
        let cli = Cli::try_parse_from(["bee-rust", "generate", "controller", "users"]).unwrap();
        match cli.command {
            Commands::Generate { kind } => match kind {
                GenerateKind::Controller { name } => assert_eq!(name, "users"),
                _ => panic!("expected Controller"),
            },
            _ => panic!("expected Generate command"),
        }
    }

    #[test]
    fn test_generate_model() {
        let cli = Cli::try_parse_from([
            "bee-rust",
            "generate",
            "model",
            "post",
            "--fields",
            "title:string,body:text",
        ])
        .unwrap();
        match cli.command {
            Commands::Generate { kind } => match kind {
                GenerateKind::Model { name, fields } => {
                    assert_eq!(name, "post");
                    assert_eq!(fields, Some("title:string,body:text".into()));
                }
                _ => panic!("expected Model"),
            },
            _ => panic!("expected Generate command"),
        }
    }

    #[test]
    fn test_run_command() {
        let cli = Cli::try_parse_from(["bee-rust", "run"]).unwrap();
        match cli.command {
            Commands::Run { watch } => assert!(!watch),
            _ => panic!("expected Run command"),
        }
    }

    #[test]
    fn test_run_watch() {
        let cli = Cli::try_parse_from(["bee-rust", "run", "--watch"]).unwrap();
        match cli.command {
            Commands::Run { watch } => assert!(watch),
            _ => panic!("expected Run command"),
        }
    }

    #[test]
    fn test_migrate_up() {
        let cli = Cli::try_parse_from(["bee-rust", "migrate", "up"]).unwrap();
        match cli.command {
            Commands::Migrate { direction } => match direction {
                MigrateDirection::Up => {}
                _ => panic!("expected Up"),
            },
            _ => panic!("expected Migrate command"),
        }
    }

    #[test]
    fn test_migrate_down() {
        let cli = Cli::try_parse_from(["bee-rust", "migrate", "down"]).unwrap();
        match cli.command {
            Commands::Migrate { direction } => match direction {
                MigrateDirection::Down => {}
                _ => panic!("expected Down"),
            },
            _ => panic!("expected Migrate command"),
        }
    }

    #[test]
    fn test_pack_default_target() {
        let cli = Cli::try_parse_from(["bee-rust", "pack"]).unwrap();
        match cli.command {
            Commands::Pack { target } => assert_eq!(target, "linux/x86_64"),
            _ => panic!("expected Pack command"),
        }
    }

    #[test]
    fn test_pack_custom_target() {
        let cli = Cli::try_parse_from(["bee-rust", "pack", "--target", "linux/aarch64"]).unwrap();
        match cli.command {
            Commands::Pack { target } => assert_eq!(target, "linux/aarch64"),
            _ => panic!("expected Pack command"),
        }
    }
}
