# M2 소셜 완전 마일스톤 구현 계획
**语言 / Languages:** [中文](2026-08-17-m2-social-full.md) · [English](2026-08-17-m2-social-full.en.md) · [한국어](2026-08-17-m2-social-full.ko.md) · [Русский](2026-08-17-m2-social-full.ru.md) · [Deutsch](2026-08-17-m2-social-full.de.md) · [Français](2026-08-17-m2-social-full.fr.md) · [Español](2026-08-17-m2-social-full.es.md) · [Português](2026-08-17-m2-social-full.pt.md) · [हिन्दी](2026-08-17-m2-social-full.hi.md) · [العربية](2026-08-17-m2-social-full.ar.md) · [বাংলা](2026-08-17-m2-social-full.bn.md) · [Bahasa Indonesia](2026-08-17-m2-social-full.id.md) · [日本語](2026-08-17-m2-social-full.ja.md)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** M2 마일스톤을 완료한다: 팔로우 체계, 전체 피드(팔로잉 피드), 전문 검색(bee_search gRPC), 알림 체계(좋아요/댓글/팔로우 이벤트).

**Architecture:** service(webman PHP)에 Follow/Notification 모델과 컨트롤러를 추가하고, 타임라인을 "나 + 팔로잉"으로 변경한다; 검색은 gRPC로 infrastructure(Rust tonic + bee_search SearchEngine, ES를 사용할 수 없으면 service가 503으로 우아하게 다운그레이드)에 연결한다; admin에 팔로우 통계를 추가한다; Android/iOS/HarmonyOS에 사용자 프로필 페이지와 알림 페이지를 추가한다.

**Tech Stack:** PHP 8.3 / webman 2.x / Eloquent / sqlite :memory: 테스트; Rust tonic 0.12 + prost 0.13 + bee_search(feature "elasticsearch"); gRPC proto; Android OkHttp + kotlinx.serialization.

---

### Task 1: 데이터 계층 — follows / notifications 테이블과 모델

**Files:**
- Create: `service/database/m2.sql`
- Create: `service/app/model/Follow.php`
- Create: `service/app/model/Notification.php`
- Modify: `service/tests/bootstrap.php:25-66`(테이블 2개 스키마 추가)
- Create: `service/tests/FollowModelTest.php`

- [ ] **Step 1: m2.sql 작성(프로덕션 DB 마이그레이션)**

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

- [ ] **Step 2: 모델 작성**

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

- [ ] **Step 3: bootstrap.php에 테스트 테이블 추가**

`service/tests/bootstrap.php`의 `likes` 테이블 생성 뒤에 추가한다:

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

- [ ] **Step 4: FollowModelTest 작성 및 검증 실행**

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

실행: `cd /home/wwwroot/social/service && vendor/bin/phpunit tests/FollowModelTest.php`
Expected: 2 tests, PASS(고유 제약 위반 시 QueryException이 발생하고 expectException이 포착).

- [ ] **Step 5: Commit**

```bash
cd /home/wwwroot/social && git add service/database/m2.sql service/app/model/Follow.php service/app/model/Notification.php service/tests/bootstrap.php service/tests/FollowModelTest.php
git commit -m "feat(service): follows/notifications 数据层"
```

---

### Task 2: 팔로우 API

**Files:**
- Create: `service/app/common/UserBrief.php`
- Create: `service/app/controller/FollowController.php`
- Modify: `service/config/route.php:41`(AuthMiddleware group 안에 라우트 5개 추가)
- Create: `service/tests/FollowControllerTest.php`

- [ ] **Step 1: UserBrief 도구**

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

(`app/model/User.php`에 `profile()` 관계가 있는지 확인하고, 없으면 `return $this->hasOne(UserProfile::class, 'user_id');`를 추가한다)

- [ ] **Step 2: FollowController**

`service/app/controller/FollowController.php`(apidoc 포함, PostController 스타일 준수):

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

Follow 모델에 관계 2개를 추가한다(FollowModelTest 뒤에 `service/app/model/Follow.php`로 추가):

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

- [ ] **Step 3: 라우트 등록**

`service/config/route.php`의 AuthMiddleware group 안(`/posts` 앞)에 추가한다:

```php
        Route::post('/users/{id}/follow', [app\controller\FollowController::class, 'follow']);
        Route::post('/users/{id}/unfollow', [app\controller\FollowController::class, 'unfollow']);
        Route::get('/users/{id}/following', [app\controller\FollowController::class, 'following']);
        Route::get('/users/{id}/followers', [app\controller\FollowController::class, 'followers']);
        Route::get('/users/{id}/relation', [app\controller\FollowController::class, 'relation']);
```

- [ ] **Step 4: FollowControllerTest**

`service/tests/FollowControllerTest.php`(테스트 패턴은 PostInteractionTest를 따름: `new Request('POST',...) + setPost + $req->uid + json_decode(rawBody)['code']`):

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

참고: 컨트롤러 메서드 시그니처는 `follow(Request $request, string $id)`이므로 테스트는 `$ctrl->follow($req, '5')`를 직접 호출하고 rawBody를 단언해야 한다. 컨트롤러가 attribute 대신 `$request->uid`를 쓰는 경우 테스트에서 `$req->uid = $me->id;`를 사용한다(PostInteractionTest 패턴 참고, 둘 중 하나로 통일).

- [ ] **Step 5: 테스트 실행**

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

### Task 3: 전체 피드(팔로잉 피드)

**Files:**
- Modify: `service/app/controller/PostController.php:44-55`(timeline을 팔로잉 피드로 변경)
- Create: `service/tests/PostFeedTest.php`

- [ ] **Step 1: timeline 개조**

`PostController::timeline`에서 `$paginator` 한 줄을 다음과 같이 바꾼다:

```php
        $followeeIds = \app\model\Follow::where('follower_id', $request->uid)->pluck('followee_id')->all();
        $ids = array_merge([$request->uid], $followeeIds);
        $paginator = Post::with('user')->whereIn('user_id', $ids)
            ->orderByDesc('created_at')->paginate($pageSize, ['*'], 'page', $page);
```

(나머지는 그대로 둔다; `$ids` 중복 제거는 생략 가능 — array_merge 후 중복 id는 whereIn이 자연히 제거)

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

- [ ] **Step 3: 테스트 실행**

```bash
cd /home/wwwroot/social/service && vendor/bin/phpunit tests/PostFeedTest.php tests/PostInteractionTest.php
```
Expected: 전체 PASS.

- [ ] **Step 4: Commit**

```bash
cd /home/wwwroot/social && git add service/app/controller/PostController.php service/tests/PostFeedTest.php
git commit -m "feat(service): 时间线改为关注流"
```

---

### Task 4: 알림 체계

**Files:**
- Modify: `service/app/controller/PostController.php:90-95`(like 시 알림 작성)
- Modify: `service/app/controller/CommentController.php:60-62`(댓글 시 알림 작성)
- Create: `service/app/controller/NotificationController.php`
- Modify: `service/config/route.php:41`
- Create: `service/tests/NotificationControllerTest.php`

- [ ] **Step 1: like/comment 이벤트에 알림 작성**

`PostController::like`의 `if ($like->wasRecentlyCreated)` 블록 안에 추가한다:

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

`CommentController::create`의 `$post->increment('comment_count');` 뒤에 추가한다:

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

- [ ] **Step 3: 라우트**

`service/config/route.php`의 AuthMiddleware group 안에 추가한다:

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

- [ ] **Step 5: 테스트 실행**

```bash
cd /home/wwwroot/social/service && vendor/bin/phpunit tests/
```
Expected: 전체 PASS.

- [ ] **Step 6: Commit**

```bash
cd /home/wwwroot/social && git add service/app/controller/PostController.php service/app/controller/CommentController.php service/app/controller/NotificationController.php service/config/route.php service/tests/NotificationControllerTest.php
git commit -m "feat(service): 通知体系（点赞/评论/关注事件）"
```

---

### Task 5: 검색 계약 + Rust 구현

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

`build.rs`의 compile_protos 배열에 `"../../../contracts/search/search_service.proto"`를 추가한다.

`Cargo.toml`에 의존성을 추가한다:

```toml
serde_json = "1"
bee_search = { path = "../bee_search", features = ["elasticsearch"] }
```

- [ ] **Step 3: MemoryEngine**

`infrastructure/crates/bee_search/src/lib.rs`를 읽고 `#[cfg(test)] mod tests` 안의 `StubEngine`을 모듈 레벨 `pub struct MemoryEngine`으로 끌어올린다(SearchEngine trait의 모든 메서드 구현; 메모리 HashMap에 Document 저장, search는 tests와 동일한 매칭 로직 — `contains`가 id 또는 json 매칭). `#[cfg(test)]` 제한을 제거하고 상단에 `// ponytail: 内存实现，生产走 Elasticsearch feature`라고 표시한다. `cargo build`로 검증한다.

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

(`h.0.0`/`h.1.0`은 tuple struct 접근 — bee_search의 Document/DocumentId가 named field 구조체라면 `.0.id`/`.0.content`로 바꾸고 search 반환 타입을 확인한다; lib.rs의 실제 정의를 기준으로 한다. SearchEngine::search가 Document 대신 `Vec<DocumentId>`를 반환하면 Hit.json을 빈 문자열로 하거나 추가 fetch를 한다.)

- [ ] **Step 5: main.rs 등록**

`main.rs`에 추가한다:

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

(Elasticsearch::new의 시그니처는 lib.rs를 기준으로 한다; async라면 `.await`. 충돌 처리: `use social::search::v1::search_service_server::SearchServiceServer as SearchServiceServerAlias`로 tonic의 SearchService trait 이름과의 충돌을 피한다.)

- [ ] **Step 6: 빌드 + 테스트**

```bash
cd /home/wwwroot/social/infrastructure && cargo build -p social_grpc && cargo test -p social_grpc
```
Expected: build 통과, `index_then_search_roundtrip` PASS.

- [ ] **Step 7: Commit**

```bash
cd /home/wwwroot/social && git add contracts/search/search_service.proto infrastructure/crates/social_grpc/build.rs infrastructure/crates/social_grpc/Cargo.toml infrastructure/crates/social_grpc/src/search.rs infrastructure/crates/social_grpc/src/main.rs infrastructure/crates/bee_search/src/lib.rs
git commit -m "feat(infra): search gRPC 服务（bee_search + MemoryEngine）"
```

---

### Task 6: service 검색 API(수작업 PHP gRPC stub)

**Files:**
- Create: `service/generated/Search/V1/SearchServiceClient.php`
- Create: `service/generated/Search/V1/IndexRequest.php`、`IndexResponse.php`、`DeleteRequest.php`、`SearchRequest.php`、`Hit.php`、`SearchResponse.php`
- Create: `service/generated/GPBMetadata/Search/SearchService.php`
- Create: `service/app/common/SearchSync.php`
- Create: `service/app/controller/SearchController.php`
- Modify: `service/config/route.php`
- Modify: `service/app/controller/PostController.php:29-31`(create 후 인덱스 동기화)
- Create: `service/tests/SearchControllerTest.php`

배경: grpc_php_plugin이 없어 stub을 직접 작성한다(`service/generated/Social/Infra/V1/InfraServiceClient.php` 패턴 참고).

- [ ] **Step 1: message 클래스(IndexRequest를 예로, 나머지는 동형)**

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

`IndexResponse.php` / `DeleteRequest.php`는 동형. `SearchRequest.php`에 `from`/`size` `checkInt32` 필드 2개 추가. `Hit.php`는 `id`(int64)/`json`(string) 포함. `SearchResponse.php`의 repeated 필드:

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

- [ ] **Step 3: SearchSync 싱글턴(fire-and-forget + 다운그레이드)**

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

- [ ] **Step 5: 라우트 + 발행 동기화**

`service/config/route.php`의 AuthMiddleware group 안에 추가한다:

```php
        Route::get('/search/posts', [app\controller\SearchController::class, 'posts']);
        Route::get('/search/users', [app\controller\SearchController::class, 'users']);
```

`PostController::create`의 `$post = Post::create(...)` 뒤에 추가한다:

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

- [ ] **Step 7: 테스트 실행**

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

### Task 7: 전체 검증 + E2E 스모크

**Files:**
- Create: `service/scripts/probe_m2.php`

- [ ] **Step 1: 스모크 스크립트**

`service/scripts/probe_m2.php`(서버 없이 Eloquent + 컨트롤러를 직접 실행; `php scripts/probe_m2.php`):

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

> 힌트: probe는 `require __DIR__ . '/../tests/bootstrap.php'`로 테스트 테이블 생성 로직을 그대로 재사용하면 되고, 다시 만들 필요가 없다. 그런 다음 다음 단언 체인을 실행한다:
> 1. 사용자 A/B 2명 등록(AuthController::register, 또는 User::create + UserProfile::create 직접 호출)
> 2. A가 B를 팔로우 → Follow 존재와 B의 follow 알림 수신을 단언
> 3. B가 게시 → A의 timeline에 해당 게시물 포함(PostController::timeline)
> 4. A가 B의 게시물에 좋아요 → B의 like 알림 수신을 단언
> 5. NotificationController::unreadCount가 > 0 단언; readAll 후 0
> 6. SearchController::users('B닉네임')가 히트함을 단언
> 7. `M2 E2E OK` 출력 후 종료 코드 0; 단언 실패 시 오류 출력 후 종료 코드 1

- [ ] **Step 2: 실행**

```bash
cd /home/wwwroot/social/service && php scripts/probe_m2.php
```
Expected: `M2 E2E OK`.

- [ ] **Step 3: 전체 회귀 테스트**

```bash
cd /home/wwwroot/social/service && vendor/bin/phpunit tests/ && cd /home/wwwroot/social/infrastructure && cargo test -p social_grpc
```
Expected: 전체 PASS.

- [ ] **Step 4: Commit**

```bash
cd /home/wwwroot/social && git add service/scripts/probe_m2.php
git commit -m "test(service): M2 E2E 冒烟脚本"
```

---

### Task 8: admin 팔로우 통계

**Files:**
- Create: `admin/app/model/SocialFollow.php`
- Modify: `admin/app/admin/controller/UserController.php`(index에 follows_count/fans_count 추가)

- [ ] **Step 1: SocialFollow 모델**

`admin/app/model/SocialFollow.php`(**반드시 저작권 헤더 포함**):

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

- [ ] **Step 2: UserController 통계**

`admin/app/admin/controller/UserController.php`의 index 메서드를 읽고 반환 목록을 만드는 루프/매핑에 추가한다(사용자 행마다 필드 2개):

```php
            $row['follows_count'] = SocialFollow::where('follower_id', $row['id'])->count();
            $row['fans_count'] = SocialFollow::where('followee_id', $row['id'])->count();
```

(`use app\model\SocialFollow;` 사용; index가 페이지네이션 배열을 반환하면 item에 추가하고, 메서드 구조가 다르면 최소 변경으로 기존 반환 구조를 유지하며 맞춘다.)

- [ ] **Step 3: 문법 검사**

```bash
cd /home/wwwroot/social/admin && php -l app/model/SocialFollow.php && php -l app/admin/controller/UserController.php
```
Expected: 오류 없음.

- [ ] **Step 4: Commit**

```bash
cd /home/wwwroot/social && git add admin/app/model/SocialFollow.php admin/app/admin/controller/UserController.php
git commit -m "feat(admin): 用户列表关注/粉丝统计"
```

---

### Task 9: Android 클라이언트(사용자 프로필 + 알림)

**Files:**
- Modify: `apps/android/.../api/ApiClient.kt`(BASE 8787→8788 + 새 API 메서드)
- Modify: `apps/android/.../MainActivity.kt`(진입점)
- Create: `apps/android/.../api/UserScreen.kt`
- Create: `apps/android/.../api/NotificationScreen.kt`

- [ ] **Step 1: BASE 수정 및 API 메서드 추가**

`ApiClient.kt`의 `BASE = "http://10.0.2.2:8787"`을 `"http://10.0.2.2:8788"`로 변경한다. data class와 메서드를 추가한다(기존 OkHttp 동기 + kotlinx.serialization 패턴 준수):

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

(`post`/`get`/`getList`는 기존 private 메서드 시그니처를 재사용; `getList`는 `data.list` 필드를 파싱하고, 필드명은 기존 TimelineScreen 파싱 방식을 따른다.)

- [ ] **Step 2: UserScreen**

`UserScreen.kt`: `TimelineScreen.kt` 구조를 본뜬다(ListView + 싱글턴 ApiClient + Executor). 표시: 아바타, 닉네임, bio, 팔로워 수/팔로잉 수/팔로우 여부 버튼; 아래에 "TA의 소식" 목록(TimelineScreen의 게시물 item 레이아웃 재사용, `GET /api/v1/users/{id}/posts`를 쓸 수 없으면 timeline을 호출해 클라이언트에서 필터링하거나 아예 숨김 — 우선 팔로우 상호작용 + 관계 표시만 하고, 소식 목록은 timeline 전체 인터페이스를 사용).

> 참고: `GET /api/v1/users/{id}/posts` 라우트가 없으면 클라이언트는 기존 `GET /posts` 목록을 사용하면 되고, 백엔드 인터페이스를 새로 만들지 않는다.

- [ ] **Step 3: NotificationScreen**

`NotificationScreen.kt`: ListView로 `NotificationItem` 표시(type 한글 라벨: like→"내 소식을 좋아합니다", comment→"내 소식에 댓글을 달았습니다", follow→"나를 팔로우했습니다"), 클릭 시 MarkAllRead로 진입.

- [ ] **Step 4: MainActivity 진입점**

`MainActivity.kt`에 진입점 2개를 추가한다(사용자 아바타/알림 아이콘이 해당 Screen으로 이동, 기존 Intent/레이아웃 전환 방식 준수).

- [ ] **Step 5: 컴파일 검증**

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

### Task 10: iOS / HarmonyOS 클라이언트

**Files:**
- Modify: `apps/ios/.../APIClient.swift`、`ContentView.swift`
- Modify: `apps/harmonyos/.../ApiService.ets`、`Index.ets`

- [ ] **Step 1: 먼저 기존 코드를 읽는다**

`apps/ios/`와 `apps/harmonyos/`의 기존 파일을 읽고 네트워크 계층 패턴(APIClient.swift의 요청 래핑, ApiService.ets의 `@ohos.net.http` 래핑)과 페이지 구성 방식을 확인한다.

- [ ] **Step 2: iOS에 API + 페이지 추가**

`APIClient.swift`에 추가(기존 메서드 패턴 준수): `follow(id:)`, `unfollow(id:)`, `relation(id:)`, `notifications()`, `unreadCount()`, `markAllRead()`. `ContentView.swift`에 사용자 프로필(팔로우 버튼 + 팔로워/팔로잉 수)과 알림 목록(type 한글 라벨 + 안 읽음 배지) 두 뷰 진입점을 추가한다.

- [ ] **Step 3: HarmonyOS에 API + 페이지 추가**

`ApiService.ets`에 동일한 메서드를 추가한다(`follow`/`unfollow`/`relation`/`notifications`/`unreadCount`/`markAllRead`). `Index.ets`에 사용자 프로필 페이지와 알림 페이지를 추가한다(기존 List/Scroll 레이아웃 + 상태 관리 방식 준수).

- [ ] **Step 4: 문법 검사**

HarmonyOS: 환경이 가능하면 `cd apps/harmonyos && hvigorw assembleHap`을 실행하고, 아니면 `node --check` 류 문법 검사는 건너뛰고 미검증임을 명시한다.
iOS: 본 머신에 Xcode가 없으면 컴파일 검증을 하지 않았음을 명시한다.

- [ ] **Step 5: Commit**

```bash
cd /home/wwwroot/social && git add apps/ios/ apps/harmonyos/
git commit -m "feat(mobile): iOS/HarmonyOS 用户主页与通知"
```

---

## 완료 정의(Definition of Done)

1. `service`의 모든 PHPUnit 테스트 통과(새 테스트 파일 4개 포함)
2. `infrastructure` cargo build + cargo test 통과(roundtrip 테스트 포함)
3. `probe_m2.php`가 `M2 E2E OK` 출력
4. admin `php -l` 통과
5. Android compileDebugKotlin 통과
6. M0/M1 기존 테스트 회귀 없음
7. 모든 커밋 push 완료(사용자 기존 워크플로)

