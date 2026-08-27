// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
//! 广播帧协议，与 PHP `app\ws\Envelope::encode` 逐字节兼容：
//! `{"type": "...", "data": {...}}`，无 seq 时省略（JSON 无空格、不转义斜杠）。

use serde::Serialize;

pub const T_ROOM_JOIN: &str = "room_join";
pub const T_ROOM_LEAVE: &str = "room_leave";
pub const T_ROOM_UP_MIC: &str = "room_up_mic";
pub const T_ROOM_DOWN_MIC: &str = "room_down_mic";
pub const T_ROOM_OFFER: &str = "room_offer";
pub const T_ROOM_ANSWER: &str = "room_answer";
pub const T_ROOM_ICE: &str = "room_ice";
pub const T_ROOM_KICK_MIC: &str = "room_kick_mic";
pub const T_ROOM_CLOSED: &str = "room_closed";
pub const T_LIVE_JOIN: &str = "live_join";
pub const T_LIVE_LEAVE: &str = "live_leave";
pub const T_DANMAKU: &str = "danmaku";
pub const T_LIVE_MIC_UP: &str = "live_mic_up";
pub const T_LIVE_MIC_DOWN: &str = "live_mic_down";
pub const T_LIVE_CLOSED: &str = "live_closed";

#[derive(Debug, Clone, Serialize)]
pub struct Envelope {
    pub r#type: String,
    pub data: serde_json::Value,
}

impl Envelope {
    pub fn frame(ty: &str, data: serde_json::Value) -> Self {
        Self {
            r#type: ty.to_string(),
            data,
        }
    }

    pub fn encode(&self) -> String {
        serde_json::to_string(self).expect("envelope serialization cannot fail")
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn encode_matches_php_contract() {
        let e = Envelope::frame(T_LIVE_JOIN, serde_json::json!({"room_id": 1, "user_id": 7}));
        // PHP: json_encode(['type'=>'live_join','data'=>['room_id'=>1,'user_id'=>7]], UNESCAPED_SLASHES|UNESCAPED_UNICODE)
        assert_eq!(e.encode(), r#"{"type":"live_join","data":{"room_id":1,"user_id":7}}"#);
        assert!(!e.encode().contains(' '));
    }
}
