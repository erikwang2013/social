<?php
// Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz

require __DIR__ . '/../vendor/autoload.php';

/**
 * Webman\Config (2.1.x) exposes load/clear/get but no setter; tests inject
 * config values into its protected static store instead of editing config files.
 */
function set_payment_config(array $payment): void
{
    static $property;
    $property ??= new ReflectionProperty(Webman\Config::class, 'config');
    $config = $property->getValue(null);
    $config['payment'] = array_replace_recursive($config['payment'] ?? [], $payment);
    $property->setValue(null, $config);
}
