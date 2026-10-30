<?php
return [
    'secret_key' => env('JWT_SECRET', ''),
    'alg' => 'HS256',
    'default_expire' => 7200,       // access 2h
    'refresh_expire' => 1209600,    // refresh 14d
    'type' => 'header',
    'name' => 'Authorization',
    'prefix' => 'Bearer',
    'issuer' => 'social-service',
];
