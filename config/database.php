<?php
declare(strict_types=1);

use App\Core\Env;

return [
    'driver' => 'mysql',
    'host' => (string) Env::get('DB_HOST', '127.0.0.1'),
    'port' => (int) Env::get('DB_PORT', '3306'),
    'database' => (string) Env::get('DB_DATABASE', 'sppg_cangkring'),
    'username' => (string) Env::get('DB_USERNAME', 'root'),
    'password' => (string) Env::get('DB_PASSWORD', ''),
    'charset' => (string) Env::get('DB_CHARSET', 'utf8mb4'),
];
