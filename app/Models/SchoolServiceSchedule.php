<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

/**
 * Hari pelayanan per sekolah. Jadwal layanan tidak diasumsikan sama untuk semua
 * sekolah, melainkan dikonfigurasi per hari dan slot makan.
 */
final class SchoolServiceSchedule extends Model
{
    protected static string $table = 'school_service_schedules';

    /** @var array<int, string> */
    public const WEEKDAYS = [
        1 => 'Senin',
        2 => 'Selasa',
        3 => 'Rabu',
        4 => 'Kamis',
        5 => 'Jumat',
        6 => 'Sabtu',
        7 => 'Minggu',
    ];

    /** @return array<int, array<string, mixed>> */
    public static function forSchool(int $schoolId): array
    {
        return self::fetchAll(
            'SELECT * FROM school_service_schedules
             WHERE school_id = :school_id
             ORDER BY service_weekday ASC, meal_slot ASC',
            ['school_id' => $schoolId]
        );
    }

    /**
     * Sekolah yang dilayani pada satu hari dan slot tertentu, beserta hari dan
     * jam layanannya. Dipakai perencana menu agar tidak menawarkan sekolah yang
     * tidak menerima pada hari tersebut.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function schoolsOn(int $weekday, string $mealSlot = ''): array
    {
        $conditions = ['d.service_weekday = :weekday', 'd.is_active = 1', "s.school_status = 'aktif'"];
        $params = ['weekday' => $weekday];

        if ($mealSlot !== '' && MenuCategory::slotExists($mealSlot)) {
            $conditions[] = 'd.meal_slot = :meal_slot';
            $params['meal_slot'] = $mealSlot;
        }

        return self::fetchAll(
            'SELECT s.id, s.school_code, s.school_name, l.level_name, d.service_weekday,
                    d.service_time, d.meal_slot, d.portion_limit,
                    ' . sprintf(School::CURRENT_TOTAL_SQL, 's') . ' AS beneficiary_total
             FROM school_service_schedules d
             INNER JOIN schools s ON s.id = d.school_id
             INNER JOIN school_levels l ON l.id = s.level_id
             WHERE ' . implode(' AND ', $conditions) . '
             ORDER BY s.school_name ASC',
            $params
        );
    }

    /**
     * Hari pelayanan yang benar-benar berjalan: barisnya ada dan masih aktif.
     * Beda dengan scopeExists() yang hanya memeriksa duplikasi saat menyimpan konfigurasi.
     */
    public static function hasActiveDay(int $schoolId, int $weekday, string $mealSlot): bool
    {
        return (int) (self::fetchOne(
            'SELECT COUNT(*) AS total FROM school_service_schedules
             WHERE school_id = :school_id AND service_weekday = :weekday AND meal_slot = :slot
               AND is_active = 1',
            ['school_id' => $schoolId, 'weekday' => $weekday, 'slot' => $mealSlot]
        )['total'] ?? 0) > 0;
    }

    public static function scopeExists(int $schoolId, int $weekday, string $mealSlot, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) AS total FROM school_service_schedules
                WHERE school_id = :school_id AND service_weekday = :weekday AND meal_slot = :slot';
        $params = ['school_id' => $schoolId, 'weekday' => $weekday, 'slot' => $mealSlot];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }

        return (int) (self::fetchOne($sql, $params)['total'] ?? 0) > 0;
    }

    /** @param array<string, mixed> $data */
    public static function create(array $data): int
    {
        Database::execute(
            'INSERT INTO school_service_schedules (
                school_id, service_weekday, service_time, meal_slot, portion_limit, is_active, notes,
                created_by, updated_by, created_at, updated_at
             ) VALUES (
                :school_id, :service_weekday, :service_time, :meal_slot, :portion_limit, :is_active, :notes,
                :created_by, :updated_by, NOW(), NOW()
             )',
            self::values($data)
        );

        return (int) Database::lastInsertId();
    }

    /** @param array<string, mixed> $data */
    public static function update(int $id, array $data): void
    {
        $values = self::values($data);
        unset($values['created_by']);

        Database::execute(
            'UPDATE school_service_schedules SET
                school_id = :school_id, service_weekday = :service_weekday, service_time = :service_time,
                meal_slot = :meal_slot, portion_limit = :portion_limit, is_active = :is_active,
                notes = :notes, updated_by = :updated_by, updated_at = NOW()
             WHERE id = :id',
            $values + ['id' => $id]
        );
    }

    public static function delete(int $id): void
    {
        Database::execute('DELETE FROM school_service_schedules WHERE id = :id', ['id' => $id]);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private static function values(array $data): array
    {
        $portionLimit = $data['portion_limit'] ?? null;

        return [
            'school_id' => (int) $data['school_id'],
            'service_weekday' => (int) $data['service_weekday'],
            'service_time' => ($data['service_time'] ?? '') === '' ? null : (string) $data['service_time'],
            'meal_slot' => (string) ($data['meal_slot'] ?? 'makan_siang'),
            'portion_limit' => $portionLimit === null || $portionLimit === '' ? null : (int) $portionLimit,
            'is_active' => !empty($data['is_active']) ? 1 : 0,
            'notes' => ($data['notes'] ?? '') === '' ? null : mb_substr((string) $data['notes'], 0, 255),
            'created_by' => $data['created_by'] ?? null,
            'updated_by' => $data['updated_by'] ?? null,
        ];
    }

    /**
     * Sekolah aktif yang belum punya satu pun hari pelayanan aktif. Tanpa hari
     * pelayanan, penyusun jadwal tidak dapat menawarkan sekolah tersebut.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function schoolsWithoutDays(int $limit = 10): array
    {
        $limit = max(1, min($limit, 50));

        return self::fetchAll(
            'SELECT s.id, s.school_code, s.school_name, l.level_name
             FROM schools s
             INNER JOIN school_levels l ON l.id = s.level_id
             WHERE s.school_status = \'aktif\'
               AND NOT EXISTS (SELECT 1 FROM school_service_schedules d
                               WHERE d.school_id = s.id AND d.is_active = 1)
             ORDER BY s.school_name ASC LIMIT ' . $limit
        );
    }

    public static function weekdayName(int $weekday): string
    {
        return self::WEEKDAYS[$weekday] ?? (string) $weekday;
    }
}
