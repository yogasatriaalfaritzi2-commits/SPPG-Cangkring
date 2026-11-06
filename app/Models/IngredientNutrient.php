<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

/**
 * Satu baris untuk satu zat gizi pada satu bahan. Angka tidak pernah dibuat otomatis:
 * setiap baris membawa sumber (referensi atau estimasi), dasar penyajian angka, dan
 * tanggal pembaruan sehingga tampilan dapat menyatakan status gizi bahan dengan jujur.
 */
final class IngredientNutrient extends Model
{
    protected static string $table = 'ingredient_nutrients';

    /** Zat gizi yang dihitung pada ringkasan menu; kode lain tetap boleh disimpan. */
    public const CORE_CODES = ['energy_kcal', 'protein_g', 'carb_g', 'fat_g', 'fiber_g'];

    /** @var array<string, string> */
    public const CORE_LABELS = [
        'energy_kcal' => 'Energi',
        'protein_g' => 'Protein',
        'carb_g' => 'Karbohidrat',
        'fat_g' => 'Lemak',
        'fiber_g' => 'Serat',
    ];

    /** @var array<string, string> */
    public const CORE_UNITS = [
        'energy_kcal' => 'kkal',
        'protein_g' => 'gram',
        'carb_g' => 'gram',
        'fat_g' => 'gram',
        'fiber_g' => 'gram',
    ];

    /** @return array<int, array<string, mixed>> */
    public static function forIngredient(int $ingredientId): array
    {
        return self::fetchAll(
            'SELECT n.*, r.reference_name, r.publisher, r.published_year, r.needs_verification
             FROM ingredient_nutrients n
             LEFT JOIN nutrition_references r ON r.id = n.reference_id
             WHERE n.ingredient_id = :ingredient_id
             ORDER BY n.nutrient_code ASC',
            ['ingredient_id' => $ingredientId]
        );
    }

    /** Satu bahan hanya boleh punya satu angka untuk tiap zat gizi. */
    public static function scopeExists(int $ingredientId, string $nutrientCode, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) AS total FROM ingredient_nutrients
                WHERE ingredient_id = :ingredient_id AND nutrient_code = :code';
        $params = ['ingredient_id' => $ingredientId, 'code' => $nutrientCode];

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
            'INSERT INTO ingredient_nutrients (
                ingredient_id, nutrient_code, nutrient_value, nutrient_unit, reference_amount, reference_unit,
                value_source, reference_id, data_updated_at, notes, created_by, updated_by, created_at, updated_at
             ) VALUES (
                :ingredient_id, :nutrient_code, :nutrient_value, :nutrient_unit, :reference_amount, :reference_unit,
                :value_source, :reference_id, :data_updated_at, :notes, :created_by, :updated_by, NOW(), NOW()
             )',
            self::values($data)
        );

        return (int) Database::lastInsertId();
    }

    /** @param array<string, mixed> $data */
    public static function update(int $id, array $data): void
    {
        $values = self::values($data);
        unset($values['created_by'], $values['ingredient_id']);

        Database::execute(
            'UPDATE ingredient_nutrients SET
                nutrient_code = :nutrient_code, nutrient_value = :nutrient_value,
                nutrient_unit = :nutrient_unit, reference_amount = :reference_amount,
                reference_unit = :reference_unit, value_source = :value_source,
                reference_id = :reference_id, data_updated_at = :data_updated_at, notes = :notes,
                updated_by = :updated_by, updated_at = NOW()
             WHERE id = :id',
            $values + ['id' => $id]
        );
    }

    public static function delete(int $id): void
    {
        Database::execute('DELETE FROM ingredient_nutrients WHERE id = :id', ['id' => $id]);
    }

    /** @return array<int, array<string, mixed>> Daftar zat gizi yang pernah dipakai, untuk pilihan formulir. */
    public static function usedCodes(): array
    {
        $rows = self::fetchAll(
            'SELECT nutrient_code, nutrient_unit, COUNT(*) AS total
             FROM ingredient_nutrients
             GROUP BY nutrient_code, nutrient_unit
             ORDER BY nutrient_code ASC'
        );

        $known = [];
        foreach (self::CORE_CODES as $code) {
            $known[$code] = true;
        }

        foreach ($rows as $row) {
            $known[(string) $row['nutrient_code']] = true;
        }

        return array_map(
            static fn (string $code): array => [
                'nutrient_code' => $code,
                'label' => self::CORE_LABELS[$code] ?? $code,
                'unit' => self::CORE_UNITS[$code] ?? 'gram',
            ],
            array_keys($known)
        );
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private static function values(array $data): array
    {
        $referenceId = $data['reference_id'] ?? '';
        $updated = $data['data_updated_at'] ?? '';

        return [
            'ingredient_id' => (int) $data['ingredient_id'],
            'nutrient_code' => mb_strtolower(trim((string) $data['nutrient_code'])),
            'nutrient_value' => round((float) $data['nutrient_value'], 4),
            'nutrient_unit' => mb_strtolower(trim((string) $data['nutrient_unit'])),
            'reference_amount' => round((float) ($data['reference_amount'] ?? 100), 3),
            'reference_unit' => mb_strtolower(trim((string) ($data['reference_unit'] ?? 'gram'))),
            'value_source' => (string) ($data['value_source'] ?? 'referensi') === 'estimasi'
                ? 'estimasi'
                : 'referensi',
            'reference_id' => $referenceId === '' || $referenceId === null ? null : (int) $referenceId,
            'data_updated_at' => $updated === '' ? null : (string) $updated,
            'notes' => ($data['notes'] ?? '') === '' ? null : mb_substr((string) $data['notes'], 0, 255),
            'created_by' => $data['created_by'] ?? null,
            'updated_by' => $data['updated_by'] ?? null,
        ];
    }
}
