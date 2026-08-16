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
        $req->setPost(['email' => uniqid() . '@inter.test', 'password' => 'secret123']);
        (new AuthController())->register($req);
        $uid = \app\model\User::where('email', 'like', '%@inter.test')->orderByDesc('id')->first()->id;

        $req2 = new Request('POST', '/api/v1/posts');
        $req2->setPost(['content' => '互动测试动态']);
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
        $req->setPost(['content' => '不错的动态']);
        $req->uid = $uid;
        $res = (new CommentController())->create($req, (string) $postId);
        $this->assertSame(0, json_decode($res->rawBody(), true)['code']);
        $this->assertSame(1, Post::find($postId)->comment_count);

        $req2 = new Request("GET /api/v1/posts/$postId/comments HTTP/1.1\r\n\r\n");
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
