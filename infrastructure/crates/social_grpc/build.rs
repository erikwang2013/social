fn main() -> Result<(), Box<dyn std::error::Error>> {
    tonic_build::configure()
        .include_file("social.rs")
        .compile_protos(
            &[
                "../../../contracts/infra/infra_service.proto",
                "../../../contracts/common/types.proto",
            ],
            &["../../../contracts"],
        )?;
    Ok(())
}
