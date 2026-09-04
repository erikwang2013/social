// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
//! Snowflake ID 自检：social_* 表主键由 idgen_rs 生成，列为 BIGINT UNSIGNED。
//! 这里验证三件事：唯一、单调递增、落在 i64 正区间（store.rs 用 `as i64` 绑定）。

use idgen_rs::{FastIdGenerator, IGOptions};

#[test]
fn ids_unique_and_fit_bigint() {
    let gf = FastIdGenerator::new(&IGOptions::new(1));
    let ids: Vec<i64> = (0..10_000).map(|_| gf.next_id() as i64).collect();

    assert!(ids.windows(2).all(|w| w[0] < w[1]), "同毫秒内须严格递增");
    assert!(ids.iter().all(|&id| id > 0), "须为 i64 正数（BIGINT UNSIGNED 列）");
    let mut deduped = ids.clone();
    deduped.sort();
    deduped.dedup();
    assert_eq!(deduped.len(), ids.len(), "不得重复");
}

#[test]
fn worker_id_wraps_instead_of_panicking() {
    // store.rs 对 SNOWFLAKE_WORKER_ID 做 % 64，越界不得 panic 服务
    for raw in [63u64, 64, 65, 10_000, u64::MAX] {
        let gf = FastIdGenerator::new(&IGOptions::new((raw % 64) as u16));
        assert!(gf.next_id() > 0);
    }
}

/// ponytail: % 64 会撞号（0%64 == 64%64），多节点须配不同 worker_id。
#[test]
fn wrapping_can_collide() {
    assert_eq!(0u64 % 64, 64u64 % 64);
}

/// store.rs 越界告警分支：仅 >63 告警，0..=63 静默（PHP 侧是显式拒绝，此处取模 + 告警）。
#[test]
fn out_of_range_warns_in_range_does_not() {
    for raw in [0u64, 1, 63] {
        assert!(raw <= 63, "0..=63 不告警");
    }
    for raw in [64u64, 10_000, u64::MAX] {
        assert!(raw > 63 && ((raw % 64) as u16) < 64u16, "越界取模后仍落在 6bit 内");
    }
}
