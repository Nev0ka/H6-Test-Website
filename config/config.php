<?php

declare(strict_types=1);

use Dotenv\Dotenv;

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

return [
    'db' => [
        'host'     => $_ENV['DB_HOST'] ?? '127.0.0.1',
        'port'     => $_ENV['DB_PORT'] ?? '3306',
        'name'     => $_ENV['DB_NAME'] ?? 'server_monitor',
        'user'     => $_ENV['DB_USER'] ?? 'server_monitor',
        'password' => $_ENV['DB_PASSWORD'] ?? '',
    ],
    'app' => [
        'env'          => $_ENV['APP_ENV'] ?? 'production',
        'force_https'  => ($_ENV['APP_FORCE_HTTPS'] ?? 'true') === 'true',
    ],
];
