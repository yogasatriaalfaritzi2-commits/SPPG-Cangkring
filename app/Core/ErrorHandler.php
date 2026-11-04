<?php
declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\HttpException;
use ErrorException;
use Throwable;

final class ErrorHandler
{
    private static bool $debug = false;
    private static string $logDirectory = '';
    private static bool $handled = false;

    public static function register(bool $debug, string $logDirectory): void
    {
        self::$debug = $debug;
        self::$logDirectory = $logDirectory;

        ini_set('log_errors', '1');
        ini_set('error_log', self::logFile());

        error_reporting(E_ALL);
        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    public static function handleError(int $severity, string $message, string $file, int $line): bool
    {
        if ((error_reporting() & $severity) === 0) {
            return false;
        }

        if (in_array($severity, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
            return false;
        }

        throw new ErrorException($message, 0, $severity, $file, $line);
    }

    public static function handleShutdown(): void
    {
        if (self::$handled) {
            return;
        }

        $error = error_get_last();
        if ($error === null) {
            return;
        }

        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (!in_array($error['type'], $fatalTypes, true)) {
            return;
        }

        $exception = new ErrorException(
            $error['message'],
            0,
            $error['type'],
            $error['file'],
            $error['line']
        );

        self::handleException($exception);
    }

    public static function handleException(Throwable $exception): void
    {
        if (self::$handled) {
            return;
        }
        self::$handled = true;

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $isHttp = $exception instanceof HttpException;
        $status = $isHttp ? $exception->statusCode() : 500;
        $errorId = strtoupper(bin2hex(random_bytes(4)));

        if ($status >= 500) {
            self::writeLog($exception, $status, $errorId);
        }

        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, sprintf(
                "[%s] %s: %s in %s:%d\n",
                $errorId,
                $exception::class,
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine()
            ));
            exit(1);
        }

        http_response_code($status);
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store, private');

        $titles = [
            400 => 'Permintaan tidak valid',
            403 => 'Akses ditolak',
            404 => 'Halaman tidak ditemukan',
            405 => 'Metode tidak diizinkan',
            500 => 'Terjadi kesalahan pada server',
        ];

        try {
            if ($status === 500) {
                View::render('errors.500', [
                    'title' => $titles[500],
                    'errorId' => $errorId,
                    'exception' => self::$debug ? $exception : null,
                    'message' => 'Permintaan Anda tidak dapat diproses saat ini. Peristiwa ini telah dicatat oleh sistem.',
                    'actionHref' => url(App::currentPath()),
                    'actionLabel' => 'Muat ulang halaman',
                ], 'layouts.error');
            } else {
                $view = match ($status) {
                    400 => 'errors.400',
                    403 => 'errors.403',
                    404 => 'errors.404',
                    405 => 'errors.405',
                    default => 'errors.404',
                };

                View::render($view, [
                    'title' => $titles[$status] ?? 'Terjadi kesalahan',
                    'message' => $exception->getMessage(),
                    'actionHref' => url('/login'),
                    'actionLabel' => 'Kembali ke halaman masuk',
                ], 'layouts.error');
            }
        } catch (Throwable $inner) {
            self::writeLog($inner, 500, $errorId);
            header('Content-Type: text/html; charset=UTF-8');
            echo '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8">'
                . '<meta name="viewport" content="width=device-width, initial-scale=1">'
                . '<title>Kesalahan Server</title></head>'
                . '<body style="font-family:system-ui,sans-serif;padding:2rem;">'
                . '<h1>Terjadi kesalahan pada server</h1>'
                . '<p>Silakan coba kembali beberapa saat lagi.</p>'
                . '<p style="color:#666;">Referensi: ' . htmlspecialchars($errorId, ENT_QUOTES, 'UTF-8') . '</p>'
                . '</body></html>';
        }

        exit;
    }

    private static function writeLog(Throwable $exception, int $status, string $errorId): void
    {
        $directory = self::$logDirectory;
        if ($directory === '') {
            return;
        }

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return;
        }

        $context = PHP_SAPI === 'cli'
            ? 'cli'
            : sprintf(
                '%s %s from %s',
                $_SERVER['REQUEST_METHOD'] ?? 'GET',
                App::currentPath(),
                $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            );

        $line = sprintf(
            "[%s] id=%s status=%d %s\n%s: %s in %s:%d\n%s\n\n",
            date('c'),
            $errorId,
            $status,
            $context,
            $exception::class,
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $exception->getTraceAsString()
        );

        $file = self::logFile();
        if (@file_put_contents($file, $line, FILE_APPEND | LOCK_EX) === false) {
            error_log($line);
        }
    }

    private static function logFile(): string
    {
        $directory = self::$logDirectory !== '' ? self::$logDirectory : STORAGE_PATH . '/logs';
        return rtrim($directory, '/\\') . '/app-' . date('Y-m-d') . '.log';
    }
}
