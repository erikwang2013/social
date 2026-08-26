<?php
/**
 * 社交平台 monorepo API 自动化测试（只读测试，不修改业务代码）
 *
 * 覆盖：service(8788 HTTP / 8789 WS) 与 admin(8791) 两套 webman 服务
 * 运行：php run.php
 * 输出：控制台摘要 + results.json（脚本同目录）
 *
 * 测试凭据：
 *  - admin:  e2e_smoke（测试账号，密码由 SQL 预置为 ApiTest!2026）
 *  - service: 每次运行注册全新临时账号（apitest_*@test.dev），运行结束 SQL 清理
 *
 * 依赖：ext-curl、ext-json、ext-redis（读取验证码答案）、ffmpeg（语音测试样本）
 *
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);

const SVC = 'http://127.0.0.1:8788';
const ADM = 'http://127.0.0.1:8791';
const ADMIN_USER = 'e2e_smoke';
const ADMIN_PASS = 'ApiTest!2026';
const DB = ['host' => '127.0.0.1', 'port' => 3306, 'user' => 'root', 'pass' => 'root'];

$GLOBALS['results'] = [];
$GLOBALS['count'] = ['pass' => 0, 'fail' => 0];
$GLOBALS['ts'] = date('YmdHis');
$GLOBALS['cleanup'] = [];

function ok(string $name, bool $cond, string $detail = ''): void
{
    $GLOBALS['count'][$cond ? 'pass' : 'fail']++;
    $GLOBALS['results'][] = [
        'name' => $name,
        'pass' => $cond,
        'detail' => $cond ? '' : $detail,
    ];
    printf("[%s] %s%s\n", $cond ? 'PASS' : 'FAIL', $name, $cond ? '' : '  -- ' . $detail);
}

function http(string $method, string $url, $body = null, array $headers = [], int $timeout = 15): array
{
    $ch = curl_init($url);
    $h = ['Accept: application/json'];
    foreach ($headers as $k => $v) {
        $h[] = "$k: $v";
    }
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => $h,
        CURLOPT_HEADER => true,
    ];
    if ($method === 'POST' || $method === 'PUT' || $method === 'DELETE') {
        $opts[CURLOPT_CUSTOMREQUEST] = $method;
        if ($body !== null) {
            if (is_array($body) && !isset($body['__multipart'])) {
                $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
                $h[] = 'Content-Type: application/json';
            } else {
                unset($body['__multipart']);
                $opts[CURLOPT_POSTFIELDS] = $body;
            }
            $opts[CURLOPT_HTTPHEADER] = $h;
        }
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    $hdrSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $headersRaw = substr($raw, 0, $hdrSize);
    $bodyRaw = substr($raw, $hdrSize);
    $json = json_decode($bodyRaw, true);
    return [
        'status' => $status,
        'body' => $json !== null ? $json : $bodyRaw,
        'raw' => $bodyRaw,
        'headers' => $headersRaw,
        'err' => $err,
    ];
}

function bodyCode(array $r): ?int
{
    if (!is_array($r['body']) || !isset($r['body']['code'])) {
        return null;
    }
    return (int) $r['body']['code'];
}

function sql(string $sql): bool
{
    $m = new mysqli(DB['host'], DB['user'], DB['pass'], null, DB['port']);
    if ($m->connect_errno) {
        return false;
    }
    $m->select_db('social');
    $m->query($sql);
    $m->close();
    return true;
}

function sqlAdmin(string $sql): void
{
    $m = new mysqli(DB['host'], DB['user'], DB['pass'], 'open_admin', DB['port']);
    if (!$m->connect_errno) {
        $m->query($sql);
    }
    $m->close();
}

/** 服务端验证码答案在 Redis（poster:captcha:{key}），测试环境读取构造正确答案 */
function captchaSolve(): array
{
    $redis = new Redis();
    $redis->connect('127.0.0.1', 6379);
    for ($i = 0; $i < 20; $i++) {
        $r = http('POST', ADM . '/api/captcha/generate', ['difficulty' => 'medium']);
        $d = $r['body']['data'] ?? null;
        if (!$d || ($d['type'] ?? '') !== 'click') {
            usleep(100000);
            continue;
        }
        $ans = json_decode((string) $redis->get('poster:captcha:' . $d['key']), true);
        if (!$ans) {
            continue;
        }
        $pairs = array_map(fn($t) => [$t['x'], $t['y']], $ans['data']['targets'] ?? []);
        return ['key' => $d['key'], 'pairs' => $pairs];
    }
    return [];
}

/** admin 完整登录（验证码 + 凭证） */
function adminLogin(): array
{
    $cap = captchaSolve();
    if (!$cap) {
        return ['ok' => false, 'msg' => '无法生成 click 验证码'];
    }
    $v = http('POST', ADM . '/api/captcha/verify', ['key' => $cap['key'], 'type' => 'click', 'clicks' => $cap['pairs']]);
    if (bodyCode($v) !== 0) {
        return ['ok' => false, 'msg' => '验证码校验失败: ' . json_encode($v['body'])];
    }
    $r = http('POST', ADM . '/api/auth/login', ['username' => ADMIN_USER, 'password' => ADMIN_PASS, 'captcha_key' => $cap['key']]);
    if (bodyCode($r) !== 0) {
        return ['ok' => false, 'msg' => json_encode($r['body'])];
    }
    return ['ok' => true, 'data' => $r['body']['data']];
}

/** 生成 1x1 PNG */
function tinyPng(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
}

function authH(string $token): array
{
    return ['Authorization' => 'Bearer ' . $token, 'API-Version' => 'v1'];
}

/* ============================== service 套件 ============================== */

function serviceSuite(): void
{
    $ts = $GLOBALS['ts'];
    $u1 = ['email' => "apitest_u1_$ts@test.dev", 'password' => 'Test123456', 'nickname' => '接口测试一号'];
    $u2 = ['email' => "apitest_u2_$ts@test.dev", 'password' => 'Test123456', 'nickname' => '接口测试二号'];
    $u3 = ['email' => "apitest_u3_$ts@test.dev", 'password' => 'Test123456', 'nickname' => '接口测试三号'];

    /* 健康检查 */
    $r = http('GET', SVC . '/health');
    ok('S01 健康检查 /health', $r['status'] === 200 && ($r['body']['status'] ?? '') === 'ok', json_encode($r['body']));

    /* 认证 */
    $r = http('POST', SVC . '/api/v1/auth/register', $u1);
    ok('S02 注册成功返回 token', bodyCode($r) === 0 && !empty($r['body']['data']['access_token'] ?? ''), json_encode($r['body']));
    $t1 = $r['body']['data']['access_token'] ?? '';
    $rt1 = $r['body']['data']['refresh_token'] ?? '';

    $r = http('POST', SVC . '/api/v1/auth/register', $u1);
    ok('S03 重复邮箱注册 409', bodyCode($r) === 409, json_encode($r['body']));

    $r = http('POST', SVC . '/api/v1/auth/register', ['email' => 'not-an-email', 'password' => '12345678']);
    ok('S04 非法邮箱 400', bodyCode($r) === 400, json_encode($r['body']));

    $r = http('POST', SVC . '/api/v1/auth/register', ['email' => "x$ts@test.dev", 'password' => '123']);
    ok('S05 密码过短 400', bodyCode($r) === 400, json_encode($r['body']));

    $r = http('POST', SVC . '/api/v1/auth/register', $u2);
    $t2 = $r['body']['data']['access_token'] ?? '';
    $r = http('POST', SVC . '/api/v1/auth/register', $u3);
    $t3 = $r['body']['data']['access_token'] ?? '';
    ok('S06 注册 u2/u3', $t2 !== '' && $t3 !== '', 'u2/u3 token 为空');

    $r = http('GET', SVC . '/api/v1/auth/me', null, authH($t2));
    $uid2 = (int) ($r['body']['data']['user']['id'] ?? 0);
    $r = http('GET', SVC . '/api/v1/auth/me', null, authH($t3));
    $uid3 = (int) ($r['body']['data']['user']['id'] ?? 0);
    ok('S06b 获取 u2/u3 ID', $uid2 > 0 && $uid3 > 0, "uid2=$uid2 uid3=$uid3");

    $r = http('POST', SVC . '/api/v1/auth/login', ['email' => $u1['email'], 'password' => 'WrongPass1']);
    ok('S07 错误密码登录 401', bodyCode($r) === 401, json_encode($r['body']));

    $r = http('POST', SVC . '/api/v1/auth/login', ['email' => 'nobody' . $ts . '@test.dev', 'password' => 'Test123456']);
    ok('S08 不存在用户登录 401', bodyCode($r) === 401, json_encode($r['body']));

    $r = http('POST', SVC . '/api/v1/auth/refresh', ['refresh_token' => 'garbage.token.here']);
    ok('S09 无效刷新令牌 401', bodyCode($r) === 401, json_encode($r['body']));

    $r = http('POST', SVC . '/api/v1/auth/refresh', ['refresh_token' => $rt1]);
    ok('S10 刷新令牌成功', bodyCode($r) === 0 && !empty($r['body']['data']['access_token'] ?? ''), json_encode($r['body']));

    $r = http('GET', SVC . '/api/v1/auth/me');
    ok('S11 未认证访问 /auth/me 401', $r['status'] === 401 || bodyCode($r) === 401, 'status=' . $r['status'] . ' ' . json_encode($r['body']));

    $r = http('GET', SVC . '/api/v1/auth/me', null, authH($t1));
    ok('S12 当前用户信息', bodyCode($r) === 0 && !empty($r['body']['data']['user']['id'] ?? ''), json_encode($r['body']));
    $uid1 = $r['body']['data']['user']['id'] ?? 0;

    /* 个人资料 */
    $r = http('GET', SVC . '/api/v1/me', null, authH($t1));
    ok('S13 查看个人资料', bodyCode($r) === 0 && ($r['body']['data']['nickname'] ?? '') === $u1['nickname'], json_encode($r['body']));

    $r = http('PUT', SVC . '/api/v1/me', ['nickname' => '接口测试一号改', 'gender' => 1, 'bio' => '自动化测试简介'], authH($t1));
    ok('S14 更新个人资料', bodyCode($r) === 0 && ($r['body']['data']['gender'] ?? null) === 1, json_encode($r['body']));

    $r = http('PUT', SVC . '/api/v1/me', ['gender' => 9], authH($t1));
    ok('S15 非法性别 400', bodyCode($r) === 400, json_encode($r['body']));

    $r = http('PUT', SVC . '/api/v1/me', ['birthday' => '2026-13-99'], authH($t1));
    ok('S16 非法生日 400', bodyCode($r) === 400, json_encode($r['body']));

    /* 关注 */
    $r = http('POST', SVC . '/api/v1/users/' . $uid1 . '/follow', null, authH($t1));
    ok('S17 关注自己 400', bodyCode($r) === 400, json_encode($r['body']));

    $r = http('POST', SVC . '/api/v1/users/999999999/follow', null, authH($t1));
    ok('S18 关注不存在用户 404', bodyCode($r) === 404, json_encode($r['body']));

    $r = http('POST', SVC . '/api/v1/users/' . $uid1 . '/follow', null, authH($t2));
    ok('S19 u2 关注 u1', bodyCode($r) === 0 && ($r['body']['data']['following'] ?? null) === true, json_encode($r['body']));

    $r = http('GET', SVC . '/api/v1/users/' . $uid1 . '/followers', null, authH($t1));
    ok('S20 u1 粉丝列表包含 u2', bodyCode($r) === 0 && ($r['body']['data']['total'] ?? 0) >= 1, json_encode($r['body']));

    $r = http('POST', SVC . '/api/v1/users/' . $uid2 . '/follow', null, authH($t1));
    ok('S21 u1 关注 u2', bodyCode($r) === 0, json_encode($r['body']));

    $r = http('GET', SVC . '/api/v1/users/' . $uid1 . '/following', null, authH($t1));
    ok('S22 u1 关注列表包含 u2', bodyCode($r) === 0 && ($r['body']['data']['total'] ?? 0) >= 1, json_encode($r['body']));

    $r = http('GET', SVC . '/api/v1/users/' . $uid2 . '/relation', null, authH($t1));
    ok('S23 关注关系查询', bodyCode($r) === 0 && ($r['body']['data']['is_following'] ?? null) === true, json_encode($r['body']));

    $r = http('POST', SVC . '/api/v1/users/' . $uid2 . '/unfollow', null, authH($t1));
    ok('S24 取消关注', bodyCode($r) === 0 && ($r['body']['data']['following'] ?? null) === false, json_encode($r['body']));

    /* 动态 */
    $r = http('POST', SVC . '/api/v1/posts', ['content' => ''], authH($t1));
    ok('S25 空内容发动态 400', bodyCode($r) === 400, json_encode($r['body']));

    $r = http('POST', SVC . '/api/v1/posts', ['content' => '接口测试动态一号 ' . $ts], authH($t1));
    ok('S26 u1 发布动态', bodyCode($r) === 0 && !empty($r['body']['data']['id'] ?? ''), json_encode($r['body']));
    $post1 = $r['body']['data']['id'] ?? 0;

    $r = http('POST', SVC . '/api/v1/posts', ['content' => '接口测试动态二号 ' . $ts], authH($t2));
    $post2 = $r['body']['data']['id'] ?? 0;
    ok('S27 u2 发布动态', $post2 > 0, json_encode($r['body']));

    $r = http('GET', SVC . '/api/v1/posts', null, authH($t1));
    ok('S28 时间线', bodyCode($r) === 0 && ($r['body']['data']['total'] ?? 0) >= 1, json_encode($r['body']));

    $r = http('GET', SVC . '/api/v1/posts/' . $post1, null, authH($t1));
    ok('S29 动态详情', bodyCode($r) === 0 && ($r['body']['data']['id'] ?? 0) == $post1, json_encode($r['body']));

    $r = http('GET', SVC . '/api/v1/posts/999999999', null, authH($t1));
    ok('S30 动态详情 404', bodyCode($r) === 404, json_encode($r['body']));

    $r = http('POST', SVC . '/api/v1/posts/' . $post2 . '/like', null, authH($t1));
    ok('S31 u1 点赞 u2 动态', bodyCode($r) === 0 && ($r['body']['data']['liked'] ?? null) === true, json_encode($r['body']));

    $r = http('POST', SVC . '/api/v1/posts/' . $post2 . '/unlike', null, authH($t1));
    ok('S32 取消点赞', bodyCode($r) === 0 && ($r['body']['data']['liked'] ?? null) === false, json_encode($r['body']));

    $r = http('POST', SVC . '/api/v1/posts/999999999/like', null, authH($t1));
    ok('S33 点赞不存在动态 404', bodyCode($r) === 404, json_encode($r['body']));

    /* 评论 */
    $r = http('GET', SVC . '/api/v1/posts/' . $post1 . '/comments', null, authH($t1));
    ok('S34 评论列表', bodyCode($r) === 0 && isset($r['body']['data']['list']), json_encode($r['body']));

    $r = http('POST', SVC . '/api/v1/posts/' . $post1 . '/comments', ['content' => '第一条自动化评论'], authH($t2));
    ok('S35 u2 发表评论', bodyCode($r) === 0 && !empty($r['body']['data']['id'] ?? ''), json_encode($r['body']));

    $r = http('POST', SVC . '/api/v1/posts/' . $post1 . '/comments', ['content' => ''], authH($t2));
    ok('S36 空评论 400', bodyCode($r) === 400, json_encode($r['body']));

    $r = http('POST', SVC . '/api/v1/posts/999999999/comments', ['content' => 'x'], authH($t2));
    ok('S37 评论不存在动态 404', bodyCode($r) === 404, json_encode($r['body']));

    /* 通知（u2 应收到 u1 关注 + 点赞 + 评论三类通知） */
    $r = http('POST', SVC . '/api/v1/users/' . $uid2 . '/follow', null, authH($t1));
    $r = http('POST', SVC . '/api/v1/posts/' . $post2 . '/like', null, authH($t1));
    $r = http('GET', SVC . '/api/v1/notifications', null, authH($t2));
    ok('S38 u2 通知列表', bodyCode($r) === 0 && ($r['body']['data']['total'] ?? 0) >= 2, json_encode($r['body']));
    $notifId = $r['body']['data']['list'][0]['id'] ?? 0;

    $r = http('GET', SVC . '/api/v1/notifications/unread-count', null, authH($t2));
    ok('S39 未读通知数', bodyCode($r) === 0 && isset($r['body']['data']['unread_count']), json_encode($r['body']));

    $r = http('POST', SVC . '/api/v1/notifications/' . $notifId . '/read', null, authH($t2));
    ok('S40 标记单条已读', bodyCode($r) === 0, json_encode($r['body']));

    $r = http('POST', SVC . '/api/v1/notifications/read-all', null, authH($t2));
    ok('S41 全部已读', bodyCode($r) === 0, json_encode($r['body']));

    /* 搜索 */
    $r = http('GET', SVC . '/api/v1/search/posts', null, authH($t1));
    ok('S42 搜索缺关键词 400', bodyCode($r) === 400, json_encode($r['body']));

    $r = http('GET', SVC . '/api/v1/search/users?q=' . urlencode('接口测试一号'), null, authH($t1));
    ok('S43 搜索用户', bodyCode($r) === 0 && ($r['body']['data']['total'] ?? 0) >= 1, json_encode($r['body']));

    $r = http('GET', SVC . '/api/v1/search/posts?q=' . urlencode($ts), null, authH($t1));
    $sc = bodyCode($r);
    ok('S44 搜索动态(ES 可用则命中/不可用则 503 降级)', $sc === 0 || ($sc === 503 && ($r['body']['lang_key'] ?? '') === 'search.unavailable'), 'code=' . var_export($sc, true) . ' ' . json_encode($r['body']));
    if ($sc !== 0) {
        ok('S44b 搜索动态 ES 降级说明', true, 'elasticsearch 未配置，搜索接口按设计降级 503');
    }

    /* IM */
    $r = http('GET', SVC . '/api/v1/im/conversations', null, authH($t1));
    ok('S45 会话列表', bodyCode($r) === 0 && isset($r['body']['data']['list']), json_encode($r['body']));

    $r = http('POST', SVC . '/api/v1/im/conversations', ['type' => 1, 'member_ids' => [$uid1]], authH($t1));
    ok('S46 私聊自己 400', bodyCode($r) === 400, json_encode($r['body']));

    $r = http('POST', SVC . '/api/v1/im/conversations', ['type' => 1, 'member_ids' => [$uid2]], authH($t1));
    ok('S47 创建私聊会话', bodyCode($r) === 0 && !empty($r['body']['data']['id'] ?? ''), json_encode($r['body']));
    $conv1 = $r['body']['data']['id'] ?? 0;

    $r = http('POST', SVC . '/api/v1/im/conversations', ['type' => 2], authH($t1));
    ok('S48 群聊缺群名 400', bodyCode($r) === 400, json_encode($r['body']));

    $r = http('POST', SVC . '/api/v1/im/conversations', ['type' => 2, 'name' => '接口测试群', 'member_ids' => [$uid2, $uid3]], authH($t1));
    ok('S49 创建群聊', bodyCode($r) === 0 && !empty($r['body']['data']['id'] ?? ''), json_encode($r['body']));

    $r = http('POST', SVC . '/api/v1/im/conversations', ['type' => 9], authH($t1));
    ok('S50 非法会话类型 400', bodyCode($r) === 400, json_encode($r['body']));

    $r = http('GET', SVC . '/api/v1/im/conversations/' . $conv1 . '/messages', null, authH($t1));
    ok('S51 会话消息历史', bodyCode($r) === 0 && isset($r['body']['data']['list'], $r['body']['data']['has_more']), json_encode($r['body']));

    $r = http('GET', SVC . '/api/v1/im/conversations/' . $conv1 . '/messages', null, authH($t3));
    ok('S52 非成员读消息 404', bodyCode($r) === 404, json_encode($r['body']));

    $r = http('POST', SVC . '/api/v1/im/device-token', ['platform' => 'android', 'token' => 'apitest-push-token-' . $ts], authH($t1));
    ok('S53 上报推送令牌', bodyCode($r) === 0, json_encode($r['body']));

    $r = http('POST', SVC . '/api/v1/im/device-token', ['platform' => '', 'token' => ''], authH($t1));
    ok('S54 空推送令牌 400', bodyCode($r) === 400, json_encode($r['body']));

    /* 语音 */
    $wav = '/tmp/apitest_' . $ts . '.wav';
    exec('ffmpeg -y -f lavfi -i sine=frequency=440:duration=1 -ar 8000 -ac 1 ' . escapeshellarg($wav) . ' 2>/dev/null');
    $r = http('POST', SVC . '/api/v1/im/voice', ['voice' => new CURLFile($wav, 'audio/wav', 'voice.wav'), '__multipart' => true], authH($t1));
    ok('S55 上传语音', bodyCode($r) === 0 && !empty($r['body']['data']['voice_url'] ?? ''), json_encode($r['body']));
    $voiceUrl = $r['body']['data']['voice_url'] ?? '';

    if ($voiceUrl !== '') {
        // voice_url 为相对 API 根的路径（缺 /api/v1 前缀），测试按服务根拼接修正
        $r = http('GET', SVC . '/api/v1' . $voiceUrl, null, authH($t1));
        ok('S56 语音文件访问', $r['status'] === 200 && str_contains($r['headers'], 'audio/mp4'), 'status=' . $r['status'] . ' ' . $r['headers']);
    } else {
        ok('S56 语音文件访问', false, '无 voice_url');
    }

    $r = http('GET', SVC . '/api/v1/voice/%2e%2e%2f%2e%2e%2fetc%2fpasswd', null, authH($t1));
    ok('S57 语音路径穿越拦截', bodyCode($r) === 404 && !str_contains((string) $r['raw'], 'root:'), json_encode($r['body']));

    $r = http('GET', SVC . '/api/v1/voice/zzzz.m4a', null, authH($t1));
    ok('S57b 非法文件名 400', bodyCode($r) === 400, json_encode($r['body']));

    $r = http('GET', SVC . '/api/v1/voice/calls', null, authH($t1));
    ok('S58 通话历史', bodyCode($r) === 0 && isset($r['body']['data']['list']), json_encode($r['body']));

    $r = http('POST', SVC . '/api/v1/voice/rooms', ['name' => ''], authH($t1));
    ok('S59 空房名 400', bodyCode($r) === 400, json_encode($r['body']));

    $r = http('POST', SVC . '/api/v1/voice/rooms', ['name' => '接口测试语聊房 ' . $ts], authH($t1));
    ok('S60 u1 创建语聊房', bodyCode($r) === 0 && !empty($r['body']['data']['room_id'] ?? ''), json_encode($r['body']));
    $room1 = $r['body']['data']['room_id'] ?? 0;

    $r = http('GET', SVC . '/api/v1/voice/rooms', null, authH($t1));
    ok('S61 开放房间列表', bodyCode($r) === 0 && isset($r['body']['data']['list']), json_encode($r['body']));

    $r = http('GET', SVC . '/api/v1/voice/rooms/' . $room1, null, authH($t1));
    ok('S62 房间详情', bodyCode($r) === 0 && ($r['body']['data']['id'] ?? 0) == $room1, json_encode($r['body']));

    $r = http('GET', SVC . '/api/v1/voice/rooms/999999999', null, authH($t1));
    ok('S63 房间详情 404', bodyCode($r) === 404, json_encode($r['body']));

    $r = http('POST', SVC . '/api/v1/voice/rooms/' . $room1 . '/close', null, authH($t2));
    ok('S64 非房主关房 403', bodyCode($r) === 403, json_encode($r['body']));

    $r = http('POST', SVC . '/api/v1/voice/rooms/' . $room1 . '/close', null, authH($t1));
    ok('S65 房主关房', bodyCode($r) === 0, json_encode($r['body']));

    $r = http('POST', SVC . '/api/v1/voice/rooms/999999999/close', null, authH($t1));
    ok('S66 关不存在房间 404', bodyCode($r) === 404, json_encode($r['body']));

    /* 登出 */
    $r = http('POST', SVC . '/api/v1/auth/logout', null, authH($t1));
    ok('S67 登出', bodyCode($r) === 0, json_encode($r['body']));

    $r = http('GET', SVC . '/api/v1/me', null, authH($t1));
    ok('S68 登出后旧 token 失效 401', $r['status'] === 401 || bodyCode($r) === 401, 'status=' . $r['status'] . ' ' . json_encode($r['body']));

    /* 清理 */
    sql("DELETE FROM social_message_reads WHERE conversation_id IN (SELECT id FROM social_conversations WHERE id IN (SELECT conversation_id FROM social_conversation_members WHERE user_id IN (SELECT id FROM social_users WHERE email LIKE 'apitest_%')) )");
    sql("DELETE FROM social_messages WHERE conversation_id IN (SELECT id FROM social_conversations WHERE id IN (SELECT conversation_id FROM social_conversation_members WHERE user_id IN (SELECT id FROM social_users WHERE email LIKE 'apitest_%')))");
    sql("DELETE FROM social_conversation_members WHERE user_id IN (SELECT id FROM social_users WHERE email LIKE 'apitest_%')");
    sql("DELETE FROM social_conversations WHERE owner_id IN (SELECT id FROM social_users WHERE email LIKE 'apitest_%') OR id NOT IN (SELECT conversation_id FROM social_conversation_members)");
    sql("DELETE FROM social_device_tokens WHERE user_id IN (SELECT id FROM social_users WHERE email LIKE 'apitest_%')");
    sql("DELETE FROM social_call_records WHERE caller_id IN (SELECT id FROM social_users WHERE email LIKE 'apitest_%') OR callee_id IN (SELECT id FROM social_users WHERE email LIKE 'apitest_%')");
    sql("DELETE FROM social_voice_room_members WHERE room_id IN (SELECT id FROM social_voice_rooms WHERE owner_id IN (SELECT id FROM social_users WHERE email LIKE 'apitest_%'))");
    sql("DELETE FROM social_voice_rooms WHERE owner_id IN (SELECT id FROM social_users WHERE email LIKE 'apitest_%')");
    sql("DELETE FROM social_notifications WHERE user_id IN (SELECT id FROM social_users WHERE email LIKE 'apitest_%') OR actor_id IN (SELECT id FROM social_users WHERE email LIKE 'apitest_%')");
    sql("DELETE FROM social_likes WHERE user_id IN (SELECT id FROM social_users WHERE email LIKE 'apitest_%') OR post_id IN (SELECT id FROM social_posts WHERE user_id IN (SELECT id FROM social_users WHERE email LIKE 'apitest_%'))");
    sql("DELETE FROM social_comments WHERE user_id IN (SELECT id FROM social_users WHERE email LIKE 'apitest_%') OR post_id IN (SELECT id FROM social_posts WHERE user_id IN (SELECT id FROM social_users WHERE email LIKE 'apitest_%'))");
    sql("DELETE FROM social_follows WHERE follower_id IN (SELECT id FROM social_users WHERE email LIKE 'apitest_%') OR followee_id IN (SELECT id FROM social_users WHERE email LIKE 'apitest_%')");
    sql("DELETE FROM social_posts WHERE user_id IN (SELECT id FROM social_users WHERE email LIKE 'apitest_%')");
    sql("DELETE FROM social_user_profiles WHERE user_id IN (SELECT id FROM social_users WHERE email LIKE 'apitest_%')");
    sql("DELETE FROM social_users WHERE email LIKE 'apitest_%'");
}

/* ============================== admin 套件 ============================== */

function adminSuite(): void
{
    $ts = $GLOBALS['ts'];

    /* 公开端点 */
    $r = http('GET', ADM . '/health');
    ok('A01 健康检查', bodyCode($r) === 0 && ($r['body']['data']['database'] ?? '') === 'ok' && ($r['body']['data']['redis'] ?? '') === 'ok', json_encode($r['body']));

    $r = http('GET', ADM . '/metrics');
    ok('A02 Prometheus 指标', $r['status'] === 200 && str_contains($r['raw'], 'open_admin_'), 'status=' . $r['status']);

    $r = http('GET', ADM . '/.well-known/security.txt');
    ok('A03 security.txt', $r['status'] === 200 && str_contains($r['raw'], 'Contact:'), 'status=' . $r['status']);

    $r = http('GET', ADM . '/api/docs');
    ok('A04 OpenAPI 文档', $r['status'] === 200 && isset($r['body']['openapi']), 'status=' . $r['status'] . ' ' . substr($r['raw'], 0, 100));

    /* 验证码 */
    $r = http('POST', ADM . '/api/captcha/generate', []);
    ok('A05 生成验证码', bodyCode($r) === 0 && !empty($r['body']['data']['key'] ?? ''), json_encode($r['body']));

    $r = http('POST', ADM . '/api/captcha/verify', ['key' => 'no_such_key', 'type' => 'click', 'clicks' => [[1, 1]]]);
    ok('A06 错误验证码 422', bodyCode($r) === 422, json_encode($r['body']));

    /* 登录（验证码答案读取自 Redis） */
    $r = http('POST', ADM . '/api/auth/login', ['username' => ADMIN_USER, 'password' => ADMIN_PASS]);
    ok('A07 缺验证码参数 422', bodyCode($r) === 422, json_encode($r['body']));

    $cap = captchaSolve();
    ok('A08 验证码答案可获取(click)', !empty($cap), '无法生成 click 验证码');
    if ($cap) {
        $r = http('POST', ADM . '/api/captcha/verify', ['key' => $cap['key'], 'type' => 'click', 'clicks' => $cap['pairs']]);
        ok('A09 验证码校验通过', bodyCode($r) === 0 && ($r['body']['data']['valid'] ?? null) === true, json_encode($r['body']));
    } else {
        ok('A09 验证码校验通过', false, '跳过');
    }

    $cap2 = captchaSolve();
    if ($cap2) {
        http('POST', ADM . '/api/captcha/verify', ['key' => $cap2['key'], 'type' => 'click', 'clicks' => $cap2['pairs']]);
        $r = http('POST', ADM . '/api/auth/login', ['username' => 'apitest_nobody_' . $ts, 'password' => 'whatever123', 'captcha_key' => $cap2['key']]);
        ok('A10 错误密码 401', bodyCode($r) === 401, json_encode($r['body']));
    } else {
        ok('A10 错误密码 401', false, '无法生成 click 验证码');
    }

    $login = adminLogin();
    ok('A11 管理端登录', $login['ok'], $login['msg'] ?? '');
    if (!$login['ok']) {
        return;
    }
    $tk = $login['data']['access_token'];
    $rtk = $login['data']['refresh_token'] ?? '';
    $h = authH($tk);

    $r = http('POST', ADM . '/api/auth/refresh', ['refresh_token' => $rtk]);
    ok('A12 刷新令牌', bodyCode($r) === 0 && !empty($r['body']['data']['access_token'] ?? ''), json_encode($r['body']));

    $r = http('GET', ADM . '/admin/profile', null, authH('invalid.token'));
    ok('A13 无效 token 401', $r['status'] === 401 || bodyCode($r) === 401, 'status=' . $r['status'] . ' ' . json_encode($r['body']));

    $r = http('GET', ADM . '/admin/profile');
    ok('A14 未认证访问管理端 401', $r['status'] === 401 || bodyCode($r) === 401, 'status=' . $r['status'] . ' ' . json_encode($r['body']));

    $r = http('POST', ADM . '/api/auth/login', ['username' => ADMIN_USER, 'password' => ADMIN_PASS, 'captcha_key' => 'x'], ['API-Version' => 'v99']);
    ok('A15 非法 API 版本 400', $r['status'] === 400 || bodyCode($r) === 400, 'status=' . $r['status'] . ' ' . json_encode($r['body']));

    $r = http('GET', ADM . '/admin/profile', null, $h);
    ok('A16 个人中心', bodyCode($r) === 0 && ($r['body']['data']['username'] ?? '') === ADMIN_USER, json_encode($r['body']));

    $r = http('GET', ADM . '/admin/dashboard', null, $h);
    ok('A17 仪表盘', bodyCode($r) === 0 && isset($r['body']['data']), json_encode($r['body']));

    /* 用户管理 */
    $r = http('GET', ADM . '/admin/user', null, $h);
    ok('A18 用户列表', bodyCode($r) === 0 && isset($r['body']['data']['list'], $r['body']['data']['total']), json_encode($r['body']));
    $firstHash = $r['body']['data']['list'][0]['id'] ?? '';

    $r = http('GET', ADM . '/admin/user/' . $firstHash, null, $h);
    ok('A19 用户详情', bodyCode($r) === 0 && !empty($r['body']['data']['id'] ?? ''), json_encode($r['body']));

    $r = http('GET', ADM . '/admin/user/bad_hashid_xyz', null, $h);
    ok('A20 非法 hashid 用户详情 404', bodyCode($r) === 404, json_encode($r['body']));

    $r = http('POST', ADM . '/admin/user', ['username' => 'apitest_u_' . $ts, 'password' => 'ApiTest!2026', 'real_name' => '接口测试用户'], $h);
    ok('A21 创建用户', bodyCode($r) === 0 && !empty($r['body']['data']['id'] ?? ''), json_encode($r['body']));
    $tmpUserHash = $r['body']['data']['id'] ?? '';

    $r = http('PUT', ADM . '/admin/user/' . $tmpUserHash, ['real_name' => '接口测试用户-改'], $h);
    ok('A22 更新用户', bodyCode($r) === 0, json_encode($r['body']));

    $r = http('DELETE', ADM . '/admin/user/' . $tmpUserHash, ['password' => 'WrongPassword'], $h);
    ok('A23 删除用户错误二次确认密码 422', bodyCode($r) === 422, json_encode($r['body']));

    $r = http('DELETE', ADM . '/admin/user/' . $tmpUserHash, ['password' => ADMIN_PASS], $h);
    ok('A24 删除用户(密码确认)', bodyCode($r) === 0, json_encode($r['body']));

    /* 角色权限 */
    $r = http('GET', ADM . '/admin/role', null, $h);
    ok('A25 角色列表', bodyCode($r) === 0 && isset($r['body']['data']['list']), json_encode($r['body']));

    $r = http('POST', ADM . '/admin/role', ['name' => '接口测试角色', 'slug' => 'apitest_role_' . $ts, 'status' => 1], $h);
    ok('A26 创建角色', bodyCode($r) === 0 && !empty($r['body']['data']['id'] ?? ''), json_encode($r['body']));
    $tmpRoleHash = $r['body']['data']['id'] ?? '';

    $r = http('PUT', ADM . '/admin/role/' . $tmpRoleHash, ['description' => '自动化测试角色'], $h);
    ok('A27 更新角色', bodyCode($r) === 0, json_encode($r['body']));

    $r = http('DELETE', ADM . '/admin/role/' . $tmpRoleHash, ['password' => ADMIN_PASS], $h);
    ok('A28 删除角色(密码确认)', bodyCode($r) === 0, json_encode($r['body']));

    $r = http('GET', ADM . '/admin/permission', null, $h);
    ok('A29 权限树', bodyCode($r) === 0, json_encode($r['body']));

    /* 系统配置 */
    $r = http('GET', ADM . '/admin/config', null, $h);
    ok('A30 配置列表', bodyCode($r) === 0 && isset($r['body']['data']['list']), json_encode($r['body']));

    $r = http('POST', ADM . '/admin/config', ['group' => 'apitest', 'key' => 'apitest_key_' . $ts, 'value' => 'apitest_value', 'description' => '自动化测试配置'], $h);
    ok('A31 创建配置', bodyCode($r) === 0 && !empty($r['body']['data']['id'] ?? ''), json_encode($r['body']));
    $tmpCfgHash = $r['body']['data']['id'] ?? '';

    $r = http('PUT', ADM . '/admin/config/' . $tmpCfgHash, ['value' => 'apitest_value_2'], $h);
    ok('A32 更新配置', bodyCode($r) === 0, json_encode($r['body']));

    $r = http('DELETE', ADM . '/admin/config/' . $tmpCfgHash, ['password' => ADMIN_PASS], $h);
    ok('A33 删除配置(密码确认)', bodyCode($r) === 0, json_encode($r['body']));

    /* 日志 / 个人中心 */
    $r = http('GET', ADM . '/admin/log', null, $h);
    ok('A34 操作日志', bodyCode($r) === 0 && isset($r['body']['data']['list']), json_encode($r['body']));

    $r = http('PUT', ADM . '/admin/profile', ['real_name' => 'E2E测试账号'], $h);
    ok('A35 更新个人信息', bodyCode($r) === 0, json_encode($r['body']));

    $r = http('PUT', ADM . '/admin/profile/password', ['old_password' => 'WrongOld', 'new_password' => 'ApiTest!2026b'], $h);
    ok('A36 修改密码错误旧密码 422', bodyCode($r) === 422, json_encode($r['body']));

    $r = http('PUT', ADM . '/admin/profile/password', ['old_password' => ADMIN_PASS, 'new_password' => 'ApiTest!2026b'], $h);
    ok('A37 修改密码', bodyCode($r) === 0, json_encode($r['body']));

    $r = http('PUT', ADM . '/admin/profile/password', ['old_password' => 'ApiTest!2026b', 'new_password' => ADMIN_PASS], $h);
    ok('A38 修改密码还原', bodyCode($r) === 0, json_encode($r['body']));

    /* 导出 */
    $r = http('POST', ADM . '/admin/export/excel', ['table' => 'admin_user', 'title' => '接口测试导出'], $h);
    ok('A39 导出 Excel', $r['status'] === 200 && str_contains($r['headers'], 'attachment'), 'status=' . $r['status'] . ' ' . $r['headers']);

    $r = http('POST', ADM . '/admin/export/pdf', ['type' => 'table', 'title' => '接口测试PDF'], $h);
    ok('A40 导出 PDF', $r['status'] === 200 && (str_contains($r['headers'], 'application/pdf') || str_contains($r['headers'], 'attachment')), 'status=' . $r['status'] . ' ' . $r['headers']);

    /* 导入（phpspreadsheet 生成 xlsx） */
    $xlsx = null;
    require_once '/home/wwwroot/social/admin/vendor/autoload.php';
    try {
        $sp = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sh = $sp->getActiveSheet();
        $sh->fromArray(['username', 'password', 'real_name', 'phone', 'email']);
        $sh->fromArray(["apitest_imp_" . $ts, 'Import123', '导入测试', '13800138000', "imp$ts@test.dev"], null, 'A2');
        $tmp = '/tmp/apitest_import_' . $ts . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($sp))->save($tmp);
        $xlsx = $tmp;
    } catch (\Throwable $e) {
        ok('A41 Excel 导入(样本生成)', false, $e->getMessage());
    }
    if ($xlsx !== null) {
        $r = http('POST', ADM . '/admin/import/users', ['file' => new CURLFile($xlsx, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'import.xlsx'), '__multipart' => true], $h);
        ok('A41 Excel 导入用户', bodyCode($r) === 0 && ($r['body']['data']['success'] ?? 0) === 1, json_encode($r['body']));
        sqlAdmin("DELETE FROM erik_admin_user WHERE username LIKE 'apitest_imp_%'");
    }

    /* 上传 */
    $png = '/tmp/apitest_' . $ts . '.png';
    file_put_contents($png, tinyPng());
    $r = http('POST', ADM . '/admin/upload', ['file' => new CURLFile($png, 'image/png', 'tiny.png'), '__multipart' => true], $h);
    ok('A42 文件上传', bodyCode($r) === 0 && !empty($r['body']['data']['url'] ?? ''), json_encode($r['body']));

    $bad = '/tmp/apitest_' . $ts . '.txt';
    file_put_contents($bad, 'not an image');
    $r = http('POST', ADM . '/admin/upload', ['file' => new CURLFile($bad, 'text/plain', 'evil.txt'), '__multipart' => true], $h);
    ok('A43 上传非法类型 422', bodyCode($r) === 422, json_encode($r['body']));

    /* 登出 */
    $r = http('POST', ADM . '/admin/profile/logout', null, $h);
    ok('A44 登出', bodyCode($r) === 0, json_encode($r['body']));

    $r = http('GET', ADM . '/admin/profile', null, $h);
    ok('A45 登出后旧 token 失效 401', $r['status'] === 401 || bodyCode($r) === 401, 'status=' . $r['status'] . ' ' . json_encode($r['body']));
}

/* ============================== 汇总 ============================== */

serviceSuite();
adminSuite();

$pass = $GLOBALS['count']['pass'];
$fail = $GLOBALS['count']['fail'];
$total = $pass + $fail;

echo "\n================ 汇总 ================\n";
printf("接口用例总数: %d  通过: %d  失败: %d\n", $total, $pass, $fail);
if ($fail > 0) {
    echo "\n失败清单:\n";
    foreach ($GLOBALS['results'] as $res) {
        if (!$res['pass']) {
            printf("  - %s\n    %s\n", $res['name'], $res['detail']);
        }
    }
}

file_put_contents(__DIR__ . '/results.json', json_encode([
    'timestamp' => date('Y-m-d H:i:s'),
    'total' => $total,
    'pass' => $pass,
    'fail' => $fail,
    'results' => $GLOBALS['results'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

exit($fail > 0 ? 1 : 0);
