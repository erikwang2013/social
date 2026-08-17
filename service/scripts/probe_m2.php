<?php
// M2 E2E 冒烟：注册→关注→发帖→关注流→点赞→通知→搜索
require __DIR__ . '/../tests/bootstrap.php';

use app\model\User;
use app\model\UserProfile;
use app\model\Follow;
use app\model\Notification;
use app\controller\PostController;
use app\controller\FollowController;
use app\controller\NotificationController;
use support\Request;

function check(bool $cond, string $msg): void
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: $msg\n");
        exit(1);
    }
    echo "OK: $msg\n";
}

// 1. 注册 2 用户 A/B
$a = User::create(['email' => uniqid() . '@probe.a', 'password' => password_hash('x', PASSWORD_DEFAULT)]);
$b = User::create(['email' => uniqid() . '@probe.b', 'password' => password_hash('x', PASSWORD_DEFAULT)]);
$bNick = 'probe-B-' . uniqid();
UserProfile::create(['user_id' => $b->id, 'nickname' => $bNick]);

// 2. A 关注 B → Follow 存在、B 收到 follow 通知
$req = new Request("POST / HTTP/1.1\r\n\r\n");
$req->uid = (int) $a->id;
(new FollowController)->follow($req, (string) $b->id);
check(Follow::where('follower_id', $a->id)->where('followee_id', $b->id)->exists(), 'A 关注 B');
check(Notification::where('user_id', $b->id)->where('type', 'follow')->exists(), 'B 收到 follow 通知');

// 3. B 发帖 → A 的 timeline 含该帖
$req = new Request("POST / HTTP/1.1\r\n\r\n");
$req->setPost(['content' => 'probe post']);
$req->uid = (int) $b->id;
$res = json_decode((new PostController)->create($req)->rawBody(), true);
$postId = (int) $res['data']['id'];
$req = new Request("GET / HTTP/1.1\r\n\r\n");
$req->uid = (int) $a->id;
$timeline = json_decode((new PostController)->timeline($req)->rawBody(), true);
$ids = array_column($timeline['data']['list'], 'id');
check(in_array($postId, $ids, true), 'A 时间线含 B 的帖');

// 4. A 点赞 B 的帖 → B 收到 like 通知
$req = new Request("POST / HTTP/1.1\r\n\r\n");
$req->uid = (int) $a->id;
(new PostController)->like($req, (string) $postId);
check(Notification::where('user_id', $b->id)->where('type', 'like')->exists(), 'B 收到 like 通知');

// 5. 未读数 > 0；readAll 后为 0
$req = new Request("GET / HTTP/1.1\r\n\r\n");
$req->uid = (int) $b->id;
$uc = json_decode((new NotificationController)->unreadCount($req)->rawBody(), true);
check($uc['data']['unread_count'] > 0, '未读数 > 0');
(new NotificationController)->readAll($req);
$uc2 = json_decode((new NotificationController)->unreadCount($req)->rawBody(), true);
check($uc2['data']['unread_count'] === 0, 'readAll 后未读数 0');

// 6. 搜索用户（SearchController 由 T6 提供，未实现时跳过）
if (class_exists(\app\controller\SearchController::class)) {
    $req = new Request("GET /?q=" . rawurlencode($bNick) . " HTTP/1.1\r\n\r\n");
    $res = json_decode((new \app\controller\SearchController)->users($req)->rawBody(), true);
    check($res['code'] === 0 && $res['data']['total'] >= 1, '搜索用户命中');
} else {
    echo "SKIP: SearchController 未实现，跳过搜索断言\n";
}

echo "M2 E2E OK\n";
