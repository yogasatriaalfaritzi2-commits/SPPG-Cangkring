<?php
declare(strict_types=1);

/**
 * @return array<int, string> Daftar pesan pelanggaran kebijakan kata sandi.
 */
function password_policy_errors(string $password, string $label = 'Kata sandi'): array
{
    $errors = [];
    $length = strlen($password);

    if ($length < 8 || $length > 72) {
        $errors[] = $label . ' harus terdiri dari 8 sampai 72 karakter.';
    }

    if ($length > 0 && preg_match('/[A-Za-z]/', $password) !== 1) {
        $errors[] = $label . ' harus memuat minimal satu huruf.';
    }

    if ($length > 0 && preg_match('/\d/', $password) !== 1) {
        $errors[] = $label . ' harus memuat minimal satu angka.';
    }

    if ($length > 0 && preg_match('/\s/', $password) === 1) {
        $errors[] = $label . ' tidak boleh memuat spasi.';
    }

    return $errors;
}

function phone_normalize(string $value): string
{
    $digits = preg_replace('/[^0-9]/', '', $value) ?? '';

    if (strlen($digits) > 10 && str_starts_with($digits, '62')) {
        $digits = '0' . substr($digits, 2);
    }

    return $digits;
}

function phone_is_valid(string $value): bool
{
    return preg_match('/^0[2-9][0-9]{7,12}$/', phone_normalize($value)) === 1;
}

function username_is_valid(string $value): bool
{
    return preg_match('/^[a-z0-9][a-z0-9._-]{2,39}$/', $value) === 1;
}

/**
 * Membentuk slug aman dari teks bebas, mengikuti gaya slug role yang sudah ada
 * (huruf kecil dengan garis bawah sebagai pemisah).
 */
function slugify_text(string $value): string
{
    $slug = mb_strtolower(trim($value), 'UTF-8');
    $slug = preg_replace('/[^a-z0-9]+/', '_', $slug) ?? '';
    $slug = trim($slug, '_');

    return substr($slug, 0, 40);
}

function slug_is_valid(string $value): bool
{
    return preg_match('/^[a-z][a-z0-9_]{2,39}$/', $value) === 1;
}

/** Kode proyek: huruf besar di awal, lalu huruf/angka/pemisah, maksimal 30 karakter. */
function project_code_is_valid(string $value): bool
{
    return preg_match('/^[A-Z][A-Z0-9._-]{2,29}$/', $value) === 1;
}

/** Tanggal harus benar-benar ada di kalender dan ditulis sebagai YYYY-MM-DD. */
function date_is_valid(string $value): bool
{
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
        return false;
    }

    $date = DateTime::createFromFormat('Y-m-d', $value);

    return $date !== false && $date->format('Y-m-d') === $value;
}

function coordinate_is_valid(string $value, bool $isLatitude): bool
{
    if (!is_numeric($value)) {
        return false;
    }

    $number = (float) $value;

    return $isLatitude
        ? $number >= -90 && $number <= 90
        : $number >= -180 && $number <= 180;
}
