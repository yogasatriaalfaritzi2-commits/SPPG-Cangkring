<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

/**
 * Sumber data gizi yang dapat ditelusuri. Setiap angka gizi dan target gizi menunjuk
 * ke sini, dan flag needs_verification menandai referensi yang angkanya belum dipastikan
 * terhadap dokumen asli sehingga tidak ditampilkan sebagai nilai resmi.
 */
final class NutritionReference extends Model
{
    protected static string $table = 'nutrition_references';

    /** @return array<int, array<string, mixed>> */
    public static function activeList(): array
    {
        return self::fetchAll(
            'SELECT * FROM nutrition_references
             WHERE is_active = 1
             ORDER BY published_year DESC, reference_name ASC'
        );
    }

    /** @return array<int, array<string, mixed>> */
    public static function allOrdered(): array
    {
        return self::fetchAll(
            'SELECT r.*,
                    (SELECT COUNT(*) FROM ingredient_nutrients n WHERE n.reference_id = r.id) AS nutrient_count,
                    (SELECT COUNT(*) FROM nutrition_targets t WHERE t.reference_id = r.id) AS target_count
             FROM nutrition_references r
             ORDER BY r.is_active DESC, r.published_year DESC, r.reference_name ASC'
        );
    }

    public static function codeExists(string $code, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) AS total FROM nutrition_references WHERE reference_code = :code';
        $params = ['code' => $code];

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
            'INSERT INTO nutrition_references (
                reference_code, reference_name, publisher, published_year, reference_url,
                needs_verification, is_active, created_by, updated_by, created_at, updated_at
             ) VALUES (
                :reference_code, :reference_name, :publisher, :published_year, :reference_url,
                :needs_verification, :is_active, :created_by, :updated_by, NOW(), NOW()
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
            'UPDATE nutrition_references SET
                reference_code = :reference_code, reference_name = :reference_name,
                publisher = :publisher, published_year = :published_year, reference_url = :reference_url,
                needs_verification = :needs_verification, is_active = :is_active,
                updated_by = :updated_by, updated_at = NOW()
             WHERE id = :id',
            $values + ['id' => $id]
        );
    }

    public static function usageCount(int $id): int
    {
        $row = self::fetchOne(
            'SELECT
                (SELECT COUNT(*) FROM ingredient_nutrients WHERE reference_id = :id) AS nutrients,
                (SELECT COUNT(*) FROM nutrition_targets WHERE reference_id = :id2) AS targets',
            ['id' => $id, 'id2' => $id]
        ) ?? [];

        return (int) ($row['nutrients'] ?? 0) + (int) ($row['targets'] ?? 0);
    }

    public static function delete(int $id): void
    {
        Database::execute('DELETE FROM nutrition_references WHERE id = :id', ['id' => $id]);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private static function values(array $data): array
    {
        $year = $data['published_year'] ?? '';
        $url = trim((string) ($data['reference_url'] ?? ''));

        return [
            'reference_code' => (string) $data['reference_code'],
            'reference_name' => (string) $data['reference_name'],
            'publisher' => ($data['publisher'] ?? '') === '' ? null : (string) $data['publisher'],
            'published_year' => $year === '' ? null : (int) $year,
            'reference_url' => $url === '' ? null : $url,
            'needs_verification' => !empty($data['needs_verification']) ? 1 : 0,
            'is_active' => !empty($data['is_active']) ? 1 : 0,
            'created_by' => $data['created_by'] ?? null,
            'updated_by' => $data['updated_by'] ?? null,
        ];
    }
}
