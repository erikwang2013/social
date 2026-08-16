// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
use async_trait::async_trait;
use chrono::{DateTime, Utc};
use reqwest::{Client, header::CONTENT_TYPE};

use crate::{
    CQSpec, Fields, Point, TagFilter, Tags, TimeSeries, TimeSeriesDB, Timestamp, TsdbError,
};

/// QuestDB driver backed by its HTTP exec API (`POST /exec`).
pub struct QuestDB {
    client: Client,
    base_url: String,
}

impl QuestDB {
    pub fn new(base_url: impl Into<String>) -> Self {
        Self { client: Client::new(), base_url: base_url.into() }
    }
}

#[async_trait]
impl TimeSeriesDB for QuestDB {
    async fn write_point(&self, point: Point) -> Result<(), TsdbError> {
        let cols = point_columns(&point);
        let sql = format!(
            "INSERT INTO {} ({}) VALUES ({})",
            point.measurement,
            cols.join(", "),
            point_values(&point, &cols)?
        );
        self.exec(&sql).await
    }

    async fn write_batch(&self, points: TimeSeries) -> Result<(), TsdbError> {
        let Some(first) = points.first() else {
            return Ok(());
        };
        // ponytail: multi-row VALUES requires a uniform column layout across
        // the batch; mixed schemas would misalign rows.
        let cols = point_columns(first);
        let mut sql = format!("INSERT INTO {} ({}) VALUES ", first.measurement, cols.join(", "));
        for (i, p) in points.iter().enumerate() {
            if i > 0 {
                sql.push_str(", ");
            }
            sql.push_str(&format!("({})", point_values(p, &cols)?));
        }
        self.exec(&sql).await
    }

    async fn query_range(
        &self,
        measurement: &str,
        start: Timestamp,
        end: Timestamp,
        _tag_filters: Option<&[TagFilter]>,
    ) -> Result<TimeSeries, TsdbError> {
        let sql = format!(
            "SELECT * FROM {m} WHERE ts >= '{s}' AND ts <= '{e}'",
            m = measurement,
            s = start.to_rfc3339(),
            e = end.to_rfc3339(),
        );
        let res = self.post_exec(&sql).await?;
        if !res.status().is_success() {
            return Err(http_error(res, "query_range").await);
        }
        let payload: serde_json::Value = res.json().await.map_err(query_err)?;
        if let Some(err) = payload.get("error") {
            return Err(TsdbError::QueryError(format!("questdb query failed: {err}")));
        }
        parse_dataset(&payload, measurement)
    }

    async fn create_continuous_query(&self, _spec: CQSpec) -> Result<(), TsdbError> {
        // ponytail: no native CQ in QuestDB; no-op until needed
        Ok(())
    }
}

fn point_columns(point: &Point) -> Vec<String> {
    let mut tags: Vec<&String> = point.tags.keys().collect();
    tags.sort();
    let mut fields: Vec<&String> = point.fields.keys().collect();
    fields.sort();
    let mut cols = vec!["ts".to_string()];
    cols.extend(tags.into_iter().cloned());
    cols.extend(fields.into_iter().cloned());
    cols
}

fn point_values(point: &Point, cols: &[String]) -> Result<String, TsdbError> {
    let mut vals = vec![point.timestamp.timestamp_micros().to_string()];
    for c in &cols[1..] {
        if let Some(v) = point.tags.get(c) {
            vals.push(format!("'{}'", v.replace('\'', "''")));
        } else if let Some(v) = point.fields.get(c) {
            vals.push(field_value(v)?);
        }
    }
    Ok(vals.join(", "))
}

fn field_value(v: &serde_json::Value) -> Result<String, TsdbError> {
    match v {
        serde_json::Value::String(s) => Ok(format!("'{}'", s.replace('\'', "''"))),
        serde_json::Value::Number(n) => Ok(n.to_string()),
        serde_json::Value::Bool(b) => Ok(if *b { "true" } else { "false" }.to_string()),
        _ => Err(TsdbError::WriteError(format!("unsupported field value: {v}"))),
    }
}

fn parse_time(s: &str) -> Result<Timestamp, TsdbError> {
    if let Ok(us) = s.parse::<i64>() {
        return DateTime::from_timestamp_micros(us)
            .ok_or_else(|| TsdbError::QueryError(format!("bad timestamp {s:?}")));
    }
    DateTime::parse_from_rfc3339(s)
        .map(|dt| dt.with_timezone(&Utc))
        .map_err(|e| TsdbError::QueryError(format!("bad timestamp {s:?}: {e}")))
}

/// Maps `{columns, dataset}` output to points: `ts` is the timestamp,
/// SYMBOL columns become tags, everything else becomes fields.
fn parse_dataset(payload: &serde_json::Value, measurement: &str) -> Result<TimeSeries, TsdbError> {
    let cols: Vec<(String, String)> = payload["columns"]
        .as_array()
        .map(|arr| {
            arr.iter()
                .map(|c| {
                    (
                        c["name"].as_str().unwrap_or_default().to_string(),
                        c["type"].as_str().unwrap_or_default().to_string(),
                    )
                })
                .collect()
        })
        .unwrap_or_default();
    let mut points = Vec::new();
    for row in payload["dataset"].as_array().map(Vec::as_slice).unwrap_or(&[]) {
        let mut tags = Tags::new();
        let mut fields = Fields::new();
        let mut timestamp = None;
        for (i, (name, ty)) in cols.iter().enumerate() {
            let value = row.get(i).and_then(|v| v.as_str()).unwrap_or_default();
            if name == "ts" {
                timestamp = Some(parse_time(value)?);
            } else if ty == "SYMBOL" {
                tags.insert(name.clone(), value.to_string());
            } else {
                fields.insert(name.clone(), typed_value(value, ty));
            }
        }
        if let Some(ts) = timestamp {
            points.push(Point { measurement: measurement.into(), tags, fields, timestamp: ts });
        }
    }
    Ok(points)
}

fn typed_value(s: &str, ty: &str) -> serde_json::Value {
    match ty {
        "DOUBLE" | "FLOAT" | "LONG" | "INT" | "SHORT" | "BYTE" => {
            s.parse::<f64>().map(|n| serde_json::json!(n)).unwrap_or(serde_json::json!(s))
        }
        "BOOLEAN" => serde_json::json!(s == "true"),
        _ => serde_json::json!(s),
    }
}

impl QuestDB {
    async fn post_exec(&self, sql: &str) -> Result<reqwest::Response, TsdbError> {
        self.client
            .post(format!("{}/exec", self.base_url))
            .header(CONTENT_TYPE, "application/x-www-form-urlencoded")
            .body(format!("query={}", urlencode(sql)))
            .send()
            .await
            .map_err(conn_err)
    }

    async fn exec(&self, sql: &str) -> Result<(), TsdbError> {
        let res = self.post_exec(sql).await?;
        if !res.status().is_success() {
            return Err(http_error(res, "exec").await);
        }
        let payload: serde_json::Value = res.json().await.map_err(query_err)?;
        if let Some(err) = payload.get("error") {
            return Err(TsdbError::QueryError(format!("questdb exec failed: {err}")));
        }
        Ok(())
    }
}

fn urlencode(s: &str) -> String {
    let mut out = String::new();
    for b in s.bytes() {
        match b {
            b'A'..=b'Z' | b'a'..=b'z' | b'0'..=b'9' | b'-' | b'_' | b'.' | b'~' => {
                out.push(b as char)
            }
            b' ' => out.push('+'),
            _ => out.push_str(&format!("%{b:02X}")),
        }
    }
    out
}

async fn http_error(res: reqwest::Response, op: &str) -> TsdbError {
    let status = res.status();
    let body = res.text().await.unwrap_or_default();
    TsdbError::QueryError(format!("questdb {op} failed with {status}: {body}"))
}

fn conn_err(e: reqwest::Error) -> TsdbError {
    TsdbError::ConnectionError(e.to_string())
}

fn query_err(e: reqwest::Error) -> TsdbError {
    TsdbError::QueryError(e.to_string())
}

#[cfg(test)]
mod tests {
    use super::*;
    use axum::body::Body;
    use axum::http::{Request, StatusCode};
    use axum::routing::post;
    use chrono::TimeZone;

    async fn mock(routes: Vec<(&str, axum::routing::MethodRouter)>) -> String {
        let mut app = axum::Router::new();
        for (path, router) in routes {
            app = app.route(path, router);
        }
        let listener = tokio::net::TcpListener::bind("127.0.0.1:0").await.unwrap();
        let addr = listener.local_addr().unwrap();
        tokio::spawn(async move { axum::serve(listener, app).await.unwrap() });
        format!("http://{addr}")
    }

    fn make_point(day: u32) -> Point {
        let mut point = Point {
            measurement: "cpu".into(),
            tags: Tags::new(),
            fields: Fields::new(),
            timestamp: Utc.with_ymd_and_hms(2026, 1, day, 0, 0, 0).single().unwrap(),
        };
        point.tags.insert("host".into(), "srv1".into());
        point.fields.insert("value".into(), serde_json::json!(42.0));
        point
    }

    fn decoded(body: &str) -> String {
        let bytes = body.as_bytes();
        let mut out = Vec::with_capacity(bytes.len());
        let mut i = 0;
        while i < bytes.len() {
            let hex = if bytes[i] == b'%' && i + 2 < bytes.len() {
                (bytes[i + 1] as char).to_digit(16).and_then(|hi| {
                    (bytes[i + 2] as char).to_digit(16).map(|lo| (hi * 16 + lo) as u8)
                })
            } else {
                None
            };
            match hex {
                Some(b) => {
                    out.push(b);
                    i += 3;
                }
                None => {
                    out.push(if bytes[i] == b'+' { b' ' } else { bytes[i] });
                    i += 1;
                }
            }
        }
        String::from_utf8(out).unwrap()
    }

    #[tokio::test]
    async fn write_inserts_columns() {
        let base = mock(vec![(
            "/exec",
            post(|req: Request<Body>| async move {
                let body = axum::body::to_bytes(req.into_body(), 4096).await.unwrap();
                let text = decoded(core::str::from_utf8(&body).unwrap());
                assert!(text.contains(
                    "INSERT INTO cpu (ts, host, value) VALUES (1767225600000000, 'srv1', 42.0)"
                ));
                (StatusCode::OK, axum::Json(serde_json::json!({"ddl": false, "count": 1})))
            }),
        )])
        .await;
        let db = QuestDB::new(base);
        db.write_point(make_point(1)).await.unwrap();
    }

    #[tokio::test]
    async fn write_batch_sends_multi_row() {
        let base = mock(vec![(
            "/exec",
            post(|req: Request<Body>| async move {
                let body = axum::body::to_bytes(req.into_body(), 4096).await.unwrap();
                let text = decoded(core::str::from_utf8(&body).unwrap());
                assert!(text.contains(
                    "VALUES (1767225600000000, 'srv1', 42.0), (1767312000000000, 'srv1', 42.0)"
                ));
                (StatusCode::OK, axum::Json(serde_json::json!({"ddl": false, "count": 2})))
            }),
        )])
        .await;
        let db = QuestDB::new(base);
        db.write_batch(vec![make_point(1), make_point(2)]).await.unwrap();
    }

    #[tokio::test]
    async fn query_parses_dataset() {
        let base = mock(vec![(
            "/exec",
            post(|req: Request<Body>| async move {
                let body = axum::body::to_bytes(req.into_body(), 4096).await.unwrap();
                let text = decoded(core::str::from_utf8(&body).unwrap());
                assert!(text.contains("SELECT * FROM cpu WHERE ts >= '2026-01-01T00:00:00+00:00'"));
                (
                    StatusCode::OK,
                    axum::Json(serde_json::json!({
                        "query": "select-ts",
                        "columns": [
                            {"name": "ts", "type": "TIMESTAMP"},
                            {"name": "host", "type": "SYMBOL"},
                            {"name": "value", "type": "DOUBLE"}
                        ],
                        "dataset": [["2026-01-01T00:00:00Z", "srv1", "42.0"]],
                        "count": 1,
                        "ddl": false
                    })),
                )
            }),
        )])
        .await;
        let db = QuestDB::new(base);
        let start = Utc.with_ymd_and_hms(2026, 1, 1, 0, 0, 0).single().unwrap();
        let end = Utc.with_ymd_and_hms(2026, 1, 31, 0, 0, 0).single().unwrap();
        let points = db.query_range("cpu", start, end, None).await.unwrap();
        assert_eq!(points.len(), 1);
        assert_eq!(points[0].measurement, "cpu");
        assert_eq!(points[0].tags.get("host").unwrap(), "srv1");
        assert_eq!(points[0].fields["value"], 42.0);
        assert_eq!(points[0].timestamp.timestamp(), 1767225600);
    }

    #[tokio::test]
    async fn create_cq_is_noop() {
        let db = QuestDB::new("http://127.0.0.1:1");
        db.create_continuous_query(CQSpec {
            name: "cq1".into(),
            source: "raw".into(),
            target: "downsampled".into(),
            every: "1h".into(),
            aggregation: crate::Aggregation::Mean,
        })
        .await
        .unwrap();
    }
}
