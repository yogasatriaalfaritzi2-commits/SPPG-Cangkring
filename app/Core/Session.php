<?php
declare(strict_types=1);

namespace App\Core;

final class Session
{
    private static bool $started = false;

    /** @param array<string, mixed> $config */
    public static function start(array $config, string $basePath): void
    {
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            return;
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');

        session_name((string) ($config['name'] ?? 'sppg_session'));

        $secure = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
        $cookiePath = $basePath === '' ? '/' : rtrim($basePath, '/') . '/';

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $cookiePath,
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
        self::$started = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public static function forget(string ...$keys): void
    {
        foreach ($keys as $key) {
            unset($_SESSION[$key]);
        }
    }

    public static function pull(string $key, mixed $default = null): mixed
    {
        $value = self::get($key, $default);
        self::forget($key);
        return $value;
    }

    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    /** @return array<int, array{type: string, message: string}> */
    public static function getFlashes(): array
    {
        $flashes = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);

        if (!is_array($flashes)) {
            return [];
        }

        return array_values(array_filter(
            $flashes,
            static fn (mixed $flash): bool => is_array($flash) && isset($flash['type'], $flash['message'])
        ));
    }

    /** @param array<string, string> $data */
    public static function putOld(array $data): void
    {
        $_SESSION['_old'] = $data;
    }

    public static function clearOld(): void
    {
        unset($_SESSION['_old']);
    }
}
