<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

final class Setting extends Model
{
    protected static string $table = 'settings';
    protected static string $primaryKey = 'setting_key';

    /** @return array<string, string> */
    public static function allKeyed(): array
    {
        $rows = self::fetchAll('SELECT setting_key, setting_value FROM settings');

        $settings = [];
        foreach ($rows as $row) {
            $settings[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
        }

        return $settings;
    }

    public static function put(string $key, string $value, ?int $updatedBy): void
    {
        Database::execute(
            'INSERT INTO settings (setting_key, setting_value, updated_by, updated_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                updated_by = VALUES(updated_by),
                updated_at = NOW()',
            [$key, $value, $updatedBy]
        );
    }

    /** @return array<string, mixed>|null */
    public static function lastUpdate(): ?array
    {
        return self::fetchOne(
            'SELECT s.updated_at, u.full_name AS user_name
             FROM settings s
             LEFT JOIN users u ON u.id = s.updated_by
             WHERE s.updated_at IS NOT NULL
             ORDER BY s.updated_at DESC
             LIMIT 1'
        );
    }
}
