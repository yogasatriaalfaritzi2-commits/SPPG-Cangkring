<?php
declare(strict_types=1);

namespace App\Core;

use App\Models\LoginAttempt;
use App\Models\Permission;
use App\Models\User;

final class Auth
{
    /** @var array<string, mixed>|null */
    private static ?array $user = null;

    private static bool $resolved = false;

    /** @var array<int, string>|null */
    private static ?array $permissions = null;

    /** @return array<string, mixed>|null */
    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$user;
        }
        self::$resolved = true;

        $id = Session::get('auth_id');
        if (!is_int($id) && !is_numeric($id)) {
            return null;
        }

        $user = User::find((int) $id);
        if (
            $user === null
            || (string) ($user['status'] ?? '') !== User::STATUS_ACTIVE
            || (int) ($user['role_active'] ?? 0) !== 1
        ) {
            self::invalidate();
            return null;
        }

        self::$user = $user;
        return self::$user;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function isSuperAdmin(): bool
    {
        return (string) (self::user()['role_slug'] ?? '') === User::SUPER_ADMIN_SLUG;
    }

    public static function roleSlug(): string
    {
        return (string) (self::user()['role_slug'] ?? '');
    }

    public static function roleName(): string
    {
        return (string) (self::user()['role_name'] ?? '');
    }

    /** @return array<int, string> */
    public static function permissions(): array
    {
        if (self::$permissions !== null) {
            return self::$permissions;
        }

        $user = self::user();
        if ($user === null) {
            return self::$permissions = [];
        }

        if ((string) $user['role_slug'] === User::SUPER_ADMIN_SLUG) {
            return self::$permissions = Permission::slugs();
        }

        return self::$permissions = Permission::slugsForRole((int) $user['role_id']);
    }

    public static function hasPermission(string $permission): bool
    {
        return in_array($permission, self::permissions(), true);
    }

    /** @param array<int, string> $permissions */
    public static function hasAnyPermission(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (self::hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    public static function homePath(): string
    {
        if (self::roleSlug() === 'admin' && self::hasPermission('users.view')) {
            return '/pengguna';
        }

        if (self::hasPermission('dashboard.view')) {
            return '/dashboard';
        }

        return self::hasPermission('projects.view') ? '/proyek' : '/profil';
    }

    /**
     * @return array{ok: bool, throttled: bool, message: string, user?: array<string, mixed>}
     */
    public static function attempt(string $login, string $password, string $ip): array
    {
        $login = mb_strtolower(trim($login));
        $maxAttempts = (int) App::config('app.auth.max_attempts', 5);
        $windowMinutes = (int) App::config('app.auth.window_minutes', 15);

        if (LoginAttempt::failuresInWindow($login, $ip, $windowMinutes) >= $maxAttempts) {
            return [
                'ok' => false,
                'throttled' => true,
                'message' => 'Terlalu banyak percobaan masuk yang gagal. Coba lagi beberapa saat lagi.',
            ];
        }

        $user = User::findByLogin($login);

        $canLogin = $user !== null
            && (string) ($user['status'] ?? '') === User::STATUS_ACTIVE
            && (int) ($user['role_active'] ?? 0) === 1
            && password_verify($password, (string) $user['password']);

        if (!$canLogin) {
            LoginAttempt::record($login, $ip, false);
            return [
                'ok' => false,
                'throttled' => false,
                'message' => 'Nama pengguna atau kata sandi tidak sesuai.',
            ];
        }

        if (password_needs_rehash((string) $user['password'], PASSWORD_DEFAULT)) {
            User::updatePassword((int) $user['id'], $password);
        }

        User::touchLogin((int) $user['id'], $ip);
        LoginAttempt::record($login, $ip, true);
        LoginAttempt::clearFailures($login, $ip);
        self::startSession($user);

        return [
            'ok' => true,
            'throttled' => false,
            'message' => '',
            'user' => $user,
        ];
    }

    /** @param array<string, mixed> $user */
    public static function startSession(array $user): void
    {
        Session::regenerate();
        Csrf::rotate();
        Session::set('auth_id', (int) $user['id']);
        Session::set('auth_time', time());
        Session::set('last_activity', time());

        self::$user = $user;
        self::$resolved = true;
        self::$permissions = null;
    }

    public static function logout(): void
    {
        self::invalidate();
        Csrf::rotate();
    }

    public static function invalidate(): void
    {
        self::$user = null;
        self::$resolved = true;
        self::$permissions = null;

        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        Session::forget('auth_id', 'auth_time', 'last_activity', 'url.intended');
        Session::regenerate();
    }
}