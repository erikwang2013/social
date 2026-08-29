<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace tests;

use Aws\Command;
use Aws\MockHandler;
use Aws\Result;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use support\Request;
use app\admin\controller\StorageProviderController;
use app\common\Storage;
use app\model\StorageProvider;

/**
 * M6c1 CDN 存储服务商控制器单测
 * - 默认连接以 sqlite :memory: 隔离（独立 Capsule 覆盖 Eloquent resolver，不触真实 MySQL）
 * - CRUD + key/secret 不回显 + 活动服务商保护 + activate（local 直通 / s3 验签 MockHandler）
 */
class StorageProviderControllerTest extends TestCase
{
    private static ?\Illuminate\Database\ConnectionResolverInterface $oldResolver = null;

    public static function setUpBeforeClass(): void
    {
        class_exists('support\Db');
        self::$oldResolver = Model::getConnectionResolver();
        $capsule = new Capsule();
        // 与生产同构：前缀 erik_ + 模型表名 storage_provider → erik_storage_provider
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'erik_'], 'mysql');
        $capsule->getDatabaseManager()->setDefaultConnection('mysql');
        $capsule->bootEloquent();

        $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
        $schema->create('storage_provider', function ($t) {
            $t->unsignedBigInteger('id')->primary();
            $t->string('name', 50);
            $t->string('driver', 10)->default('s3');
            $t->string('endpoint', 255)->default('');
            $t->string('region', 50)->default('auto');
            $t->string('key', 255)->default('');
            $t->string('secret', 255)->default('');
            $t->string('bucket', 100)->default('');
            $t->string('cdn_url', 255)->default('');
            $t->tinyInteger('enabled')->default(1);
            $t->tinyInteger('is_active')->default(0);
            $t->timestamps();
        });
        StorageProvider::forceCreate([
            'id' => 30000000000000001, 'name' => '本地存储', 'driver' => 'local', 'is_active' => 1,
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        Model::setConnectionResolver(self::$oldResolver);
    }

    protected function setUp(): void
    {
        StorageProvider::where('id', '!=', 30000000000000001)->delete();
        StorageProvider::where('id', 30000000000000001)->update([
            'driver' => 'local', 'is_active' => 1, 'endpoint' => '', 'bucket' => '', 'cdn_url' => '',
        ]);
        Storage::$handler = null;
    }

    private function get(string $uri): Request
    {
        return new Request("GET $uri HTTP/1.1\r\nHost: localhost\r\n\r\n");
    }

    private function post(string $uri, array $data): Request
    {
        $body = http_build_query($data);
        return new Request(
            "POST $uri HTTP/1.1\r\nHost: localhost\r\n" .
            "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($body) . "\r\n\r\n$body"
        );
    }

    private function put(string $uri, array $data): Request
    {
        $body = http_build_query($data);
        return new Request(
            "PUT $uri HTTP/1.1\r\nHost: localhost\r\n" .
            "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($body) . "\r\n\r\n$body"
        );
    }

    private function body(mixed $res): array
    {
        return json_decode($res->rawBody(), true);
    }

    private function ctrl(): StorageProviderController
    {
        return new StorageProviderController();
    }

    private function createS3Provider(string $name = 'R2 测试'): StorageProvider
    {
        return StorageProvider::forceCreate([
            'id' => SnowflakeForTest::next(),
            'name' => $name, 'driver' => 's3',
            'endpoint' => 'https://x.r2.cloudflarestorage.com', 'region' => 'auto',
            'key' => 'ak123', 'secret' => 'sk456', 'bucket' => 'media', 'cdn_url' => 'https://cdn.example.com',
        ]);
    }

    #[Test]
    public function test_list_contains_seed_and_hides_secrets(): void
    {
        $res = $this->body($this->ctrl()->index($this->get('/admin/storage/providers')));
        $this->assertSame(0, $res['code']);
        $this->assertNotEmpty($res['data']);
        $this->assertStringContainsString('本地存储', json_encode($res['data'], JSON_UNESCAPED_UNICODE), '种子服务商应在列表');
        $this->assertStringNotContainsString('"key"', json_encode($res['data']), 'key/secret 永不回显');
    }

    #[Test]
    public function test_store_validates_driver_and_s3_required_fields(): void
    {
        $bad = $this->body($this->ctrl()->store($this->post('/admin/storage/providers', ['driver' => 'ftp', 'name' => 'x'])));
        $this->assertSame(422, $bad['code']);

        $missing = $this->body($this->ctrl()->store($this->post('/admin/storage/providers', [
            'name' => 'R2', 'driver' => 's3', 'endpoint' => 'https://x.r2.cloudflarestorage.com',
        ])));
        $this->assertSame(422, $missing['code'], 's3 缺 bucket/cdn_url 应拒绝');
    }

    #[Test]
    public function test_store_create_update_delete_flow(): void
    {
        $res = $this->body($this->ctrl()->store($this->post('/admin/storage/providers', [
            'name' => 'R2 测试', 'driver' => 's3', 'endpoint' => 'https://x.r2.cloudflarestorage.com',
            'region' => 'auto', 'key' => 'ak123', 'secret' => 'sk456', 'bucket' => 'media',
            'cdn_url' => 'https://cdn.example.com',
        ])));
        $this->assertSame(0, $res['code']);
        $id = (int) $res['data']['id'];

        $list = $this->body($this->ctrl()->index($this->get('/admin/storage/providers')));
        $this->assertCount(2, $list['data']);
        $this->assertStringNotContainsString('"ak123"', json_encode($list), '明文 key 不得出现在任何响应');

        $upd = $this->body($this->ctrl()->update($this->put("/admin/storage/providers/$id", ['name' => 'R2 改名', 'bucket' => 'media2']), (string) $id));
        $this->assertSame(0, $upd['code']);
        $this->assertSame('R2 改名', StorageProvider::find($id)->name);
        $this->assertSame('media2', StorageProvider::find($id)->bucket);

        $del = $this->body($this->ctrl()->destroy($this->post("/admin/storage/providers/$id/delete", []), (string) $id));
        $this->assertSame(0, $del['code']);
        $this->assertNull(StorageProvider::find($id));
    }

    #[Test]
    public function test_destroy_active_provider_rejected(): void
    {
        $active = StorageProvider::where('is_active', 1)->first();
        $res = $this->body($this->ctrl()->destroy($this->post('/admin/storage/providers/active/delete', []), (string) $active->id));
        $this->assertNotSame(0, $res['code'], '活动服务商不可删除');
        $this->assertNotNull(StorageProvider::find($active->id));
    }

    #[Test]
    public function test_activate_local_provider_flips_active(): void
    {
        $p = StorageProvider::where('id', 30000000000000001)->first();
        $res = $this->body($this->ctrl()->activate($this->post("/admin/storage/providers/{$p->id}/activate", []), (string) $p->id));
        $this->assertSame(0, $res['code']);
        $this->assertSame(1, (int) StorageProvider::find($p->id)->is_active);
        $this->assertSame(0, (int) StorageProvider::where('is_active', 1)->where('id', '!=', $p->id)->count(), '其他服务商应全部失活');
    }

    #[Test]
    public function test_activate_s3_provider_verifies_connection(): void
    {
        $p = $this->createS3Provider('R2 真桶');

        $mock = new MockHandler();
        $mock->append(new Result(['Contents' => []]));
        Storage::$handler = $mock;
        $ok = $this->body($this->ctrl()->activate($this->post("/admin/storage/providers/{$p->id}/activate", []), (string) $p->id));
        $this->assertSame(0, $ok['code'], '验签成功应激活');
        $this->assertSame(1, (int) StorageProvider::find($p->id)->is_active);

        $q = $this->createS3Provider('R2 坏桶');
        $fail = new MockHandler();
        $fail->append(fn(Command $cmd) => throw new \Aws\Exception\AwsException('Access Denied', $cmd, ['code' => 'AccessDenied']));
        Storage::$handler = $fail;
        $bad = $this->body($this->ctrl()->activate($this->post("/admin/storage/providers/{$q->id}/activate", []), (string) $q->id));
        $this->assertNotSame(0, $bad['code'], '验签失败不得激活');
        $this->assertStringContainsString('连接失败', $bad['message']);
        $this->assertSame(0, (int) StorageProvider::find($q->id)->is_active);
    }

    #[Test]
    public function test_routes_registered_in_admin_group(): void
    {
        $routes = file_get_contents(__DIR__ . '/../config/route.php');
        $matched = preg_match("/Route::group\('\/admin'.*?middleware\(\[(.*?)\]\)/s", $routes, $m);
        $this->assertSame(1, $matched);
        foreach (['get(\'/storage/providers\'', 'post(\'/storage/providers\'', 'put(\'/storage/providers/{id}\'', 'delete(\'/storage/providers/{id}\'', 'post(\'/storage/providers/{id}/activate\''] as $needle) {
            $this->assertStringContainsString($needle, $routes, "$needle 应注册在 /admin 组内");
        }
    }
}

/** 测试用自增 id（snowflake 在 sqlite 无意义，直接递增避免主键冲突） */
final class SnowflakeForTest
{
    private static int $n = 30000000000000099;

    public static function next(): int
    {
        return ++self::$n;
    }
}
