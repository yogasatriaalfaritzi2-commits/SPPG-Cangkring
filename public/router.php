<?php
declare(strict_types=1);

// Router untuk server bawaan PHP:
// php -S 127.0.0.1:8099 -t public public/router.php

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri = is_string($uri) ? rawurldecode($uri) : '/';

if ($uri !== '/' && !str_contains($uri, '..')) {
    $file = __DIR__ . $uri;
    $real = realpath($file);
    if (
        $real !== false
        && is_file($real)
        && str_starts_with(strtolower(str_replace('\\', '/', $real)), strtolower(str_replace('\\', '/', __DIR__)))
    ) {
        return false;
    }
}

require __DIR__ . '/index.php';
