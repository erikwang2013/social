<?php
namespace app\controller;

use support\Request;
use app\model\Post;
use app\model\Comment;
use hg\apidoc\annotation as Apidoc;

/**
 * @Apidoc\Title("评论")
 */
class CommentController
{
    /**
     * @Apidoc\Title("评论列表")
     * @Apidoc\Url("/api/v1/posts/{id}/comments")
     * @Apidoc\Method("GET")
     * @Apidoc\Header("Authorization", type="string", require=true, desc="Bearer access_token")
     * @Apidoc\Param("id", type="int", require=true, desc="动态ID", path=true)
     * @Apidoc\Param("page", type="int", require=false, desc="页码，默认1")
     * @Apidoc\Param("page_size", type="int", require=false, desc="每页条数，默认20")
     * @Apidoc\Returned(ref="Response")
     */
    public function index(Request $request, string $id)
    {
        $post = Post::find((int) $id);
        if (!$post) {
            return json(['code' => 404, 'message' => '动态不存在', 'lang_key' => 'post.not_found'], 404);
        }
        $page = max(1, (int) $request->get('page', 1));
        $pageSize = min(50, max(1, (int) $request->get('page_size', 20)));
        $paginator = Comment::with('user')->where('post_id', $post->id)
            ->orderByDesc('created_at')->paginate($pageSize, ['*'], 'page', $page);
        return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => [
            'list' => $paginator->items(),
            'total' => $paginator->total(),
        ]]);
    }

    /**
     * @Apidoc\Title("发表评论")
     * @Apidoc\Url("/api/v1/posts/{id}/comments")
     * @Apidoc\Method("POST")
     * @Apidoc\Header("Authorization", type="string", require=true, desc="Bearer access_token")
     * @Apidoc\Param("id", type="int", require=true, desc="动态ID", path=true)
     * @Apidoc\Param("content", type="string", require=true, desc="评论内容(1-500字)")
     * @Apidoc\Returned(ref="Response")
     */
    public function create(Request $request, string $id)
    {
        $post = Post::find((int) $id);
        if (!$post) {
            return json(['code' => 404, 'message' => '动态不存在', 'lang_key' => 'post.not_found'], 404);
        }
        $content = trim((string) $request->post('content'));
        $len = mb_strlen($content);
        if ($len < 1 || $len > 500) {
            return json(['code' => 400, 'message' => '评论长度需 1-500 字', 'lang_key' => 'comment.content_length'], 400);
        }
        $comment = Comment::create(['post_id' => $post->id, 'user_id' => $request->uid, 'content' => $content]);
        $post->increment('comment_count');
        return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => $comment->load('user')]);
    }
}
