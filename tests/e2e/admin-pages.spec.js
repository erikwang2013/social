// admin 应用公开页面：/install 向导、/health、/metrics、security.txt、/api/docs、
// 未登录访问受保护页的 401 行为
const { test, expect } = require('@playwright/test');

const ADMIN = process.env.ADMIN_URL || 'http://127.0.0.1:8791';

test.describe('admin 公开页面', () => {
  test('/health 返回健康状态', async ({ request }) => {
    const res = await request.get(`${ADMIN}/health`);
    expect(res.status()).toBe(200);
    const body = await res.json();
    expect(body.code).toBe(0);
    expect(body.data.redis).toBe('ok');
  });

  test('/metrics 输出 Prometheus 指标', async ({ request }) => {
    const res = await request.get(`${ADMIN}/metrics`);
    expect(res.status()).toBe(200);
    const text = await res.text();
    expect(text).toContain('# HELP');
  });

  test('/.well-known/security.txt 暴露安全联系信息', async ({ request }) => {
    const res = await request.get(`${ADMIN}/.well-known/security.txt`);
    expect(res.status()).toBe(200);
    const text = await res.text();
    expect(text).toContain('Contact: mailto:erik@erik.xyz');
  });

  test('/api/docs 返回 OpenAPI 规范', async ({ request }) => {
    const res = await request.get(`${ADMIN}/api/docs`);
    expect(res.status()).toBe(200);
    const body = await res.json();
    expect(body.openapi).toMatch(/^3\./);
    expect(body.paths).toBeTruthy();
  });

  test('/install 渲染安装向导(步骤1 数据库配置)', async ({ page }) => {
    await page.goto(`${ADMIN}/install`);
    await expect(page).toHaveTitle(/数据库配置/);
    await expect(page.locator('body')).toContainText('数据库配置');
  });

  test('未登录访问 /admin/dashboard 返回 401', async ({ request }) => {
    const res = await request.get(`${ADMIN}/admin/dashboard`);
    expect(res.status()).toBe(200); // 应用层 200 + code 401
    const body = await res.json();
    expect(body.code).toBe(401);
  });

  test('未知路由返回 404', async ({ request }) => {
    const res = await request.get(`${ADMIN}/no-such-page`);
    expect(res.status()).toBe(404);
  });
});
