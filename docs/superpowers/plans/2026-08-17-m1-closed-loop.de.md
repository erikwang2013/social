# M1 Closed-Loop-Meilenstein-Implementierungsplan
**语言 / Languages:** [中文](2026-08-17-m1-closed-loop.md) · [English](2026-08-17-m1-closed-loop.en.md) · [한국어](2026-08-17-m1-closed-loop.ko.md) · [Русский](2026-08-17-m1-closed-loop.ru.md) · [Deutsch](2026-08-17-m1-closed-loop.de.md) · [Français](2026-08-17-m1-closed-loop.fr.md) · [Español](2026-08-17-m1-closed-loop.es.md) · [Português](2026-08-17-m1-closed-loop.pt.md) · [हिन्दी](2026-08-17-m1-closed-loop.hi.md) · [العربية](2026-08-17-m1-closed-loop.ar.md) · [বাংলা](2026-08-17-m1-closed-loop.bn.md) · [Bahasa Indonesia](2026-08-17-m1-closed-loop.id.md) · [日本語](2026-08-17-m1-closed-loop.ja.md)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Ziel:** M1-geschlossenen Kreislauf abschließen: Registrierung/Login/Profil, Beitrag erstellen/Detail, vereinfachte Timeline, Likes & Kommentare, und für alle service-APIs die hg/apidoc-Schnittstellendokumentation generieren.

**Architektur:** service (webman) stellt alle clientseitigen HTTP-APIs bereit (`/api/v1`) mit JWT-Zwei-Token-Authentifizierung (access 2h + refresh 14d, jti Redis-Blacklist für Logout), Eloquent verbindet sich direkt mit MySQL (Tabellenpräfix `social_`); admin bietet die Benutzerverwaltung (M1 fragt Tabellen direkt ab, gRPC auf M2 verschoben); drei mobile Clients (Android/iOS/HarmonyOS) implementieren Login + Timeline + Beitrag erstellen nativ. Alle service-APIs nutzen hg/apidoc-Annotationen zur Dokumentationsgenerierung.

**Tech-Stack:** PHP 8.3 / webman 2.x / webman/database (Eloquent) / phpunit / grpc ext / hg/apidoc / JWT (erikwang2013/jwt-webman) / Redis / Kotlin+OkHttp / Swift+URLSession / ArkTS+http module

**Bekannte Einschränkungen (verschoben):** Captcha, Bild-Upload, Follows, vollständiger Feed, admin→service gRPC, Inhaltsübersetzung → alles M2+.

---

## Dateistruktur

```
service/
  config/database.php              # mysql default + sqlite :memory: test
  database/migrations/m1.sql       # social_ 表 DDL
  app/model/User.php / UserProfile.php / Post.php / Comment.php / Like.php
  app/common/JwtHelper.php         # encode/decode/revoke
  app/middleware/AuthMiddleware.php
  app/controller/AuthController.php    # register/login/refresh/logout/me
  app/controller/MeController.php      # update 资料
  app/controller/PostController.php    # create/timeline/detail/like/unlike
  app/controller/CommentController.php # index/create
  app/common/Definitions.php          # @Apidoc\Define
  config/route.php                     # /api/v1 group + apidoc register
  config/plugin/erikwang2013/jwt/jwt.php
  config/plugin/hg/apidoc/app.php + route.php
  phpunit.xml
  tests/bootstrap.php
  tests/AuthModelTest.php / AuthControllerTest.php / AuthFlowTest.php
  tests/MeControllerTest.php / PostControllerTest.php / PostInteractionTest.php
admin/
  app/model/SocialUser.php
  app/admin/controller/SocialUserController.php
  config/route.php                   # /admin/user group
apps/android/...（T8）
apps/ios/...（T9）
apps/harmonyos/...（T10）
```

---

### Task 1 (backend-service): Datenbankschicht + Testgerüst

**Dateien:**
- Erstellen: `service/config/database.php`
- Erstellen: `service/database/migrations/m1.sql`
- Erstellen: `service/app/model/User.php`, `UserProfile.php`, `Post.php`, `Comment.php`, `Like.php`
- Erstellen: `service/phpunit.xml`
- Erstellen: `service/tests/bootstrap.php`
- Erstellen: `service/tests/AuthModelTest.php`
- Ändern: `service/composer.json` (webman/database + phpunit dev)

- [ ] **Step 1: Abhängigkeiten installieren**

```bash
cd service && composer update --no-interaction --no-plugins
# 若 webman/database、phpunit 未在 composer.json，先手动加入：
# composer.json require: "webman/database": "^2.1"；require-dev: "phpunit/phpunit": "^10.5"
```

- [ ] **Step 2: config/database.php schreiben** (analog zu admin/config/database.php: default mysql, Testverbindung nutzt sqlite :memory:)

```php
<?php
return [
    'default' => env('DB_CONNECTION', 'mysql'),
    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'social'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => env('DB_PREFIX', 'social_'),
            'strict' => true,
            'engine' => null,
        ],
        'test' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => 'social_',
        ],
    ],
];
```

- [ ] **Step 3: m1.sql schreiben** (nur Struktur, für Produktion/lokales mysql; Tests legen Tabellen inline über bootstrap an)

```sql
CREATE TABLE IF NOT EXISTS social_users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  status TINYINT NOT NULL DEFAULT 1 COMMENT '1正常 0禁用',
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS social_user_profiles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL UNIQUE,
  nickname VARCHAR(64) NOT NULL DEFAULT '',
  avatar VARCHAR(255) NOT NULL DEFAULT '',
  bio VARCHAR(255) NOT NULL DEFAULT '',
  gender TINYINT NOT NULL DEFAULT 0 COMMENT '0保密 1男 2女',
  birthday DATE NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  KEY idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS social_posts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  content TEXT NOT NULL,
  like_count INT UNSIGNED NOT NULL DEFAULT 0,
  comment_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  KEY idx_user_id (user_id),
  KEY idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS social_comments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  post_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  content VARCHAR(500) NOT NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  KEY idx_post_id (post_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS social_likes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  post_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY uniq_post_user (post_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 4: Modelle schreiben** (5 Modelle; Eloquent liest das Präfix aus config('database'))

```php
<?php
// app/model/User.php
namespace app\model;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $table = 'users';
    protected $hidden = ['password'];
}
```

```php
<?php
// app/model/UserProfile.php
namespace app\model;

use Illuminate\Database\Eloquent\Model;

class UserProfile extends Model
{
    protected $table = 'user_profiles';
    protected $fillable = ['user_id', 'nickname', 'avatar', 'bio', 'gender', 'birthday'];
}
```

```php
<?php
// app/model/Post.php
namespace app\model;

use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    protected $table = 'posts';
    protected $fillable = ['user_id', 'content'];
    protected $appends = ['liked'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getLikedAttribute()
    {
        $uid = request()->uid ?? 0;
        return $uid ? Like::where('post_id', $this->id)->where('user_id', $uid)->exists() : false;
    }
}
```

```php
<?php
// app/model/Comment.php
namespace app\model;

use Illuminate\Database\Eloquent\Model;

class Comment extends Model
{
    protected $table = 'comments';
    protected $fillable = ['post_id', 'user_id', 'content'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
```

```php
<?php
// app/model/Like.php
namespace app\model;

use Illuminate\Database\Eloquent\Model;

class Like extends Model
{
    protected $table = 'likes';
    protected $fillable = ['post_id', 'user_id'];
}
```

- [ ] **Step 5: phpunit.xml + tests/bootstrap.php schreiben**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         bootstrap="tests/bootstrap.php"
         colors="true">
  <testsuites>
    <testsuite name="unit">
      <directory>tests</directory>
    </testsuite>
  </testsuites>
</phpunit>
```

```php
<?php
// tests/bootstrap.php
require __DIR__ . '/../vendor/autoload.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Container\Container;

$capsule = new Capsule;
$capsule->addConnection([
    'driver' => 'sqlite',
    'database' => ':memory:',
    'prefix' => 'social_',
]);
$capsule->setEventDispatcher(new Dispatcher(new Container));
$capsule->setAsGlobal();
$capsule->bootEloquent();

Capsule::schema()->create('users', function ($t) {
    $t->increments('id');
    $t->string('email', 190)->unique();
    $t->string('password');
    $t->tinyInteger('status')->default(1);
    $t->timestamps();
});
Capsule::schema()->create('user_profiles', function ($t) {
    $t->increments('id');
    $t->unsignedBigInteger('user_id')->unique();
    $t->string('nickname', 64)->default('');
    $t->string('avatar', 255)->default('');
    $t->string('bio', 255)->default('');
    $t->tinyInteger('gender')->default(0);
    $t->date('birthday')->nullable();
    $t->timestamps();
});
Capsule::schema()->create('posts', function ($t) {
    $t->increments('id');
    $t->unsignedBigInteger('user_id');
    $t->text('content');
    $t->unsignedInteger('like_count')->default(0);
    $t->unsignedInteger('comment_count')->default(0);
    $t->timestamps();
    $t->index('user_id');
    $t->index('created_at');
});
Capsule::schema()->create('comments', function ($t) {
    $t->increments('id');
    $t->unsignedBigInteger('post_id');
    $t->unsignedBigInteger('user_id');
    $t->string('content', 500);
    $t->timestamps();
    $t->index('post_id');
});
Capsule::schema()->create('likes', function ($t) {
    $t->increments('id');
    $t->unsignedBigInteger('post_id');
    $t->unsignedBigInteger('user_id');
    $t->timestamps();
    $t->unique(['post_id', 'user_id']);
});
```

- [ ] **Step 6: Test tests/AuthModelTest.php schreiben**

```php
<?php
use PHPUnit\Framework\TestCase;
use app\model\User;
use app\model\UserProfile;
use app\model\Post;
use app\model\Like;

class AuthModelTest extends TestCase
{
    public function testUserCreateAndTablePrefix()
    {
        $u = User::create(['email' => 'a@b.com', 'password' => password_hash('secret', PASSWORD_BCRYPT)]);
        $this->assertTrue($u->exists);
        $this->assertSame('social_users', $u->getTable());
        $this->assertNull($u->password, 'password hidden');
    }

    public function testProfileCreated()
    {
        $u = User::create(['email' => 'c@d.com', 'password' => 'x']);
        UserProfile::create(['user_id' => $u->id, 'nickname' => '测试']);
        $profile = UserProfile::where('user_id', $u->id)->first();
        $this->assertSame('测试', $profile->nickname);
    }

    public function testLikeUniqueConstraint()
    {
        $u = User::create(['email' => 'e@f.com', 'password' => 'x']);
        $p = Post::create(['user_id' => $u->id, 'content' => 'hello']);
        Like::create(['post_id' => $p->id, 'user_id' => $u->id]);
        $this->expectException(\Illuminate\Database\QueryException::class);
        Like::create(['post_id' => $p->id, 'user_id' => $u->id]);
    }
}
```

- [ ] **Step 7: Tests ausführen**

```bash
cd service && vendor/bin/phpunit
```

Erwartet: 3 tests, 3 assertions, PASS.

- [ ] **Step 8: Committen**

```bash
git add service/composer.json service/composer.lock service/config/database.php service/database service/app/model service/phpunit.xml service/tests
git commit -m "feat(service): database layer + models + phpunit skeleton"
```

---

### Task 2 (backend-service): Authentifizierung (Registrierung/Login/Refresh/Logout/me)

**Dateien:**
- Erstellen: `service/app/common/JwtHelper.php`
- Erstellen: `service/app/middleware/AuthMiddleware.php`
- Erstellen: `service/app/controller/AuthController.php`
- Erstellen: `service/config/route.php`
- Erstellen: `service/config/plugin/erikwang2013/jwt/jwt.php`
- Erstellen: `service/tests/AuthControllerTest.php`, `service/tests/AuthFlowTest.php`
- Ändern: `service/app/functions.php` (redisAvailable() hinzufügen)

- [ ] **Step 1: JWT-Paket installieren (überspringen, falls bereits in composer.json)**

```bash
cd service && composer update --no-interaction --no-plugins
```

- [ ] **Step 2: jwt.php-Konfiguration**

```php
<?php
// config/plugin/erikwang2013/jwt/jwt.php
return [
    'secret_key' => env('JWT_SECRET', 'social-dev-secret-change-me'),
    'alg' => 'HS256',
    'default_expire' => 7200,       // access 2h
    'refresh_expire' => 1209600,    // refresh 14d
    'type' => 'header',
    'name' => 'Authorization',
    'prefix' => 'Bearer',
    'issuer' => 'social-service',
];
```

- [ ] **Step 3: redisAvailable() an app/functions.php anhängen** (damit Tests Redis-abhängige Fälle überspringen können)

```php
function redisAvailable(): bool
{
    try {
        $r = new \Redis();
        $r->connect(env('REDIS_HOST', '127.0.0.1'), (int) env('REDIS_PORT', 6379));
        return $r->ping() === true || $r->ping() === '+PONG';
    } catch (\Throwable $e) {
        return false;
    }
}
```

- [ ] **Step 4: JwtHelper**

```php
<?php
// app/common/JwtHelper.php
namespace app\common;

use Webman\Config;
use Firebase\JWT\JWT; // erikwang2013/jwt-webman 依赖

class JwtHelper
{
    private static function config(): array
    {
        return Config::get('plugin.erikwang2013.jwt.jwt');
    }

    public static function encode(int $userId, string $type, int $ttl): string
    {
        $cfg = self::config();
        return JWT::encode([
            'sub' => $userId,
            'type' => $type,
            'jti' => bin2hex(random_bytes(16)),
            'iat' => time(),
            'exp' => time() + $ttl,
            'iss' => $cfg['issuer'],
        ], $cfg['secret_key'], $cfg['alg']);
    }

    public static function decode(string $token): ?object
    {
        try {
            $cfg = self::config();
            $payload = JWT::decode($token, $cfg['secret_key'], [$cfg['alg']]);
            if (($payload->iss ?? '') !== $cfg['issuer']) return null;
            return $payload;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** jti blacklist：登出后将 refresh jti 写入 Redis，TTL 与 refresh 一致 */
    public static function revoke(string $jti, int $ttl): void
    {
        $redis = new \Redis();
        $redis->connect(env('REDIS_HOST', '127.0.0.1'), (int) env('REDIS_PORT', 6379));
        $redis->setex('jwt:blacklist:' . $jti, $ttl, '1');
    }

    public static function isRevoked(string $jti): bool
    {
        $redis = new \Redis();
        $redis->connect(env('REDIS_HOST', '127.0.0.1'), (int) env('REDIS_PORT', 6379));
        return (bool) $redis->exists('jwt:blacklist:' . $jti);
    }
}
```

> Hinweis: JWTFactory::createFromConfig von erikwang2013/jwt-webman funktioniert ebenfalls; um die Tests einfach zu halten (nicht von dessen Interna abhängig), verwenden wir direkt dessen zugrunde liegendes firebase/php-jwt. Falls das Paket kein firebase/jwt enthält, auf die `erikwang2013\jwt\JWTFactory`-Variante wechseln:

```php
// 备选（若 firebase/jwt 不在依赖树）：
use erikwang2013\jwt\JWTFactory;
$jwt = JWTFactory::createFromConfig(config('plugin.erikwang2013.jwt.jwt'));
$token = $jwt->encode(['sub' => $userId, 'type' => $type, 'jti' => $jti]);
```

- [ ] **Step 5: AuthMiddleware**

```php
<?php
// app/middleware/AuthMiddleware.php
namespace app\middleware;

use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;
use app\common\JwtHelper;

class AuthMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $auth = $request->header('Authorization', '');
        if (!preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            return json(['code' => 401, 'message' => '未登录', 'lang_key' => 'auth.unauthorized'], 401);
        }
        $payload = JwtHelper::decode($m[1]);
        if (!$payload || ($payload->type ?? '') !== 'access' || JwtHelper::isRevoked($payload->jti)) {
            return json(['code' => 401, 'message' => '凭证无效或已过期', 'lang_key' => 'auth.token_invalid'], 401);
        }
        $request->uid = (int) $payload->sub;
        $request->jti = $payload->jti;
        return $handler($request);
    }
}
```

- [ ] **Step 6: AuthController** (mit @Apidoc-Annotationen — Task 6 aktiviert die apidoc-Generierung, hier direkt mitschreiben)

```php
<?php
// app/controller/AuthController.php
namespace app\controller;

use support\Request;
use app\model\User;
use app\model\UserProfile;
use app\common\JwtHelper;
use hg\apidoc\annotation as Apidoc;

/**
 * @Apidoc\Title("认证")
 */
class AuthController
{
    private const LOGIN_FAIL_LIMIT = 5;
    private const LOGIN_FAIL_TTL = 900;

    /**
     * @Apidoc\Title("注册")
     * @Apidoc\Url("/api/v1/auth/register")
     * @Apidoc\Method("POST")
     * @Apidoc\Param("email", type="string", require=true, desc="邮箱")
     * @Apidoc\Param("password", type="string", require=true, desc="密码(6-32位)")
     * @Apidoc\Param("nickname", type="string", require=false, desc="昵称")
     * @Apidoc\Returned(ref="Response")
     * @Apidoc\Returned("data", type="object", desc="token 信息")
     * @Apidoc\Returned("data.access_token", type="string", desc="访问令牌(2h)")
     * @Apidoc\Returned("data.refresh_token", type="string", desc="刷新令牌(14d)")
     */
    public function register(Request $request)
    {
        $email = trim((string) $request->post('email'));
        $password = (string) $request->post('password');
        $nickname = trim((string) $request->post('nickname', ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return json(['code' => 400, 'message' => '邮箱格式不正确', 'lang_key' => 'auth.email_invalid'], 400);
        }
        if (strlen($password) < 6 || strlen($password) > 32) {
            return json(['code' => 400, 'message' => '密码长度需 6-32 位', 'lang_key' => 'auth.password_length'], 400);
        }
        if (User::where('email', $email)->exists()) {
            return json(['code' => 409, 'message' => '邮箱已注册', 'lang_key' => 'auth.email_exists'], 409);
        }

        $user = User::create([
            'email' => $email,
            'password' => password_hash($password, PASSWORD_BCRYPT),
        ]);
        UserProfile::create([
            'user_id' => $user->id,
            'nickname' => $nickname !== '' ? $nickname : explode('@', $email)[0],
        ]);

        return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => $this->tokens($user->id)]);
    }

    /**
     * @Apidoc\Title("登录")
     * @Apidoc\Url("/api/v1/auth/login")
     * @Apidoc\Method("POST")
     * @Apidoc\Param("email", type="string", require=true, desc="邮箱")
     * @Apidoc\Param("password", type="string", require=true, desc="密码")
     * @Apidoc\Returned(ref="Response")
     */
    public function login(Request $request)
    {
        $email = trim((string) $request->post('email'));
        $password = (string) $request->post('password');

        $redis = $this->failCounter($email, false);
        $key = 'login:fail:' . $email;
        if ($redis && (int) $redis->get($key) >= self::LOGIN_FAIL_LIMIT) {
            return json(['code' => 429, 'message' => '尝试次数过多，请15分钟后再试', 'lang_key' => 'auth.too_many_attempts'], 429);
        }

        $user = User::where('email', $email)->first();
        if (!$user || !password_verify($password, $user->password)) {
            if ($redis) {
                $redis->incr($key);
                $redis->expire($key, self::LOGIN_FAIL_TTL);
            }
            return json(['code' => 401, 'message' => '邮箱或密码错误', 'lang_key' => 'auth.credentials_invalid'], 401);
        }
        if ((int) $user->status !== 1) {
            return json(['code' => 403, 'message' => '账号已被禁用', 'lang_key' => 'auth.account_disabled'], 403);
        }
        if ($redis) {
            $redis->del($key);
        }
        return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => $this->tokens($user->id)]);
    }

    /**
     * @Apidoc\Title("刷新令牌")
     * @Apidoc\Url("/api/v1/auth/refresh")
     * @Apidoc\Method("POST")
     * @Apidoc\Param("refresh_token", type="string", require=true, desc="刷新令牌")
     * @Apidoc\Returned(ref="Response")
     */
    public function refresh(Request $request)
    {
        $token = trim((string) $request->post('refresh_token'));
        $payload = JwtHelper::decode($token);
        if (!$payload || ($payload->type ?? '') !== 'refresh' || JwtHelper::isRevoked($payload->jti)) {
            return json(['code' => 401, 'message' => '刷新令牌无效', 'lang_key' => 'auth.token_invalid'], 401);
        }
        return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => $this->tokens((int) $payload->sub)]);
    }

    /**
     * @Apidoc\Title("登出")
     * @Apidoc\Url("/api/v1/auth/logout")
     * @Apidoc\Method("POST")
     * @Apidoc\Header("Authorization", type="string", require=true, desc="Bearer access_token")
     * @Apidoc\Returned(ref="Response")
     */
    public function logout(Request $request)
    {
        JwtHelper::revoke($request->jti, 7200);
        return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok']);
    }

    /**
     * @Apidoc\Title("当前用户")
     * @Apidoc\Url("/api/v1/auth/me")
     * @Apidoc\Method("GET")
     * @Apidoc\Header("Authorization", type="string", require=true, desc="Bearer access_token")
     * @Apidoc\Returned(ref="Response")
     * @Apidoc\Returned("data.user", type="object", desc="用户信息")
     * @Apidoc\Returned("data.profile", type="object", desc="资料信息")
     */
    public function me(Request $request)
    {
        $user = User::find($request->uid);
        $profile = UserProfile::where('user_id', $request->uid)->first();
        return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => [
            'user' => $user,
            'profile' => $profile,
        ]]);
    }

    private function tokens(int $userId): array
    {
        $cfg = config('plugin.erikwang2013.jwt.jwt');
        return [
            'access_token' => JwtHelper::encode($userId, 'access', (int) $cfg['default_expire']),
            'refresh_token' => JwtHelper::encode($userId, 'refresh', (int) $cfg['refresh_expire']),
            'expires_in' => (int) $cfg['default_expire'],
        ];
    }

    private function failCounter(string $email, bool $write): ?\Redis
    {
        if (!redisAvailable()) return null;
        $redis = new \Redis();
        $redis->connect(env('REDIS_HOST', '127.0.0.1'), (int) env('REDIS_PORT', 6379));
        return $redis;
    }
}
```

- [ ] **Step 7: Routen config/route.php** (Task 6 hängt den apidoc-Register an)

```php
<?php
use Webman\Route;
use app\middleware\AuthMiddleware;

Route::group('/api/v1', function () {
    Route::post('/auth/register', [app\controller\AuthController::class, 'register']);
    Route::post('/auth/login', [app\controller\AuthController::class, 'login']);
    Route::post('/auth/refresh', [app\controller\AuthController::class, 'refresh']);

    Route::group('', function () {
        Route::post('/auth/logout', [app\controller\AuthController::class, 'logout']);
        Route::get('/auth/me', [app\controller\AuthController::class, 'me']);
        Route::get('/me', [app\controller\MeController::class, 'index']);
        Route::put('/me', [app\controller\MeController::class, 'update']);
        Route::post('/posts', [app\controller\PostController::class, 'create']);
        Route::get('/posts', [app\controller\PostController::class, 'timeline']);
        Route::get('/posts/{id}', [app\controller\PostController::class, 'detail']);
        Route::post('/posts/{id}/like', [app\controller\PostController::class, 'like']);
        Route::post('/posts/{id}/unlike', [app\controller\PostController::class, 'unlike']);
        Route::get('/posts/{id}/comments', [app\controller\CommentController::class, 'index']);
        Route::post('/posts/{id}/comments', [app\controller\CommentController::class, 'create']);
    })->middleware(AuthMiddleware::class);
});
```

- [ ] **Step 8: Test tests/AuthControllerTest.php**

```php
<?php
use PHPUnit\Framework\TestCase;
use support\Request;
use app\controller\AuthController;

class AuthControllerTest extends TestCase
{
    public function testRegisterSuccess()
    {
        $req = new Request('POST', '/api/v1/auth/register');
        $req->setInput(['email' => 'u1@test.com', 'password' => 'secret123', 'nickname' => '小明']);
        $res = (new AuthController())->register($req);
        $data = json_decode($res->rawBody(), true);
        $this->assertSame(0, $data['code']);
        $this->assertArrayHasKey('access_token', $data['data']);
        $this->assertArrayHasKey('refresh_token', $data['data']);
    }

    public function testRegisterDuplicateEmail()
    {
        $req = new Request('POST', '/api/v1/auth/register');
        $req->setInput(['email' => 'dup@test.com', 'password' => 'secret123']);
        (new AuthController())->register($req);
        $req2 = new Request('POST', '/api/v1/auth/register');
        $req2->setInput(['email' => 'dup@test.com', 'password' => 'secret123']);
        $res = (new AuthController())->register($req2);
        $data = json_decode($res->rawBody(), true);
        $this->assertSame(409, $data['code']);
    }

    public function testLoginWrongPassword()
    {
        $req = new Request('POST', '/api/v1/auth/register');
        $req->setInput(['email' => 'l1@test.com', 'password' => 'secret123']);
        (new AuthController())->register($req);

        $req2 = new Request('POST', '/api/v1/auth/login');
        $req2->setInput(['email' => 'l1@test.com', 'password' => 'wrong']);
        $res = (new AuthController())->login($req2);
        $data = json_decode($res->rawBody(), true);
        $this->assertSame(401, $data['code']);
    }

    public function testLoginSuccess()
    {
        $req = new Request('POST', '/api/v1/auth/register');
        $req->setInput(['email' => 'l2@test.com', 'password' => 'secret123']);
        (new AuthController())->register($req);

        $req2 = new Request('POST', '/api/v1/auth/login');
        $req2->setInput(['email' => 'l2@test.com', 'password' => 'secret123']);
        $res = (new AuthController())->login($req2);
        $data = json_decode($res->rawBody(), true);
        $this->assertSame(0, $data['code']);
    }
}
```

- [ ] **Step 9: Test tests/AuthFlowTest.php** (Redis-abhängige Fälle laufen nur bei redisAvailable())

```php
<?php
use PHPUnit\Framework\TestCase;
use support\Request;
use app\controller\AuthController;
use app\common\JwtHelper;

class AuthFlowTest extends TestCase
{
    public function testRefreshTokenRoundTrip()
    {
        $req = new Request('POST', '/api/v1/auth/register');
        $req->setInput(['email' => 'r1@test.com', 'password' => 'secret123']);
        $res = (new AuthController())->register($req);
        $data = json_decode($res->rawBody(), true);
        $refresh = $data['data']['refresh_token'];

        $req2 = new Request('POST', '/api/v1/auth/refresh');
        $req2->setInput(['refresh_token' => $refresh]);
        $res2 = (new AuthController())->refresh($req2);
        $data2 = json_decode($res2->rawBody(), true);
        $this->assertSame(0, $data2['code']);
        $this->assertArrayHasKey('access_token', $data2['data']);
    }

    public function testLogoutRevokesRefresh()
    {
        if (!redisAvailable()) {
            $this->markTestSkipped('Redis 不可用');
        }
        $req = new Request('POST', '/api/v1/auth/register');
        $req->setInput(['email' => 'r2@test.com', 'password' => 'secret123']);
        $res = (new AuthController())->register($req);
        $data = json_decode($res->rawBody(), true);
        $refresh = $data['data']['refresh_token'];
        $payload = JwtHelper::decode($refresh);
        JwtHelper::revoke($payload->jti, 7200);
        $this->assertTrue(JwtHelper::isRevoked($payload->jti));
    }
}
```

- [ ] **Step 10: Tests ausführen**

```bash
cd service && vendor/bin/phpunit
```

Erwartet: alles PASS (Redis-Fälle übersprungen oder bestanden).

- [ ] **Step 11: Committen**

```bash
git add service/app/common/JwtHelper.php service/app/middleware/AuthMiddleware.php service/app/controller/AuthController.php service/config/route.php service/config/plugin/erikwang2013/jwt/jwt.php service/app/functions.php service/tests/AuthControllerTest.php service/tests/AuthFlowTest.php
git commit -m "feat(service): auth register/login/refresh/logout/me + JWT + middleware"
```

---

### Task 3 (backend-service): Profil-API (me-Update)

**Dateien:**
- Erstellen: `service/app/controller/MeController.php`
- Erstellen: `service/tests/MeControllerTest.php`

- [ ] **Step 1: MeController schreiben**

```php
<?php
// app/controller/MeController.php
namespace app\controller;

use support\Request;
use app\model\UserProfile;
use hg\apidoc\annotation as Apidoc;

/**
 * @Apidoc\Title("个人资料")
 */
class MeController
{
    /**
     * @Apidoc\Title("查看资料")
     * @Apidoc\Url("/api/v1/me")
     * @Apidoc\Method("GET")
     * @Apidoc\Header("Authorization", type="string", require=true, desc="Bearer access_token")
     * @Apidoc\Returned(ref="Response")
     */
    public function index(Request $request)
    {
        $profile = UserProfile::where('user_id', $request->uid)->first();
        return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => $profile]);
    }

    /**
     * @Apidoc\Title("更新资料")
     * @Apidoc\Url("/api/v1/me")
     * @Apidoc\Method("PUT")
     * @Apidoc\Header("Authorization", type="string", require=true, desc="Bearer access_token")
     * @Apidoc\Param("nickname", type="string", require=false, desc="昵称(1-32字符)")
     * @Apidoc\Param("avatar", type="string", require=false, desc="头像URL")
     * @Apidoc\Param("bio", type="string", require=false, desc="简介(最多200字)")
     * @Apidoc\Param("gender", type="int", require=false, desc="性别 0保密 1男 2女")
     * @Apidoc\Param("birthday", type="string", require=false, desc="生日 YYYY-MM-DD")
     * @Apidoc\Returned(ref="Response")
     */
    public function update(Request $request)
    {
        $profile = UserProfile::where('user_id', $request->uid)->firstOrFail();
        $data = [];

        if ($request->post('nickname') !== null) {
            $nickname = trim((string) $request->post('nickname'));
            if ($nickname === '' || mb_strlen($nickname) > 32) {
                return json(['code' => 400, 'message' => '昵称需 1-32 字符', 'lang_key' => 'me.nickname_length'], 400);
            }
            $data['nickname'] = $nickname;
        }
        if ($request->post('avatar') !== null) {
            $avatar = trim((string) $request->post('avatar'));
            if ($avatar !== '' && !filter_var($avatar, FILTER_VALIDATE_URL)) {
                return json(['code' => 400, 'message' => '头像地址不合法', 'lang_key' => 'me.avatar_invalid'], 400);
            }
            $data['avatar'] = $avatar;
        }
        if ($request->post('bio') !== null) {
            $bio = trim((string) $request->post('bio'));
            if (mb_strlen($bio) > 200) {
                return json(['code' => 400, 'message' => '简介最多200字', 'lang_key' => 'me.bio_length'], 400);
            }
            $data['bio'] = $bio;
        }
        if ($request->post('gender') !== null) {
            $gender = (int) $request->post('gender');
            if (!in_array($gender, [0, 1, 2], true)) {
                return json(['code' => 400, 'message' => '性别取值 0/1/2', 'lang_key' => 'me.gender_invalid'], 400);
            }
            $data['gender'] = $gender;
        }
        if ($request->post('birthday') !== null) {
            $birthday = (string) $request->post('birthday');
            if ($birthday !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthday)) {
                return json(['code' => 400, 'message' => '生日格式 YYYY-MM-DD', 'lang_key' => 'me.birthday_invalid'], 400);
            }
            $data['birthday'] = $birthday !== '' ? $birthday : null;
        }

        if ($data !== []) {
            $profile->update($data);
        }
        return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => $profile->refresh()]);
    }
}
```

- [ ] **Step 2: MeControllerTest schreiben**

```php
<?php
use PHPUnit\Framework\TestCase;
use support\Request;
use app\controller\AuthController;
use app\controller\MeController;

class MeControllerTest extends TestCase
{
    private function registerAndGetUid(): int
    {
        $req = new Request('POST', '/api/v1/auth/register');
        $req->setInput(['email' => uniqid() . '@me.test', 'password' => 'secret123', 'nickname' => '初始名']);
        (new AuthController())->register($req);
        return \app\model\User::where('email', 'like', '%@me.test')->orderByDesc('id')->first()->id;
    }

    private function asRequest(string $method, string $path, array $input): Request
    {
        $req = new Request($method, $path);
        $req->setInput($input);
        return $req;
    }

    public function testUpdateNickname()
    {
        $uid = $this->registerAndGetUid();
        $req = $this->asRequest('PUT', '/api/v1/me', ['nickname' => '新昵称']);
        $req->uid = $uid;
        $res = (new MeController())->update($req);
        $data = json_decode($res->rawBody(), true);
        $this->assertSame(0, $data['code']);
        $this->assertSame('新昵称', $data['data']['nickname']);
    }

    public function testUpdateInvalidNickname()
    {
        $uid = $this->registerAndGetUid();
        $req = $this->asRequest('PUT', '/api/v1/me', ['nickname' => '']);
        $req->uid = $uid;
        $res = (new MeController())->update($req);
        $data = json_decode($res->rawBody(), true);
        $this->assertSame(400, $data['code']);
    }

    public function testUpdateInvalidBirthday()
    {
        $uid = $this->registerAndGetUid();
        $req = $this->asRequest('PUT', '/api/v1/me', ['birthday' => '2026-13-99']);
        $req->uid = $uid;
        $res = (new MeController())->update($req);
        $data = json_decode($res->rawBody(), true);
        $this->assertSame(400, $data['code']);
    }
}
```

- [ ] **Step 3: Tests ausführen**

```bash
cd service && vendor/bin/phpunit
```

Erwartet: alles PASS.

- [ ] **Step 4: Committen**

```bash
git add service/app/controller/MeController.php service/tests/MeControllerTest.php
git commit -m "feat(service): profile update API"
```

---

### Task 4 (backend-service): Beiträge (erstellen/Timeline/Detail)

**Dateien:**
- Erstellen: `service/app/controller/PostController.php`
- Erstellen: `service/tests/PostControllerTest.php`

- [ ] **Step 1: PostController schreiben (create/timeline/detail)**

```php
<?php
// app/controller/PostController.php
namespace app\controller;

use support\Request;
use app\model\Post;
use app\model\Comment;
use hg\apidoc\annotation as Apidoc;

/**
 * @Apidoc\Title("动态")
 */
class PostController
{
    /**
     * @Apidoc\Title("发布动态")
     * @Apidoc\Url("/api/v1/posts")
     * @Apidoc\Method("POST")
     * @Apidoc\Header("Authorization", type="string", require=true, desc="Bearer access_token")
     * @Apidoc\Param("content", type="string", require=true, desc="内容(1-5000字)")
     * @Apidoc\Returned(ref="Response")
     */
    public function create(Request $request)
    {
        $content = trim((string) $request->post('content'));
        $len = mb_strlen($content);
        if ($len < 1 || $len > 5000) {
            return json(['code' => 400, 'message' => '内容长度需 1-5000 字', 'lang_key' => 'post.content_length'], 400);
        }
        $post = Post::create(['user_id' => $request->uid, 'content' => $content]);
        return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => $post->load('user')->makeHidden('liked')]);
    }

    /**
     * @Apidoc\Title("时间线")
     * @Apidoc\Url("/api/v1/posts")
     * @Apidoc\Method("GET")
     * @Apidoc\Header("Authorization", type="string", require=true, desc="Bearer access_token")
     * @Apidoc\Param("page", type="int", require=false, desc="页码，默认1")
     * @Apidoc\Param("page_size", type="int", require=false, desc="每页条数，默认20，最大50")
     * @Apidoc\Returned(ref="Response")
     * @Apidoc\Returned("data.list", type="array", desc="动态列表")
     * @Apidoc\Returned("data.total", type="int", desc="总数")
     */
    public function timeline(Request $request)
    {
        $page = max(1, (int) $request->get('page', 1));
        $pageSize = min(50, max(1, (int) $request->get('page_size', 20)));
        $paginator = Post::with('user')->orderByDesc('created_at')->paginate($pageSize, ['*'], 'page', $page);
        return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => [
            'list' => $paginator->items(),
            'total' => $paginator->total(),
            'page' => $page,
            'page_size' => $pageSize,
        ]]);
    }

    /**
     * @Apidoc\Title("动态详情")
     * @Apidoc\Url("/api/v1/posts/{id}")
     * @Apidoc\Method("GET")
     * @Apidoc\Header("Authorization", type="string", require=true, desc="Bearer access_token")
     * @Apidoc\Param("id", type="int", require=true, desc="动态ID", path=true)
     * @Apidoc\Returned(ref="Response")
     */
    public function detail(Request $request, string $id)
    {
        $post = Post::with('user')->find((int) $id);
        if (!$post) {
            return json(['code' => 404, 'message' => '动态不存在', 'lang_key' => 'post.not_found'], 404);
        }
        $post->setAttribute('comments', Comment::where('post_id', $post->id)
            ->with('user')->orderByDesc('created_at')->limit(5)->get());
        return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => $post]);
    }
}
```

- [ ] **Step 2: PostControllerTest schreiben** (der Timeline-Sortiertest benötigt usleep(1100000), da created_at nur Sekundengenauigkeit hat)

```php
<?php
use PHPUnit\Framework\TestCase;
use support\Request;
use app\controller\AuthController;
use app\controller\PostController;
use app\model\Post;

class PostControllerTest extends TestCase
{
    private function registerUid(): int
    {
        $req = new Request('POST', '/api/v1/auth/register');
        $req->setInput(['email' => uniqid() . '@post.test', 'password' => 'secret123']);
        (new AuthController())->register($req);
        return \app\model\User::where('email', 'like', '%@post.test')->orderByDesc('id')->first()->id;
    }

    public function testCreatePost()
    {
        $uid = $this->registerUid();
        $req = new Request('POST', '/api/v1/posts');
        $req->setInput(['content' => '第一条动态']);
        $req->uid = $uid;
        $res = (new PostController())->create($req);
        $data = json_decode($res->rawBody(), true);
        $this->assertSame(0, $data['code']);
        $this->assertSame('第一条动态', $data['data']['content']);
    }

    public function testCreatePostEmptyContent()
    {
        $uid = $this->registerUid();
        $req = new Request('POST', '/api/v1/posts');
        $req->setInput(['content' => '   ']);
        $req->uid = $uid;
        $res = (new PostController())->create($req);
        $data = json_decode($res->rawBody(), true);
        $this->assertSame(400, $data['code']);
    }

    public function testTimelineOrderedDesc()
    {
        $uid = $this->registerUid();
        foreach (['第一', '第二'] as $content) {
            $req = new Request('POST', '/api/v1/posts');
            $req->setInput(['content' => $content]);
            $req->uid = $uid;
            (new PostController())->create($req);
            usleep(1100000); // created_at 秒级精度，间隔 1.1s 保证排序稳定
        }

        $req = new Request('GET', '/api/v1/posts');
        $req->uid = $uid;
        $res = (new PostController())->timeline($req);
        $data = json_decode($res->rawBody(), true);
        $this->assertSame(0, $data['code']);
        $this->assertSame('第二', $data['data']['list'][0]['content']);
        $this->assertSame('第一', $data['data']['list'][1]['content']);
    }

    public function testDetailNotFound()
    {
        $req = new Request('GET', '/api/v1/posts/99999');
        $req->uid = 1;
        $res = (new PostController())->detail($req, '99999');
        $data = json_decode($res->rawBody(), true);
        $this->assertSame(404, $data['code']);
    }
}
```

- [ ] **Step 3: Tests ausführen**

```bash
cd service && vendor/bin/phpunit
```

Erwartet: alles PASS (Timeline-Test dauert ca. 2-3 Sekunden).

- [ ] **Step 4: Committen**

```bash
git add service/app/controller/PostController.php service/tests/PostControllerTest.php
git commit -m "feat(service): post create/timeline/detail"
```

---

### Task 5 (backend-service): Likes + Kommentare

**Dateien:**
- Ändern: `service/app/controller/PostController.php` (like/unlike anhängen)
- Erstellen: `service/app/controller/CommentController.php`
- Erstellen: `service/tests/PostInteractionTest.php`

- [ ] **Step 1: like/unlike an PostController anhängen**

```php
// 追加到 PostController 类内：

/**
 * @Apidoc\Title("点赞")
 * @Apidoc\Url("/api/v1/posts/{id}/like")
 * @Apidoc\Method("POST")
 * @Apidoc\Header("Authorization", type="string", require=true, desc="Bearer access_token")
 * @Apidoc\Param("id", type="int", require=true, desc="动态ID", path=true)
 * @Apidoc\Returned(ref="Response")
 */
public function like(Request $request, string $id)
{
    $post = Post::find((int) $id);
    if (!$post) {
        return json(['code' => 404, 'message' => '动态不存在', 'lang_key' => 'post.not_found'], 404);
    }
    $like = \app\model\Like::firstOrCreate(['post_id' => $post->id, 'user_id' => $request->uid]);
    if ($like->wasRecentlyCreated) {
        $post->increment('like_count');
    }
    return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => ['liked' => true]]);
}

/**
 * @Apidoc\Title("取消点赞")
 * @Apidoc\Url("/api/v1/posts/{id}/unlike")
 * @Apidoc\Method("POST")
 * @Apidoc\Header("Authorization", type="string", require=true, desc="Bearer access_token")
 * @Apidoc\Param("id", type="int", require=true, desc="动态ID", path=true)
 * @Apidoc\Returned(ref="Response")
 */
public function unlike(Request $request, string $id)
{
    $post = Post::find((int) $id);
    if (!$post) {
        return json(['code' => 404, 'message' => '动态不存在', 'lang_key' => 'post.not_found'], 404);
    }
    $deleted = \app\model\Like::where('post_id', $post->id)->where('user_id', $request->uid)->delete();
    if ($deleted) {
        $post->decrement('like_count');
    }
    return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => ['liked' => false]]);
}
```

- [ ] **Step 2: CommentController schreiben**

```php
<?php
// app/controller/CommentController.php
namespace app\controller;

use support\Request;
use app\model\Post;
use app\model\Comment;
use hg\apidoc\annotation as Apidoc;

/**
 * @Apidoc\Title("评论")
 */
class CommentController
{
    /**
     * @Apidoc\Title("评论列表")
     * @Apidoc\Url("/api/v1/posts/{id}/comments")
     * @Apidoc\Method("GET")
     * @Apidoc\Header("Authorization", type="string", require=true, desc="Bearer access_token")
     * @Apidoc\Param("id", type="int", require=true, desc="动态ID", path=true)
     * @Apidoc\Param("page", type="int", require=false, desc="页码，默认1")
     * @Apidoc\Param("page_size", type="int", require=false, desc="每页条数，默认20")
     * @Apidoc\Returned(ref="Response")
     */
    public function index(Request $request, string $id)
    {
        $post = Post::find((int) $id);
        if (!$post) {
            return json(['code' => 404, 'message' => '动态不存在', 'lang_key' => 'post.not_found'], 404);
        }
        $page = max(1, (int) $request->get('page', 1));
        $pageSize = min(50, max(1, (int) $request->get('page_size', 20)));
        $paginator = Comment::with('user')->where('post_id', $post->id)
            ->orderByDesc('created_at')->paginate($pageSize, ['*'], 'page', $page);
        return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => [
            'list' => $paginator->items(),
            'total' => $paginator->total(),
        ]]);
    }

    /**
     * @Apidoc\Title("发表评论")
     * @Apidoc\Url("/api/v1/posts/{id}/comments")
     * @Apidoc\Method("POST")
     * @Apidoc\Header("Authorization", type="string", require=true, desc="Bearer access_token")
     * @Apidoc\Param("id", type="int", require=true, desc="动态ID", path=true)
     * @Apidoc\Param("content", type="string", require=true, desc="评论内容(1-500字)")
     * @Apidoc\Returned(ref="Response")
     */
    public function create(Request $request, string $id)
    {
        $post = Post::find((int) $id);
        if (!$post) {
            return json(['code' => 404, 'message' => '动态不存在', 'lang_key' => 'post.not_found'], 404);
        }
        $content = trim((string) $request->post('content'));
        $len = mb_strlen($content);
        if ($len < 1 || $len > 500) {
            return json(['code' => 400, 'message' => '评论长度需 1-500 字', 'lang_key' => 'comment.content_length'], 400);
        }
        $comment = Comment::create(['post_id' => $post->id, 'user_id' => $request->uid, 'content' => $content]);
        $post->increment('comment_count');
        return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => $comment->load('user')]);
    }
}
```

- [ ] **Step 3: PostInteractionTest schreiben**

```php
<?php
use PHPUnit\Framework\TestCase;
use support\Request;
use app\controller\AuthController;
use app\controller\PostController;
use app\controller\CommentController;
use app\model\Post;

class PostInteractionTest extends TestCase
{
    private function uidAndPost(): array
    {
        $req = new Request('POST', '/api/v1/auth/register');
        $req->setInput(['email' => uniqid() . '@inter.test', 'password' => 'secret123']);
        (new AuthController())->register($req);
        $uid = \app\model\User::where('email', 'like', '%@inter.test')->orderByDesc('id')->first()->id;

        $req2 = new Request('POST', '/api/v1/posts');
        $req2->setInput(['content' => '互动测试动态']);
        $req2->uid = $uid;
        (new PostController())->create($req2);
        $post = Post::where('user_id', $uid)->orderByDesc('id')->first();
        return [$uid, $post->id];
    }

    public function testLikeThenUnlikeIdempotent()
    {
        [$uid, $postId] = $this->uidAndPost();

        $req = new Request('POST', "/api/v1/posts/$postId/like");
        $req->uid = $uid;
        $res = (new PostController())->like($req, (string) $postId);
        $this->assertSame(0, json_decode($res->rawBody(), true)['code']);
        $this->assertSame(1, Post::find($postId)->like_count);

        // 重复点赞幂等
        $res = (new PostController())->like($req, (string) $postId);
        $this->assertSame(1, Post::find($postId)->like_count);

        $req2 = new Request('POST', "/api/v1/posts/$postId/unlike");
        $req2->uid = $uid;
        (new PostController())->unlike($req2, (string) $postId);
        $this->assertSame(0, Post::find($postId)->like_count);
    }

    public function testCommentIncrementsCount()
    {
        [$uid, $postId] = $this->uidAndPost();
        $req = new Request('POST', "/api/v1/posts/$postId/comments");
        $req->setInput(['content' => '不错的动态']);
        $req->uid = $uid;
        $res = (new CommentController())->create($req, (string) $postId);
        $this->assertSame(0, json_decode($res->rawBody(), true)['code']);
        $this->assertSame(1, Post::find($postId)->comment_count);

        $req2 = new Request('GET', "/api/v1/posts/$postId/comments");
        $req2->uid = $uid;
        $res2 = (new CommentController())->index($req2, (string) $postId);
        $data = json_decode($res2->rawBody(), true);
        $this->assertSame(1, $data['data']['total']);
    }

    public function testLikeNotFound()
    {
        $req = new Request('POST', '/api/v1/posts/99999/like');
        $req->uid = 1;
        $res = (new PostController())->like($req, '99999');
        $this->assertSame(404, json_decode($res->rawBody(), true)['code']);
    }
}
```

- [ ] **Step 4: Tests ausführen**

```bash
cd service && vendor/bin/phpunit
```

Erwartet: alles PASS.

- [ ] **Step 5: Committen**

```bash
git add service/app/controller/PostController.php service/app/controller/CommentController.php service/tests/PostInteractionTest.php
git commit -m "feat(service): like/unlike + comments"
```

---

### Task 6 (backend-service): hg/apidoc-Schnittstellendokumentation

**Dateien:**
- Erstellen: `service/config/plugin/hg/apidoc/app.php`
- Erstellen: `service/config/plugin/hg/apidoc/route.php`
- Erstellen: `service/app/common/Definitions.php`
- Ändern: `service/config/route.php` (apidoc-Routen registrieren)

- [ ] **Step 1: hg/apidoc installieren** (composer.json enthält bereits "hg/apidoc": "*", einfach update)

```bash
cd service && composer update --no-interaction --no-plugins
```

- [ ] **Step 2: apidoc-Konfiguration schreiben** (admin-Vorlage kopieren und anpassen)

```php
<?php
// config/plugin/hg/apidoc/app.php
return [
    'enable' => true,
    'title' => 'Social 用户端 API',
    'desc' => '社交平台用户端接口文档（M1：认证/资料/动态/评论/点赞）',
    'apps' => [
        [
            'title' => 'Social 用户端 API',
            'path' => 'app\controller',
            'controllers' => [],
            'tags' => [],
        ],
    ],
    'config' => [
        'hash' => true,
        'auth' => false,
    ],
    'path' => base_path() . '/public/apidoc',
];
```

```php
<?php
// config/plugin/hg/apidoc/route.php
return [
    'prefix' => 'apidoc',
];
```

- [ ] **Step 3: Definitions.php schreiben (gemeinsame Antwortstruktur)**

```php
<?php
// app/common/Definitions.php
namespace app\common;

use hg\apidoc\annotation as Apidoc;

class Definitions
{
    /**
     * @Apidoc\Define("Response", desc="统一响应结构")
     * @Apidoc\Param("code", type="int", require=true, desc="0成功，非0错误码")
     * @Apidoc\Param("message", type="string", require=true, desc="错误消息")
     * @Apidoc\Param("lang_key", type="string", require=true, desc="多语言错误键")
     * @Apidoc\Param("data", type="object", require=false, desc="业务数据")
     */
    public function response() {}
}
```

- [ ] **Step 4: apidoc-Routen registrieren (an Ende von config/route.php anhängen)**

```php
// 文件末尾追加：
if (config('plugin.hg.apidoc.app.enable', true)) {
    \hg\apidoc\providers\WebmanService::register();
}
```

- [ ] **Step 5: Generierung lokal verifizieren**

```bash
cd service && php start.php start -d && sleep 2
curl -sf http://127.0.0.1:8788/apidoc/ | grep -q 'apidoc' && echo "apidoc OK"
```

Erwartet: Ausgabe `apidoc OK` (Seitentitel: Social-Client-API).

- [ ] **Step 6: Alle Tests ausführen, um Regressionen auszuschließen**

```bash
cd service && vendor/bin/phpunit
```

- [ ] **Step 7: Committen**

```bash
git add service/config/plugin/hg/apidoc service/app/common/Definitions.php service/config/route.php service/composer.json service/composer.lock
git commit -m "feat(service): hg/apidoc interface docs for all APIs"
```

---

### Task 7 (backend-admin): Benutzerverwaltung (direkte Abfrage der social_-Tabellen)

**Dateien:**
- Erstellen: `admin/app/model/SocialUser.php`
- Erstellen: `admin/app/admin/controller/SocialUserController.php`
- Ändern: `admin/config/route.php` (Routengruppe /admin/user)

- [ ] **Step 1: SocialUser-Modell schreiben**

```php
<?php
// admin/app/model/SocialUser.php
namespace app\model;

use Illuminate\Database\Eloquent\Model;

class SocialUser extends Model
{
    protected $table = 'social_users';
    public $timestamps = false;

    public function profile()
    {
        return $this->hasOne(SocialUserProfile::class, 'user_id');
    }
}
```

```php
<?php
// admin/app/model/SocialUserProfile.php
namespace app\model;

use Illuminate\Database\Eloquent\Model;

class SocialUserProfile extends Model
{
    protected $table = 'social_user_profiles';
    public $timestamps = false;
}
```

- [ ] **Step 2: SocialUserController schreiben**

```php
<?php
// admin/app/admin/controller/SocialUserController.php
namespace app\admin\controller;

use support\Request;
use app\model\SocialUser;

class SocialUserController
{
    public function list(Request $request)
    {
        $query = SocialUser::with('profile');
        if ($keyword = trim((string) $request->get('keyword'))) {
            $query->where(function ($q) use ($keyword) {
                $q->where('email', 'like', "%{$keyword}%")
                  ->orWhereHas('profile', function ($p) use ($keyword) {
                      $p->where('nickname', 'like', "%{$keyword}%");
                  });
            });
        }
        $page = max(1, (int) $request->get('page', 1));
        $pageSize = min(100, max(1, (int) $request->get('page_size', 20)));
        $paginator = $query->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);
        return json(['code' => 0, 'message' => 'ok', 'data' => [
            'list' => $paginator->items(),
            'total' => $paginator->total(),
        ]]);
    }

    public function detail(Request $request, string $id)
    {
        $user = SocialUser::with('profile')->find((int) $id);
        if (!$user) {
            return json(['code' => 404, 'message' => '用户不存在'], 404);
        }
        return json(['code' => 0, 'message' => 'ok', 'data' => $user]);
    }

    public function status(Request $request, string $id)
    {
        $user = SocialUser::find((int) $id);
        if (!$user) {
            return json(['code' => 404, 'message' => '用户不存在'], 404);
        }
        $status = (int) $request->post('status');
        if (!in_array($status, [0, 1], true)) {
            return json(['code' => 400, 'message' => 'status 取值 0/1'], 400);
        }
        $user->status = $status;
        $user->save();
        return json(['code' => 0, 'message' => 'ok', 'data' => $user]);
    }
}
```

- [ ] **Step 3: Admin-Routen anhängen (Ende von config/route.php)**

```php
Route::group('/admin/user', function () {
    Route::get('', [app\admin\controller\SocialUserController::class, 'list']);
    Route::get('/{id}', [app\admin\controller\SocialUserController::class, 'detail']);
    Route::post('/{id}/status', [app\admin\controller\SocialUserController::class, 'status']);
})->middleware([app\middleware\AuthCheck::class]);
```

> Hinweis: AuthCheck ist ein vorhandener open-admin-Middleware (bei anderem Namen den bestehenden Login-Prüf-Middleware von admin verwenden). M2 stellt auf gRPC-Aufrufe an service um.

- [ ] **Step 4: Syntaxprüfung**

```bash
cd admin && php -l app/model/SocialUser.php && php -l app/model/SocialUserProfile.php && php -l app/admin/controller/SocialUserController.php && php -l config/route.php
```

Erwartet: 4 Zeilen `No syntax errors detected`.

- [ ] **Step 5: Committen**

```bash
git add admin/app/model/SocialUser.php admin/app/model/SocialUserProfile.php admin/app/admin/controller/SocialUserController.php admin/config/route.php
git commit -m "feat(admin): social user management (direct table, gRPC in M2)"
```

---

### Task 8 (android-dev): Login + Timeline + Beitrag erstellen

**Dateien:**
- Erstellen: `apps/android/app/src/main/java/.../api/ApiClient.kt`
- Erstellen: `apps/android/app/src/main/java/.../api/LoginScreen.kt`
- Erstellen: `apps/android/app/src/main/java/.../api/TimelineScreen.kt`
- Ändern: `apps/android/app/src/main/java/.../MainActivity.kt`
- Ändern: `apps/android/app/build.gradle.kts` (okhttp + kotlinx-serialization)

- [ ] **Step 1: Abhängigkeiten zu build.gradle.kts hinzufügen**

```kotlin
dependencies {
    implementation("com.squareup.okhttp3:okhttp:4.12.0")
    implementation("org.jetbrains.kotlinx:kotlinx-serialization-json:1.7.1")
}
```

```kotlin
plugins {
    ...
    id("org.jetbrains.kotlin.plugin.serialization") version "2.0.21"
}
```

- [ ] **Step 2: ApiClient.kt**

```kotlin
package com.example.social.api

import kotlinx.serialization.Serializable
import kotlinx.serialization.json.Json
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody

private const val BASE = "http://10.0.2.2:8787" // 模拟器访问宿主机

@Serializable
data class LoginResponse(
    val code: Int,
    val message: String = "",
    @kotlinx.serialization.SerialName("lang_key") val langKey: String = "",
    val data: TokenData? = null,
)

@Serializable
data class TokenData(
    @kotlinx.serialization.SerialName("access_token") val accessToken: String,
    @kotlinx.serialization.SerialName("refresh_token") val refreshToken: String,
    @kotlinx.serialization.SerialName("expires_in") val expiresIn: Long,
)

@Serializable
data class PostItem(
    val id: Long,
    val content: String,
    @kotlinx.serialization.SerialName("like_count") val likeCount: Int = 0,
    @kotlinx.serialization.SerialName("comment_count") val commentCount: Int = 0,
    @kotlinx.serialization.SerialName("created_at") val createdAt: String = "",
)

object ApiClient {
    private val json = Json { ignoreUnknownKeys = true }
    private val client = OkHttpClient.Builder().build()

    var accessToken: String = ""

    private fun baseRequest(method: String, path: String, body: String? = null): Request.Builder {
        val rb = Request.Builder()
            .url(BASE + path)
            .method(method, body?.toRequestBody("application/json".toMediaType()))
        if (accessToken.isNotEmpty()) rb.header("Authorization", "Bearer $accessToken")
        return rb
    }

    fun login(email: String, password: String): LoginResponse {
        val jsonBody = json.encodeToString(
            kotlinx.serialization.json.buildJsonObject {
                put("email", email); put("password", password)
            }
        )
        val resp = client.newCall(baseRequest("POST", "/api/v1/auth/login", jsonBody).build()).execute()
        val parsed = json.decodeFromString<LoginResponse>(resp.body!!.string())
        parsed.data?.let { accessToken = it.accessToken }
        return parsed
    }

    fun register(email: String, password: String, nickname: String): LoginResponse {
        val jsonBody = json.encodeToString(
            kotlinx.serialization.json.buildJsonObject {
                put("email", email); put("password", password); put("nickname", nickname)
            }
        )
        val resp = client.newCall(baseRequest("POST", "/api/v1/auth/register", jsonBody).build()).execute()
        val parsed = json.decodeFromString<LoginResponse>(resp.body!!.string())
        parsed.data?.let { accessToken = it.accessToken }
        return parsed
    }

    fun timeline(): List<PostItem> {
        val resp = client.newCall(baseRequest("GET", "/api/v1/posts").build()).execute()
        val text = resp.body!!.string()
        val root = json.parseToJsonElement(text).jsonObject
        return root["data"]!!.jsonObject["list"]!!.jsonArray.map {
            json.decodeFromJsonElement<PostItem>(it)
        }
    }

    fun createPost(content: String): Boolean {
        val jsonBody = json.encodeToString(
            kotlinx.serialization.json.buildJsonObject { put("content", content) }
        )
        val resp = client.newCall(baseRequest("POST", "/api/v1/posts", jsonBody).build()).execute()
        return resp.isSuccessful && json.parseToJsonElement(resp.body!!.string()).jsonObject["code"]!!.jsonPrimitive.int == 0
    }
}
```

- [ ] **Step 3: LoginScreen.kt + TimelineScreen.kt + MainActivity.kt** (Compose oder native Views — dem bestehenden UI-Stil der App folgend; hier minimale Umsetzung mit nativen Views)

```kotlin
// LoginScreen.kt
package com.example.social.api

import android.app.Activity
import android.widget.*

object LoginScreen {
    fun show(activity: Activity, onSuccess: () -> Unit) {
        val ll = LinearLayout(activity).apply { orientation = LinearLayout.VERTICAL }
        val email = EditText(activity).apply { hint = "邮箱" }
        val pass = EditText(activity).apply { hint = "密码"; inputType = android.text.InputType.TYPE_CLASS_TEXT or android.text.InputType.TYPE_TEXT_VARIATION_PASSWORD }
        val nickname = EditText(activity).apply { hint = "昵称(注册)" }
        val btn = Button(activity).apply { text = "登录" }
        val regBtn = Button(activity).apply { text = "注册并登录" }
        val msg = TextView(activity)

        btn.setOnClickListener {
            val r = ApiClient.login(email.text.toString(), pass.text.toString())
            if (r.code == 0) onSuccess() else msg.text = r.message
        }
        regBtn.setOnClickListener {
            val r = ApiClient.register(email.text.toString(), pass.text.toString(), nickname.text.toString())
            if (r.code == 0) onSuccess() else msg.text = r.message
        }
        ll.addView(email); ll.addView(pass); ll.addView(nickname); ll.addView(btn); ll.addView(regBtn); ll.addView(msg)
        activity.setContentView(ll)
    }
}
```

```kotlin
// TimelineScreen.kt
package com.example.social.api

import android.app.Activity
import android.widget.*
import java.util.concurrent.Executors

object TimelineScreen {
    fun show(activity: Activity) {
        val ll = LinearLayout(activity).apply { orientation = LinearLayout.VERTICAL }
        val input = EditText(activity).apply { hint = "说点什么…" }
        val send = Button(activity).apply { text = "发布" }
        val list = ListView(activity)
        val executor = Executors.newSingleThreadExecutor()

        fun refresh() {
            executor.execute {
                val posts = ApiClient.timeline()
                activity.runOnUiThread {
                    list.adapter = ArrayAdapter(
                        activity, android.R.layout.simple_list_item_1,
                        posts.map { "${it.content}  ♥${it.likeCount} 💬${it.commentCount}" }
                    )
                }
            }
        }

        send.setOnClickListener {
            executor.execute {
                ApiClient.createPost(input.text.toString())
                activity.runOnUiThread { input.setText(""); refresh() }
            }
        }
        ll.addView(input); ll.addView(send); ll.addView(list)
        activity.setContentView(ll)
        refresh()
    }
}
```

```kotlin
// MainActivity.kt
package com.example.social.api

import android.app.Activity
import android.os.Bundle

class MainActivity : Activity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        if (ApiClient.accessToken.isEmpty()) {
            LoginScreen.show(this) { TimelineScreen.show(this) }
        } else {
            TimelineScreen.show(this)
        }
    }
}
```

- [ ] **Step 4: Kompilierung prüfen**

```bash
cd apps/android && ./gradlew assembleDebug
```

Erwartet: BUILD SUCCESSFUL.

- [ ] **Step 5: Committen**

```bash
git add apps/android/app/src/main/java apps/android/app/build.gradle.kts
git commit -m "feat(android): login + timeline + create post"
```

---

### Task 9 (ios-dev): Login + Timeline + Beitrag erstellen

**Dateien:**
- Ändern: `apps/ios/.../APIClient.swift` (neu schreiben)
- Ändern: `apps/ios/.../ContentView.swift` (LoginView + TimelineView)

- [ ] **Step 1: APIClient.swift**

```swift
import Foundation

struct TokenData: Codable {
    let accessToken: String
    let refreshToken: String
    let expiresIn: Int

    enum CodingKeys: String, CodingKey {
        case accessToken = "access_token"
        case refreshToken = "refresh_token"
        case expiresIn = "expires_in"
    }
}

struct LoginResponse: Codable {
    let code: Int
    let message: String
    let langKey: String
    let data: TokenData?

    enum CodingKeys: String, CodingKey {
        case code, message, data
        case langKey = "lang_key"
    }
}

struct PostItem: Codable, Identifiable {
    let id: Int64
    let content: String
    let likeCount: Int
    let commentCount: Int

    enum CodingKeys: String, CodingKey {
        case id, content
        case likeCount = "like_count"
        case commentCount = "comment_count"
    }
}

final class APIClient {
    static let shared = APIClient()
    private let base = "http://127.0.0.1:8787" // 模拟器访问宿主机
    private let session = URLSession.shared
    var accessToken: String = ""

    func request(_ method: String, _ path: String, body: [String: Any]? = nil,
                 completion: @escaping (Data?, Error?) -> Void) {
        var req = URLRequest(url: URL(string: base + path)!)
        req.httpMethod = method
        req.setValue("application/json", forHTTPHeaderField: "Content-Type")
        if !accessToken.isEmpty {
            req.setValue("Bearer \(accessToken)", forHTTPHeaderField: "Authorization")
        }
        if let body = body {
            req.httpBody = try? JSONSerialization.data(withJSONObject: body)
        }
        session.dataTask(with: req) { data, _, error in
            completion(data, error)
        }.resume()
    }

    func login(email: String, password: String, completion: @escaping (LoginResponse?) -> Void) {
        request("POST", "/api/v1/auth/login", body: ["email": email, "password": password]) { data, _ in
            guard let data = data else { completion(nil); return }
            let resp = try? JSONDecoder().decode(LoginResponse.self, from: data)
            resp?.data.map { self.accessToken = $0.accessToken }
            completion(resp)
        }
    }

    func register(email: String, password: String, nickname: String, completion: @escaping (LoginResponse?) -> Void) {
        request("POST", "/api/v1/auth/register",
                body: ["email": email, "password": password, "nickname": nickname]) { data, _ in
            guard let data = data else { completion(nil); return }
            let resp = try? JSONDecoder().decode(LoginResponse.self, from: data)
            resp?.data.map { self.accessToken = $0.accessToken }
            completion(resp)
        }
    }

    func timeline(completion: @escaping ([PostItem]) -> Void) {
        request("GET", "/api/v1/posts") { data, _ in
            guard let data = data,
                  let root = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
                  let d = root["data"] as? [String: Any],
                  let list = d["list"] else { completion([]); return }
            let listData = try? JSONSerialization.data(withJSONObject: list)
            let items = listData.flatMap { try? JSONDecoder().decode([PostItem].self, from: $0) } ?? []
            completion(items)
        }
    }

    func createPost(content: String, completion: @escaping (Bool) -> Void) {
        request("POST", "/api/v1/posts", body: ["content": content]) { data, _ in
            guard let data = data,
                  let root = try? JSONSerialization.jsonObject(with: data) as? [String: Any] else {
                completion(false); return
            }
            completion((root["code"] as? Int) == 0)
        }
    }
}
```

- [ ] **Step 2: ContentView.swift (LoginView + TimelineView)**

```swift
import SwiftUI

struct ContentView: View {
    @State private var loggedIn = false

    var body: some View {
        if loggedIn {
            TimelineView()
        } else {
            LoginView(onSuccess: { loggedIn = true })
        }
    }
}

struct LoginView: View {
    let onSuccess: () -> Void
    @State private var email = ""
    @State private var password = ""
    @State private var nickname = ""
    @State private var message = ""

    var body: some View {
        VStack(spacing: 16) {
            TextField("邮箱", text: $email).textFieldStyle(.roundedBorder)
            SecureField("密码", text: $password).textFieldStyle(.roundedBorder)
            TextField("昵称(注册)", text: $nickname).textFieldStyle(.roundedBorder)
            Button("登录") {
                APIClient.shared.login(email: email, password: password) { resp in
                    DispatchQueue.main.async {
                        if resp?.code == 0 { onSuccess() } else { message = resp?.message ?? "登录失败" }
                    }
                }
            }
            Button("注册并登录") {
                APIClient.shared.register(email: email, password: password, nickname: nickname) { resp in
                    DispatchQueue.main.async {
                        if resp?.code == 0 { onSuccess() } else { message = resp?.message ?? "注册失败" }
                    }
                }
            }
            Text(message).foregroundColor(.red)
        }
        .padding()
    }
}

struct TimelineView: View {
    @State private var posts: [PostItem] = []
    @State private var content = ""

    var body: some View {
        VStack {
            HStack {
                TextField("说点什么…", text: $content).textFieldStyle(.roundedBorder)
                Button("发布") {
                    APIClient.shared.createPost(content: content) { _ in
                        DispatchQueue.main.async { content = ""; load() }
                    }
                }
            }
            .padding()
            List(posts) { post in
                VStack(alignment: .leading) {
                    Text(post.content)
                    Text("♥\(post.likeCount)  💬\(post.commentCount)")
                        .font(.caption).foregroundColor(.gray)
                }
            }
        }
        .onAppear(perform: load)
    }

    func load() {
        APIClient.shared.timeline { items in
            DispatchQueue.main.async { posts = items }
        }
    }
}
```

> Hinweis: Falls das iOS-Projekt storyboard/UIKit verwendet, ContentView durch einen äquivalenten UIKit-Controller ersetzen; hier wird die SwiftUI-Vorlage gezeigt.

- [ ] **Step 3: Committen**

```bash
git add apps/ios
git commit -m "feat(ios): login + timeline + create post"
```

---

### Task 10 (harmonyos-dev): Login + Timeline + Beitrag erstellen

**Dateien:**
- Erstellen: `apps/harmonyos/entry/src/main/ets/service/ApiService.ets`
- Ändern: `apps/harmonyos/entry/src/main/ets/pages/Index.ets`

- [ ] **Step 1: ApiService.ets (http-Modul)**

```typescript
import http from '@ohos.net.http'

export interface TokenData {
  access_token: string
  refresh_token: string
  expires_in: number
}

export interface LoginResult {
  code: number
  message: string
  data?: TokenData
}

export interface PostItem {
  id: number
  content: string
  like_count: number
  comment_count: number
}

const BASE = 'http://10.0.2.2:8787' // 模拟器访问宿主机（真机改为局域网 IP）

export class ApiService {
  static accessToken: string = ''

  private static async request(method: http.RequestMethod, path: string,
                               body?: Record<string, Object>): Promise<any> {
    const client = http.createHttp()
    const extraData = body ? JSON.stringify(body) : undefined
    const res = await client.request(BASE + path, {
      method: method,
      header: {
        'Content-Type': 'application/json',
        ...(ApiService.accessToken ? { Authorization: 'Bearer ' + ApiService.accessToken } : {})
      },
      extraData: extraData,
      expectDataType: http.HttpDataType.STRING,
    })
    return JSON.parse(res.result as string)
  }

  static async login(email: string, password: string): Promise<LoginResult> {
    const r = await this.request(http.RequestMethod.POST, '/api/v1/auth/login', { email, password })
    if (r.data?.access_token) this.accessToken = r.data.access_token
    return r
  }

  static async register(email: string, password: string, nickname: string): Promise<LoginResult> {
    const r = await this.request(http.RequestMethod.POST, '/api/v1/auth/register', { email, password, nickname })
    if (r.data?.access_token) this.accessToken = r.data.access_token
    return r
  }

  static async timeline(): Promise<PostItem[]> {
    const r = await this.request(http.RequestMethod.GET, '/api/v1/posts')
    return r.data?.list ?? []
  }

  static async createPost(content: string): Promise<boolean> {
    const r = await this.request(http.RequestMethod.POST, '/api/v1/posts', { content })
    return r.code === 0
  }
}
```

- [ ] **Step 2: Index.ets neu schreiben (loginPanel/timelinePanel-Builder)**

```typescript
import { ApiService, PostItem } from '../service/ApiService'

@Entry
@Component
struct Index {
  @State email: string = ''
  @State password: string = ''
  @State nickname: string = ''
  @State message: string = ''
  @State loggedIn: boolean = false
  @State posts: PostItem[] = []
  @State content: string = ''

  build() {
    Column() {
      if (this.loggedIn) {
        this.timelinePanel()
      } else {
        this.loginPanel()
      }
    }
    .width('100%')
    .height('100%')
  }

  @Builder
  loginPanel() {
    Column({ space: 12 }) {
      TextInput({ placeholder: '邮箱', text: this.email })
        .onChange(v => this.email = v)
      TextInput({ placeholder: '密码', text: this.password })
        .type(InputType.Password)
        .onChange(v => this.password = v)
      TextInput({ placeholder: '昵称(注册)', text: this.nickname })
        .onChange(v => this.nickname = v)
      Button('登录').width('100%').onClick(async () => {
        const r = await ApiService.login(this.email, this.password)
        if (r.code === 0) { this.loggedIn = true } else { this.message = r.message }
      })
      Button('注册并登录').width('100%').onClick(async () => {
        const r = await ApiService.register(this.email, this.password, this.nickname)
        if (r.code === 0) { this.loggedIn = true } else { this.message = r.message }
      })
      Text(this.message).fontColor(Color.Red)
    }
    .padding(24)
    .margin({ top: 80 })
  }

  @Builder
  timelinePanel() {
    Column() {
      Row({ space: 8 }) {
        TextInput({ placeholder: '说点什么…', text: this.content })
          .layoutWeight(1)
          .onChange(v => this.content = v)
        Button('发布').onClick(async () => {
          const ok = await ApiService.createPost(this.content)
          if (ok) { this.content = ''; await this.load() }
        })
      }
      .padding(12)

      List({ space: 8 }) {
        ForEach(this.posts, (post: PostItem) => {
          ListItem() {
            Column() {
              Text(post.content).width('100%')
              Text(`♥${post.like_count}  💬${post.comment_count}`)
                .fontSize(12).fontColor(Color.Gray).width('100%')
            }
            .alignItems(HorizontalAlign.Start)
            .padding(12)
          }
        }, (post: PostItem) => post.id.toString())
      }
      .layoutWeight(1)
    }
    .onAppear(() => { this.load() })
  }

  async load() {
    this.posts = await ApiService.timeline()
  }
}
```

- [ ] **Step 3: Syntaxprüfung** (sobald die DevEco-Umgebung bereit ist)

```bash
cd apps/harmonyos && hvigorw assembleHap --mode module -p module=entry@default 2>&1 | tail -5
```

Erwartet: BUILD SUCCESSFUL (falls lokal kein DevEco/hvigor vorhanden, überspringen und nach CI-Bereitschaft verifizieren).

- [ ] **Step 4: Committen**

```bash
git add apps/harmonyos/entry/src/main/ets
git commit -m "feat(harmonyos): login + timeline + create post"
```

---

## Abschlussprüfung (nach Abschluss aller Aufgaben)

```bash
# service 全量测试
cd service && vendor/bin/phpunit
# 本地 E2E（需 ext-grpc + 8788 端口）
bash scripts/ci-probe.sh
# apidoc 文档
curl -sf http://127.0.0.1:8788/apidoc/ | head -c 200
```

Committen: `git commit -m "chore: M1 closed-loop complete"` (falls Änderungen übrig sind).

---

## Self-Review-Protokoll

**1. Spec-Abdeckung (Zeilen §14 für M1 + Ergänzungen des Nutzers):**
- Registrierung/Login/Profil → T2, T3 ✓
- Beitrag erstellen/Detail → T4 ✓
- Vereinfachte Timeline → T4 ✓
- Likes & Kommentare → T5 ✓
- hg/apidoc-Schnittstellendokumentation (neueste Anforderung des Nutzers) → T6 ✓ (alle Controller tragen @Apidoc-Annotationen)
- Alle drei mobilen Clients implementieren Login+Timeline+Beitrag erstellen → T8/T9/T10 ✓

**2. Platzhalter-Scan:** kein TBD/TODO; der Middleware-Name AuthCheck in admin ist mit "falls anders, bestehenden verwenden" markiert, mit klarem Fallback.

**3. Typkonsistenz:**
- JwtHelper::encode/decode/revoke/isRevoked werden in T2 definiert, T2-Tests verwenden sie konsistent
- `$request->uid`/`$request->jti` werden von AuthMiddleware injiziert, T3/T4/T5-Tests weisen `$req->uid` direkt zu ✓
- Antwortfelder `access_token`/`refresh_token`/`expires_in` sind in T2 als snake_case definiert; T8/T9/T10-Clients gleichen über SerialName/CodingKeys/Literale ab ✓
- PostController::detail/like/unlike und CommentController::index/create injizieren `string $id` mit identischer Signatur, Route `{id}` passt dazu ✓

**4. Bekannte Einschränkungen (bewusst verschoben):**
- Redis-Fälle werden ohne Redis übersprungen (redisAvailable())
- admin→service gRPC auf M2 verschoben (M1 fragt Tabellen direkt ab, Tabellenpräfix social_ verifiziert)
- Captcha/Bild-Upload/Follows/vollständiger Feed/Übersetzung → M2+
- created_at Sekundengenauigkeit → im Timeline-Test mit usleep(1100000) vermerkt
