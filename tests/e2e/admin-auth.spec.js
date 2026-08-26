// admin 登录 + 管理页面 E2E
// 滑块验证码通过「拼图块 vs 背景图」像素相关匹配求解（真实交互路径，无绕过）
// 前置：测试账号 e2e_smoke 已存在且密码已知（测试夹具，见 README）
const { test, expect } = require('@playwright/test');

const ADMIN = process.env.ADMIN_URL || 'http://127.0.0.1:8791';
const TEST_USER = process.env.E2E_ADMIN_USER || 'e2e_smoke';
const TEST_PASS = process.env.E2E_ADMIN_PASS || 'ApiTest!2026'; // 夹具密码见 tests/api/run.php（SQL 预置）

// 在页面内对背景图与拼图块做像素相关，返回缺口绝对坐标
// 用 Pearson 相关系数（对亮度缩放不变，兼顾纹理/纯色背景）：
// 缺口处 = 拼图块原始内容(被 0x40 黑罩压暗)，与拼图块相关性最高；
// 纯色背景处方差为 0 → 相关为 NaN，按 0 处理。
async function locateGap(page, bgUri, pzUri) {
  return page.evaluate(async ({ bgUri, pzUri }) => {
    const load = (uri) => new Promise((res, rej) => {
      const img = new Image();
      img.onload = () => res(img);
      img.onerror = () => rej(new Error('image load failed'));
      img.src = uri;
    });
    const [bgImg, pzImg] = await Promise.all([load(bgUri), load(pzUri)]);
    const c1 = document.createElement('canvas');
    c1.width = bgImg.width; c1.height = bgImg.height;
    const c2 = document.createElement('canvas');
    c2.width = pzImg.width; c2.height = pzImg.height;
    const x1 = c1.getContext('2d', { willReadFrequently: true }); x1.drawImage(bgImg, 0, 0);
    const x2 = c2.getContext('2d', { willReadFrequently: true }); x2.drawImage(pzImg, 0, 0);
    const bg = x1.getImageData(0, 0, c1.width, c1.height).data;
    const pz = x2.getImageData(0, 0, c2.width, c2.height).data;
    const W = c1.width, H = c1.height, pw = c2.width, ph = c2.height;

    // 拼图块灰度均值/方差（抽样步长 2）
    let pzSum = 0, pzSum2 = 0, n = 0;
    for (let j = 0; j < ph; j += 2)
      for (let i = 0; i < pw; i += 2) {
        const o = (j * pw + i) * 4;
        const g = 0.299 * pz[o] + 0.587 * pz[o + 1] + 0.114 * pz[o + 2];
        pzSum += g; pzSum2 += g * g; n++;
      }
    const pzMean = pzSum / n;
    const pzVar = pzSum2 / n - pzMean * pzMean;

    const corrAt = (x, y, step) => {
      let sxy = 0, sy = 0, sy2 = 0, m = 0;
      for (let j = 0; j < ph; j += step) {
        const pzRow = j * pw * 4;
        const bgRow = ((y + j) * W + x) * 4;
        for (let i = 0; i < pw; i += step) {
          const o = pzRow + i * 4, b = bgRow + i * 4;
          const gy = 0.299 * bg[b] + 0.587 * bg[b + 1] + 0.114 * bg[b + 2];
          const px = 0.299 * pz[o] + 0.587 * pz[o + 1] + 0.114 * pz[o + 2];
          sxy += (px - pzMean) * (gy - pzMean);
          sy += gy; sy2 += gy * gy; m++;
        }
      }
      const gyMean = sy / m;
      const gyVar = sy2 / m - gyMean * gyMean;
      const denom = Math.sqrt(pzVar * gyVar);
      return denom > 1e-9 ? (sxy / m) / denom : 0; // 纯色区域无方差 → 0
    };

    let best = { x: 50, y: 20, score: -Infinity };
    for (let y = 20; y <= H - ph - 20; y += 2)
      for (let x = 50; x <= W - pw - 50; x += 2) {
        const s = corrAt(x, y, 2);
        if (s > best.score) best = { x, y, score: s };
      }
    for (let y = best.y - 3; y <= best.y + 3; y++)
      for (let x = best.x - 3; x <= best.x + 3; x++) {
        const s = corrAt(x, y, 1);
        if (s > best.score) best = { x, y, score: s };
      }
    return best.x;
  }, { bgUri, pzUri });
}

// 走完整验证码链路：generate → 像素求解 → verify → 返回 captcha_key
// 注意：captcha 类型随机（click/rotate/slider 三选一），只解 slider；
// 拼图块偶发与背景不匹配（相关峰 < 0.85）或图片解码抖动 → 换下一张重试。
async function passCaptcha(request, page) {
  for (let attempt = 0; attempt < 12; attempt++) {
    const gen = await request.post(`${ADMIN}/api/captcha/generate`, { data: { difficulty: 'medium' } });
    const body = await gen.json();
    if (body.code !== 0) continue;
    const { key, type, image, extra } = body.data;
    if (type !== 'slider') continue; // 无法自动求解的类型直接换新
    try {
      const x = await locateGap(page, image, extra.puzzle);
      const ver = await request.post(`${ADMIN}/api/captcha/verify`, {
        data: { key, type, clicks: x },
      });
      const vbody = await ver.json();
      if (vbody.code === 0 && vbody.data.valid) return key;
    } catch (e) {
      test.info().annotations.push({ type: 'retry', description: `验证码尝试失败: ${e.message}` });
    }
  }
  throw new Error('滑块验证码 12 次求解失败');
}

test.describe('admin 登录与管理页面', () => {
  test.describe.serial('登录 + 受保护页面遍历', () => {
    let token = '';

    test('滑块验证码求解 + 登录获取 JWT', async ({ request, page }) => {
      test.setTimeout(180_000);
      const captchaKey = await passCaptcha(request, page);
      const res = await request.post(`${ADMIN}/api/auth/login`, {
        data: { username: TEST_USER, password: TEST_PASS, captcha_key: captchaKey },
      });
      const body = await res.json();
      expect(body.code).toBe(0);
      expect(body.data.access_token).toBeTruthy();
      token = body.data.access_token;
    });

    test('错误密码登录返回 401', async ({ request, page }) => {
      test.setTimeout(180_000);
      const captchaKey = await passCaptcha(request, page);
      const res = await request.post(`${ADMIN}/api/auth/login`, {
        data: { username: TEST_USER, password: 'Wrong_Pass_1', captcha_key: captchaKey },
      });
      const body = await res.json();
      expect(body.code).toBe(401);
    });

    test('缺少验证码返回 422', async ({ request }) => {
      const res = await request.post(`${ADMIN}/api/auth/login`, {
        data: { username: TEST_USER, password: TEST_PASS },
      });
      const body = await res.json();
      expect(body.code).toBe(422);
    });

    test('/admin/dashboard 统计聚合', async ({ request }) => {
      const res = await request.get(`${ADMIN}/admin/dashboard`, {
        headers: { Authorization: `Bearer ${token}` },
      });
      const body = await res.json();
      expect(body.code).toBe(0);
      expect(Array.isArray(body.data.stats)).toBe(true);
    });

    test('/admin/user 用户列表', async ({ request }) => {
      const res = await request.get(`${ADMIN}/admin/user?page=1`, {
        headers: { Authorization: `Bearer ${token}` },
      });
      const body = await res.json();
      expect(body.code).toBe(0);
      expect(Array.isArray(body.data.list)).toBe(true);
    });

    test('/admin/role 角色列表', async ({ request }) => {
      const res = await request.get(`${ADMIN}/admin/role`, {
        headers: { Authorization: `Bearer ${token}` },
      });
      const body = await res.json();
      expect(body.code).toBe(0);
    });

    test('/admin/permission 权限列表', async ({ request }) => {
      const res = await request.get(`${ADMIN}/admin/permission`, {
        headers: { Authorization: `Bearer ${token}` },
      });
      const body = await res.json();
      expect(body.code).toBe(0);
      expect(Array.isArray(body.data)).toBe(true);
    });

    test('/admin/config 系统配置列表', async ({ request }) => {
      const res = await request.get(`${ADMIN}/admin/config`, {
        headers: { Authorization: `Bearer ${token}` },
      });
      const body = await res.json();
      expect(body.code).toBe(0);
    });

    test('/admin/log 操作日志列表', async ({ request }) => {
      const res = await request.get(`${ADMIN}/admin/log`, {
        headers: { Authorization: `Bearer ${token}` },
      });
      const body = await res.json();
      expect(body.code).toBe(0);
    });

    test('/admin/profile 个人信息', async ({ request }) => {
      const res = await request.get(`${ADMIN}/admin/profile`, {
        headers: { Authorization: `Bearer ${token}` },
      });
      const body = await res.json();
      expect(body.code).toBe(0);
      expect(body.data.username).toBeTruthy();
    });

    test('/admin/social-user 社交用户列表', async ({ request }) => {
      const res = await request.get(`${ADMIN}/admin/social-user`, {
        headers: { Authorization: `Bearer ${token}` },
      });
      const body = await res.json();
      expect(body.code).toBe(0);
    });

    test('登出后原 token 失效', async ({ request }) => {
      const res = await request.post(`${ADMIN}/admin/profile/logout`, {
        headers: { Authorization: `Bearer ${token}` },
      });
      expect((await res.json()).code).toBe(0);
      const after = await request.get(`${ADMIN}/admin/dashboard`, {
        headers: { Authorization: `Bearer ${token}` },
      });
      expect((await after.json()).code).toBe(401);
    });
  });
});
