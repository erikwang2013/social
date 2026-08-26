// E2E 配置 — 两个 webman 服务均以真实进程运行
// admin  : http://127.0.0.1:8791  (后台管理 API + install 向导/apidoc)
// service: http://127.0.0.1:8788  (用户端 API + 首页/apidoc)
const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
  testDir: './',
  testMatch: /.*\.spec\.js/,
  timeout: 60_000,
  expect: { timeout: 10_000 },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [['list'], ['html', { outputFolder: 'artifacts/html-report', open: 'never' }]],
  use: {
    baseURL: process.env.ADMIN_URL || 'http://127.0.0.1:8791',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'off',
  },
  projects: [
    { name: 'admin', testMatch: /admin-.*\.spec\.js/ },
    { name: 'service', testMatch: /service-.*\.spec\.js/ },
  ],
});
