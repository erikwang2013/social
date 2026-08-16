<?php
return [
    'secret_key' => env('JWT_SECRET', 'social-service-dev-secret-key-change-me-2026'),
    'alg' => 'HS256',
    'default_expire' => 7200,       // access 2h
    'refresh_expire' => 1209600,    // refresh 14d
    'type' => 'header',
    'name' => 'Authorization',
    'prefix' => 'Bearer',
    'issuer' => 'social-service',
];
