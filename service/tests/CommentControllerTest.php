<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
require __DIR__ . '/bootstrap.php';

use app\controller\CommentController;
use app\model\Comment;
use app\model\Notification;
use app\model\Post;
use app\model\User;
use PHPUnit\Framework\TestCase;
use support\Request;

class CommentControllerTest extends TestCase
{
    private function request(string $method, string $path, array $post = []): Request
    {
        // get()/post() 依赖可解析的 HTTP 请求行（rawHead），需完整 buffer
        $req = new Request("$method $path HTTP/1.1\r\nHost: localhost\r\n\r\n");
        if ($post !== []) {
            $req->setPost($post);
        }
        $req->uid = 1;
        return $req;
    }

    private function post(int $userId = 1): Post
    {
        return Post::create(['user_id' => $userId, 'content' => '原帖']);
    }

    public function testIndexMissingPost(): void
    {
        $res = (new CommentController())->index($this->request('GET', '/api/v1/posts/999/comments'), '999');
        $this->assertSame(404, $this->body($res)['code']);
    }

    private function body(\Webman\Http\Response $res): array
    {
        return json_decode($res->rawBody(), true);
    }

    public function testIndexEmpty(): void
    {
        $post = $this->post();
        $res = (new CommentController())->index($this->request('GET', "/api/v1/posts/{$post->id}/comments"), (string) $post->id);
        $data = json_decode($res->rawBody(), true);
        $this->assertSame(0, $data['code']);
        $this->assertSame([], $data['data']['list']);
        $this->assertSame(0, $data['data']['total']);
    }

    public function testIndexWithComments(): void
    {
        $post = $this->post();
        $u2 = User::create(['email' => 'c' . uniqid() . '@t.com', 'password' => 'x']);
        Comment::create(['post_id' => $post->id, 'user_id' => $u2->id, 'content' => '好文']);
        $res = (new CommentController())->index($this->request('GET', "/api/v1/posts/{$post->id}/comments"), (string) $post->id);
        $data = json_decode($res->rawBody(), true);
        $this->assertSame(1, $data['data']['total']);
        $this->assertSame('好文', $data['data']['list'][0]['content']);
    }

    public function testCreateMissingPost(): void
    {
        $res = (new CommentController())->create($this->request('POST', '/api/v1/posts/999/comments', ['content' => 'x']), '999');
        $this->assertSame(404, $this->body($res)['code']);
    }

    public function testCreateEmptyContentRejected(): void
    {
        $post = $this->post();
        $res = (new CommentController())->create($this->request('POST', "/api/v1/posts/{$post->id}/comments", ['content' => '   ']), (string) $post->id);
        $this->assertSame(400, $this->body($res)['code']);
    }

    public function testCreateTooLongContentRejected(): void
    {
        $post = $this->post();
        $res = (new CommentController())->create($this->request('POST', "/api/v1/posts/{$post->id}/comments", ['content' => str_repeat('长', 501)]), (string) $post->id);
        $this->assertSame(400, $this->body($res)['code']);
    }

    public function testCreateSuccessIncrementsCount(): void
    {
        $post = $this->post();
        $res = (new CommentController())->create($this->request('POST', "/api/v1/posts/{$post->id}/comments", ['content' => '赞']), (string) $post->id);
        $data = json_decode($res->rawBody(), true);
        $this->assertSame(0, $data['code']);
        $this->assertSame(1, (int) Post::find($post->id)->comment_count);
        $this->assertSame(1, Comment::where('post_id', $post->id)->count());
    }

    public function testCreateOnOthersPostCreatesNotification(): void
    {
        $owner = User::create(['email' => 'o' . uniqid() . '@t.com', 'password' => 'x']);
        $post = $this->post((int) $owner->id);
        (new CommentController())->create($this->request('POST', "/api/v1/posts/{$post->id}/comments", ['content' => '我来评论']), (string) $post->id);
        $notif = Notification::where('user_id', $owner->id)->where('type', 'comment')->first();
        $this->assertNotNull($notif);
        $this->assertSame((int) $post->id, (int) $notif->ref_id);
        $this->assertSame(1, (int) $notif->actor_id);
    }

    public function testCreateOnOwnPostNoNotification(): void
    {
        $post = $this->post(1);
        (new CommentController())->create($this->request('POST', "/api/v1/posts/{$post->id}/comments", ['content' => '自己评']), (string) $post->id);
        $this->assertSame(0, Notification::where('user_id', 1)->count());
    }
}
