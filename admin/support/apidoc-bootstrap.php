<?php
/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

/**
 * hg/apidoc 注解别名注册
 *
 * Doctrine AnnotationReader 将 @Apidoc\Title 解析为类名 Apidoc\Title，
 * 但实际类在 hg\apidoc\annotation\Title。通过 class_alias 建立映射。
 */

$apidocAnnotations = [
    'AddField', 'After', 'Author', 'Before', 'ContentType', 'Desc',
    'EventBase', 'Field', 'Group', 'Header', 'Md', 'Method',
    'NotDebug', 'NotDefaultAuthor', 'NotHeaders', 'NotParams',
    'NotParse', 'NotQuerys', 'NotResponseError', 'NotResponses',
    'NotResponseSuccess', 'ParamBase', 'Param', 'ParamType',
    'Property', 'Query', 'ResponseErrorMd', 'ResponseError',
    'ResponseStatus', 'ResponseSuccessMd', 'ResponseSuccess',
    'Returned', 'RouteMiddleware', 'RouteParam', 'Sort', 'Tag',
    'Title', 'Url', 'WithoutField',
];

foreach ($apidocAnnotations as $name) {
    $alias = "Apidoc\\{$name}";
    $target = "hg\\apidoc\\annotation\\{$name}";
    if (!class_exists($alias, false) && class_exists($target)) {
        class_alias($target, $alias);
    }
}
