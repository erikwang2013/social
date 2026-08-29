<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
require __DIR__ . '/bootstrap.php';

use app\common\Storage;
use app\controller\ImageController;
use app\model\StorageProvider;
use Aws\MockHandler;
use Aws\Result;
use PHPUnit\Framework\TestCase;
use support\Request;

/**
 * M6c2 图片上传接 Storage 单测
 * - open_admin 连接在测试胶囊中覆盖为 sqlite :memory:（不触真实 MySQL）
 * - local 活动 → 相对 URL `/upload/...` 落盘；s3 活动 → `{cdn_url}/upload/...` 且本地不留文件
 */
class ImageControllerTest extends TestCase
{
    private const SEED_ID = 30000000000000001;
    private const S3_ID = 30000000000000002;

    private string $tmpFile = '';

    public static function setUpBeforeClass(): void
    {
        // Encryptable 测试进程内对称加解密即可；密钥对齐 config/plugin 默认值
        putenv('ENCRYPTION_KEY=open-admin-db-encryption-key-32b');
        // M6c: 覆盖共享胶囊里的 open_admin 连接为 sqlite，隔离真实 MySQL
        $capsule = (new \ReflectionClass(\Illuminate\Database\Capsule\Manager::class))->getProperty('instance')->getValue();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'erik_'], 'open_admin');
        support\Db::connection('open_admin')->getSchemaBuilder()->create('storage_provider', function ($t) {
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
            'id' => self::SEED_ID, 'name' => '本地存储', 'driver' => 'local', 'is_active' => 1,
        ]);
    }

    protected function setUp(): void
    {
        StorageProvider::where('id', '!=', self::SEED_ID)->delete();
        StorageProvider::where('id', self::SEED_ID)->update([
            'driver' => 'local', 'is_active' => 1, 'endpoint' => '', 'bucket' => '', 'cdn_url' => '',
        ]);
        Storage::$handler = null;
        // 清 Storage 的 Redis 60s 活动服务商缓存（裸 key，无前缀），避免用例间串数据（本机 Redis 常开）
        try {
            $r = new \Redis();
            $r->connect(env('REDIS_HOST', '127.0.0.1'), (int) env('REDIS_PORT', 6379), 1.0);
            $r->del('storage:active_provider');
        } catch (\Throwable) {
        }
        $this->tmpFile = '';
    }

    protected function tearDown(): void
    {
        if ($this->tmpFile !== '' && is_file($this->tmpFile)) {
            @unlink($this->tmpFile);
        }
    }

    private function uploadRequest(): Request
    {
        $img = imagecreatetruecolor(320, 240);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 100, 50));
        ob_start();
        imagejpeg($img, null, 90);
        $jpg = ob_get_clean();
        imagedestroy($img);

        // 构造真实 multipart 原始报文（workerman 从 buffer 解析上传文件）
        $boundary = '----WebmanFormBoundary' . uniqid();
        $body = "--$boundary\r\n"
            . "Content-Disposition: form-data; name=\"image\"; filename=\"t.jpg\"\r\n"
            . "Content-Type: image/jpeg\r\n\r\n"
            . $jpg . "\r\n"
            . "--$boundary--\r\n";
        $raw = "POST /api/v1/image/upload HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-Type: multipart/form-data; boundary=$boundary\r\n"
            . "Content-Length: " . strlen($body) . "\r\n\r\n"
            . $body;
        $req = new Request($raw);
        $req->uid = 1;
        return $req;
    }

    private function body(mixed $res): array
    {
        return json_decode($res->rawBody(), true);
    }

    public function testUploadLocalReturnsRelativeUrlAndWritesDisk(): void
    {
        $data = $this->body((new ImageController())->upload($this->uploadRequest()));
        $this->assertSame(0, $data['code']);
        $this->assertMatchesRegularExpression('#^/upload/\d{4}-\d{2}-\d{2}/[a-f0-9]+\.jpg$#', $data['data']['url']);
        $this->assertSame(320, $data['data']['width']);
        $this->assertSame(240, $data['data']['height']);
        $this->assertFileExists(public_path() . $data['data']['url']);
        $this->tmpFile = public_path() . $data['data']['url'];
    }

    public function testUploadS3ReturnsCdnUrlAndKeepsNothingLocal(): void
    {
        StorageProvider::forceCreate([
            'id' => self::S3_ID, 'name' => 'R2', 'driver' => 's3',
            'endpoint' => 'https://x.r2.cloudflarestorage.com', 'region' => 'auto',
            'key' => 'ak123', 'secret' => 'sk456', 'bucket' => 'media',
            'cdn_url' => 'https://cdn.example.com', 'is_active' => 1,
        ]);
        StorageProvider::where('id', self::SEED_ID)->update(['is_active' => 0]);

        $mock = new MockHandler();
        $mock->append(new Result([]));
        Storage::$handler = $mock;

        $data = $this->body((new ImageController())->upload($this->uploadRequest()));
        $this->assertSame(0, $data['code']);
        $key = substr($data['data']['url'], strlen('https://cdn.example.com/'));
        $this->assertMatchesRegularExpression('#^upload/\d{4}-\d{2}-\d{2}/[a-f0-9]+\.jpg$#', $key);
        $this->assertFileDoesNotExist(public_path() . '/' . $key, 's3 上传后本地不得残留文件');
    }

    public function testUploadWithoutActiveProviderFails(): void
    {
        StorageProvider::where('id', self::SEED_ID)->update(['is_active' => 0]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('未配置活动的存储服务商');
        (new ImageController())->upload($this->uploadRequest());
    }
}
