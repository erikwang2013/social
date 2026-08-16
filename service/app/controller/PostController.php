<?php
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
}
