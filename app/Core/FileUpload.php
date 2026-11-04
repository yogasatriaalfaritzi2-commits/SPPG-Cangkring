<?php
declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Penyimpanan berkas unggahan di luar folder public.
 * Pemeriksaan dilakukan pada isi berkas (bukan hanya nama) dan nama penyimpanan
 * dibuat acak sehingga tidak dapat ditebak maupun dieksekusi oleh web server.
 */
final class FileUpload
{
    public const SUBDIR = 'projects';

    /** @var array<string, array<int, string>> Ekstensi yang diizinkan beserta MIME hasil deteksi */
    private const ALLOWED = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
        'pdf' => ['application/pdf'],
    ];

    /**
     * @param array{name: string, type: string, tmp_name: string, error: int, size: int} $file
     * @return array{ok: bool, error: string, stored: string, mime: string, size: int, original: string}
     */
    public static function store(array $file): array
    {
        $result = [
            'ok' => false,
            'error' => '',
            'stored' => '',
            'mime' => '',
            'size' => 0,
            'original' => self::safeOriginalName($file['name']),
        ];

        match (true) {
            $file['error'] === UPLOAD_ERR_INI_SIZE,
            $file['error'] === UPLOAD_ERR_FORM_SIZE => $result['error'] = 'Ukuran berkas melebihi batas yang diizinkan server.',
            $file['error'] === UPLOAD_ERR_PARTIAL => $result['error'] = 'Berkas hanya terunggah sebagian. Silakan ulangi.',
            $file['error'] === UPLOAD_ERR_NO_TMP_DIR,
            $file['error'] === UPLOAD_ERR_CANT_WRITE,
            $file['error'] === UPLOAD_ERR_EXTENSION => $result['error'] = 'Berkas tidak dapat disimpan pada server ini.',
            default => null,
        };

        if ($result['error'] !== '') {
            return $result;
        }

        $maxBytes = (int) App::config('app.uploads.max_mb', 10) * 1048576;
        if ($file['size'] <= 0 || $file['size'] > $maxBytes) {
            $result['error'] = 'Ukuran berkas harus antara 1 byte sampai '
                . (int) App::config('app.uploads.max_mb', 10) . ' MB.';
            return $result;
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            $result['error'] = 'Berkas unggahan tidak valid.';
            return $result;
        }

        $extension = strtolower((string) pathinfo($result['original'], PATHINFO_EXTENSION));
        if (!array_key_exists($extension, self::ALLOWED)) {
            $result['error'] = 'Jenis berkas tidak diizinkan. Gunakan JPG, PNG, WEBP, atau PDF.';
            return $result;
        }

        $detected = self::detectMimeType($file['tmp_name']);
        if ($detected === '') {
            $result['error'] = 'Jenis berkas tidak dapat diperiksa. Unggahan ditolak.';
            return $result;
        }

        if (!in_array($detected, self::ALLOWED[$extension], true)) {
            $result['error'] = 'Isi berkas tidak sesuai dengan ekstensinya. Unggahan ditolak.';
            return $result;
        }

        if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true) && @getimagesize($file['tmp_name']) === false) {
            $result['error'] = 'Berkas gambar rusak atau tidak dapat dibaca.';
            return $result;
        }

        $directory = self::directory();
        if ($directory === null) {
            $result['error'] = 'Folder penyimpanan berkas belum tersedia.';
            return $result;
        }

        $stored = bin2hex(random_bytes(20)) . '.' . $extension;
        if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $stored)) {
            $result['error'] = 'Berkas gagal disimpan ke server.';
            return $result;
        }

        $result['ok'] = true;
        $result['stored'] = $stored;
        $result['mime'] = $detected;
        $result['size'] = (int) filesize($directory . '/' . $stored);

        return $result;
    }

    /**
     * Mengembalikan jalur absolut yang aman untuk sebuah berkas tersimpan.
     * Nama harus sesuai pola acak yang dibuat kelas ini dan benar-benar berada
     * di dalam folder penyimpanan.
     */
    public static function path(string $stored): ?string
    {
        if (preg_match('/^[a-f0-9]{40}\.(jpg|jpeg|png|webp|pdf)$/', $stored) !== 1) {
            return null;
        }

        $directory = self::directory();
        if ($directory === null) {
            return null;
        }

        $path = $directory . '/' . $stored;
        if (!is_file($path)) {
            return null;
        }

        $real = realpath($path);
        $root = realpath($directory);

        return $real !== null && $root !== null && str_starts_with($real, $root . DIRECTORY_SEPARATOR)
            ? $real
            : null;
    }

    public static function remove(string $stored): bool
    {
        $path = self::path($stored);

        return $path === null || @unlink($path);
    }

    /**
     * Mengirim berkas unggahan ke peramban. Pemanggil wajib memeriksa izin
     * penggunanya lebih dahulu, karena berkas disimpan di luar folder public.
     */
    public static function respond(string $stored, string $mime, string $original, bool $inline): never
    {
        $realPath = self::path($stored);
        if ($realPath === null) {
            throw new HttpException(404, 'Berkas tidak ditemukan pada penyimpanan server.');
        }

        if (!headers_sent()) {
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . (string) filesize($realPath));
            header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . self::downloadName($original) . '"');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, no-store, max-age=0');
        }

        readfile($realPath);
        exit;
    }

    private static function downloadName(string $name): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F"]/', '', $name) ?? '';
        $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? '');

        return mb_substr($clean === '' ? 'berkas' : $clean, 0, 120);
    }

    private static function detectMimeType(string $path): string
    {
        try {
            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo !== false) {
                    $mime = finfo_file($finfo, $path);
                    finfo_close($finfo);

                    return is_string($mime) ? $mime : '';
                }
            }
        } catch (Throwable) {
            return '';
        }

        return '';
    }

    /** @return string|null Jalur folder penyimpanan, dibuat bila belum ada. */
    private static function directory(): ?string
    {
        $directory = STORAGE_PATH . '/uploads/' . self::SUBDIR;

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return null;
        }

        return is_dir($directory) ? $directory : null;
    }

    private static function safeOriginalName(string $name): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? '';
        $clean = basename(str_replace('\\', '/', $clean));
        $clean = trim($clean);

        if ($clean === '') {
            return 'berkas';
        }

        return mb_substr($clean, 0, 190);
    }
}
