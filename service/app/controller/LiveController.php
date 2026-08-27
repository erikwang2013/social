<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\controller;

use app\live\LiveCenter;
use app\model\LiveRoom;
use support\Request;
use Webman\Http\Response;

class LiveController
{
    private static ?LiveCenter $lc = null;

    private static function liveCenter(): LiveCenter
    {
        return self::$lc ??= new LiveCenter();
    }

    /** 开播：POST /api/v1/live/rooms {title} → {room_id, push_url, play_url} */
    public function create(Request $request): Response
    {
        $title = trim((string) $request->post('title', ''));
        if ($title === '' || mb_strlen($title) > 100) {
            return json(['code' => 400, 'message' => '标题需 1-100 字符', 'lang_key' => 'live.title_invalid'], 400);
        }
        $roomId = self::liveCenter()->create((int) $request->uid, $title);
        $room = LiveRoom::query()->find($roomId);
        return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => [
            'room_id' => $roomId,
            'push_url' => (string) $room->push_url,
            'play_url' => (string) $room->play_url,
        ]]);
    }

    /** 直播中列表：GET /api/v1/live/rooms?page= */
    public function rooms(Request $request): Response
    {
        $page = max(1, (int) $request->get('page', 1));
        $lc = self::liveCenter();
        $list = LiveRoom::query()->where('status', 1)->orderByDesc('started_at')->forPage($page, 20)->get()
            ->map(fn($room) => [
                'id' => (int) $room->id,
                'owner_id' => (int) $room->owner_id,
                'title' => (string) $room->title,
                'play_url' => (string) $room->play_url,
                'online_count' => $lc->onlineCount((int) $room->id),
                'mic_count' => $lc->micCount((int) $room->id),
            ])->values();
        return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => ['list' => $list]]);
    }

    /** 详情：GET /api/v1/live/rooms/{id} */
    public function detail(Request $request, string $id): Response
    {
        $room = LiveRoom::query()->find((int) $id);
        if ($room === null || (int) $room->status !== 1) {
            return json(['code' => 404, 'message' => '直播间不存在或已结束', 'lang_key' => 'live.room_not_found'], 404);
        }
        $lc = self::liveCenter();
        return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => [
            'id' => (int) $room->id,
            'owner_id' => (int) $room->owner_id,
            'title' => (string) $room->title,
            'status' => (int) $room->status,
            'push_url' => (int) $request->uid === (int) $room->owner_id ? (string) $room->push_url : '',
            'play_url' => (string) $room->play_url,
            'online_count' => $lc->onlineCount((int) $room->id),
            'mic_users' => $lc->micUsers((int) $room->id),
            'danmaku' => $lc->recentDanmaku((int) $room->id),
        ]]);
    }

    /** 关播（仅房主）：POST /api/v1/live/rooms/{id}/close */
    public function close(Request $request, string $id): Response
    {
        try {
            self::liveCenter()->close((int) $id, (int) $request->uid);
        } catch (\RuntimeException $e) {
            $isForbidden = $e->getMessage() === 'live_room_forbidden';
            return json([
                'code' => $isForbidden ? 403 : 404,
                'message' => $isForbidden ? '仅房主可关播' : '直播间不存在或已结束',
                'lang_key' => $isForbidden ? 'live.room_forbidden' : 'live.room_not_found',
            ], $isForbidden ? 403 : 404);
        }
        return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => ['room_id' => (int) $id]]);
    }

    /** 上麦：POST /api/v1/live/rooms/{id}/mic */
    public function micUp(Request $request, string $id): Response
    {
        try {
            self::liveCenter()->micUp((int) $id, (int) $request->uid);
        } catch (\RuntimeException $e) {
            $full = $e->getMessage() === 'live_mic_full';
            return json([
                'code' => $full ? 422 : 404,
                'message' => $full ? '麦位已满' : '直播间不存在或已结束',
                'lang_key' => $full ? 'live.mic_full' : 'live.room_not_found',
            ], $full ? 422 : 404);
        }
        return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => ['room_id' => (int) $id]]);
    }

    /** 下麦：DELETE /api/v1/live/rooms/{id}/mic */
    public function micDown(Request $request, string $id): Response
    {
        self::liveCenter()->micDown((int) $id, (int) $request->uid);
        return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => ['room_id' => (int) $id]]);
    }
}
