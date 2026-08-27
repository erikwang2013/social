<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

declare(strict_types=1);

namespace tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use support\Request;
use support\exception\NotFoundException;
use app\common\HashidsService;
use app\model\AdminRole;
use app\admin\controller\BaseController;
use app\admin\controller\RoleController;
use app\admin\controller\PermissionController;
use app\admin\controller\ConfigController;
use app\admin\controller\ExportController;

/**
 * 管理端控制器关键逻辑单测
 * - BaseController::decodeId 非法 hashid → NotFoundException(404)
 * - RoleController super_admin 角色保护（403）
 * - PermissionController 权限树构建（hashid 化 + 嵌套）
 * - ConfigController 入参校验（422）与非法 id（404）
 * - ExportController 敏感字段清单与 PDF HTML 转义
 */
class AdminControllerTest extends TestCase
{
    private function callProtected(object $obj, string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod($obj, $method))->invoke($obj, ...$args);
    }

    // ─────────────────────────── BaseController ───────────────────────────

    #[Test]
    public function test_decode_id_roundtrip(): void
    {
        $ctrl = new BaseController();
        $encoded = $this->callProtected($ctrl, 'encodeId', 12345);
        $this->assertIsString($encoded);
        $this->assertNotSame('12345', $encoded, 'hashid 不应等于明文 ID');
        $this->assertSame(12345, $this->callProtected($ctrl, 'decodeId', $encoded));
    }

    #[Test]
    public function test_decode_id_invalid_hashid_throws_404(): void
    {
        $ctrl = new BaseController();
        try {
            $this->callProtected($ctrl, 'decodeId', 'not-a-valid-hashid');
            $this->fail('非法 hashid 应抛出 NotFoundException');
        } catch (NotFoundException $e) {
            $this->assertSame(404, $e->getCode(), '非法 hashid 应按 404 处理');
        }
    }

    #[Test]
    public function test_encode_ids_masks_id_fields(): void
    {
        $ctrl = new BaseController();
        $data = $this->callProtected($ctrl, 'encodeIds', ['id' => 42, 'name' => 'x']);
        $this->assertNotSame(42, $data['id']);
        $this->assertIsString($data['id']);
        $this->assertSame('x', $data['name'], '非 ID 字段不应被改写');
    }

    // ─────────────────────────── RoleController ───────────────────────────

    #[Test]
    public function test_role_update_super_admin_rejected(): void
    {
        $role = AdminRole::where('slug', 'super_admin')->first();
        if (!$role) {
            $this->markTestSkipped('数据库无 super_admin 角色（seed 未执行）');
        }

        $req = new Request('PUT', '/admin/role');
        $req->setPost(['name' => '试图改名']);
        $res = (new RoleController())->update($req, HashidsService::encode((int) $role->id));

        $body = json_decode($res->rawBody(), true);
        $this->assertSame(403, $body['code'], 'super_admin 角色不可编辑');
    }

    // ──────────────────────── PermissionController ─────────────────────────

    #[Test]
    public function test_permission_build_tree_nests_children_and_encodes_ids(): void
    {
        $ctrl = new PermissionController();
        $tree = $this->callProtected($ctrl, 'buildTree', [
            ['id' => 1, 'parent_id' => 0, 'name' => 'a'],
            ['id' => 2, 'parent_id' => 1, 'name' => 'b'],
            ['id' => 3, 'parent_id' => 1, 'name' => 'c'],
            ['id' => 4, 'parent_id' => 2, 'name' => 'd'],
        ]);

        $this->assertCount(1, $tree, '仅根节点留在顶层');
        $node = $tree[0];
        $this->assertIsString($node['id'], '节点 id 应 hashid 化');
        $this->assertNotSame(1, $node['id']);
        $this->assertCount(2, $node['children'], '根节点应有 2 个子节点');
        $this->assertCount(1, $node['children'][0]['children'], '子节点应继续嵌套');
    }

    // ─────────────────────────── ConfigController ──────────────────────────

    #[Test]
    public function test_config_store_requires_fields(): void
    {
        // webman Request 构造参数为原始 HTTP 报文（buffer），需要完整首行才能解析 POST 体
        $req = new Request(
            "POST /admin/config HTTP/1.1\r\nHost: localhost\r\n" .
            "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: 0\r\n\r\n"
        );
        $res = (new ConfigController())->store($req);

        $body = json_decode($res->rawBody(), true);
        $this->assertSame(422, $body['code'], '缺 group/key/value 应校验失败');
    }

    #[Test]
    public function test_config_update_invalid_hashid_throws_404(): void
    {
        $req = new Request('PUT', '/admin/config/bad');
        try {
            (new ConfigController())->update($req, 'bad!!!');
            $this->fail('非法 hashid 应抛出 NotFoundException');
        } catch (NotFoundException $e) {
            $this->assertSame(404, $e->getCode());
        }
    }

    // ─────────────────────────── ExportController ──────────────────────────

    #[Test]
    public function test_export_sensitive_fields(): void
    {
        $ctrl = new ExportController();
        $this->assertSame(
            ['phone', 'email', 'id_card'],
            $this->callProtected($ctrl, 'getSensitiveFields', 'admin_user'),
            'admin_user 导出时 phone/email/id_card 必须脱敏'
        );
        $this->assertSame([], $this->callProtected($ctrl, 'getSensitiveFields', 'operation_log'));
    }

    #[Test]
    public function test_export_pdf_html_escapes_and_has_copyright(): void
    {
        $ctrl = new ExportController();
        $html = $this->callProtected($ctrl, 'buildPdfHtml', 'table', '<script>alert(1)</script>', [
            'columns' => ['姓名'],
            'rows'    => [['<b>张三</b>']],
        ]);

        $this->assertStringContainsString('&lt;script&gt;', $html, '标题应被 htmlspecialchars 转义（防 XSS）');
        $this->assertStringContainsString('&lt;b&gt;张三&lt;/b&gt;', $html, '单元格值应被转义');
        $this->assertStringContainsString('https://erik.xyz', $html, 'PDF 应包含版权声明');
    }
}
