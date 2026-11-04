<?php
declare(strict_types=1);

namespace App\Core;

use App\Models\Setting;
use Throwable;

final class App
{
    /** @var array<string, mixed> */
    private static array $config = [];

    /** @var array<string, string> */
    private static array $settings = [];

    private static bool $settingsLoaded = false;
    private static string $basePath = '';
    private static string $currentPath = '/';

    private Router $router;

    public function __construct(array $config, string $basePath)
    {
        self::$config = $config;
        self::$basePath = $basePath;
        Database::configure($config['database']);
        $this->router = new Router();
    }

    public function run(): void
    {
        header_remove('X-Powered-By');
        $this->sendSecurityHeaders();
        header('Cache-Control: no-store, no-cache, must-revalidate, private');
        header('Pragma: no-cache');

        $request = Request::capture();
        self::$currentPath = $request->path();

        $this->loadSettings();

        Session::start(self::config('app.session', []), self::$basePath);
        $this->expireIdleSession();

        Csrf::verify($request);

        $routes = require BASE_PATH . '/routes/web.php';
        $routes($this->router);

        $this->router->dispatch($request);
    }

    public static function loadSettings(): void
    {
        if (self::$settingsLoaded) {
            return;
        }
        self::$settingsLoaded = true;

        self::$settings = self::readSettings();

        $timezone = self::setting('timezone', '');
        if (
            is_string($timezone)
            && $timezone !== ''
            && in_array($timezone, timezone_identifiers_list(), true)
        ) {
            date_default_timezone_set($timezone);
        }
    }

    /**
     * Muat ulang pengaturan setelah nilai disimpan, sehingga perubahan ambang
     * peringatan dan kebijakan berlaku pada permintaan berikutnya tanpa kode baru.
     */
    public static function refreshSettings(): void
    {
        self::$settingsLoaded = true;
        self::$settings = self::readSettings();
    }

    /** @return array<string, string> */
    private static function readSettings(): array
    {
        try {
            return Setting::allKeyed();
        } catch (Throwable) {
            return [];
        }
    }

    private function expireIdleSession(): void
    {
        $minutes = (int) self::config('app.session.lifetime', 120);
        $lastActivity = (int) Session::get('last_activity', 0);

        if (Session::has('auth_id') && $lastActivity > 0 && (time() - $lastActivity) > ($minutes * 60)) {
            Auth::invalidate();
            Session::flash('warning', 'Sesi Anda telah berakhir. Silakan masuk kembali.');
        }

        Session::set('last_activity', time());
    }

    private function sendSecurityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header(
            // font-src mengizinkan data: karena berkas bundel FullCalendar menyertakan font
            // ikonnya sendiri sebagai URI base64. Font tidak dapat menjalankan skrip dan
            // sumbernya tetap lokal, jadi pelonggaran ini tidak membuka asal pihak ketiga.
            "Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; "
            . "frame-ancestors 'none'; img-src 'self' data:; font-src 'self' data:; "
            . "style-src 'self' 'unsafe-inline'; script-src 'self'; object-src 'none'"
        );
    }

    public static function config(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$config)) {
            return self::$config[$key];
        }

        $value = self::$config;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public static function setting(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, self::$settings) ? self::$settings[$key] : $default;
    }

    /** @return array<string, string> */
    public static function settings(): array
    {
        return self::$settings;
    }

    public static function basePath(): string
    {
        return self::$basePath;
    }

    public static function currentPath(): string
    {
        return self::$currentPath;
    }
}
