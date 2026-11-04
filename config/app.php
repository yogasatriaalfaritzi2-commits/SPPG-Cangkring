<?php
declare(strict_types=1);

use App\Core\Env;

return [
    'name' => (string) Env::get('APP_NAME', 'SPPG Cangkring'),
    'env' => (string) Env::get('APP_ENV', 'production'),
    'debug' => Env::bool('APP_DEBUG', false),
    'url' => rtrim((string) Env::get('APP_URL', ''), '/'),
    'timezone' => (string) Env::get('APP_TIMEZONE', 'Asia/Jakarta'),
    'version' => '0.4.0',

    'session' => [
        'name' => (string) Env::get('SESSION_NAME', 'sppg_session'),
        'lifetime' => max(5, (int) Env::get('SESSION_LIFETIME', '120')),
    ],

    'auth' => [
        'max_attempts' => max(1, (int) Env::get('LOGIN_MAX_ATTEMPTS', '5')),
        'window_minutes' => max(1, (int) Env::get('LOGIN_WINDOW_MINUTES', '15')),
    ],

    'uploads' => [
        'max_mb' => min(25, max(1, (int) Env::get('UPLOAD_MAX_MB', '10'))),
    ],
];
