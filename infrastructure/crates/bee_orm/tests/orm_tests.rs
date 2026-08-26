// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
use bee_orm::Model;

#[derive(Model)]
#[allow(dead_code)]
struct User {
    id: i32,
    name: String,
    age: i32,
}

#[test]
fn test_table_name() {
    assert_eq!(User::table_name(), "users");
}

#[test]
fn test_query_select_all() {
    let sql = User::query().to_sql();
    assert_eq!(sql, "SELECT * FROM users");
}

#[test]
fn test_query_with_filter() {
    let sql = User::query().filter("age > 18").to_sql();
    assert_eq!(sql, "SELECT * FROM users WHERE age > 18");
}

#[test]
fn test_query_with_multiple_filters() {
    let sql = User::query().filter("age > 18").filter("name LIKE 'A%'").to_sql();
    assert_eq!(sql, "SELECT * FROM users WHERE age > 18 AND name LIKE 'A%'");
}

#[test]
fn test_query_with_order_by() {
    let sql = User::query().order_by("id DESC").to_sql();
    assert_eq!(sql, "SELECT * FROM users ORDER BY id DESC");
}

#[test]
fn test_query_with_limit_offset() {
    let sql = User::query().limit(10).offset(20).to_sql();
    assert_eq!(sql, "SELECT * FROM users LIMIT 10 OFFSET 20");
}

#[test]
fn test_query_combined() {
    let sql = User::query().filter("age > 18").order_by("id DESC").limit(10).offset(5).to_sql();
    assert_eq!(sql, "SELECT * FROM users WHERE age > 18 ORDER BY id DESC LIMIT 10 OFFSET 5");
}

#[test]
fn test_filter_eq_parametrised() {
    let qs = User::query().filter_eq("name", "o'neil");
    let sql = qs.to_sql();
    assert_eq!(sql, "SELECT * FROM users WHERE name = ?");
    assert_eq!(qs.params(), &["o'neil"]);
    assert!(!sql.contains("o'neil"));
}

#[test]
fn test_filter_comparisons() {
    let qs = User::query().filter_gt("age", "18").filter_lt("age", "65");
    assert_eq!(qs.to_sql(), "SELECT * FROM users WHERE age > ? AND age < ?");
    assert_eq!(qs.params(), &["18", "65"]);
}

#[test]
fn test_filter_contains_parametrised() {
    let qs = User::query().filter_contains("name", "o'neil");
    assert_eq!(qs.to_sql(), "SELECT * FROM users WHERE name LIKE ?");
    assert_eq!(qs.params(), &["%o'neil%"]);
    assert!(!qs.to_sql().contains("o'neil"));
}

#[test]
fn test_mixed_raw_and_parametrised_filters() {
    let qs = User::query().filter("active = 1").filter_eq("name", "o'neil").filter_gt("age", "18");
    assert_eq!(qs.to_sql(), "SELECT * FROM users WHERE active = 1 AND name = ? AND age > ?");
    assert_eq!(qs.params(), &["o'neil", "18"]);
}

#[test]
fn test_query_without_filters_is_plain_select() {
    let qs = User::query();
    assert_eq!(qs.to_sql(), "SELECT * FROM users");
    assert!(qs.params().is_empty());
}

#[test]
fn test_query_with_only_offset() {
    // OFFSET without LIMIT is valid SQL and must be emitted on its own.
    let sql = User::query().offset(20).to_sql();
    assert_eq!(sql, "SELECT * FROM users OFFSET 20");
}

#[test]
fn test_multiple_order_clauses_are_comma_joined() {
    let sql = User::query().order_by("age DESC").order_by("name ASC").to_sql();
    assert_eq!(sql, "SELECT * FROM users ORDER BY age DESC, name ASC");
}

#[test]
fn test_filter_contains_param_order_matches_sql() {
    // One param per parametrised filter, in call order, regardless of
    // interleaved raw filters.
    let qs = User::query()
        .filter("active = 1")
        .filter_contains("name", "a")
        .filter_eq("city", "x")
        .filter_lt("age", "30");
    assert_eq!(
        qs.to_sql(),
        "SELECT * FROM users WHERE active = 1 AND name LIKE ? AND city = ? AND age < ?"
    );
    assert_eq!(qs.params(), &["%a%", "x", "30"]);
}

#[test]
fn test_limit_zero_and_offset_zero() {
    let sql = User::query().limit(0).offset(0).to_sql();
    assert_eq!(sql, "SELECT * FROM users LIMIT 0 OFFSET 0");
}

#[test]
fn test_queryset_builder_is_consuming_but_reusable() {
    // Methods take self by value; chaining must produce an equivalent query
    // regardless of split across statements.
    let a = User::query().filter_eq("age", "18").limit(5);
    let b = User::query().filter_eq("age", "18");
    assert_eq!(a.to_sql(), "SELECT * FROM users WHERE age = ? LIMIT 5");
    assert_eq!(b.to_sql(), "SELECT * FROM users WHERE age = ?");
}

#[test]
fn test_table_name_is_pluralised() {
    assert_eq!(User::table_name(), "users");
}

#[test]
fn test_orm_error_display() {
    assert_eq!(
        bee_orm::OrmError::ConnectionError("boom".into()).to_string(),
        "connection error: boom"
    );
    assert_eq!(bee_orm::OrmError::QueryError("bad sql".into()).to_string(), "query error: bad sql");
    assert_eq!(bee_orm::OrmError::NotFound.to_string(), "not found");
}
