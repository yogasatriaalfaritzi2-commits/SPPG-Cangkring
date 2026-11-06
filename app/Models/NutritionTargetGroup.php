<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

/**
 * Kelompok sasaran target gizi. Target tidak boleh disamakan untuk semua usia,
 * jadi kelompok didefinisikan berdasarkan jenjang, rentang umur, dan jenis kelamin.
 */
final class NutritionTargetGroup extends Model
{
    protected static string $table = 'nutrition_target_groups';

    /** @return array<int, array<string, mixed>> */
    public static function activeList(): array
    {
        return self::fetchAll(
            'SELECT g.*, l.level_name,
                    (SELECT COUNT(*) FROM nutrition_targets t WHERE t.group_id = g.id) AS target_count
             FROM nutrition_target_groups g
             LEFT JOIN school_levels l ON l.id = g.level_id
             WHERE g.is_active = 1
             ORDER BY l.sort_order ASC, g.age_min ASC, g.group_name ASC'
        );
    }

    /** @return array<int, array<string, mixed>> */
    public static function allOrdered(): array
    {
        return self::fetchAll(
            'SELECT g.*, l.level_name,
                    (SELECT COUNT(*) FROM nutrition_targets t WHERE t.group_id = g.id) AS target_count
             FROM nutrition_target_groups g
             LEFT JOIN school_levels l ON l.id = g.level_id
             ORDER BY g.is_active DESC, l.sort_order ASC, g.group_name ASC'
        );
    }

    /** @return array<string, mixed>|null */
    public static function findDetail(int $id): ?array
    {
        return self::fetchOne(
            'SELECT g.*, l.level_name, l.level_code
             FROM nutrition_target_groups g
             LEFT JOIN school_levels l ON l.id = g.level_id
             WHERE g.id = :id LIMIT 1',
            ['id' => $id]
        );
    }

    public static function codeExists(string $code, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) AS total FROM nutrition_target_groups WHERE group_code = :code';
        $params = ['code' => $code];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }

        return (int) (self::fetchOne($sql, $params)['total'] ?? 0) > 0;
    }

    /** Kelompok yang cocok untuk satu sekolah, dipakai halaman evaluasi gizi. */
    public static function forSchool(array $school): ?array
    {
        $rows = self::fetchAll(
            'SELECT g.*, l.level_name
             FROM nutrition_target_groups g
             LEFT JOIN school_levels l ON l.id = g.level_id
             WHERE g.is_active = 1 AND (g.level_id = :level_id OR g.level_id IS NULL)
             ORDER BY g.level_id IS NULL ASC, g.group_name ASC',
            ['level_id' => (int) ($school['level_id'] ?? 0)]
        );

        return $rows === [] ? null : $rows[0];
    }

    /** @param array<string, mixed> $data */
    public static function create(array $data): int
    {
        Database::execute(
            'INSERT INTO nutrition_target_groups (
                group_code, group_name, level_id, age_min, age_max, sex, notes, is_active,
                created_by, updated_by, created_at, updated_at
             ) VALUES (
                :group_code, :group_name, :level_id, :age_min, :age_max, :sex, :notes, :is_active,
                :created_by, :updated_by, NOW(), NOW()
             )',
            self::values($data) + ['created_by' => $data['created_by'] ?? null]
        );

        return (int) Database::lastInsertId();
    }

    /** @param array<string, mixed> $data */
    public static function update(int $id, array $data): void
    {
        Database::execute(
            'UPDATE nutrition_target_groups SET
                group_code = :group_code, group_name = :group_name, level_id = :level_id,
                age_min = :age_min, age_max = :age_max, sex = :sex, notes = :notes,
                is_active = :is_active, updated_by = :updated_by, updated_at = NOW()
             WHERE id = :id',
            self::values($data) + ['id' => $id]
        );
    }

    public static function usageCount(int $id): int
    {
        return (int) (self::fetchOne(
            'SELECT COUNT(*) AS total FROM nutrition_targets WHERE group_id = :id',
            ['id' => $id]
        )['total'] ?? 0);
    }

    public static function delete(int $id): void
    {
        Database::execute('DELETE FROM nutrition_target_groups WHERE id = :id', ['id' => $id]);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private static function values(array $data): array
    {
        $sex = (string) ($data['sex'] ?? 'semua');

        return [
            'group_code' => mb_strtoupper(trim((string) $data['group_code'])),
            'group_name' => (string) $data['group_name'],
            'level_id' => ($data['level_id'] ?? '') === '' ? null : (int) $data['level_id'],
            'age_min' => ($data['age_min'] ?? '') === '' ? null : (int) $data['age_min'],
            'age_max' => ($data['age_max'] ?? '') === '' ? null : (int) $data['age_max'],
            'sex' => in_array($sex, ['l', 'p', 'semua'], true) ? $sex : 'semua',
            'notes' => ($data['notes'] ?? '') === '' ? null : mb_substr((string) $data['notes'], 0, 500),
            'is_active' => !empty($data['is_active']) ? 1 : 0,
            'updated_by' => $data['updated_by'] ?? null,
        ];
    }
}
