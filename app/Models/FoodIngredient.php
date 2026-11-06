<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;
use RuntimeException;

/**
 * Katalog bahan makanan beserta satuan, faktor konversi, bagian yang dapat dimakan,
 * dan harga rujukan. Nilai gizi tidak disimpan di tabel ini agar setiap angka dapat
 * membawa sumbernya sendiri lewat tabel ingredient_nutrients.
 */
final class FoodIngredient extends Model
{
    protected static string $table = 'food_ingredients';

    /** @var array<string, string> */
    public const SORTABLE = [
        'code' => 'f.ingredient_code',
        'name' => 'f.ingredient_name',
        'category' => 'c.category_name',
        'price' => 'f.reference_price',
    ];

    /** @return array<string, mixed>|null */
    public static function findWithNames(int $id): ?array
    {
        return self::fetchOne(
            'SELECT f.*, c.category_name, c.category_code
             FROM food_ingredients f
             INNER JOIN ingredient_categories c ON c.id = f.category_id
             WHERE f.id = :id LIMIT 1',
            ['id' => $id]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public static function optionList(): array
    {
        return self::fetchAll(
            'SELECT f.id, f.ingredient_code, f.ingredient_name, f.base_unit, f.purchase_unit,
                    f.conversion_factor, f.edible_factor, f.reference_price, f.price_basis,
                    f.allergen_info, c.category_name, c.id AS category_id, c.category_code,
                    (SELECT COUNT(*) FROM ingredient_nutrients n WHERE n.ingredient_id = f.id) AS nutrient_count
             FROM food_ingredients f
             INNER JOIN ingredient_categories c ON c.id = f.category_id
             WHERE f.is_active = 1
             ORDER BY c.sort_order ASC, f.ingredient_name ASC'
        );
    }

    /**
     * @param array{search: string, category: string, state: string, nutrition: string, sort: string, dir: string} $filters
     * @return array{rows: array<int, array<string, mixed>>, total: int, page: int, pages: int, per_page: int}
     */
    public static function paginate(array $filters, int $page, int $perPage = 12): array
    {
        $conditions = [];
        $params = [];

        if ($filters['search'] !== '') {
            $conditions[] = '(LOCATE(:search_code, f.ingredient_code) > 0'
                . ' OR LOCATE(:search_name, f.ingredient_name) > 0'
                . ' OR LOCATE(:search_allergen, COALESCE(f.allergen_info, \'\')) > 0)';
            foreach (['search_code', 'search_name', 'search_allergen'] as $key) {
                $params[$key] = $filters['search'];
            }
        }

        if ($filters['category'] !== '' && ctype_digit($filters['category'])) {
            $conditions[] = 'f.category_id = :category_id';
            $params['category_id'] = $filters['category'];
        }

        if ($filters['state'] === 'active') {
            $conditions[] = 'f.is_active = 1';
        } elseif ($filters['state'] === 'inactive') {
            $conditions[] = 'f.is_active = 0';
        }

        if ($filters['nutrition'] === 'filled') {
            $conditions[] = '(SELECT COUNT(*) FROM ingredient_nutrients n WHERE n.ingredient_id = f.id) > 0';
        } elseif ($filters['nutrition'] === 'empty') {
            $conditions[] = '(SELECT COUNT(*) FROM ingredient_nutrients n WHERE n.ingredient_id = f.id) = 0';
        }

        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

        $total = (int) (self::fetchOne(
            'SELECT COUNT(*) AS total FROM food_ingredients f
             INNER JOIN ingredient_categories c ON c.id = f.category_id' . $where,
            $params
        )['total'] ?? 0);

        $perPage = max(1, min($perPage, 50));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));

        $column = self::SORTABLE[$filters['sort']] ?? 'f.ingredient_name';
        $direction = strtolower($filters['dir']) === 'asc' ? 'ASC' : 'DESC';

        $rows = self::fetchAll(
            'SELECT f.*, c.category_name, c.category_code,
                    (SELECT COUNT(*) FROM ingredient_nutrients n WHERE n.ingredient_id = f.id) AS nutrient_count,
                    (SELECT COUNT(*) FROM menu_ingredients mi WHERE mi.ingredient_id = f.id) AS menu_count
             FROM food_ingredients f
             INNER JOIN ingredient_categories c ON c.id = f.category_id'
            . $where
            . ' ORDER BY ' . $column . ' ' . $direction . ', f.id DESC'
            . ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
            $params
        );

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
        ];
    }

    public static function codeExists(string $code, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) AS total FROM food_ingredients WHERE ingredient_code = :code';
        $params = ['code' => $code];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }

        return (int) (self::fetchOne($sql, $params)['total'] ?? 0) > 0;
    }

    public static function nextCode(?string $year = null): string
    {
        $year = $year !== null && $year !== '' ? $year : date('Y');

        for ($sequence = 1; $sequence <= 9999; $sequence++) {
            $candidate = sprintf('BHN-%s-%03d', $year, $sequence);
            if (!self::codeExists($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Kode bahan otomatis sudah habis untuk tahun ini.');
    }

    /** @param array<string, mixed> $data */
    public static function create(array $data): int
    {
        Database::execute(
            'INSERT INTO food_ingredients (
                ingredient_code, ingredient_name, category_id, base_unit, purchase_unit, conversion_factor,
                edible_factor, allergen_info, reference_price, price_basis, price_reference, price_updated_at,
                is_active, notes, created_by, updated_by, created_at, updated_at
             ) VALUES (
                :ingredient_code, :ingredient_name, :category_id, :base_unit, :purchase_unit, :conversion_factor,
                :edible_factor, :allergen_info, :reference_price, :price_basis, :price_reference, :price_updated_at,
                :is_active, :notes, :created_by, :updated_by, NOW(), NOW()
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
            'UPDATE food_ingredients SET
                ingredient_code = :ingredient_code, ingredient_name = :ingredient_name,
                category_id = :category_id, base_unit = :base_unit, purchase_unit = :purchase_unit,
                conversion_factor = :conversion_factor, edible_factor = :edible_factor,
                allergen_info = :allergen_info, reference_price = :reference_price,
                price_basis = :price_basis, price_reference = :price_reference,
                price_updated_at = :price_updated_at, is_active = :is_active, notes = :notes,
                updated_by = :updated_by, updated_at = NOW()
             WHERE id = :id',
            $values + ['id' => $id]
        );
    }

    public static function usageCount(int $id): int
    {
        return (int) (self::fetchOne(
            'SELECT (SELECT COUNT(*) FROM menu_ingredients WHERE ingredient_id = :id) AS menus',
            ['id' => $id]
        )['menus'] ?? 0);
    }

    public static function delete(int $id): void
    {
        Database::execute('DELETE FROM food_ingredients WHERE id = :id', ['id' => $id]);
    }

    /**
     * Muat seluruh nilai gizi untuk banyak bahan dalam satu query. Hasilnya
     * ingredient_id => nutrient_code => baris gizi.
     *
     * @param array<int, int> $ids
     * @return array<int, array<string, array<string, mixed>>>
     */
    public static function nutrientMap(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = self::fetchAll(
            'SELECT n.*, r.reference_name, r.needs_verification
             FROM ingredient_nutrients n
             LEFT JOIN nutrition_references r ON r.id = n.reference_id
             WHERE n.ingredient_id IN (' . $placeholders . ')
             ORDER BY n.ingredient_id ASC, n.nutrient_code ASC',
            $ids
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['ingredient_id']][(string) $row['nutrient_code']] = $row;
        }

        return $map;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private static function values(array $data): array
    {
        $number = static fn (mixed $value): ?float =>
            $value === null || $value === '' ? null : round((float) $value, 4);

        $price = $data['reference_price'] ?? '';

        return [
            'ingredient_code' => (string) $data['ingredient_code'],
            'ingredient_name' => (string) $data['ingredient_name'],
            'category_id' => (int) $data['category_id'],
            'base_unit' => mb_strtolower(trim((string) ($data['base_unit'] ?? 'gram'))),
            'purchase_unit' => ($data['purchase_unit'] ?? '') === ''
                ? null
                : mb_strtolower(trim((string) $data['purchase_unit'])),
            'conversion_factor' => $number($data['conversion_factor'] ?? null),
            'edible_factor' => $number($data['edible_factor'] ?? null),
            'allergen_info' => ($data['allergen_info'] ?? '') === '' ? null : mb_substr((string) $data['allergen_info'], 0, 255),
            'reference_price' => $price === '' || $price === null ? null : round((float) $price, 2),
            'price_basis' => ($data['price_basis'] ?? '') === '' ? null : mb_strtolower(trim((string) $data['price_basis'])),
            'price_reference' => ($data['price_reference'] ?? '') === '' ? null : mb_substr((string) $data['price_reference'], 0, 150),
            'price_updated_at' => ($data['price_updated_at'] ?? '') === '' ? null : (string) $data['price_updated_at'],
            'is_active' => !empty($data['is_active']) ? 1 : 0,
            'notes' => ($data['notes'] ?? '') === '' ? null : mb_substr((string) $data['notes'], 0, 500),
            'created_by' => $data['created_by'] ?? null,
            'updated_by' => $data['updated_by'] ?? null,
        ];
    }
}
