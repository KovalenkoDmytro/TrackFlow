<?php

declare(strict_types=1);

// Set environment variables from phpunit.xml <env> tags for Docker compatibility
// Modern PHPUnit doesn't automatically apply <env> tags at the OS level when docker-compose
// environment vars already exist, so we set them explicitly here before the app boots

$env_vars = [
    'APP_ENV' => 'testing',
    'APP_MAINTENANCE_DRIVER' => 'file',
    'BCRYPT_ROUNDS' => '4',
    'BROADCAST_CONNECTION' => 'null',
    'CACHE_STORE' => 'array',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'DB_URL' => '',
    'MAIL_MAILER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'SESSION_DRIVER' => 'array',
    'SHOPIFY_API_KEY' => 'test-shopify-api-key',
    'SHOPIFY_API_SECRET' => 'test-shopify-api-secret',
    'PULSE_ENABLED' => 'false',
    'TELESCOPE_ENABLED' => 'false',
    'NIGHTWATCH_ENABLED' => 'false',
];

foreach ($env_vars as $key => $value) {
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
    putenv("{$key}={$value}");
}

require_once __DIR__.'/../vendor/autoload.php';
