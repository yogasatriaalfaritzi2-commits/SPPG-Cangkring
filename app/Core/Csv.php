<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Unduhan CSV untuk modul keuangan dan jadwal. Berkas dikirim dari sisi server
 * sehingga angka yang terbaca di spreadsheet sama dengan angka di database.
 */
final class Csv
{
    /**
     * @param array<int, string> $header
     * @param array<int, array<int, mixed>> $rows
     */
    public static function send(string $filename, array $header, array $rows): never
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?? 'ekspor';
        $safe = trim($safe, '._-');
        if ($safe === '') {
            $safe = 'ekspor';
        }

        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . mb_substr($safe, 0, 120) . '.csv"');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, no-store, max-age=0');
        }

        $out = fopen('php://output', 'wb');
        if ($out === false) {
            throw new HttpException(500, 'Berkas ekspor tidak dapat disiapkan pada server ini.');
        }

        // BOM agar Excel mengenali UTF-8 tanpa mengacaukan huruf daerah.
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $header);

        foreach ($rows as $row) {
            fputcsv($out, array_map(static fn (mixed $value): string => self::guard($value), $row));
        }

        fclose($out);
        exit;
    }

    /**
     * Baca baris CSV dari berkas unggahan. Baris kosong dilewati dan pemisah koma
     * mengikuti bentuk yang ditulis Excel (termasuk titik koma untuk regional ID).
     *
     * @return array<int, array<int, string>>
     */
    public static function read(string $path, int $limit = 1000): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        // Deteksi pemisah dari baris pertama agar ekspor Excel regional dapat diimpor balik.
        $firstLine = fgets($handle);
        rewind($handle);

        $delimiter = is_string($firstLine) && substr_count($firstLine, ';') > substr_count($firstLine, ',')
            ? ';'
            : ',';

        $rows = [];
        while (($line = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            if ($line === [null] || $line === false) {
                continue;
            }

            $rows[] = array_map(static fn (mixed $value): string => is_string($value) ? trim($value) : (string) ($value ?? ''), $line);

            if (count($rows) > $limit) {
                fclose($handle);
                throw new HttpException(400, 'Berkas impor melebihi ' . $limit . ' baris.');
            }
        }

        fclose($handle);

        return $rows;
    }

    /**
     * Mencegah CSV injection: teks yang dapat dibaca spreadsheet sebagai rumus
     * diberi tanda petik satu di depannya.
     */
    private static function guard(mixed $value): string
    {
        $text = $value === null ? '' : (string) $value;

        if ($text === '' || is_numeric($text)) {
            return $text;
        }

        return preg_match('/^[=+@\-]/', $text) === 1 ? "'" . $text : $text;
    }
}
