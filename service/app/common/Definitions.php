<?php
namespace app\common;

use hg\apidoc\annotation as Apidoc;

class Definitions
{
    /**
     * @Apidoc\Define("Response", desc="统一响应结构")
     * @Apidoc\Param("code", type="int", require=true, desc="0成功，非0错误码")
     * @Apidoc\Param("message", type="string", require=true, desc="错误消息")
     * @Apidoc\Param("lang_key", type="string", require=true, desc="多语言错误键")
     * @Apidoc\Param("data", type="object", require=false, desc="业务数据")
     */
    public function response() {}
}
