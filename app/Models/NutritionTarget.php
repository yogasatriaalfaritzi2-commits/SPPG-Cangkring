<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

/**
 * Batas target gizi per kelompok sasaran beserta penilaiannya. Nilai yang belum
 * ditandai terverifikasi, atau yang sumbernya masih memerlukan verifikasi, selalu
 * ikut dilaporkan supaya tidak ditampilkan seolah standar resmi.
 */
final class NutritionTarget extends Model
{
    protected static string $table = 'nutrition_targets';

    public const BASIS_PER_SERVING = 'per_porsi';
    public const BASIS_PER_DAY = 'per_hari';

    /** @var array<int, string> */
    public const BASES = [self::BASIS_PER_SERVING, self::BASIS_PER_DAY];

    /** @return array<int, array<string, mixed>> */
    public static function forGroup(int $groupId, string $basis = ''): array
    {
        $conditions = ['t.group_id = :group_id'];
        $params = ['group_id' => $groupId];

        if (in_array($basis, self::BASES, true)) {
            $conditions[] = 't.target_basis = :basis';
            $params['basis'] = $basis;
        }

        return self::fetchAll(
            'SELECT t.*, r.reference_name, r.publisher, r.published_year, r.needs_verification,
                    g.group_name
             FROM nutrition_targets t
             INNER JOIN nutrition_target_groups g ON g.id = t.group_id
             LEFT JOIN nutrition_references r ON r.id = t.reference_id
             WHERE ' . implode(' AND ', $conditions) . '
             ORDER BY t.target_basis ASC, t.nutrient_code ASC',
            $params
        );
    }

    /** @return array<string, mixed>|null */
    public static function findDetail(int $id): ?array
    {
        return self::fetchOne(
            'SELECT t.*, r.reference_name, r.needs_verification, g.group_name, g.group_code
             FROM nutrition_targets t
             INNER JOIN nutrition_target_groups g ON g.id = t.group_id
             LEFT JOIN nutrition_references r ON r.id = t.reference_id
             WHERE t.id = :id LIMIT 1',
            ['id' => $id]
        );
    }

    public static function scopeExists(int $groupId, string $code, string $basis, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) AS total FROM nutrition_targets
                WHERE group_id = :group_id AND nutrient_code = :code AND target_basis = :basis';
        $params = ['group_id' => $groupId, 'code' => $code, 'basis' => $basis];

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
            'INSERT INTO nutrition_targets (
                group_id, nutrient_code, nutrient_unit, min_value, max_value, target_basis,
                reference_id, is_verified, notes, created_by, updated_by, created_at, updated_at
             ) VALUES (
                :group_id, :nutrient_code, :nutrient_unit, :min_value, :max_value, :target_basis,
                :reference_id, :is_verified, :notes, :created_by, :updated_by, NOW(), NOW()
             )',
            self::values($data) + ['created_by' => $data['created_by'] ?? null]
        );

        return (int) Database::lastInsertId();
    }

    /** @param array<string, mixed> $data */
    public static function update(int $id, array $data): void
    {
        Database::execute(
            'UPDATE nutrition_targets SET
                nutrient_code = :nutrient_code, nutrient_unit = :nutrient_unit,
                min_value = :min_value, max_value = :max_value, target_basis = :target_basis,
                reference_id = :reference_id, is_verified = :is_verified, notes = :notes,
                updated_by = :updated_by, updated_at = NOW()
             WHERE id = :id',
            self::values($data) + ['id' => $id]
        );
    }

    public static function delete(int $id): void
    {
        Database::execute('DELETE FROM nutrition_targets WHERE id = :id', ['id' => $id]);
    }

    /**
     * Bandingkan hasil perhitungan dengan target kelompok sasaran.
     * Status hanya menyatakan posisi terhadap batas yang dikonfigurasi; angka target
     * yang belum terverifikasi ditandai agar tidak dibaca sebagai standar resmi.
     *
     * @param array<string, float> $values nutrient_code => nilai pada dasar yang sama
     * @return array{rows: array<int, array<string, mixed>>, below: int, above: int, ok: int, unverified: int}
     */
    public static function evaluate(array $values, int $groupId, string $basis): array
    {
        $targets = self::forGroup($groupId, $basis);
        $byCode = [];
        foreach ($targets as $target) {
            $byCode[(string) $target['nutrient_code']] = $target;
        }

        $rows = [];
        $below = 0;
        $above = 0;
        $ok = 0;
        $unverified = 0;

        foreach ($targets as $target) {
            $code = (string) $target['nutrient_code'];
            $value = $values[$code] ?? null;
            $min = $target['min_value'] === null ? null : (float) $target['min_value'];
            $max = $target['max_value'] === null ? null : (float) $target['max_value'];

            if ($value === null) {
                $status = 'belum_tersedia';
            } elseif ($min !== null && $value < $min) {
                $status = 'di_bawah';
                $below++;
            } elseif ($max !== null && $value > $max) {
                $status = 'di_atas';
                $above++;
            } else {
                $status = 'sesuai';
                $ok++;
            }

            if ((int) $target['is_verified'] !== 1 || (int) ($target['needs_verification'] ?? 0) === 1) {
                $unverified++;
            }

            $rows[] = [
                'nutrient_code' => $code,
                'label' => IngredientNutrient::CORE_LABELS[$code] ?? $code,
                'unit' => (string) $target['nutrient_unit'],
                'value' => $value === null ? null : round($value, 3),
                'min' => $min,
                'max' => $max,
                'status' => $status,
                'target_basis' => (string) $target['target_basis'],
                'is_verified' => (int) $target['is_verified'],
                'needs_verification' => (int) ($target['needs_verification'] ?? 0),
                'reference_name' => $target['reference_name'],
                'notes' => $target['notes'],
            ];
        }

        foreach ($values as $code => $value) {
            if (isset($byCode[$code])) {
                continue;
            }
            $rows[] = [
                'nutrient_code' => $code,
                'label' => IngredientNutrient::CORE_LABELS[$code] ?? $code,
                'unit' => '',
                'value' => round((float) $value, 3),
                'min' => null,
                'max' => null,
                'status' => 'tanpa_target',
                'target_basis' => $basis,
                'is_verified' => 0,
                'needs_verification' => 1,
                'reference_name' => null,
                'notes' => 'Zat gizi ini belum memiliki target pada kelompok sasaran.',
            ];
        }

        return [
            'rows' => $rows,
            'below' => $below,
            'above' => $above,
            'ok' => $ok,
            'unverified' => $unverified,
        ];
    }

    /** @return array<int, string> Zat gizi yang sudah punya target, untuk pilihan formulir. */
    public static function usedCodes(): array
    {
        $rows = self::fetchAll(
            'SELECT DISTINCT nutrient_code, nutrient_unit FROM nutrition_targets ORDER BY nutrient_code ASC'
        );

        return array_map(static fn (array $row): string => (string) $row['nutrient_code'], $rows);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private static function values(array $data): array
    {
        $min = $data['min_value'] ?? '';
        $max = $data['max_value'] ?? '';
        $referenceId = $data['reference_id'] ?? '';

        return [
            'group_id' => (int) $data['group_id'],
            'nutrient_code' => mb_strtolower(trim((string) $data['nutrient_code'])),
            'nutrient_unit' => mb_strtolower(trim((string) $data['nutrient_unit'])),
            'min_value' => $min === '' ? null : round((float) $min, 4),
            'max_value' => $max === '' ? null : round((float) $max, 4),
            'target_basis' => in_array((string) ($data['target_basis'] ?? ''), self::BASES, true)
                ? (string) $data['target_basis']
                : self::BASIS_PER_SERVING,
            'reference_id' => $referenceId === '' ? null : (int) $referenceId,
            'is_verified' => !empty($data['is_verified']) ? 1 : 0,
            'notes' => ($data['notes'] ?? '') === '' ? null : mb_substr((string) $data['notes'], 0, 255),
            'updated_by' => $data['updated_by'] ?? null,
        ];
    }
}
