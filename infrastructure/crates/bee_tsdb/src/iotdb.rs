// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
use async_trait::async_trait;
use chrono::{DateTime, Utc};
use reqwest::Client;

use crate::{
    Aggregation, CQSpec, Fields, FilterOp, Point, TagFilter, Tags, TimeSeries, TimeSeriesDB,
    Timestamp, TsdbError,
};

/// IoTDB driver backed by its REST API v1 (`POST /api/v1/query` with SQL).
pub struct IoTDB {
    client: Client,
    base_url: String,
}

impl IoTDB {
    pub fn new(base_url: impl Into<String>) -> Self {
        Self { client: Client::new(), base_url: base_url.into() }
    }
}

#[async_trait]
impl TimeSeriesDB for IoTDB {
    async fn write_point(&self, point: Point) -> Result<(), TsdbError> {
        let path = path_prefix(&point.measurement, &point.tags);
        let mut cols = vec!["timestamp".to_string()];
        let mut vals = vec![point.timestamp.timestamp_millis().to_string()];
        for (k, v) in &point.fields {
            cols.push(k.clone());
            vals.push(iotdb_value(v)?);
        }
        let sql = format!("INSERT INTO {path}({}) VALUES ({})", cols.join(", "), vals.join(", "));
        self.exec(&sql).await
    }

    async fn write_batch(&self, points: TimeSeries) -> Result<(), TsdbError> {
        for point in points {
            self.write_point(point).await?;
        }
        Ok(())
    }

    async fn query_range(
        &self,
        measurement: &str,
        start: Timestamp,
        end: Timestamp,
        tag_filters: Option<&[TagFilter]>,
    ) -> Result<TimeSeries, TsdbError> {
        // Wildcard covers every tag variant; tag path segments are unknown at
        // query time, so filters apply client-side over the parsed tags.
        // ponytail: no server-side filter pushdown; add when throughput matters.
        let sql = format!(
            "SELECT * FROM root.{m}.** WHERE time >= '{s}' AND time <= '{e}'",
            m = measurement,
            s = start.to_rfc3339(),
            e = end.to_rfc3339(),
        );
        let res = self
            .client
            .post(format!("{}/api/v1/query", self.base_url))
            .json(&serde_json::json!({ "sql": sql }))
            .send()
            .await
            .map_err(conn_err)?;
        if !res.status().is_success() {
            return Err(http_error(res, "query_range").await);
        }
        let payload: serde_json::Value = res.json().await.map_err(query_err)?;
        if payload["code"].as_i64().unwrap_or(200) != 200 {
            return Err(TsdbError::QueryError(format!("iotdb query failed: {payload}")));
        }
        let mut points = parse_result(
            payload["result"].as_array().map(Vec::as_slice).unwrap_or(&[]),
            measurement,
        )?;
        if let Some(filters) = tag_filters {
            points.retain(|p| filters.iter().all(|f| filter_matches(f, p)));
        }
        Ok(points)
    }

    async fn create_continuous_query(&self, spec: CQSpec) -> Result<(), TsdbError> {
        let agg = aggregation_fn(&spec.aggregation);
        let sql = format!(
            "CREATE CONTINUOUS QUERY {n} AS SELECT {a}(*) INTO root.{t} FROM root.{s} EVERY {e}",
            n = spec.name,
            a = agg,
            t = spec.target,
            s = spec.source,
            e = spec.every,
        );
        self.exec(&sql).await
    }
}

/// IoTDB is hierarchical, so tags fold into the path as alternating
/// key/value segments — that keeps tag keys recoverable on read.
fn path_prefix(measurement: &str, tags: &Tags) -> String {
    let mut tags: Vec<(&String, &String)> = tags.iter().collect();
    tags.sort_by_key(|(k, _)| *k);
    let mut path = format!("root.{measurement}");
    for (k, v) in tags {
        path.push_str(&format!(".{k}.{v}"));
    }
    path
}

fn aggregation_fn(agg: &Aggregation) -> &'static str {
    match agg {
        Aggregation::Mean => "mean",
        Aggregation::Sum => "sum",
        Aggregation::Count => "count",
        Aggregation::Min => "min",
        Aggregation::Max => "max",
    }
}

fn iotdb_value(v: &serde_json::Value) -> Result<String, TsdbError> {
    match v {
        serde_json::Value::String(s) => Ok(format!("'{}'", s.replace('\'', "''"))),
        serde_json::Value::Number(n) => Ok(n.to_string()),
        serde_json::Value::Bool(b) => Ok(b.to_string()),
        _ => Err(TsdbError::WriteError(format!("unsupported field value: {v}"))),
    }
}

fn filter_matches(f: &TagFilter, p: &Point) -> bool {
    match &f.op {
        FilterOp::Eq => p.tags.get(&f.key) == Some(&f.value),
        FilterOp::Neq => p.tags.get(&f.key) != Some(&f.value),
        FilterOp::Regex => {
            p.tags.get(&f.key).is_some_and(|v| v.contains(f.value.trim_matches('*')))
        }
    }
}

fn parse_time(s: &str) -> Result<Timestamp, TsdbError> {
    if let Ok(ms) = s.parse::<i64>() {
        return DateTime::from_timestamp_millis(ms)
            .ok_or_else(|| TsdbError::QueryError(format!("bad timestamp {s:?}")));
    }
    DateTime::parse_from_rfc3339(s)
        .map(|dt| dt.with_timezone(&Utc))
        .map_err(|e| TsdbError::QueryError(format!("bad timestamp {s:?}: {e}")))
}

/// Parses the REST result: first element is the comma-joined column list,
/// remaining elements are comma-joined rows (`Time` first, then one column
/// per timeseries, `root.<measurement>.<tagk>.<tagv>...<field>`).
fn parse_result(result: &[serde_json::Value], measurement: &str) -> Result<TimeSeries, TsdbError> {
    let mut points = Vec::new();
    let Some(header) = result.first().and_then(|h| h.as_str()) else {
        return Ok(points);
    };
    let cols: Vec<&str> = header.split(',').collect();
    for row in result.iter().skip(1) {
        let row = row
            .as_str()
            .ok_or_else(|| TsdbError::QueryError("iotdb result row is not a string".into()))?;
        let vals: Vec<&str> = row.split(',').collect();
        let mut timestamp = None;
        for (col_idx, col) in cols.iter().enumerate() {
            if col.eq_ignore_ascii_case("time") {
                timestamp = Some(parse_time(vals.get(col_idx).copied().unwrap_or_default())?);
                continue;
            }
            let segs: Vec<&str> = col.strip_prefix("root.").unwrap_or(col).split('.').collect();
            // [measurement, (tagk, tagv)*, field] — always even length.
            if segs.len() < 2 || segs[0] != measurement {
                continue;
            }
            let mut tags = Tags::new();
            let mut i = 1;
            while i + 1 < segs.len() - 1 {
                tags.insert(segs[i].to_string(), segs[i + 1].to_string());
                i += 2;
            }
            let field = segs[segs.len() - 1];
            let value = vals.get(col_idx).copied().unwrap_or_default();
            let mut fields = Fields::new();
            if let Ok(n) = value.parse::<f64>() {
                fields.insert(field.to_string(), serde_json::json!(n));
            } else {
                fields.insert(field.to_string(), serde_json::json!(value));
            }
            if let Some(ts) = timestamp {
                points.push(Point {
                    measurement: measurement.to_string(),
                    tags,
                    fields,
                    timestamp: ts,
                });
            }
        }
    }
    Ok(points)
}

impl IoTDB {
    async fn exec(&self, sql: &str) -> Result<(), TsdbError> {
        let res = self
            .client
            .post(format!("{}/api/v1/query", self.base_url))
            .json(&serde_json::json!({ "sql": sql }))
            .send()
            .await
            .map_err(conn_err)?;
        if !res.status().is_success() {
            return Err(http_error(res, "exec").await);
        }
        let payload: serde_json::Value = res.json().await.map_err(query_err)?;
        if payload["code"].as_i64().unwrap_or(200) != 200 {
            return Err(TsdbError::QueryError(format!("iotdb exec failed: {payload}")));
        }
        Ok(())
    }
}

async fn http_error(res: reqwest::Response, op: &str) -> TsdbError {
    let status = res.status();
    let body = res.text().await.unwrap_or_default();
    TsdbError::QueryError(format!("iotdb {op} failed with {status}: {body}"))
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
        point.tags.insert("dc".into(), "east".into());
        point.fields.insert("value".into(), serde_json::json!(42.0));
        point
    }

    #[tokio::test]
    async fn write_sends_insert_with_tag_path() {
        let base = mock(vec![(
            "/api/v1/query",
            post(|req: Request<Body>| async move {
                let body = axum::body::to_bytes(req.into_body(), 4096).await.unwrap();
                let text = String::from_utf8(body.to_vec()).unwrap();
                assert!(text.contains(
                    "INSERT INTO root.cpu.dc.east.host.srv1(timestamp, value) VALUES (1767225600000, 42.0)"
                ));
                (StatusCode::OK, axum::Json(serde_json::json!({"code": 200, "message": "SUCCESS"})))
            }),
        )])
        .await;
        let db = IoTDB::new(base);
        db.write_point(make_point(1)).await.unwrap();
    }

    #[tokio::test]
    async fn query_sends_select_and_parses_result() {
        let base = mock(vec![(
            "/api/v1/query",
            post(|req: Request<Body>| async move {
                let body = axum::body::to_bytes(req.into_body(), 4096).await.unwrap();
                let text = String::from_utf8(body.to_vec()).unwrap();
                assert!(text.contains(
                    "SELECT * FROM root.cpu.** WHERE time >= '2026-01-01T00:00:00+00:00'"
                ));
                (
                    StatusCode::OK,
                    axum::Json(serde_json::json!({
                        "code": 200,
                        "result": [
                            "Time,root.cpu.host.srv1.value",
                            "2026-01-01T00:00:00Z,42.0"
                        ]
                    })),
                )
            }),
        )])
        .await;
        let db = IoTDB::new(base);
        let start = Utc.with_ymd_and_hms(2026, 1, 1, 0, 0, 0).single().unwrap();
        let end = Utc.with_ymd_and_hms(2026, 1, 31, 0, 0, 0).single().unwrap();
        let filter = TagFilter { key: "host".into(), value: "srv1".into(), op: FilterOp::Eq };
        let points = db.query_range("cpu", start, end, Some(&[filter])).await.unwrap();
        assert_eq!(points.len(), 1);
        assert_eq!(points[0].tags.get("host").unwrap(), "srv1");
        assert_eq!(points[0].fields["value"], 42.0);
        assert_eq!(points[0].timestamp.timestamp(), 1767225600);
    }

    #[tokio::test]
    async fn create_cq_sends_sql() {
        let base = mock(vec![(
            "/api/v1/query",
            post(|req: Request<Body>| async move {
                let body = axum::body::to_bytes(req.into_body(), 4096).await.unwrap();
                let text = String::from_utf8(body.to_vec()).unwrap();
                assert!(text.contains(
                    "CREATE CONTINUOUS QUERY cq1 AS SELECT mean(*) INTO root.downsampled FROM root.raw EVERY 1h"
                ));
                (StatusCode::OK, axum::Json(serde_json::json!({"code": 200, "message": "SUCCESS"})))
            }),
        )])
        .await;
        let db = IoTDB::new(base);
        db.create_continuous_query(CQSpec {
            name: "cq1".into(),
            source: "raw".into(),
            target: "downsampled".into(),
            every: "1h".into(),
            aggregation: Aggregation::Mean,
        })
        .await
        .unwrap();
    }
}
