<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
namespace app\controller;

use support\Request;
use app\model\Conversation;
use app\model\ConversationMember;
use app\model\DeviceToken;
use app\model\Message;
use app\model\MessageRead;
use hg\apidoc\annotation as Apidoc;

/**
 * @Apidoc\Title("IM 会话与消息")
 */
class ImController
{
    /**
     * @Apidoc\Title("会话列表")
     * @Apidoc\Url("/api/v1/im/conversations")
     * @Apidoc\Method("GET")
     * @Apidoc\Header("Authorization", type="string", require=true, desc="Bearer access_token")
     * @Apidoc\Param("page", type="int", require=false, desc="页码，默认1")
     * @Apidoc\Param("page_size", type="int", require=false, desc="每页条数，默认20，最大50")
     * @Apidoc\Returned(ref="Response")
     */
    public function conversations(Request $request)
    {
        $uid = $request->uid;
        $page = max(1, (int) $request->get('page', 1));
        $pageSize = min(50, max(1, (int) $request->get('page_size', 20)));

        $query = Conversation::whereHas('members', fn($q) => $q->where('user_id', $uid)->where('status', 1))
            ->where('status', 1)->orderByDesc('updated_at');
        $paginator = $query->paginate($pageSize, ['*'], 'page', $page);

        $items = $paginator->items();
        $ids = array_map(fn($c) => (int) $c->id, $items);
        $lastMessages = $lastReadMap = $unreadMap = [];
        if ($ids !== []) {
            // 每会话最后一条：先取 MAX(id)，再一次性取回消息体
            $lastIds = Message::whereIn('conversation_id', $ids)
                ->groupBy('conversation_id')->selectRaw('MAX(id) AS id')->pluck('id')->all();
            $lastMessages = Message::whereIn('id', $lastIds)->get()->keyBy('conversation_id')->all();
            $lastReadMap = MessageRead::where('user_id', $uid)->whereIn('conversation_id', $ids)
                ->pluck('last_read_id', 'conversation_id')->all();
            // 未读数一次性按会话聚合（阈值取各自 last_read_id）
            $unreadMap = Message::query()
                ->selectRaw('conversation_id, COUNT(*) AS cnt')
                ->whereIn('conversation_id', $ids)
                ->where(function ($q) use ($ids, $lastReadMap) {
                    foreach ($ids as $cid) {
                        $q->orWhere(fn($qq) => $qq->where('conversation_id', $cid)->where('id', '>', (int) ($lastReadMap[$cid] ?? 0)));
                    }
                })
                ->groupBy('conversation_id')->pluck('cnt', 'conversation_id')->all();
        }

        $list = [];
        foreach ($items as $conv) {
            $last = $lastMessages[(int) $conv->id] ?? null;
            $list[] = [
                'id' => $conv->id,
                'type' => $conv->type,
                'name' => $conv->name,
                'created_at' => $conv->created_at,
                'updated_at' => $conv->updated_at,
                'unread' => (int) ($unreadMap[(int) $conv->id] ?? 0),
                'last_message' => $last ? [
                    'id' => $last->id,
                    'type' => $last->type,
                    'content' => $last->content,
                    'image_url' => $last->image_url,
                    'recall_status' => $last->recall_status,
                    'sender_id' => $last->sender_id,
                    'created_at' => $last->created_at,
                ] : null,
            ];
        }
        return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => [
            'list' => $list,
            'total' => $paginator->total(),
            'page' => $page,
            'page_size' => $pageSize,
        ]]);
    }

    /**
     * @Apidoc\Title("创建会话")
     * @Apidoc\Url("/api/v1/im/conversations")
     * @Apidoc\Method("POST")
     * @Apidoc\Header("Authorization", type="string", require=true, desc="Bearer access_token")
     * @Apidoc\Param("type", type="int", require=true, desc="1私聊 2群聊")
     * @Apidoc\Param("name", type="string", require=false, desc="群名（群聊必填）")
     * @Apidoc\Param("member_ids", type="array", require=false, desc="成员ID（私聊传对方一人）")
     * @Apidoc\Returned(ref="Response")
     */
    public function create(Request $request)
    {
        $uid = $request->uid;
        $type = (int) $request->post('type');

        $ts = date('Y-m-d H:i:s');
        if ($type === 1) {
            $otherId = (int) ($request->post('member_ids')[0] ?? 0);
            if ($otherId <= 0 || $otherId === $uid) {
                return json(['code' => 400, 'message' => '私聊需指定对方用户', 'lang_key' => 'im.member_required'], 400);
            }
            $myCids = ConversationMember::where('user_id', $uid)->where('status', 1)->pluck('conversation_id');
            $otherCids = ConversationMember::where('user_id', $otherId)->where('status', 1)->pluck('conversation_id');
            foreach ($myCids->intersect($otherCids) as $cid) {
                $conv = Conversation::where('id', $cid)->where('type', 1)->where('status', 1)->first();
                if ($conv && ConversationMember::where('conversation_id', $cid)->where('status', 1)->count() === 2) {
                    return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => $conv]);
                }
            }
            $conv = Conversation::create(['type' => 1, 'name' => '', 'owner_id' => 0, 'status' => 1]);
            ConversationMember::insert([
                ['conversation_id' => $conv->id, 'user_id' => $uid, 'role' => 0, 'status' => 1, 'created_at' => $ts, 'updated_at' => $ts],
                ['conversation_id' => $conv->id, 'user_id' => $otherId, 'role' => 0, 'status' => 1, 'created_at' => $ts, 'updated_at' => $ts],
            ]);
            return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => $conv]);
        }

        if ($type === 2) {
            $memberIds = array_values(array_unique(array_map('intval', (array) $request->post('member_ids', []))));
            $memberIds = array_values(array_diff($memberIds, [$uid]));
            if (count($memberIds) > 500) {
                return json(['code' => 400, 'message' => '群成员最多500人', 'lang_key' => 'im.member_limit'], 400);
            }
            $name = trim((string) $request->post('name'));
            if ($name === '' || mb_strlen($name) > 100) {
                return json(['code' => 400, 'message' => '群名需 1-100 字符', 'lang_key' => 'im.name_length'], 400);
            }
            $conv = Conversation::create(['type' => 2, 'name' => $name, 'owner_id' => $uid, 'status' => 1]);
            $rows = [['conversation_id' => $conv->id, 'user_id' => $uid, 'role' => 1, 'status' => 1, 'created_at' => $ts, 'updated_at' => $ts]];
            foreach ($memberIds as $mid) {
                $rows[] = ['conversation_id' => $conv->id, 'user_id' => $mid, 'role' => 0, 'status' => 1, 'created_at' => $ts, 'updated_at' => $ts];
            }
            ConversationMember::insert($rows);
            return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => $conv]);
        }

        return json(['code' => 400, 'message' => 'type 取值 1/2', 'lang_key' => 'im.type_invalid'], 400);
    }

    /**
     * @Apidoc\Title("消息历史")
     * @Apidoc\Url("/api/v1/im/conversations/{id}/messages")
     * @Apidoc\Method("GET")
     * @Apidoc\Header("Authorization", type="string", require=true, desc="Bearer access_token")
     * @Apidoc\Param("id", type="int", require=true, desc="会话ID", path=true)
     * @Apidoc\Param("cursor", type="int", require=false, desc="消息ID游标，默认0（最新起）")
     * @Apidoc\Param("page_size", type="int", require=false, desc="每页条数，默认20，最大50")
     * @Apidoc\Returned(ref="Response")
     */
    public function messages(Request $request, string $id)
    {
        $uid = $request->uid;
        $cid = (int) $id;
        $isMember = ConversationMember::where('conversation_id', $cid)->where('user_id', $uid)->where('status', 1)->exists();
        if (!$isMember) {
            return json(['code' => 404, 'message' => '会话不存在', 'lang_key' => 'im.conversation_not_found'], 404);
        }
        $cursor = max(0, (int) $request->get('cursor', 0));
        $limit = min(50, max(1, (int) $request->get('page_size', 20)));
        // cursor=0 表示最新一页：倒序取 n+1，回正后 next_cursor 为页内最旧 id（下一页取 id < cursor）
        $list = Message::where('conversation_id', $cid)
            ->when($cursor > 0, fn($q) => $q->where('id', '<', $cursor))
            ->orderByDesc('id')->limit($limit + 1)->get();
        $hasMore = $list->count() > $limit;
        $list = $list->take($limit)->reverse()->values();
        return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => [
            'list' => array_values($list->all()),
            'has_more' => $hasMore,
            'next_cursor' => $hasMore && $list->isNotEmpty() ? (string) $list->first()->id : null,
        ]]);
    }

    /**
     * @Apidoc\Title("上报推送令牌")
     * @Apidoc\Url("/api/v1/im/device-token")
     * @Apidoc\Method("POST")
     * @Apidoc\Header("Authorization", type="string", require=true, desc="Bearer access_token")
     * @Apidoc\Param("platform", type="string", require=true, desc="平台 android/ios/harmonyos")
     * @Apidoc\Param("token", type="string", require=true, desc="推送令牌")
     * @Apidoc\Returned(ref="Response")
     */
    public function deviceToken(Request $request)
    {
        $platform = trim((string) $request->post('platform'));
        $token = trim((string) $request->post('token'));
        if ($platform === '' || mb_strlen($platform) > 20 || $token === '' || strlen($token) > 255) {
            return json(['code' => 400, 'message' => 'platform/token 不合法', 'lang_key' => 'im.token_invalid'], 400);
        }
        DeviceToken::updateOrCreate(
            ['user_id' => $request->uid, 'platform' => $platform],
            ['token' => $token]
        );
        return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => ['platform' => $platform]]);
    }
}
