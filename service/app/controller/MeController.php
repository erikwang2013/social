<?php
namespace app\controller;

use support\Request;
use app\model\UserProfile;
use hg\apidoc\annotation as Apidoc;

/**
 * @Apidoc\Title("个人资料")
 */
class MeController
{
    /**
     * @Apidoc\Title("查看资料")
     * @Apidoc\Url("/api/v1/me")
     * @Apidoc\Method("GET")
     * @Apidoc\Header("Authorization", type="string", require=true, desc="Bearer access_token")
     * @Apidoc\Returned(ref="Response")
     */
    public function index(Request $request)
    {
        $profile = UserProfile::where('user_id', $request->uid)->first();
        return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => $profile]);
    }

    /**
     * @Apidoc\Title("更新资料")
     * @Apidoc\Url("/api/v1/me")
     * @Apidoc\Method("PUT")
     * @Apidoc\Header("Authorization", type="string", require=true, desc="Bearer access_token")
     * @Apidoc\Param("nickname", type="string", require=false, desc="昵称(1-32字符)")
     * @Apidoc\Param("avatar", type="string", require=false, desc="头像URL")
     * @Apidoc\Param("bio", type="string", require=false, desc="简介(最多200字)")
     * @Apidoc\Param("gender", type="int", require=false, desc="性别 0保密 1男 2女")
     * @Apidoc\Param("birthday", type="string", require=false, desc="生日 YYYY-MM-DD")
     * @Apidoc\Returned(ref="Response")
     */
    public function update(Request $request)
    {
        $profile = UserProfile::where('user_id', $request->uid)->firstOrFail();
        $data = [];

        if ($request->post('nickname') !== null) {
            $nickname = trim((string) $request->post('nickname'));
            if ($nickname === '' || mb_strlen($nickname) > 32) {
                return json(['code' => 400, 'message' => '昵称需 1-32 字符', 'lang_key' => 'me.nickname_length'], 400);
            }
            $data['nickname'] = $nickname;
        }
        if ($request->post('avatar') !== null) {
            $avatar = trim((string) $request->post('avatar'));
            if ($avatar !== '' && !filter_var($avatar, FILTER_VALIDATE_URL)) {
                return json(['code' => 400, 'message' => '头像地址不合法', 'lang_key' => 'me.avatar_invalid'], 400);
            }
            $data['avatar'] = $avatar;
        }
        if ($request->post('bio') !== null) {
            $bio = trim((string) $request->post('bio'));
            if (mb_strlen($bio) > 200) {
                return json(['code' => 400, 'message' => '简介最多200字', 'lang_key' => 'me.bio_length'], 400);
            }
            $data['bio'] = $bio;
        }
        if ($request->post('gender') !== null) {
            $gender = (int) $request->post('gender');
            if (!in_array($gender, [0, 1, 2], true)) {
                return json(['code' => 400, 'message' => '性别取值 0/1/2', 'lang_key' => 'me.gender_invalid'], 400);
            }
            $data['gender'] = $gender;
        }
        if ($request->post('birthday') !== null) {
            $birthday = (string) $request->post('birthday');
            if ($birthday !== '') {
                [$y, $m, $d] = array_map('intval', explode('-', $birthday));
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthday) || !checkdate($m, $d, $y)) {
                    return json(['code' => 400, 'message' => '生日格式 YYYY-MM-DD', 'lang_key' => 'me.birthday_invalid'], 400);
                }
            }
            $data['birthday'] = $birthday !== '' ? $birthday : null;
        }

        if ($data !== []) {
            $profile->update($data);
        }
        return json(['code' => 0, 'message' => 'ok', 'lang_key' => 'ok', 'data' => $profile->refresh()]);
    }
}
