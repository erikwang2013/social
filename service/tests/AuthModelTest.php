<?php
use PHPUnit\Framework\TestCase;
use app\model\User;
use app\model\UserProfile;
use app\model\Post;
use app\model\Like;

class AuthModelTest extends TestCase
{
    public function testUserCreateAndTablePrefix()
    {
        $u = User::create(['email' => 'a@b.com', 'password' => password_hash('secret', PASSWORD_BCRYPT)]);
        $this->assertTrue($u->exists);
        $this->assertSame('users', $u->getTable());
        $this->assertSame('social_', $u->getConnection()->getTablePrefix());
        $this->assertArrayNotHasKey('password', $u->toArray(), 'password hidden');
    }

    public function testProfileCreated()
    {
        $u = User::create(['email' => 'c@d.com', 'password' => 'x']);
        UserProfile::create(['user_id' => $u->id, 'nickname' => '测试']);
        $profile = UserProfile::where('user_id', $u->id)->first();
        $this->assertSame('测试', $profile->nickname);
    }

    public function testLikeUniqueConstraint()
    {
        $u = User::create(['email' => 'e@f.com', 'password' => 'x']);
        $p = Post::create(['user_id' => $u->id, 'content' => 'hello']);
        Like::create(['post_id' => $p->id, 'user_id' => $u->id]);
        $this->expectException(\Illuminate\Database\QueryException::class);
        Like::create(['post_id' => $p->id, 'user_id' => $u->id]);
    }
}
