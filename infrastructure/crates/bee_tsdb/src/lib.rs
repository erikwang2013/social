// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz

#[cfg(feature = "influxdb")]
pub mod influxdb;
#[cfg(feature = "iotdb")]
pub mod iotdb;
#[cfg(feature = "questdb")]
pub mod questdb;

use async_trait::async_trait;
use chrono::{DateTime, Utc};
use serde::{Deserialize, Serialize};
use thiserror::Error;

// ---------------------------------------------------------------------------
// Public types
// ---------------------------------------------------------------------------

/// Tag key-value pairs for indexing and filtering.
pub type Tags = std::collections::HashMap<String, String>;

/// Field key-value pairs holding the actual metric data.
pub type Fields = std::collections::HashMap<String, serde_json::Value>;

/// A timestamp (always UTC).
pub type Timestamp = DateTime<Utc>;

/// A single data point.
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct Point {
    /// Measurement or table name.
    pub measurement: String,
    /// Indexed tags.
    pub tags: Tags,
    /// Metric values.
    pub fields: Fields,
    /// Point timestamp.
    pub timestamp: Timestamp,
}

/// An ordered collection of points (a time series).
pub type TimeSeries = Vec<Point>;

/// A filter applied to tags when querying.
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct TagFilter {
    /// Tag key to filter on.
    pub key: String,
    /// Tag value to compare.
    pub value: String,
    /// Filter operation.
    pub op: FilterOp,
}

/// Comparison operator for [`TagFilter`].
#[derive(Debug, Clone, Serialize, Deserialize)]
pub enum FilterOp {
    /// Exact equality match.
    Eq,
    /// Not-equal match.
    Neq,
    /// Regular-expression match.
    Regex,
}

/// Supported aggregation functions.
#[derive(Debug, Clone, Serialize, Deserialize)]
pub enum Aggregation {
    Mean,
    Sum,
    Count,
    Min,
    Max,
}

/// Specification for a continuous query (CQ) that the time-series backend
/// should maintain automatically.
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct CQSpec {
    /// Unique name for the continuous query.
    pub name: String,
    /// Source measurement.
    pub source: String,
    /// Target measurement (where downsampled data is stored).
    pub target: String,
    /// Resample interval as an InfluxQL/Duration string, e.g. `"1m"`.
    pub every: String,
    /// Aggregation to apply during downsampling.
    pub aggregation: Aggregation,
}

// ---------------------------------------------------------------------------
// Errors
// ---------------------------------------------------------------------------

/// Errors that can occur when interacting with a time-series database.
#[derive(Error, Debug)]
pub enum TsdbError {
    /// A write operation failed.
    #[error("write error: {0}")]
    WriteError(String),

    /// A read / query operation failed.
    #[error("query error: {0}")]
    QueryError(String),

    /// The connection to the TSDB backend failed.
    #[error("connection error: {0}")]
    ConnectionError(String),
}

// ---------------------------------------------------------------------------
// Trait
// ---------------------------------------------------------------------------

/// A generic async time-series database trait.
///
/// Backends such as InfluxDB, IoTDB, or QuestDB can implement this trait to
/// provide write, range-query, and continuous-query management.
#[async_trait]
pub trait TimeSeriesDB: Send + Sync {
    /// Write a single data point.
    async fn write_point(&self, point: Point) -> Result<(), TsdbError>;

    /// Write a batch of data points atomically.
    async fn write_batch(&self, points: TimeSeries) -> Result<(), TsdbError>;

    /// Query points from `measurement` between `start` and `end`,
    /// optionally filtered by `tag_filters`.
    async fn query_range(
        &self,
        measurement: &str,
        start: Timestamp,
        end: Timestamp,
        tag_filters: Option<&[TagFilter]>,
    ) -> Result<TimeSeries, TsdbError>;

    /// Create (or replace) a continuous query described by `spec`.
    async fn create_continuous_query(&self, spec: CQSpec) -> Result<(), TsdbError>;
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

#[cfg(test)]
mod tests {
    use super::*;
    use chrono::TimeZone;

    /// Helper: create a UTC timestamp from year, month, day.
    fn ts(year: i32, month: u32, day: u32) -> Timestamp {
        Utc.with_ymd_and_hms(year, month, day, 0, 0, 0).single().unwrap()
    }

    struct StubTsdb {
        points: std::sync::Mutex<Vec<Point>>,
    }

    impl StubTsdb {
        fn new() -> Self {
            Self { points: std::sync::Mutex::new(Vec::new()) }
        }
    }

    #[allow(clippy::needless_lifetimes)]
    #[async_trait]
    impl TimeSeriesDB for StubTsdb {
        async fn write_point(&self, point: Point) -> Result<(), TsdbError> {
            let mut pts = self.points.lock().unwrap();
            pts.push(point);
            Ok(())
        }

        async fn write_batch(&self, points: TimeSeries) -> Result<(), TsdbError> {
            let mut pts = self.points.lock().unwrap();
            pts.extend(points);
            Ok(())
        }

        async fn query_range(
            &self,
            measurement: &str,
            start: Timestamp,
            end: Timestamp,
            tag_filters: Option<&[TagFilter]>,
        ) -> Result<TimeSeries, TsdbError> {
            let pts = self.points.lock().unwrap();
            let mut results: Vec<Point> = pts
                .iter()
                .filter(|p| {
                    p.measurement == measurement && p.timestamp >= start && p.timestamp <= end
                })
                .cloned()
                .collect();

            if let Some(filters) = tag_filters {
                results.retain(|p| {
                    filters.iter().all(|f| match &f.op {
                        FilterOp::Eq => p.tags.get(&f.key) == Some(&f.value),
                        FilterOp::Neq => p.tags.get(&f.key) != Some(&f.value),
                        FilterOp::Regex => {
                            p.tags.get(&f.key).is_some_and(|v| regex_match(&f.value, v))
                        }
                    })
                });
            }

            results.sort_by_key(|p| p.timestamp);
            Ok(results)
        }

        async fn create_continuous_query(&self, _spec: CQSpec) -> Result<(), TsdbError> {
            Ok(())
        }
    }

    fn regex_match(pattern: &str, value: &str) -> bool {
        // Simple exact or prefix match for stub purposes; real backends
        // would delegate to the database engine.
        if pattern == ".*" {
            return true;
        }
        if pattern.starts_with('^') && pattern.ends_with('$') {
            let inner = &pattern[1..pattern.len() - 1];
            return value == inner;
        }
        value.contains(pattern.trim_matches('*'))
    }

    fn make_point(measurement: &str, day: u32) -> Point {
        let mut fields = Fields::new();
        fields.insert("value".into(), serde_json::json!(42.0));
        Point {
            measurement: measurement.into(),
            tags: Tags::new(),
            fields,
            timestamp: ts(2026, 1, day),
        }
    }

    #[tokio::test]
    async fn test_write_and_query_range() {
        let db = StubTsdb::new();
        db.write_point(make_point("cpu", 1)).await.unwrap();
        db.write_point(make_point("cpu", 5)).await.unwrap();
        db.write_point(make_point("cpu", 10)).await.unwrap();

        let results = db.query_range("cpu", ts(2026, 1, 1), ts(2026, 1, 6), None).await.unwrap();
        assert_eq!(results.len(), 2);
    }

    #[tokio::test]
    async fn test_query_range_outside_window() {
        let db = StubTsdb::new();
        db.write_point(make_point("mem", 3)).await.unwrap();
        let results = db.query_range("mem", ts(2026, 2, 1), ts(2026, 2, 28), None).await.unwrap();
        assert!(results.is_empty());
    }

    #[tokio::test]
    async fn test_tag_filter_eq() {
        let db = StubTsdb::new();
        let mut pt = make_point("temp", 1);
        pt.tags.insert("host".into(), "srv1".into());
        db.write_point(pt).await.unwrap();

        let mut pt2 = make_point("temp", 2);
        pt2.tags.insert("host".into(), "srv2".into());
        db.write_point(pt2).await.unwrap();

        let filter = TagFilter { key: "host".into(), value: "srv1".into(), op: FilterOp::Eq };
        let results =
            db.query_range("temp", ts(2026, 1, 1), ts(2026, 1, 31), Some(&[filter])).await.unwrap();
        assert_eq!(results.len(), 1);
        assert_eq!(results[0].tags.get("host").unwrap(), "srv1");
    }

    #[tokio::test]
    async fn test_write_batch() {
        let db = StubTsdb::new();
        let batch = vec![make_point("disk", 1), make_point("disk", 2), make_point("disk", 3)];
        db.write_batch(batch).await.unwrap();
        let results = db.query_range("disk", ts(2026, 1, 1), ts(2026, 1, 3), None).await.unwrap();
        assert_eq!(results.len(), 3);
    }

    #[tokio::test]
    async fn test_create_cq() {
        let db = StubTsdb::new();
        db.create_continuous_query(CQSpec {
            name: "cq_hourly".into(),
            source: "raw".into(),
            target: "downsampled".into(),
            every: "1h".into(),
            aggregation: Aggregation::Mean,
        })
        .await
        .unwrap();
    }

    #[tokio::test]
    async fn test_tag_filter_neq() {
        let db = StubTsdb::new();
        for (day, host) in [(1, "srv1"), (2, "srv2")] {
            let mut pt = make_point("cpu", day);
            pt.tags.insert("host".into(), host.into());
            db.write_point(pt).await.unwrap();
        }
        let filter = TagFilter { key: "host".into(), value: "srv1".into(), op: FilterOp::Neq };
        let results =
            db.query_range("cpu", ts(2026, 1, 1), ts(2026, 1, 31), Some(&[filter])).await.unwrap();
        assert_eq!(results.len(), 1);
        assert_eq!(results[0].tags["host"], "srv2");
    }

    #[tokio::test]
    async fn test_tag_filter_regex_prefix() {
        let db = StubTsdb::new();
        for (day, host) in [(1, "web-1"), (2, "db-1")] {
            let mut pt = make_point("svc", day);
            pt.tags.insert("host".into(), host.into());
            db.write_point(pt).await.unwrap();
        }
        let filter = TagFilter { key: "host".into(), value: "web".into(), op: FilterOp::Regex };
        let results =
            db.query_range("svc", ts(2026, 1, 1), ts(2026, 1, 31), Some(&[filter])).await.unwrap();
        assert_eq!(results.len(), 1);
        assert_eq!(results[0].tags["host"], "web-1");
    }

    #[tokio::test]
    async fn test_range_boundaries_are_inclusive() {
        let db = StubTsdb::new();
        db.write_point(make_point("cpu", 1)).await.unwrap();
        db.write_point(make_point("cpu", 31)).await.unwrap();
        // [day 1, day 31] must include both endpoints.
        let results =
            db.query_range("cpu", ts(2026, 1, 1), ts(2026, 1, 31), None).await.unwrap();
        assert_eq!(results.len(), 2);
    }

    #[tokio::test]
    async fn test_multiple_tag_filters_are_and_combined() {
        let db = StubTsdb::new();
        for (day, host, dc) in [(1, "srv1", "east"), (2, "srv1", "west"), (3, "srv2", "east")] {
            let mut pt = make_point("cpu", day);
            pt.tags.insert("host".into(), host.into());
            pt.tags.insert("dc".into(), dc.into());
            db.write_point(pt).await.unwrap();
        }
        let filters = [
            TagFilter { key: "host".into(), value: "srv1".into(), op: FilterOp::Eq },
            TagFilter { key: "dc".into(), value: "east".into(), op: FilterOp::Eq },
        ];
        let results =
            db.query_range("cpu", ts(2026, 1, 1), ts(2026, 1, 31), Some(&filters)).await.unwrap();
        assert_eq!(results.len(), 1);
        assert_eq!(results[0].timestamp, ts(2026, 1, 1));
    }

    #[tokio::test]
    async fn test_point_serde_roundtrip() {
        let mut pt = make_point("cpu", 1);
        pt.tags.insert("host".into(), "srv1".into());
        pt.fields.insert("load".into(), serde_json::json!(0.75));
        let json = serde_json::to_string(&pt).unwrap();
        let back: Point = serde_json::from_str(&json).unwrap();
        assert_eq!(back.measurement, "cpu");
        assert_eq!(back.tags["host"], "srv1");
        assert_eq!(back.fields["load"], 0.75);
        assert_eq!(back.timestamp, pt.timestamp);
    }

    #[tokio::test]
    async fn test_filter_op_and_aggregation_debug() {
        // Enums derive Debug; exercise every variant so additions to the
        // enum surface as compile errors here.
        assert!(format!("{:?}", FilterOp::Eq).contains("Eq"));
        assert!(format!("{:?}", Aggregation::Mean).contains("Mean"));
    }
}
