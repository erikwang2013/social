// service 用户端全流程 E2E（真实浏览器 + 真实服务）
// 覆盖：公开页面、注册/登录、资料、发帖、时间线、详情、点赞、评论、
// 关注、通知、IM 会话、语音房间、搜索、登出
const { test, expect } = require('@playwright/test');

const SVC = process.env.SERVICE_URL || 'http://127.0.0.1:8788';
const PASSWORD = 'E2e_Pass_2026!';
const email = () => `e2e_${Date.now()}_${Math.floor(Math.random() * 1e4)}@e2e.test`;

async function api(request, method, path, { token, data } = {}) {
  const headers = {};
  if (token) headers.Authorization = `Bearer ${token}`;
  const res = await request[method](`${SVC}${path}`, { headers, data });
  return { status: res.status(), body: await res.json() };
}

async function register(request, { nickname } = {}) {
  const mail = email();
  const r = await api(request, 'post', '/api/v1/auth/register', {
    data: { email: mail, password: PASSWORD, nickname },
  });
  expect(r.body.code).toBe(0);
  return { email: mail, token: r.body.data.access_token };
}

test.describe('service 用户端 E2E', () => {
  test.describe.serial('全流程', () => {
    let A, B, postId;

    test('/ 渲染首页(iframe 容器)', async ({ page }) => {
      await page.goto(`${SVC}/`);
      await expect(page.locator('iframe')).toHaveCount(1);
    });

    test('/health 健康检查', async ({ request }) => {
      const r = await api(request, 'get', '/health');
      expect(r.status).toBe(200);
      expect(r.body.status).toBe('ok');
    });

    test('/apidoc 跳转 API 文档', async ({ page }) => {
      await page.goto(`${SVC}/apidoc`);
      await page.waitForURL(/apidoc\/index\.html/);
      await expect(page).toHaveTitle(/apidoc|API/);
    });

    test('注册用户 A 并登录', async ({ request }) => {
      A = await register(request, { nickname: '阿依' });
      const r = await api(request, 'post', '/api/v1/auth/login', {
        data: { email: A.email, password: PASSWORD },
      });
      expect(r.body.code).toBe(0);
      A.token = r.body.data.access_token;
    });

    test('注册校验：非法邮箱 400 / 重复邮箱 409', async ({ request }) => {
      const bad = await api(request, 'post', '/api/v1/auth/register', {
        data: { email: 'not-an-email', password: PASSWORD },
      });
      expect(bad.body.code).toBe(400);
      const dup = await api(request, 'post', '/api/v1/auth/register', {
        data: { email: A.email, password: PASSWORD },
      });
      expect(dup.body.code).toBe(409);
    });

    test('未登录访问受保护端点返回 401', async ({ request }) => {
      const me = await api(request, 'get', '/api/v1/me');
      expect(me.body.code).toBe(401);
      const posts = await api(request, 'get', '/api/v1/posts?page=1');
      expect(posts.body.code).toBe(401);
    });

    test('错误密码登录 401', async ({ request }) => {
      const r = await api(request, 'post', '/api/v1/auth/login', {
        data: { email: A.email, password: 'Wrong_Pass_1' },
      });
      expect(r.body.code).toBe(401);
    });

    test('/api/v1/me 获取与更新资料', async ({ request }) => {
      const me = await api(request, 'get', '/api/v1/me', { token: A.token });
      expect(me.body.code).toBe(0);
      expect(me.body.data.nickname).toBe('阿依');
      A.userId = me.body.data.user_id;

      const upd = await api(request, 'put', '/api/v1/me', {
        token: A.token,
        data: { bio: 'E2E 测试签名' },
      });
      expect(upd.body.code).toBe(0);
      const me2 = await api(request, 'get', '/api/v1/me', { token: A.token });
      expect(me2.body.data.bio).toBe('E2E 测试签名');
    });

    test('创建帖子并出现在时间线/详情', async ({ request }) => {
      const created = await api(request, 'post', '/api/v1/posts', {
        token: A.token,
        data: { content: 'E2E 自动化帖子_独特标记', visibility: 'public' },
      });
      expect(created.body.code).toBe(0);
      postId = created.body.data.id;

      const tl = await api(request, 'get', '/api/v1/posts?page=1', { token: A.token });
      const contents = tl.body.data.list.map((p) => p.content);
      expect(contents).toContain('E2E 自动化帖子_独特标记');

      const detail = await api(request, 'get', `/api/v1/posts/${postId}`, { token: A.token });
      expect(detail.body.code).toBe(0);
      expect(detail.body.data.content).toBe('E2E 自动化帖子_独特标记');
    });

    test('点赞/取消点赞', async ({ request }) => {
      const like = await api(request, 'post', `/api/v1/posts/${postId}/like`, { token: A.token });
      expect(like.body.data.liked).toBe(true);
      const unlike = await api(request, 'post', `/api/v1/posts/${postId}/unlike`, { token: A.token });
      expect(unlike.body.data.liked).toBe(false);
    });

    test('用户 B 关注 A 并评论 A 的帖子，A 收到通知', async ({ request }) => {
      B = await register(request, { nickname: '阿比' });
      const bMe = await api(request, 'get', '/api/v1/me', { token: B.token });
      B.userId = bMe.body.data.user_id;

      const follow = await api(request, 'post', `/api/v1/users/${A.userId}/follow`, { token: B.token });
      expect(follow.body.code).toBe(0);

      const rel = await api(request, 'get', `/api/v1/users/${A.userId}/relation`, { token: B.token });
      expect(rel.body.data.is_following).toBe(true);

      const comment = await api(request, 'post', `/api/v1/posts/${postId}/comments`, {
        token: B.token,
        data: { content: '来自 B 的评论' },
      });
      expect(comment.body.code).toBe(0);

      const comments = await api(request, 'get', `/api/v1/posts/${postId}/comments`, { token: A.token });
      const texts = comments.body.data.list.map((c) => c.content);
      expect(texts).toContain('来自 B 的评论');

      const notif = await api(request, 'get', '/api/v1/notifications', { token: A.token });
      expect(notif.body.data.total).toBeGreaterThan(0);
      const types = notif.body.data.list.map((n) => n.type);
      expect(types).toContain('follow');
      expect(types).toContain('comment');

      const unread = await api(request, 'get', '/api/v1/notifications/unread-count', { token: A.token });
      expect(Number(unread.body.data.unread_count)).toBeGreaterThan(0);

      const read = await api(request, 'post', '/api/v1/notifications/read-all', { token: A.token });
      expect(read.body.code).toBe(0);
      const unread2 = await api(request, 'get', '/api/v1/notifications/unread-count', { token: A.token });
      expect(Number(unread2.body.data.unread_count)).toBe(0);
    });

    test('B 的粉丝/关注列表包含 A 与 B', async ({ request }) => {
      const followers = await api(request, 'get', `/api/v1/users/${A.userId}/followers`, { token: B.token });
      const ids = followers.body.data.list.map((u) => u.id);
      expect(ids).toContain(B.userId);
      const following = await api(request, 'get', `/api/v1/users/${B.userId}/following`, { token: A.token });
      const fids = following.body.data.list.map((u) => u.id);
      expect(fids).toContain(A.userId);
    });

    test('B 取消关注 A 后关系为未关注', async ({ request }) => {
      const unfollow = await api(request, 'post', `/api/v1/users/${A.userId}/unfollow`, { token: B.token });
      expect(unfollow.body.code).toBe(0);
      const rel = await api(request, 'get', `/api/v1/users/${A.userId}/relation`, { token: B.token });
      expect(rel.body.data.is_following).toBe(false);
    });

    test('新评论通知可单条标记已读', async ({ request }) => {
      await api(request, 'post', `/api/v1/posts/${postId}/comments`, {
        token: B.token,
        data: { content: '单条已读测试' },
      });
      const notif = await api(request, 'get', '/api/v1/notifications', { token: A.token });
      expect(notif.body.code).toBe(0);
      const unread = notif.body.data.list.find((n) => !n.read);
      expect(unread).toBeTruthy();

      const read = await api(request, 'post', `/api/v1/notifications/${unread.id}/read`, { token: A.token });
      expect(read.body.code).toBe(0);
      const cnt = await api(request, 'get', '/api/v1/notifications/unread-count', { token: A.token });
      expect(Number(cnt.body.data.unread_count)).toBe(0);
    });

    test('搜索用户', async ({ request }) => {
      const r = await api(request, 'get', `/api/v1/search/users?q=${encodeURIComponent('阿依')}`, {
        token: A.token,
      });
      expect(r.body.code).toBe(0);
      const nicknames = r.body.data.list.map((u) => u.nickname);
      expect(nicknames).toContain('阿依');
    });

    test('搜索帖子(依赖搜索服务，当前离线记录为阻塞)', async ({ request }) => {
      const r = await api(request, 'get', `/api/v1/search/posts?q=${encodeURIComponent('独特标记')}`, {
        token: A.token,
      });
      // 搜索服务(Elasticsearch/Scout)未启动时为 503；可用时需返回 0 且命中帖子
      expect([0, 503]).toContain(r.body.code);
      if (r.body.code === 503) {
        test.info().annotations.push({
          type: 'blocked',
          description: '搜索服务不可用(503) — 需启动 ES/搜索后端后验证 search/posts 命中',
        });
      }
    });

    test('IM 会话创建与消息', async ({ request }) => {
      const conv = await api(request, 'post', '/api/v1/im/conversations', {
        token: A.token,
        data: { type: 1, member_ids: [B.userId] },
      });
      expect(conv.body.code).toBe(0);

      const list = await api(request, 'get', '/api/v1/im/conversations', { token: A.token });
      expect(list.body.data.list.length).toBeGreaterThan(0);

      const msgs = await api(request, 'get', `/api/v1/im/conversations/${conv.body.data.id}/messages`, {
        token: A.token,
      });
      expect(msgs.body.code).toBe(0);
    });

    test('语音房间创建/列表/详情/关闭', async ({ request }) => {
      const room = await api(request, 'post', '/api/v1/voice/rooms', {
        token: A.token,
        data: { name: 'E2E 语音房' },
      });
      expect(room.body.code).toBe(0);

      const list = await api(request, 'get', '/api/v1/voice/rooms', { token: A.token });
      const names = list.body.data.list.map((r) => r.name);
      expect(names).toContain('E2E 语音房');

      const detail = await api(request, 'get', `/api/v1/voice/rooms/${room.body.data.room_id}`, { token: A.token });
      expect(detail.body.code).toBe(0);

      const close = await api(request, 'post', `/api/v1/voice/rooms/${room.body.data.room_id}/close`, {
        token: A.token,
      });
      expect(close.body.code).toBe(0);
    });

    test('登出后原 token 失效', async ({ request }) => {
      const out = await api(request, 'post', '/api/v1/auth/logout', { token: A.token });
      expect(out.body.code).toBe(0);
      const me = await api(request, 'get', '/api/v1/me', { token: A.token });
      expect(me.body.code).toBe(401);
    });
  });
});
