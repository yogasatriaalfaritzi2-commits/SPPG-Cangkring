<?php
declare(strict_types=1);

use App\Core\App;

function url(string $path = ''): string
{
    if (preg_match('#^https?://#i', $path) === 1) {
        return $path;
    }

    $configured = (string) App::config('app.url', '');
    $base = $configured !== '' ? rtrim($configured, '/') : rtrim(
        defined('APP_BASE_PATH') ? APP_BASE_PATH : '',
        '/'
    );

    if ($path === '' || $path === '/') {
        return $base === '' ? '/' : $base . '/';
    }

    return ($base === '' ? '' : $base) . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    $relative = 'assets/' . ltrim($path, '/');
    $file = BASE_PATH . '/public/' . $relative;
    $version = is_file($file)
        ? (string) filemtime($file)
        : (string) App::config('app.version', '0.1.0');

    return url($relative) . '?v=' . rawurlencode($version);
}

function redirect(string $path, int $status = 302): never
{
    $location = url($path);
    if (!headers_sent()) {
        header('Location: ' . $location, true, $status);
    }
    exit;
}

/**
 * Membangun URL dengan parameter query, mengabaikan nilai yang kosong.
 *
 * @param array<string, mixed> $params
 */
function query_url(string $path, array $params = []): string
{
    $query = [];
    foreach ($params as $key => $value) {
        if ($value === null || $value === '' || (is_array($value) && $value === [])) {
            continue;
        }
        $query[$key] = is_scalar($value) ? (string) $value : '';
    }

    $url = url($path);

    return $query === [] ? $url : $url . '?' . http_build_query($query);
}
