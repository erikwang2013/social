// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
use proc_macro::TokenStream;
use quote::quote;
use syn::{DeriveInput, parse_macro_input};

#[proc_macro_derive(Model, attributes(bee))]
pub fn derive_model(input: TokenStream) -> TokenStream {
    let input = parse_macro_input!(input as DeriveInput);
    let name = &input.ident;
    let table_name = name.to_string().to_lowercase() + "s";

    let expanded = quote! {
        impl bee_orm::Model for #name {}

        impl #name {
            pub fn query() -> bee_orm::QuerySet<Self> {
                bee_orm::QuerySet::new(#table_name)
            }

            pub fn table_name() -> &'static str {
                #table_name
            }
        }
    };

    TokenStream::from(expanded)
}
