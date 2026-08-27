// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
//! 状态机 + 弹性三件套集成自检（InMemory store，无 IO）。
//! 语义对照 PHP LiveCenter/RoomCenter：键协议、广播帧、TOCTOU、关播清理。

use bee_live::live::{InMemoryLiveStore, LiveCenter, LiveConfig, LiveError};
use bee_live::protocol::{T_DANMAKU, T_LIVE_CLOSED, T_LIVE_JOIN, T_ROOM_ANSWER, T_ROOM_CLOSED, T_ROOM_ICE, T_ROOM_JOIN, T_ROOM_KICK_MIC, T_ROOM_OFFER};
use bee_live::voice::{InMemoryRoomStore, RoomCenter, RoomError};
use serde_json::{json, Value};
use std::sync::{Arc, Mutex};

type Frames = Arc<Mutex<Vec<(i64, String)>>>;

fn live_cfg() -> LiveConfig {
    LiveConfig {
        rtmp_host: "srs.test".into(),
        hls_host: "hls.test".into(),
        mic_limit: 2,
        danmaku_keep: 10,
    }
}

#[test]
fn live_full_flow() {
    let frames: Frames = Arc::new(Mutex::new(Vec::new()));
    let f2 = frames.clone();
    let lc = LiveCenter::new(
        InMemoryLiveStore::new(),
        live_cfg(),
        Box::new(move |uid, payload| f2.lock().unwrap().push((uid, payload))),
    );

    let id = lc.create(7, "测试直播");
    assert_eq!(lc.online_count(id), 1); // 房主 join 不广播
    assert_eq!(lc.list_rooms(0, 10).len(), 1);
    let room = lc.find_room(id).unwrap();
    assert_eq!(room.push_url, "rtmp://srs.test/live/0");
    assert_eq!(room.play_url, "http://hls.test/hls/0.m3u8");

    lc.join(id, 8, true).unwrap();
    assert_eq!(lc.online_count(id), 2);
    assert!(frames.lock().unwrap().iter().any(|(u, p)| *u == 8 && p.contains(T_LIVE_JOIN)));

    let msg = lc.send_danmaku(id, 8, "第一弹幕").unwrap();
    assert_eq!(msg["content"], "第一弹幕");
    let recent = lc.recent_danmaku(id, 10);
    assert_eq!(recent.len(), 1);
    assert_eq!(recent[0]["user_id"], 8);
    assert!(frames.lock().unwrap().iter().any(|(_, p)| p.contains(T_DANMAKU)));

    lc.mic_up(id, 7).unwrap();
    lc.mic_up(id, 8).unwrap();
    assert_eq!(lc.mic_count(id), 2);
    lc.join(id, 9, true).unwrap();
    assert_eq!(lc.mic_up(id, 9), Err(LiveError::MicFull));

    lc.mic_down(id, 8);
    assert_eq!(lc.mic_count(id), 1);

    lc.close(id, 7).unwrap();
    assert_eq!(lc.online_count(id), 0);
    assert_eq!(lc.find_room(id).unwrap().status, 0);
    assert!(frames.lock().unwrap().iter().any(|(_, p)| p.contains(T_LIVE_CLOSED)));

    // 关播后 join → 回滚 + RoomNotFound
    assert_eq!(lc.join(id, 10, true), Err(LiveError::RoomNotFound));
    assert_eq!(lc.online_count(id), 0);
}

#[test]
fn live_owner_only_close() {
    let lc = LiveCenter::new(InMemoryLiveStore::new(), live_cfg(), Box::new(|_, _| {}));
    let id = lc.create(7, "t");
    assert_eq!(lc.close(id, 8), Err(LiveError::Forbidden));
    assert_eq!(lc.find_room(id).unwrap().status, 1);
}

#[test]
fn live_disconnect_cleans_all_rooms() {
    let lc = LiveCenter::new(InMemoryLiveStore::new(), live_cfg(), Box::new(|_, _| {}));
    let a = lc.create(7, "a");
    let b = lc.create(7, "b");
    lc.join(a, 8, true).unwrap();
    lc.join(b, 8, true).unwrap();
    assert_eq!(lc.online_count(a), 2);
    lc.on_disconnect(8);
    assert_eq!(lc.online_count(a), 1);
    assert_eq!(lc.online_count(b), 1);
}

#[test]
fn voice_full_flow() {
    let frames: Frames = Arc::new(Mutex::new(Vec::new()));
    let f2 = frames.clone();
    let rc = RoomCenter::new(InMemoryRoomStore::new(), Box::new(move |uid, payload| f2.lock().unwrap().push((uid, payload))), Box::new(|_, _, _| json!({})));

    let id = rc.create(7, "语聊房");
    assert_eq!(rc.status(id), 1);
    assert_eq!(rc.list_rooms(0, 10).len(), 1);
    assert!(frames.lock().unwrap().iter().any(|(_, p)| p.contains(T_ROOM_JOIN)));

    rc.join(id, 8).unwrap();
    rc.up_mic(id, 8).unwrap();
    assert_eq!(rc.mic_count(id), 2); // 房主 role=1 + 成员 role=1

    rc.kick_mic(id, 7, 8).unwrap();
    assert_eq!(rc.mic_count(id), 1);
    assert!(frames.lock().unwrap().iter().any(|(u, p)| *u == 8 && p.contains(T_ROOM_KICK_MIC)));

    // 房主离开 → 自动关房
    rc.leave(id, 7);
    assert_eq!(rc.status(id), 0);
    assert!(frames.lock().unwrap().iter().any(|(_, p)| p.contains(T_ROOM_CLOSED)));
    assert_eq!(rc.mic_count(id), 0);

    // 关闭后 join → 回滚
    assert_eq!(rc.join(id, 9), Err(RoomError::RoomNotFound));
}

#[test]
fn voice_mic_full_and_guest_up_mic() {
    let rc = RoomCenter::new(InMemoryRoomStore::new(), Box::new(|_, _| {}), Box::new(|_, _, _| json!({})));
    let id = rc.create(7, "房");
    for uid in 8..=14 {
        rc.join(id, uid).unwrap();
    }
    for uid in 7..=14 {
        rc.up_mic(id, uid).unwrap(); // 7 房主 + 7 成员 = 8 麦位
    }
    rc.join(id, 15).unwrap();
    assert_eq!(rc.up_mic(id, 15), Err(RoomError::MicFull));

    // 非成员上麦：PHP 静默返回（无副作用）
    assert_eq!(rc.up_mic(id, 99), Ok(()));
}

#[test]
fn sfu_relay_maps_method_and_echoes() {
    let frames: Frames = Arc::new(Mutex::new(Vec::new()));
    let calls: Arc<Mutex<Vec<(i64, String, Value)>>> = Arc::new(Mutex::new(Vec::new()));
    let c2 = calls.clone();
    let f2 = frames.clone();
    let rc = RoomCenter::new(
        InMemoryRoomStore::new(),
        Box::new(move |uid, payload| f2.lock().unwrap().push((uid, payload))),
        Box::new(move |room_id, method, body| {
            c2.lock().unwrap().push((room_id, method, body));
            json!({"sdp": "mock-answer", "room_id": 999}) // room_id 应被左侧覆盖
        }),
    );
    rc.sfu_relay(5, 8, T_ROOM_OFFER, json!({"sdp": "offer-1"}));
    rc.sfu_relay(5, 8, T_ROOM_ANSWER, json!({"sdp": "answer-1"}));
    rc.sfu_relay(5, 8, T_ROOM_ICE, json!({"candidate": "c-1"}));
    rc.sfu_relay(5, 8, "room_unknown", json!({"x": 1}));

    let methods: Vec<String> = calls.lock().unwrap().iter().map(|(_, m, _)| m.clone()).collect();
    assert_eq!(methods, ["produce", "connect", "transport", "transport"]);
    for (room_id, _, _) in calls.lock().unwrap().iter() {
        assert_eq!(*room_id, 5);
    }
    let msgs = frames.lock().unwrap().clone();
    assert_eq!(msgs.len(), 4);
    let types = [T_ROOM_OFFER, T_ROOM_ANSWER, T_ROOM_ICE, "room_unknown"];
    for (i, (uid, p)) in msgs.iter().enumerate() {
        assert_eq!(*uid, 8);
        let v: Value = serde_json::from_str(p).unwrap();
        assert_eq!(v["type"], types[i]);
        assert_eq!(v["data"]["room_id"], 5); // SFU 返回的 room_id=999 被覆盖（PHP `+` 语义）
        assert_eq!(v["data"]["sdp"], "mock-answer");
    }
}
