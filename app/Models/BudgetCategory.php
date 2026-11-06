<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

final class BudgetCategory extends Model
{
    protected static string $table = 'budget_categories';

    /** @return array<int, array<string, mixed>> Kategori aktif untuk pilihan formulir. */
    public static function activeList(): array
    {
        return self::fetchAll(
            'SELECT * FROM budget_categories
             WHERE is_active = 1
             ORDER BY sort_order ASC, category_name ASC'
        );
    }

    /** @return array<int, array<string, mixed>> */
    public static function allOrdered(): array
    {
        return self::fetchAll(
            'SELECT * FROM budget_categories ORDER BY sort_order ASC, category_name ASC'
        );
    }

    /** @return array<int, array<string, mixed>> */
    public static function search(string $term, string $state): array
    {
        $conditions = [];
        $params = [];

        if ($term !== '') {
            $conditions[] = '(LOCATE(:term_code, category_code) > 0 OR LOCATE(:term_name, category_name) > 0)';
            $params['term_code'] = $term;
            $params['term_name'] = $term;
        }

        if ($state === 'active') {
            $conditions[] = 'is_active = 1';
        } elseif ($state === 'inactive') {
            $conditions[] = 'is_active = 0';
        }

        return self::fetchAll(
            'SELECT c.*,
                    (SELECT COUNT(*) FROM budget_items i WHERE i.category_id = c.id) AS item_count,
                    (SELECT COUNT(*) FROM financial_transactions t
                     WHERE t.category_id = c.id AND t.status = \'approved\') AS transaction_count
             FROM budget_categories c'
            . ($conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions))
            . ' ORDER BY sort_order ASC, category_name ASC',
            $params
        );
    }

    public static function codeExists(string $code, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) AS total FROM budget_categories WHERE category_code = :code';
        $params = ['code' => $code];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }

        return (int) (self::fetchOne($sql, $params)['total'] ?? 0) > 0;
    }

    public static function nameExists(string $name, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) AS total FROM budget_categories WHERE category_name = :name';
        $params = ['name' => $name];

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
            'INSERT INTO budget_categories (
                category_code, category_name, description, sort_order, is_active,
                created_by, updated_by, created_at, updated_at
             ) VALUES (
                :category_code, :category_name, :description, :sort_order, :is_active,
                :created_by, :updated_by, NOW(), NOW()
             )',
            [
                'category_code' => (string) $data['category_code'],
                'category_name' => (string) $data['category_name'],
                'description' => $data['description'] ?? null,
                'sort_order' => (int) ($data['sort_order'] ?? 0),
                'is_active' => !empty($data['is_active']) ? 1 : 0,
                'created_by' => $data['created_by'] ?? null,
                'updated_by' => $data['updated_by'] ?? null,
            ]
        );

        return (int) Database::lastInsertId();
    }

    /** @param array<string, mixed> $data */
    public static function update(int $id, array $data): void
    {
        Database::execute(
            'UPDATE budget_categories SET
                category_code = :category_code, category_name = :category_name,
                description = :description, sort_order = :sort_order, is_active = :is_active,
                updated_by = :updated_by, updated_at = NOW()
             WHERE id = :id',
            [
                'category_code' => (string) $data['category_code'],
                'category_name' => (string) $data['category_name'],
                'description' => $data['description'] ?? null,
                'sort_order' => (int) ($data['sort_order'] ?? 0),
                'is_active' => !empty($data['is_active']) ? 1 : 0,
                'updated_by' => $data['updated_by'] ?? null,
                'id' => $id,
            ]
        );
    }

    /**
     * Kategori yang dipakai item RAB atau transaksi tidak boleh dihapus.
     * Penggunaan normal cukup dinonaktifkan agar riwayat lama tetap terbaca.
     */
    public static function usageCount(int $id): int
    {
        $row = self::fetchOne(
            'SELECT
                (SELECT COUNT(*) FROM budget_items WHERE category_id = :id) AS items,
                (SELECT COUNT(*) FROM financial_transactions WHERE category_id = :id2) AS transactions,
                (SELECT COUNT(*) FROM project_schedules WHERE category_id = :id3 AND is_deleted = 0) AS activities',
            ['id' => $id, 'id2' => $id, 'id3' => $id]
        ) ?? [];

        return (int) ($row['items'] ?? 0) + (int) ($row['transactions'] ?? 0) + (int) ($row['activities'] ?? 0);
    }

    public static function delete(int $id): void
    {
        Database::execute('DELETE FROM budget_categories WHERE id = :id', ['id' => $id]);
    }
}
