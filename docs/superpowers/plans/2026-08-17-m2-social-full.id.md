# Rencana Implementasi Milestone Sosial Lengkap M2
**语言 / Languages:** [中文](2026-08-17-m2-social-full.md) · [English](2026-08-17-m2-social-full.en.md) · [한국어](2026-08-17-m2-social-full.ko.md) · [Русский](2026-08-17-m2-social-full.ru.md) · [Deutsch](2026-08-17-m2-social-full.de.md) · [Français](2026-08-17-m2-social-full.fr.md) · [Español](2026-08-17-m2-social-full.es.md) · [Português](2026-08-17-m2-social-full.pt.md) · [हिन्दी](2026-08-17-m2-social-full.hi.md) · [العربية](2026-08-17-m2-social-full.ar.md) · [বাংলা](2026-08-17-m2-social-full.bn.md) · [Bahasa Indonesia](2026-08-17-m2-social-full.id.md) · [日本語](2026-08-17-m2-social-full.ja.md)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Menyelesaikan milestone M2: sistem follow, feed lengkap (feed yang diikuti), pencarian teks penuh (bee_search gRPC), dan sistem notifikasi (event like/komentar/follow).

**Architecture:** service (webman PHP) menambah model dan controller Follow/Notification; timeline berubah menjadi "saya + yang saya ikuti"; pencarian lewat gRPC ke infrastructure (Rust tonic + bee_search SearchEngine, saat ES tidak tersedia service menurun secara elegan dengan 503); admin menambah statistik follow; Android/iOS/HarmonyOS menambah halaman profil pengguna dan halaman notifikasi.

**Tech Stack:** PHP 8.3 / webman 2.x / Eloquent / sqlite :memory: tes; Rust tonic 0.12 + prost 0.13 + bee_search (feature "elasticsearch"); proto gRPC; Android OkHttp + kotlinx.serialization.

---

### Task 1: Lapisan data — tabel dan model follows / notifications

**Files:**
- Create: `service/database/m2.sql`
- Create: `service/app/model/Follow.php`
- Create: `service/app/model/Notification.php`
- Modify: `service/tests/bootstrap.php:25-66` (tambahkan skema kedua tabel)
- Create: `service/tests/FollowModelTest.php`

- [ ] **Step 1: Tulis m2.sql (migrasi DB produksi)**

```sql
-- Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
CREATE TABLE IF NOT EXISTS `social_follows` (
  `id` BIGINT UNSIGNED NOT NULL,
  `follower_id` BIGINT UNSIGNED NOT NULL COMMENT '关注者',
  `followee_id` BIGINT UNSIGNED NOT NULL COMMENT '被关注者',
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_follower_followee` (`follower_id`,`followee_id`),
  KEY `idx_followee` (`followee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='关注关系';

CREATE TABLE IF NOT EXISTS `social_notifications` (
  `id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT '接收者',
  `actor_id` BIGINT UNSIGNED NOT NULL COMMENT '触发者',
  `type` VARCHAR(32) NOT NULL COMMENT 'like|comment|follow',
  `ref_type` VARCHAR(32) NOT NULL DEFAULT '' COMMENT 'post|user',
  `ref_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `content` VARCHAR(500) NOT NULL DEFAULT '',
  `read_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_read` (`user_id`,`read_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='通知';
```

- [ ] **Step 2: Tulis model**

`service/app/model/Follow.php`:

```php
<?php
namespace app\model;

use Illuminate\Database\Eloquent\Model;

class Follow extends Model
{
    protected $fillable = ['follower_id', 'followee_id'];
}
```

`service/app/model/Notification.php`:

```php
<?php
namespace app\model;

use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    protected $fillable = ['user_id', 'actor_id', 'type', 'ref_type', 'ref_id', 'content', 'read_at'];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
```

- [ ] **Step 3: Tambahkan tabel tes ke bootstrap.php**

Tambahkan setelah pembuatan tabel `likes` di `service/tests/bootstrap.php`:

```php
Capsule::schema()->create('follows', function ($t) {
    $t->increments('id');
    $t->unsignedBigInteger('follower_id');
    $t->unsignedBigInteger('followee_id');
    $t->timestamps();
    $t->unique(['follower_id', 'followee_id']);
});
Capsule::schema()->create('notifications', function ($t) {
    $t->increments('id');
    $t->unsignedBigInteger('user_id');
    $t->unsignedBigInteger('actor_id');
    $t->string('type', 32);
    $t->string('ref_type', 32)->default('');
    $t->unsignedBigInteger('ref_id')->default(0);
    $t->string('content', 500)->default('');
    $t->timestamp('read_at')->nullable();
    $t->timestamps();
    $t->index(['user_id', 'read_at']);
});
```

- [ ] **Step 4: Tulis FollowModelTest dan jalankan verifikasi**

`service/tests/FollowModelTest.php`:

```php
<?php
require __DIR__ . '/bootstrap.php';

use app\model\Follow;
use app\model\Notification;
use PHPUnit\Framework\TestCase;

class FollowModelTest extends TestCase
{
    public function testFollowUniqueConstraint()
    {
        Follow::create(['follower_id' => 1, 'followee_id' => 2]);
        $this->expectException(\Illuminate\Database\QueryException::class);
        Follow::create(['follower_id' => 1, 'followee_id' => 2]);
    }

    public function testNotificationCreateAndUnread()
    {
        Notification::create(['user_id' => 2, 'actor_id' => 1, 'type' => 'follow', 'ref_type' => 'user', 'ref_id' => 1]);
        $this->assertSame(1, Notification::where('user_id', 2)->whereNull('read_at')->count());
    }
}
```

Jalankan: `cd /home/wwwroot/social/service && vendor/bin/phpunit tests/FollowModelTest.php`
Expected: 2 tests, PASS (pelanggaran constraint unik melempar QueryException, ditangkap oleh expectException).

- [ ] **Step 5: Commit**

```bash
cd /home/wwwroot/social && git add service/database/m2.sql service/app/model/Follow.php service/app/model/Notification.php service/tests/bootstrap.php service/tests/FollowModelTest.php
git commit -m "feat(service): follows/notifications 数据层"
```

---

### Task 2: API follow

**Files:**
- Create: `service/app/common/UserBrief.php`
- Create: `service/app/controller/FollowController.php`
- Modify: `service/config/route.php:41` (tambahkan 5 route di dalam grup AuthMiddleware)
- Create: `service/tests/FollowControllerTest.php`

- [ ] **Step 1: Utilitas UserBrief**

`service/app/common/UserBrief.php`:

```php
<?php
namespace app\common;

use app\model\User;

class UserBrief
{
    public static function of(User $user): array
    {
        $profile = $user->profile;
        return [
            'id' => $user->id,
            'nickname' => $profile->nickname ?? '',
            'avatar' => $profile->avatar ?? '',
            'bio' => $profile->bio ?? '',
            'gender' => $profile->gender ?? 0,
        ];
    }
}
```

(Pastikan `app/model/User.php` memiliki relasi `profile()`; jika tidak, tambahkan `return $this->hasOne(UserProfile::class, 'user_id');`)

- [ ] **Step 2: FollowController**

`service/app/controller/FollowController.php` (dengan apidoc, mengikuti gaya PostController):

```php
<?php
namespace app\controller;

use support\Request;
use app\model\User;
use app\model\Follow;
use app\model\Notification;
use app\common\UserBrief;
use hg\apidoc\annotation as Apidoc;

/**
 * @Apidoc\Title("关注")
 */
class FollowController
{
    /**
     * @Apidoc\Title("关注用户")
     * @Apidoc\Url("/api/v1/users/{id}/follow")
     * @Apidoc\Method("POST")
     * @Apidoc\Header("Authorization", type="string", require=true, desc="Bearer access_token")
     * @Apidoc\Param("id", type="int", require=true, desc="用户ID", path=true)
     * @Apidoc\Returned(ref="Response")
     */
    public function follow(Request $request, string $id)
    {
        $followeeId = (int) $id;
        if ($followeeId === $request->uid) {
            return json(['code' => 400, 'message' => '不能关注自己', 'lang_key' => 'follow.self'], 400);
        }
        if (!User::find($followeeId)) {
            return json(['code' => 404, 'message' => '用户不存在', 'lang_key' => 'user.not_found'], 404);
        }
        $follow = Follow::firstOrCreate(['follower_id' => $request->uid, 'followee_id' => $followeeId]);
        if ($follow->wasRecentlyCreated) {
            Notification::create([
                'user_id' => $followeeId,
                'actor_id' => $request->uid,
                'type' => 'follow',
                'ref_type' => 'user',
                'ref_id' => $request->uid,
            ]);
        }
        return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => ['following' => true]]);
    }

    /**
     * @Apidoc\Title("取消关注")
     * @Apidoc\Url("/api/v1/users/{id}/unfollow")
     * @Apidoc\Method("POST")
     * @Apidoc\Returned(ref="Response")
     */
    public function unfollow(Request $request, string $id)
    {
        Follow::where('follower_id', $request->uid)->where('followee_id', (int) $id)->delete();
        return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => ['following' => false]]);
    }

    /**
     * @Apidoc\Title("关注列表")
     * @Apidoc\Url("/api/v1/users/{id}/following")
     * @Apidoc\Method("GET")
     * @Apidoc\Returned(ref="Response")
     */
    public function following(Request $request, string $id)
    {
        $page = max(1, (int) $request->get('page', 1));
        $pageSize = min(50, max(1, (int) $request->get('page_size', 20)));
        $paginator = Follow::with('followee.profile')->where('follower_id', (int) $id)
            ->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);
        $list = array_map(fn($f) => UserBrief::of($f->followee), $paginator->items());
        return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => [
            'list' => $list, 'total' => $paginator->total(), 'page' => $page, 'page_size' => $pageSize,
        ]]);
    }

    /**
     * @Apidoc\Title("粉丝列表")
     * @Apidoc\Url("/api/v1/users/{id}/followers")
     * @Apidoc\Method("GET")
     * @Apidoc\Returned(ref="Response")
     */
    public function followers(Request $request, string $id)
    {
        $page = max(1, (int) $request->get('page', 1));
        $pageSize = min(50, max(1, (int) $request->get('page_size', 20)));
        $paginator = Follow::with('follower.profile')->where('followee_id', (int) $id)
            ->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);
        $list = array_map(fn($f) => UserBrief::of($f->follower), $paginator->items());
        return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => [
            'list' => $list, 'total' => $paginator->total(), 'page' => $page, 'page_size' => $pageSize,
        ]]);
    }

    /**
     * @Apidoc\Title("关注关系")
     * @Apidoc\Url("/api/v1/users/{id}/relation")
     * @Apidoc\Method("GET")
     * @Apidoc\Returned(ref="Response")
     */
    public function relation(Request $request, string $id)
    {
        $targetId = (int) $id;
        return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => [
            'is_following' => Follow::where('follower_id', $request->uid)->where('followee_id', $targetId)->exists(),
            'follower_count' => Follow::where('followee_id', $targetId)->count(),
            'following_count' => Follow::where('follower_id', $targetId)->count(),
        ]]);
    }
}
```

Tambahkan dua relasi ke model Follow (tambahkan ke `service/app/model/Follow.php` setelah FollowModelTest):

```php
    public function followee()
    {
        return $this->belongsTo(User::class, 'followee_id');
    }

    public function follower()
    {
        return $this->belongsTo(User::class, 'follower_id');
    }
```

- [ ] **Step 3: Daftarkan route**

Tambahkan di dalam grup AuthMiddleware pada `service/config/route.php` (sebelum `/posts`):

```php
        Route::post('/users/{id}/follow', [app\controller\FollowController::class, 'follow']);
        Route::post('/users/{id}/unfollow', [app\controller\FollowController::class, 'unfollow']);
        Route::get('/users/{id}/following', [app\controller\FollowController::class, 'following']);
        Route::get('/users/{id}/followers', [app\controller\FollowController::class, 'followers']);
        Route::get('/users/{id}/relation', [app\controller\FollowController::class, 'relation']);
```

- [ ] **Step 4: FollowControllerTest**

`service/tests/FollowControllerTest.php` (pola tes mengikuti PostInteractionTest: `new Request('POST',...) + setPost + $req->uid + json_decode(rawBody)['code']`):

```php
<?php
require __DIR__ . '/bootstrap.php';

use app\model\User;
use app\model\Follow;
use app\model\Notification;
use app\controller\FollowController;
use PHPUnit\Framework\TestCase;
use support\Request;

class FollowControllerTest extends TestCase
{
    private function makeUser(): User
    {
        return User::create(['email' => uniqid() . '@t.com', 'password' => password_hash('x', PASSWORD_DEFAULT)]);
    }

    private function post(array $post): Request
    {
        $req = new Request('POST / HTTP/1.1');
        $req->setPost($post);
        return $req;
    }

    public function testFollowCreatesNotificationAndIsIdempotent()
    {
        $me = $this->makeUser();
        $other = $this->makeUser();
        $ctrl = new FollowController;

        $res = json_decode($ctrl->follow($this->post([])->withAttribute('uid', $me->id), (string) $other->id)->rawBody(), true);
        $this->assertSame(0, $res['code']);
        $res2 = json_decode($ctrl->follow($this->post([])->withAttribute('uid', $me->id), (string) $other->id)->rawBody(), true);
        $this->assertSame(0, $res2['code']);
        $this->assertSame(1, Follow::count());
        $this->assertSame(1, Notification::where('user_id', $other->id)->where('type', 'follow')->count());
    }

    public function testCannotFollowSelf()
    {
        $me = $this->makeUser();
        $ctrl = new FollowController;
        $res = json_decode($ctrl->follow($this->post([])->withAttribute('uid', $me->id), (string) $me->id)->rawBody(), true);
        $this->assertSame(400, $res['code']);
    }

    public function testRelationAndLists()
    {
        $me = $this->makeUser();
        $a = $this->makeUser();
        $b = $this->makeUser();
        $ctrl = new FollowController;
        $ctrl->follow($this->post([])->withAttribute('uid', $me->id), (string) $a->id);
        $ctrl->follow($this->post([])->withAttribute('uid', $b->id), (string) $me->id);

        $rel = json_decode($ctrl->relation($this->post([])->withAttribute('uid', $me->id), (string) $a->id)->rawBody(), true);
        $this->assertTrue($rel['data']['is_following']);
        $this->assertSame(1, $rel['data']['follower_count']);
        $this->assertSame(1, $rel['data']['following_count']);

        $following = json_decode($ctrl->following($this->post([])->withAttribute('uid', $me->id), (string) $me->id)->rawBody(), true);
        $this->assertSame((int) $a->id, $following['data']['list'][0]['id']);
        $followers = json_decode($ctrl->followers($this->post([])->withAttribute('uid', $me->id), (string) $me->id)->rawBody(), true);
        $this->assertSame((int) $b->id, $followers['data']['list'][0]['id']);
    }
}
```

Catatan: signature method controller adalah `follow(Request $request, string $id)`; tes harus memanggil `$ctrl->follow($req, '5')` secara langsung dan memeriksa rawBody. Jika controller membaca `$request->uid` alih-alih attribute, gunakan `$req->uid = $me->id;` di tes (lihat pola PostInteractionTest — pilih salah satu dan konsisten).

- [ ] **Step 5: Jalankan tes**

```bash
cd /home/wwwroot/social/service && vendor/bin/phpunit tests/FollowControllerTest.php
```
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
cd /home/wwwroot/social && git add service/app/common/UserBrief.php service/app/controller/FollowController.php service/config/route.php service/tests/FollowControllerTest.php service/app/model/Follow.php
git commit -m "feat(service): 关注/取关/列表/关系 API"
```

---

### Task 3: Feed lengkap (feed yang diikuti)

**Files:**
- Modify: `service/app/controller/PostController.php:44-55` (timeline menjadi feed yang diikuti)
- Create: `service/tests/PostFeedTest.php`

- [ ] **Step 1: Ubah timeline**

Ubah baris `$paginator` di `PostController::timeline` menjadi:

```php
        $followeeIds = \app\model\Follow::where('follower_id', $request->uid)->pluck('followee_id')->all();
        $ids = array_merge([$request->uid], $followeeIds);
        $paginator = Post::with('user')->whereIn('user_id', $ids)
            ->orderByDesc('created_at')->paginate($pageSize, ['*'], 'page', $page);
```

(Sisanya tidak berubah; dedup `$ids` bisa diabaikan — id duplikat setelah array_merge otomatis dihapus whereIn)

- [ ] **Step 2: PostFeedTest**

`service/tests/PostFeedTest.php`：

```php
<?php
require __DIR__ . '/bootstrap.php';

use app\model\User;
use app\model\Post;
use app\model\Follow;
use app\controller\PostController;
use PHPUnit\Framework\TestCase;
use support\Request;

class PostFeedTest extends TestCase
{
    private function makeUser(): User
    {
        return User::create(['email' => uniqid() . '@t.com', 'password' => password_hash('x', PASSWORD_DEFAULT)]);
    }

    public function testTimelineContainsSelfAndFolloweesOnly()
    {
        $me = $this->makeUser();
        $followed = $this->makeUser();
        $stranger = $this->makeUser();
        Follow::create(['follower_id' => $me->id, 'followee_id' => $followed->id]);
        $myPost = Post::create(['user_id' => $me->id, 'content' => 'mine']);
        $theirPost = Post::create(['user_id' => $followed->id, 'content' => 'followed']);
        Post::create(['user_id' => $stranger->id, 'content' => 'stranger']);

        $req = new Request('GET / HTTP/1.1');
        $req->uid = $me->id;
        $ctrl = new PostController;
        $res = json_decode($ctrl->timeline($req)->rawBody(), true);

        $this->assertSame(2, $res['data']['total']);
        $ids = array_column($res['data']['list'], 'id');
        $this->assertContains((int) $myPost->id, $ids);
        $this->assertContains((int) $theirPost->id, $ids);
        $this->assertNotContains((int) $stranger->id, $ids);
    }
}
```

- [ ] **Step 3: Jalankan tes**

```bash
cd /home/wwwroot/social/service && vendor/bin/phpunit tests/PostFeedTest.php tests/PostInteractionTest.php
```
Expected: semua PASS.

- [ ] **Step 4: Commit**

```bash
cd /home/wwwroot/social && git add service/app/controller/PostController.php service/tests/PostFeedTest.php
git commit -m "feat(service): 时间线改为关注流"
```

---

### Task 4: Sistem notifikasi

**Files:**
- Modify: `service/app/controller/PostController.php:90-95` (tulis notifikasi saat like)
- Modify: `service/app/controller/CommentController.php:60-62` (tulis notifikasi saat komentar)
- Create: `service/app/controller/NotificationController.php`
- Modify: `service/config/route.php:41`
- Create: `service/tests/NotificationControllerTest.php`

- [ ] **Step 1: Tulis notifikasi pada event like/comment**

Tambahkan di dalam blok `if ($like->wasRecentlyCreated)` di `PostController::like`:

```php
            if ($post->user_id !== $request->uid) {
                \app\model\Notification::create([
                    'user_id' => $post->user_id,
                    'actor_id' => $request->uid,
                    'type' => 'like',
                    'ref_type' => 'post',
                    'ref_id' => $post->id,
                ]);
            }
```

Tambahkan setelah `$post->increment('comment_count');` di `CommentController::create`:

```php
        if ($post->user_id !== $request->uid) {
            \app\model\Notification::create([
                'user_id' => $post->user_id,
                'actor_id' => $request->uid,
                'type' => 'comment',
                'ref_type' => 'post',
                'ref_id' => $post->id,
                'content' => mb_substr($content, 0, 100),
            ]);
        }
```

- [ ] **Step 2: NotificationController**

`service/app/controller/NotificationController.php`：

```php
<?php
namespace app\controller;

use support\Request;
use app\model\Notification;
use app\common\UserBrief;
use hg\apidoc\annotation as Apidoc;

/**
 * @Apidoc\Title("通知")
 */
class NotificationController
{
    /**
     * @Apidoc\Title("通知列表")
     * @Apidoc\Url("/api/v1/notifications")
     * @Apidoc\Method("GET")
     * @Apidoc\Header("Authorization", type="string", require=true, desc="Bearer access_token")
     * @Apidoc\Param("page", type="int", require=false, desc="页码，默认1")
     * @Apidoc\Param("page_size", type="int", require=false, desc="每页条数，默认20")
     * @Apidoc\Returned(ref="Response")
     */
    public function index(Request $request)
    {
        $page = max(1, (int) $request->get('page', 1));
        $pageSize = min(50, max(1, (int) $request->get('page_size', 20)));
        $paginator = Notification::with('actor.profile')->where('user_id', $request->uid)
            ->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);
        $list = array_map(function ($n) {
            return [
                'id' => $n->id,
                'type' => $n->type,
                'ref_type' => $n->ref_type,
                'ref_id' => $n->ref_id,
                'content' => $n->content,
                'read' => $n->read_at !== null,
                'created_at' => (string) $n->created_at,
                'actor' => $n->actor ? UserBrief::of($n->actor) : null,
            ];
        }, $paginator->items());
        return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => [
            'list' => $list, 'total' => $paginator->total(), 'page' => $page, 'page_size' => $pageSize,
        ]]);
    }

    /**
     * @Apidoc\Title("未读数量")
     * @Apidoc\Url("/api/v1/notifications/unread-count")
     * @Apidoc\Method("GET")
     * @Apidoc\Returned(ref="Response")
     */
    public function unreadCount(Request $request)
    {
        $count = Notification::where('user_id', $request->uid)->whereNull('read_at')->count();
        return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => ['unread_count' => $count]]);
    }

    /**
     * @Apidoc\Title("标记已读")
     * @Apidoc\Url("/api/v1/notifications/{id}/read")
     * @Apidoc\Method("POST")
     * @Apidoc\Returned(ref="Response")
     */
    public function read(Request $request, string $id)
    {
        Notification::where('id', (int) $id)->where('user_id', $request->uid)
            ->whereNull('read_at')->update(['read_at' => now()]);
        return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => ['read' => true]]);
    }

    /**
     * @Apidoc\Title("全部已读")
     * @Apidoc\Url("/api/v1/notifications/read-all")
     * @Apidoc\Method("POST")
     * @Apidoc\Returned(ref="Response")
     */
    public function readAll(Request $request)
    {
        Notification::where('user_id', $request->uid)->whereNull('read_at')->update(['read_at' => now()]);
        return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => ['read' => true]]);
    }
}
```

- [ ] **Step 3: Route**

Tambahkan di dalam grup AuthMiddleware pada `service/config/route.php`:

```php
        Route::get('/notifications', [app\controller\NotificationController::class, 'index']);
        Route::get('/notifications/unread-count', [app\controller\NotificationController::class, 'unreadCount']);
        Route::post('/notifications/{id}/read', [app\controller\NotificationController::class, 'read']);
        Route::post('/notifications/read-all', [app\controller\NotificationController::class, 'readAll']);
```

- [ ] **Step 4: NotificationControllerTest**

`service/tests/NotificationControllerTest.php`：

```php
<?php
require __DIR__ . '/bootstrap.php';

use app\model\User;
use app\model\Post;
use app\model\Notification;
use app\controller\NotificationController;
use app\controller\PostController;
use app\controller\CommentController;
use PHPUnit\Framework\TestCase;
use support\Request;

class NotificationControllerTest extends TestCase
{
    private function makeUser(): User
    {
        return User::create(['email' => uniqid() . '@t.com', 'password' => password_hash('x', PASSWORD_DEFAULT)]);
    }

    private function req(int $uid): Request
    {
        $r = new Request('GET / HTTP/1.1');
        $r->uid = $uid;
        return $r;
    }

    public function testLikeAndCommentNotifyOwner()
    {
        $owner = $this->makeUser();
        $liker = $this->makeUser();
        $post = Post::create(['user_id' => $owner->id, 'content' => 'x']);

        $likeReq = new Request('POST / HTTP/1.1');
        $likeReq->uid = $liker->id;
        (new PostController)->like($likeReq, (string) $post->id);

        $commentReq = new Request('POST / HTTP/1.1');
        $commentReq->setPost(['content' => 'nice']);
        $commentReq->uid = $liker->id;
        (new CommentController)->create($commentReq, (string) $post->id);

        $types = Notification::where('user_id', $owner->id)->orderBy('id')->pluck('type')->all();
        $this->assertSame(['like', 'comment'], $types);
    }

    public function testSelfLikeDoesNotNotify()
    {
        $me = $this->makeUser();
        $post = Post::create(['user_id' => $me->id, 'content' => 'x']);
        $likeReq = new Request('POST / HTTP/1.1');
        $likeReq->uid = $me->id;
        (new PostController)->like($likeReq, (string) $post->id);
        $this->assertSame(0, Notification::count());
    }

    public function testUnreadCountAndReadAll()
    {
        $me = $this->makeUser();
        $actor = $this->makeUser();
        Notification::create(['user_id' => $me->id, 'actor_id' => $actor->id, 'type' => 'follow', 'ref_type' => 'user', 'ref_id' => $actor->id]);
        Notification::create(['user_id' => $me->id, 'actor_id' => $actor->id, 'type' => 'follow', 'ref_type' => 'user', 'ref_id' => $actor->id]);

        $ctrl = new NotificationController;
        $uc = json_decode($ctrl->unreadCount($this->req($me->id))->rawBody(), true);
        $this->assertSame(2, $uc['data']['unread_count']);

        $index = json_decode($ctrl->index($this->req($me->id))->rawBody(), true);
        $this->assertSame(2, $index['data']['total']);
        $this->assertSame((int) $actor->id, $index['data']['list'][0]['actor']['id']);
        $this->assertFalse($index['data']['list'][0]['read']);

        $ctrl->readAll($this->req($me->id));
        $uc2 = json_decode($ctrl->unreadCount($this->req($me->id))->rawBody(), true);
        $this->assertSame(0, $uc2['data']['unread_count']);
    }
}
```

- [ ] **Step 5: Jalankan tes**

```bash
cd /home/wwwroot/social/service && vendor/bin/phpunit tests/
```
Expected: semua PASS.

- [ ] **Step 6: Commit**

```bash
cd /home/wwwroot/social && git add service/app/controller/PostController.php service/app/controller/CommentController.php service/app/controller/NotificationController.php service/config/route.php service/tests/NotificationControllerTest.php
git commit -m "feat(service): 通知体系（点赞/评论/关注事件）"
```

---

### Task 5: Kontrak pencarian + implementasi Rust

**Files:**
- Create: `contracts/search/search_service.proto`
- Modify: `infrastructure/crates/social_grpc/build.rs`
- Modify: `infrastructure/crates/social_grpc/Cargo.toml`
- Modify: `infrastructure/crates/bee_search/src/lib.rs`（StubEngine → pub MemoryEngine）
- Create: `infrastructure/crates/social_grpc/src/search.rs`
- Modify: `infrastructure/crates/social_grpc/src/main.rs`

- [ ] **Step 1: proto**

`contracts/search/search_service.proto`：

```proto
syntax = "proto3";

package social.search.v1;

service SearchService {
  rpc Index(IndexRequest) returns (IndexResponse);
  rpc Delete(DeleteRequest) returns (IndexResponse);
  rpc Search(SearchRequest) returns (SearchResponse);
}

message IndexRequest {
  string index = 1;
  int64 id = 2;
  string json = 3;
}

message IndexResponse {
  bool ok = 1;
}

message DeleteRequest {
  string index = 1;
  int64 id = 2;
}

message SearchRequest {
  string index = 1;
  string query = 2;
  int32 from = 3;
  int32 size = 4;
}

message Hit {
  int64 id = 1;
  string json = 2;
}

message SearchResponse {
  repeated Hit hits = 1;
  int64 total = 2;
}
```

- [ ] **Step 2: build.rs / Cargo.toml**

Tambahkan `"../../../contracts/search/search_service.proto"` ke array compile_protos di `build.rs`.

Tambahkan dependensi ke `Cargo.toml`:

```toml
serde_json = "1"
bee_search = { path = "../bee_search", features = ["elasticsearch"] }
```

- [ ] **Step 3: MemoryEngine**

Baca `infrastructure/crates/bee_search/src/lib.rs`; angkat `StubEngine` di dalam `#[cfg(test)] mod tests` menjadi `pub struct MemoryEngine` level modul (implementasikan semua method trait SearchEngine; simpan Document di HashMap dalam memori; search memakai logika pencocokan yang sama dengan tests — `contains` mencocokkan id atau json). Hapus batasan `#[cfg(test)]` dan tandai bagian atasnya dengan `// ponytail: 内存实现，生产走 Elasticsearch feature`. Verifikasi dengan `cargo build`.

- [ ] **Step 4: search.rs**

`infrastructure/crates/social_grpc/src/search.rs`：

```rust
tonic::include_proto!("social");

use bee_search::{Document, DocumentId, SearchEngine, SearchQuery};
use social::search::v1::{
    search_service_server::{SearchService, SearchServiceServer},
    DeleteRequest, Hit, IndexRequest, IndexResponse, SearchRequest, SearchResponse,
};
use std::sync::Arc;
use tonic::{Request, Response, Status};

pub struct SearchSvc {
    pub engine: Arc<dyn SearchEngine>,
}

#[tonic::async_trait]
impl SearchService for SearchSvc {
    async fn index(&self, req: Request<IndexRequest>) -> Result<Response<IndexResponse>, Status> {
        let r = req.into_inner();
        self.engine
            .index(DocumentId(r.id), Document(r.json), Some(r.index))
            .await
            .map_err(|e| Status::internal(e.to_string()))?;
        Ok(Response::new(IndexResponse { ok: true }))
    }

    async fn delete(&self, req: Request<DeleteRequest>) -> Result<Response<IndexResponse>, Status> {
        let r = req.into_inner();
        self.engine
            .delete(DocumentId(r.id), Some(r.index))
            .await
            .map_err(|e| Status::internal(e.to_string()))?;
        Ok(Response::new(IndexResponse { ok: true }))
    }

    async fn search(&self, req: Request<SearchRequest>) -> Result<Response<SearchResponse>, Status> {
        let r = req.into_inner();
        let query = SearchQuery(serde_json::json!({
            "query": r.query,
            "from": r.from,
            "size": r.size,
        }));
        let hits = self
            .engine
            .search(query, Some(r.index))
            .await
            .map_err(|e| Status::internal(e.to_string()))?;
        let total = hits.len() as i64;
        let list = hits
            .into_iter()
            .map(|h| Hit {
                id: h.0 .0,
                json: h.1 .0,
            })
            .collect();
        Ok(Response::new(SearchResponse { hits: list, total }))
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use bee_search::MemoryEngine;

    #[tokio::test]
    async fn index_then_search_roundtrip() {
        let engine = Arc::new(MemoryEngine::new());
        let svc = SearchSvc { engine };

        let idx = Request::new(IndexRequest {
            index: "posts".into(),
            id: 42,
            json: serde_json::json!({"content": "hello world"}).to_string(),
        });
        svc.index(idx).await.unwrap();

        let res = svc
            .search(Request::new(SearchRequest {
                index: "posts".into(),
                query: "hello".into(),
                from: 0,
                size: 10,
            }))
            .await
            .unwrap()
            .into_inner();
        assert_eq!(res.total, 1);
        assert_eq!(res.hits[0].id, 42);
    }
}
```

(`h.0.0`/`h.1.0` adalah akses tuple struct — jika Document/DocumentId di bee_search berupa struct ber-field bernama, gunakan `.0.id`/`.0.content` dan periksa tipe kembalian search; patuhi definisi aktual di lib.rs. Jika SearchEngine::search mengembalikan `Vec<DocumentId>` alih-alih Document, setel Hit.json menjadi string kosong atau lakukan fetch tambahan.)

- [ ] **Step 5: Daftarkan di main.rs**

Tambahkan ke `main.rs`:

```rust
mod search;

use bee_search::SearchEngine;
use search::{SearchSvc, SearchServiceServer as SearchServer2};

// main() 内、serve 之前：
let engine: Arc<dyn SearchEngine> = Arc::new(
    bee_search::Elasticsearch::new(std::env::var("SEARCH_ES_URL").unwrap_or_else(|_| "http://127.0.0.1:9200".into())),
);
println!("search gRPC using ES {}", ...);
Server::builder()
    .add_service(InfraServiceServer::new(InfraSvc))
    .add_service(SearchServer2::new(SearchSvc { engine }))
    .serve(addr)
    .await?;
```

(Signature Elasticsearch::new mengikuti lib.rs; jika async, beri `.await`. Penanganan konflik nama: `use social::search::v1::search_service_server::SearchServiceServer as SearchServiceServerAlias` untuk menghindari konflik dengan nama trait SearchService milik tonic.)

- [ ] **Step 6: Build + tes**

```bash
cd /home/wwwroot/social/infrastructure && cargo build -p social_grpc && cargo test -p social_grpc
```
Expected: build berhasil, `index_then_search_roundtrip` PASS.

- [ ] **Step 7: Commit**

```bash
cd /home/wwwroot/social && git add contracts/search/search_service.proto infrastructure/crates/social_grpc/build.rs infrastructure/crates/social_grpc/Cargo.toml infrastructure/crates/social_grpc/src/search.rs infrastructure/crates/social_grpc/src/main.rs infrastructure/crates/bee_search/src/lib.rs
git commit -m "feat(infra): search gRPC 服务（bee_search + MemoryEngine）"
```

---

### Task 6: API pencarian service (stub gRPC PHP tulis tangan)

**Files:**
- Create: `service/generated/Search/V1/SearchServiceClient.php`
- Create: `service/generated/Search/V1/IndexRequest.php`、`IndexResponse.php`、`DeleteRequest.php`、`SearchRequest.php`、`Hit.php`、`SearchResponse.php`
- Create: `service/generated/GPBMetadata/Search/SearchService.php`
- Create: `service/app/common/SearchSync.php`
- Create: `service/app/controller/SearchController.php`
- Modify: `service/config/route.php`
- Modify: `service/app/controller/PostController.php:29-31` (sinkronkan indeks setelah create)
- Create: `service/tests/SearchControllerTest.php`

Latar belakang: grpc_php_plugin tidak ada, tulis stub secara manual (mengikuti pola `service/generated/Social/Infra/V1/InfraServiceClient.php`).

- [ ] **Step 1: Kelas message (IndexRequest sebagai contoh; sisanya isomorfik)**

`service/generated/Search/V1/IndexRequest.php`：

```php
<?php
// GENERATED CODE -- DO NOT EDIT!

namespace Search\V1;

class IndexRequest extends \Google\Protobuf\Internal\Message
{
    private $index;
    private $id;
    private $json;

    public function getIndex() { return $this->index; }
    public function setIndex($var) { \Google\Protobuf\Internal\GPBUtil::checkString($var, true); $this->index = $var; }

    public function getId() { return $this->id; }
    public function setId($var) { \Google\Protobuf\Internal\GPBUtil::checkInt64($var); $this->id = $var; }

    public function getJson() { return $this->json; }
    public function setJson($var) { \Google\Protobuf\Internal\GPBUtil::checkString($var, true); $this->json = $var; }
}
```

`IndexResponse.php` / `DeleteRequest.php` isomorfik. `SearchRequest.php` menambah dua field `checkInt32`: `from`/`size`. `Hit.php` berisi `id`(int64)/`json`(string). Field repeated pada `SearchResponse.php`:

```php
class SearchResponse extends \Google\Protobuf\Internal\Message
{
    private $hits;
    private $total;

    public function __construct()
    {
        $this->hits = new \Google\Protobuf\Internal\RepeatedField(
            \Google\Protobuf\Internal\GPBType::MESSAGE,
            \Search\V1\Hit::class
        );
    }

    public function getHits() { return $this->hits; }
    public function setHits($var) {
        $arr = new \Google\Protobuf\Internal\RepeatedField(
            \Google\Protobuf\Internal\GPBType::MESSAGE, \Search\V1\Hit::class);
        foreach ($var as $item) { $arr[] = $item; }
        $this->hits = $arr;
    }

    public function getTotal() { return $this->total; }
    public function setTotal($var) { \Google\Protobuf\Internal\GPBUtil::checkInt64($var); $this->total = $var; }
}
```

`GPBMetadata/Search/SearchService.php`：

```php
<?php
// GENERATED CODE -- DO NOT EDIT!

namespace GPBMetadata\Search;

class SearchService
{
    public static function initOnce()
    {
        // 手写 stub：元数据由服务端持有，客户端无需 proto 描述符
    }
}
```

- [ ] **Step 2: SearchServiceClient**

`service/generated/Search/V1/SearchServiceClient.php`：

```php
<?php
// GENERATED CODE -- DO NOT EDIT!

namespace Search\V1;

class SearchServiceClient extends \Grpc\BaseStub
{
    public function __construct($hostname, $opts, $channel = null)
    {
        parent::__construct($hostname, $opts, $channel);
    }

    public function Index(\Search\V1\IndexRequest $argument, $metadata = [], $options = [])
    {
        return $this->_simpleRequest('/social.search.v1.SearchService/Index',
            $argument, ['\Search\V1\IndexResponse', 'decode'], $metadata, $options);
    }

    public function Delete(\Search\V1\DeleteRequest $argument, $metadata = [], $options = [])
    {
        return $this->_simpleRequest('/social.search.v1.SearchService/Delete',
            $argument, ['\Search\V1\IndexResponse', 'decode'], $metadata, $options);
    }

    public function Search(\Search\V1\SearchRequest $argument, $metadata = [], $options = [])
    {
        return $this->_simpleRequest('/social.search.v1.SearchService/Search',
            $argument, ['\Search\V1\SearchResponse', 'decode'], $metadata, $options);
    }
}
```

- [ ] **Step 3: Singleton SearchSync (fire-and-forget + degradasi)**

`service/app/common/SearchSync.php`：

```php
<?php
namespace app\common;

use Search\V1\SearchServiceClient;
use Search\V1\IndexRequest;
use Search\V1\SearchRequest;
use Workerman\Timer;

class SearchSync
{
    private static ?SearchServiceClient $client = null;

    private static function client(): ?SearchServiceClient
    {
        if (self::$client === null) {
            $host = config('plugin.erikwang2013.search.app.host') ?? '127.0.0.1:50051';
            self::$client = new SearchServiceClient($host, ['credentials' => \Grpc\ChannelCredentials::createInsecure()]);
        }
        return self::$client;
    }

    public static function indexPost(int $postId, string $content): void
    {
        // ponytail: fire-and-forget，ES/grpc 不可用静默降级；上线后可换队列
        try {
            $req = new IndexRequest();
            $req->setIndex('posts');
            $req->setId($postId);
            $req->setJson(json_encode(['id' => $postId, 'content' => $content], JSON_UNESCAPED_UNICODE));
            $call = self::client()->Index($req);
            $call->start();
            Timer::add(0.1, function () use ($call) { $call->wait(); }, [], false);
        } catch (\Throwable $e) {
        }
    }

    public static function searchPostIds(string $query, int $from, int $size): array
    {
        try {
            $req = new SearchRequest();
            $req->setIndex('posts');
            $req->setQuery($query);
            $req->setFrom($from);
            $req->setSize($size);
            [$resp, $status] = self::client()->Search($req)->wait();
            if (!$resp) {
                return [];
            }
            $ids = [];
            foreach ($resp->getHits() as $hit) {
                $ids[] = (int) $hit->getId();
            }
            return $ids;
        } catch (\Throwable $e) {
            return [];
        }
    }
}
```

- [ ] **Step 4: SearchController**

`service/app/controller/SearchController.php`：

```php
<?php
namespace app\controller;

use support\Request;
use app\model\Post;
use app\model\UserProfile;
use app\common\SearchSync;
use hg\apidoc\annotation as Apidoc;

/**
 * @Apidoc\Title("搜索")
 */
class SearchController
{
    /**
     * @Apidoc\Title("搜索动态")
     * @Apidoc\Url("/api/v1/search/posts")
     * @Apidoc\Method("GET")
     * @Apidoc\Header("Authorization", type="string", require=true, desc="Bearer access_token")
     * @Apidoc\Param("q", type="string", require=true, desc="关键词")
     * @Apidoc\Param("page", type="int", require=false, desc="页码，默认1")
     * @Apidoc\Param("page_size", type="int", require=false, desc="每页条数，默认20")
     * @Apidoc\Returned(ref="Response")
     */
    public function posts(Request $request)
    {
        $q = trim((string) $request->get('q'));
        if ($q === '') {
            return json(['code' => 400, 'message' => '缺少关键词', 'lang_key' => 'search.q_required'], 400);
        }
        $page = max(1, (int) $request->get('page', 1));
        $pageSize = min(50, max(1, (int) $request->get('page_size', 20)));
        $ids = SearchSync::searchPostIds($q, ($page - 1) * $pageSize, $pageSize);
        if ($ids === []) {
            // ES 不可用或未命中 → 降级提示（非 500）
            return json(['code' => 503, 'message' => '搜索服务不可用', 'lang_key' => 'search.unavailable'], 503);
        }
        $posts = Post::with('user')->whereIn('id', $ids)->orderByDesc('created_at')->get();
        return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => [
            'list' => $posts, 'total' => count($ids),
        ]]);
    }

    /**
     * @Apidoc\Title("搜索用户")
     * @Apidoc\Url("/api/v1/search/users")
     * @Apidoc\Method("GET")
     * @Apidoc\Returned(ref="Response")
     */
    public function users(Request $request)
    {
        $q = trim((string) $request->get('q'));
        if ($q === '') {
            return json(['code' => 400, 'message' => '缺少关键词', 'lang_key' => 'search.q_required'], 400);
        }
        $page = max(1, (int) $request->get('page', 1));
        $pageSize = min(50, max(1, (int) $request->get('page_size', 20)));
        $paginator = UserProfile::with('user')->where('nickname', 'like', "%{$q}%")
            ->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);
        return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => [
            'list' => $paginator->items(), 'total' => $paginator->total(),
        ]]);
    }
}
```

- [ ] **Step 5: Route + sinkronisasi postingan**

Tambahkan di dalam grup AuthMiddleware pada `service/config/route.php`:

```php
        Route::get('/search/posts', [app\controller\SearchController::class, 'posts']);
        Route::get('/search/users', [app\controller\SearchController::class, 'users']);
```

Tambahkan setelah `$post = Post::create(...)` di `PostController::create`:

```php
        \app\common\SearchSync::indexPost($post->id, $content);
```

- [ ] **Step 6: SearchControllerTest**

`service/tests/SearchControllerTest.php`：

```php
<?php
require __DIR__ . '/bootstrap.php';

use app\model\User;
use app\model\UserProfile;
use app\controller\SearchController;
use PHPUnit\Framework\TestCase;
use support\Request;

class SearchControllerTest extends TestCase
{
    public function testSearchUsersByNickname()
    {
        $u = User::create(['email' => 'a@t.com', 'password' => password_hash('x', PASSWORD_DEFAULT)]);
        UserProfile::create(['user_id' => $u->id, 'nickname' => 'ruflo 测试号']);

        $req = new Request('GET / HTTP/1.1');
        $req->get = ['q' => 'ruflo'];
        $ctrl = new SearchController;
        $res = json_decode($ctrl->users($req)->rawBody(), true);
        $this->assertSame(0, $res['code']);
        $this->assertSame(1, $res['data']['total']);
    }

    public function testSearchPostsDegradesGracefully()
    {
        // ES 未部署 → SearchSync 静默失败返回 [] → 503
        $req = new Request('GET / HTTP/1.1');
        $req->get = ['q' => 'anything'];
        $ctrl = new SearchController;
        $res = json_decode($ctrl->posts($req)->rawBody(), true);
        $this->assertContains($res['code'], [0, 503]);
    }
}
```

- [ ] **Step 7: Jalankan tes**

```bash
cd /home/wwwroot/social/service && vendor/bin/phpunit tests/SearchControllerTest.php
```
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
cd /home/wwwroot/social && git add service/generated/ service/app/common/SearchSync.php service/app/controller/SearchController.php service/config/route.php service/app/controller/PostController.php service/tests/SearchControllerTest.php
git commit -m "feat(service): 全文搜索 API（gRPC + 优雅降级）"
```

---

### Task 7: Verifikasi menyeluruh + smoke test E2E

**Files:**
- Create: `service/scripts/probe_m2.php`

- [ ] **Step 1: Skrip smoke test**

`service/scripts/probe_m2.php` (menjalankan Eloquent + controller langsung, tanpa perlu server; `php scripts/probe_m2.php`):

```php
<?php
// M2 E2E 冒烟：注册→关注→发帖→关注流→点赞→通知→搜索
require __DIR__ . '/../vendor/autoload.php';
if (!defined('BASE_PATH')) define('BASE_PATH', __DIR__ . '/..');
\Webman\Config::load(BASE_PATH . '/config', ['route']);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Container\Container;
use app\model\User;
use app\model\UserProfile;
use app\model\Post;
use app\model\Follow;
use app\model\Notification;
use app\controller\AuthController;
use app\controller\PostController;
use app\controller\FollowController;
use app\controller\NotificationController;
use app\controller\SearchController;
use support\Request;

$capsule = new Capsule;
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'social_']);
$capsule->setEventDispatcher(new Dispatcher(new Container));
$capsule->setAsGlobal();
$capsule->bootEloquent();
foreach ([
    'users' => 'id increments;email unique;password;status;timestamps',
] as $table => $cols) { /* 占位——复用 tests/bootstrap.php 的建表逻辑 */ }
```

> Tips: probe cukup `require __DIR__ . '/../tests/bootstrap.php'` untuk memakai ulang pembuatan tabel tes, tidak perlu membuat tabel lagi. Lalu jalankan rantai asersi berikut:
> 1. Daftarkan 2 pengguna A/B (AuthController::register, atau langsung User::create + UserProfile::create)
> 2. A mengikuti B → pastikan Follow ada dan B menerima notifikasi follow
> 3. B posting → timeline A memuat postingan itu (PostController::timeline)
> 4. A like postingan B → B menerima notifikasi like
> 5. NotificationController::unreadCount > 0; setelah readAll menjadi 0
> 6. SearchController::users('nama panggilan B') menghasilkan hasil
> 7. Cetak `M2 E2E OK`, kode keluar 0; asersi apa pun yang gagal mencetak error dan keluar dengan kode 1

- [ ] **Step 2: Jalankan**

```bash
cd /home/wwwroot/social/service && php scripts/probe_m2.php
```
Expected: `M2 E2E OK`.

- [ ] **Step 3: Regresi menyeluruh**

```bash
cd /home/wwwroot/social/service && vendor/bin/phpunit tests/ && cd /home/wwwroot/social/infrastructure && cargo test -p social_grpc
```
Expected: semua PASS.

- [ ] **Step 4: Commit**

```bash
cd /home/wwwroot/social && git add service/scripts/probe_m2.php
git commit -m "test(service): M2 E2E 冒烟脚本"
```

---

### Task 8: Statistik follow di admin

**Files:**
- Create: `admin/app/model/SocialFollow.php`
- Modify: `admin/app/admin/controller/UserController.php` (tambahkan follows_count/fans_count ke index)

- [ ] **Step 1: Model SocialFollow**

`admin/app/model/SocialFollow.php` (**wajib menyertakan header hak cipta**):

```php
<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz

namespace app\model;

use Illuminate\Database\Eloquent\Model;

class SocialFollow extends Model
{
    protected $connection = 'social';
    protected $table = 'follows';
    public $timestamps = false;

    protected $fillable = ['id', 'follower_id', 'followee_id'];
}
```

- [ ] **Step 2: Statistik di UserController**

Baca method index pada `admin/app/admin/controller/UserController.php` dan tambahkan di loop/map yang menyusun daftar kembalian (dua field tambahan per baris pengguna):

```php
            $row['follows_count'] = SocialFollow::where('follower_id', $row['id'])->count();
            $row['fans_count'] = SocialFollow::where('followee_id', $row['id'])->count();
```

(Gunakan `use app\model\SocialFollow;`; jika index mengembalikan array terpaginasi, tambahkan ke setiap item; jika struktur method berbeda, sesuaikan dengan perubahan minimal sambil mempertahankan struktur kembalian yang ada.)

- [ ] **Step 3: Pemeriksaan sintaks**

```bash
cd /home/wwwroot/social/admin && php -l app/model/SocialFollow.php && php -l app/admin/controller/UserController.php
```
Expected: tanpa error.

- [ ] **Step 4: Commit**

```bash
cd /home/wwwroot/social && git add admin/app/model/SocialFollow.php admin/app/admin/controller/UserController.php
git commit -m "feat(admin): 用户列表关注/粉丝统计"
```

---

### Task 9: Klien Android (profil pengguna + notifikasi)

**Files:**
- Modify: `apps/android/.../api/ApiClient.kt` (BASE 8787→8788 + method API baru)
- Modify: `apps/android/.../MainActivity.kt` (titik masuk)
- Create: `apps/android/.../api/UserScreen.kt`
- Create: `apps/android/.../api/NotificationScreen.kt`

- [ ] **Step 1: Perbaiki BASE dan tambahkan method API**

Di `ApiClient.kt`, ubah `BASE = "http://10.0.2.2:8787"` menjadi `"http://10.0.2.2:8788"`. Tambahkan data class dan method (mengikuti pola OkHttp sinkron + kotlinx.serialization yang ada):

```kotlin
@Serializable data class UserBriefItem(val id: Long, val nickname: String = "", val avatar: String = "", val bio: String = "", val gender: Int = 0)
@Serializable data class RelationData(val is_following: Boolean, val follower_count: Long, val following_count: Long)
@Serializable data class NotificationItem(val id: Long, val type: String, val ref_type: String, val ref_id: Long, val content: String, val read: Boolean, val created_at: String, val actor: UserBriefItem? = null)

fun follow(userId: Long): Boolean = post("/users/$userId/follow")
fun unfollow(userId: Long): Boolean = post("/users/$userId/unfollow")
fun relation(userId: Long): RelationData = get("/users/$userId/relation")
fun notifications(page: Int = 1): List<NotificationItem> = getList("/notifications?page=$page")
fun unreadCount(): Int = get("/notifications/unread-count").let { ... }
fun markAllRead(): Boolean = post("/notifications/read-all")
```

(`post`/`get`/`getList` memakai ulang signature method privat yang ada; `getList` mengurai field `data.list`, nama field mengikuti cara penguraian TimelineScreen yang ada.)

- [ ] **Step 2: UserScreen**

`UserScreen.kt`: meniru struktur `TimelineScreen.kt` (ListView + ApiClient singleton + Executor). Menampilkan: avatar, nama panggilan, bio, jumlah pengikut/ikuti dan tombol follow; di bawahnya daftar "postingan pengguna" (pakai ulang layout item postingan TimelineScreen; jika `GET /api/v1/users/{id}/posts` tidak tersedia, panggil timeline lalu filter di sisi klien, atau sembunyikan — prioritasnya interaksi follow + tampilan relasi; untuk daftar postingan gunakan API timeline lengkap).

> Catatan: jika route `GET /api/v1/users/{id}/posts` tidak ada, klien dapat memakai daftar `GET /posts` yang sudah ada; tidak perlu endpoint backend baru.

- [ ] **Step 3: NotificationScreen**

`NotificationScreen.kt`: ListView menampilkan `NotificationItem` (label bahasa Indonesia untuk type: like→"menyukai postingan Anda", comment→"mengomentari postingan Anda", follow→"mengikuti Anda"), sentuhan masuk ke MarkAllRead.

- [ ] **Step 4: Titik masuk MainActivity**

Tambahkan dua titik masuk di `MainActivity.kt` (avatar pengguna / ikon notifikasi menuju Screen yang bersangkutan, mengikuti gaya navigasi Intent/layout yang ada).

- [ ] **Step 5: Verifikasi kompilasi**

```bash
cd /home/wwwroot/social/apps/android && ./gradlew :app:compileDebugKotlin
```
Expected: BUILD SUCCESSFUL.

- [ ] **Step 6: Commit**

```bash
cd /home/wwwroot/social && git add apps/android/
git commit -m "feat(android): 用户主页与通知页"
```

---

### Task 10: Klien iOS / HarmonyOS

**Files:**
- Modify: `apps/ios/.../APIClient.swift`、`ContentView.swift`
- Modify: `apps/harmonyos/.../ApiService.ets`、`Index.ets`

- [ ] **Step 1: Baca dulu kode yang ada**

Baca file yang ada di `apps/ios/` dan `apps/harmonyos/` untuk memastikan pola lapisan jaringan (pembungkus request di APIClient.swift, pembungkus `@ohos.net.http` di ApiService.ets) dan cara pengorganisasian halaman.

- [ ] **Step 2: iOS — tambahkan API dan halaman**

Tambahkan ke `APIClient.swift` (mengikuti pola method yang ada): `follow(id:)`, `unfollow(id:)`, `relation(id:)`, `notifications()`, `unreadCount()`, `markAllRead()`. Tambahkan di `ContentView.swift` dua titik masuk view: profil pengguna (tombol follow + jumlah pengikut/ikuti) dan daftar notifikasi (label bahasa Indonesia untuk type + badge belum dibaca).

- [ ] **Step 3: HarmonyOS — tambahkan API dan halaman**

Tambahkan method yang sama ke `ApiService.ets` (`follow`/`unfollow`/`relation`/`notifications`/`unreadCount`/`markAllRead`). Tambahkan di `Index.ets` halaman profil pengguna dan halaman notifikasi (mengikuti gaya layout List/Scroll + manajemen state yang ada).

- [ ] **Step 4: Pemeriksaan sintaks**

HarmonyOS: jalankan `cd apps/harmonyos && hvigorw assembleHap` jika lingkungan tersedia; jika tidak, lewati pemeriksaan sintaks ala `node --check` dan catat sebagai belum diverifikasi.
iOS: jika tidak ada Xcode di mesin ini, catat bahwa kompilasi belum diverifikasi.

- [ ] **Step 5: Commit**

```bash
cd /home/wwwroot/social && git add apps/ios/ apps/harmonyos/
git commit -m "feat(mobile): iOS/HarmonyOS 用户主页与通知"
```

---

## Definisi Selesai (Definition of Done)

1. Semua tes PHPUnit `service` lolos (termasuk 4 file tes baru)
2. `infrastructure` cargo build + cargo test lolos (termasuk tes roundtrip)
3. `probe_m2.php` mencetak `M2 E2E OK`
4. admin `php -l` lolos
5. Android compileDebugKotlin lolos
6. Tes M0/M1 yang ada tanpa regresi
7. Semua commit sudah di-push (sesuai alur kerja pengguna yang sudah ditetapkan)

