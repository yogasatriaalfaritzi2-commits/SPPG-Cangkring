<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

final class BudgetItem extends Model
{
    protected static string $table = 'budget_items';

    /**
     * Total item dihitung di sisi server dan menjadi sumber nilai resmi.
     * JavaScript hanya menampilkan perkiraan saat mengetik, nilai ini yang disimpan.
     */
    public static function computeTotal(string $volume, string $unitPrice): float
    {
        return round(((float) $volume) * ((float) $unitPrice), 2);
    }

    /** @param array<string, mixed> $data */
    public static function create(array $data): int
    {
        $volume = (string) $data['volume'];
        $unitPrice = (string) $data['unit_price'];

        Database::execute(
            'INSERT INTO budget_items (
                budget_plan_id, category_id, item_code, item_name, volume, unit, unit_price,
                total_price, notes, sort_order, created_by, updated_by, created_at, updated_at
             ) VALUES (
                :budget_plan_id, :category_id, :item_code, :item_name, :volume, :unit, :unit_price,
                :total_price, :notes, :sort_order, :created_by, :updated_by, NOW(), NOW()
             )',
            [
                'budget_plan_id' => (int) $data['budget_plan_id'],
                'category_id' => (int) $data['category_id'],
                'item_code' => (string) $data['item_code'],
                'item_name' => (string) $data['item_name'],
                'volume' => $volume,
                'unit' => (string) $data['unit'],
                'unit_price' => $unitPrice,
                'total_price' => self::computeTotal($volume, $unitPrice),
                'notes' => $data['notes'] ?? null,
                'sort_order' => (int) ($data['sort_order'] ?? 0),
                'created_by' => $data['created_by'] ?? null,
                'updated_by' => $data['updated_by'] ?? null,
            ]
        );

        return (int) Database::lastInsertId();
    }

    /** @param array<string, mixed> $data */
    public static function update(int $id, array $data): void
    {
        $volume = (string) $data['volume'];
        $unitPrice = (string) $data['unit_price'];

        Database::execute(
            'UPDATE budget_items SET
                category_id = :category_id, item_code = :item_code, item_name = :item_name,
                volume = :volume, unit = :unit, unit_price = :unit_price, total_price = :total_price,
                notes = :notes, sort_order = :sort_order, updated_by = :updated_by, updated_at = NOW()
             WHERE id = :id',
            [
                'category_id' => (int) $data['category_id'],
                'item_code' => (string) $data['item_code'],
                'item_name' => (string) $data['item_name'],
                'volume' => $volume,
                'unit' => (string) $data['unit'],
                'unit_price' => $unitPrice,
                'total_price' => self::computeTotal($volume, $unitPrice),
                'notes' => $data['notes'] ?? null,
                'sort_order' => (int) ($data['sort_order'] ?? 0),
                'updated_by' => $data['updated_by'] ?? null,
                'id' => $id,
            ]
        );
    }

    public static function delete(int $id): void
    {
        Database::execute('DELETE FROM budget_items WHERE id = :id', ['id' => $id]);
    }

    /** Salin item ke posisi baru dalam RAB yang sama atau RAB hasil revisi. */
    public static function duplicate(int $id, int $actorId): ?int
    {
        $item = self::findWithCategory($id);
        if ($item === null) {
            return null;
        }

        $planId = (int) $item['budget_plan_id'];
        $code = self::nextItemCode($planId, (string) $item['category_code']);

        return self::create([
            'budget_plan_id' => $planId,
            'category_id' => (int) $item['category_id'],
            'item_code' => $code,
            'item_name' => (string) $item['item_name'],
            'volume' => (string) $item['volume'],
            'unit' => (string) $item['unit'],
            'unit_price' => (string) $item['unit_price'],
            'notes' => $item['notes'],
            'sort_order' => (int) $item['sort_order'] + 1,
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]);
    }

    /** @return array<string, mixed>|null */
    public static function findWithCategory(int $id): ?array
    {
        return self::fetchOne(
            'SELECT i.*, c.category_code, c.category_name, p.project_id, p.status AS plan_status, p.rab_code
             FROM budget_items i
             INNER JOIN budget_categories c ON c.id = i.category_id
             INNER JOIN budget_plans p ON p.id = i.budget_plan_id
             WHERE i.id = :id
             LIMIT 1',
            ['id' => $id]
        );
    }

    /**
     * @return array<int, array<string, mixed>> Item dalam satu RAB, tersaring pencarian dan kategori.
     */
    public static function forPlan(int $planId, string $term = '', int $categoryId = 0): array
    {
        $conditions = ['i.budget_plan_id = :plan_id'];
        $params = ['plan_id' => $planId];

        if ($term !== '') {
            $conditions[] = '(LOCATE(:term_code, i.item_code) > 0 OR LOCATE(:term_name, i.item_name) > 0'
                . ' OR LOCATE(:term_cat, c.category_name) > 0)';
            $params['term_code'] = $term;
            $params['term_name'] = $term;
            $params['term_cat'] = $term;
        }

        if ($categoryId > 0) {
            $conditions[] = 'i.category_id = :category_id';
            $params['category_id'] = $categoryId;
        }

        return self::fetchAll(
            'SELECT i.*, c.category_code, c.category_name
             FROM budget_items i
             INNER JOIN budget_categories c ON c.id = i.category_id
             WHERE ' . implode(' AND ', $conditions) . '
             ORDER BY c.sort_order ASC, i.sort_order ASC, i.item_code ASC, i.id ASC',
            $params
        );
    }

    /**
     * Subtot per kategori pekerjaan. Ditampilkan pada rincian RAB dan dipakai
     * sebagai anggaran berdasarkan kategori.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function categoryTotals(int $planId): array
    {
        return self::fetchAll(
            'SELECT c.id, c.category_code, c.category_name,
                    COUNT(i.id) AS item_count,
                    COALESCE(SUM(i.total_price), 0) AS subtotal
             FROM budget_categories c
             LEFT JOIN budget_items i ON i.category_id = c.id AND i.budget_plan_id = :plan_id
             GROUP BY c.id, c.category_code, c.category_name
             HAVING item_count > 0
             ORDER BY c.sort_order ASC, c.category_name ASC',
            ['plan_id' => $planId]
        );
    }

    public static function sumForPlan(int $planId): float
    {
        $row = self::fetchOne(
            'SELECT COALESCE(SUM(total_price), 0) AS total FROM budget_items WHERE budget_plan_id = :plan_id',
            ['plan_id' => $planId]
        );

        return round((float) ($row['total'] ?? 0), 2);
    }

    public static function countForPlan(int $planId): int
    {
        $row = self::fetchOne(
            'SELECT COUNT(*) AS total FROM budget_items WHERE budget_plan_id = :plan_id',
            ['plan_id' => $planId]
        );

        return (int) ($row['total'] ?? 0);
    }

    /** @return array<int, array<string, mixed>> Item milik satu proyek, lintas RAB. */
    public static function forProject(int $projectId, int $categoryId = 0): array
    {
        $conditions = ['p.project_id = :project_id'];
        $params = ['project_id' => $projectId];

        if ($categoryId > 0) {
            $conditions[] = 'i.category_id = :category_id';
            $params['category_id'] = $categoryId;
        }

        return self::fetchAll(
            'SELECT i.*, c.category_code, c.category_name,
                    p.rab_code, p.name AS plan_name, p.status AS plan_status
             FROM budget_items i
             INNER JOIN budget_plans p ON p.id = i.budget_plan_id
             INNER JOIN budget_categories c ON c.id = i.category_id
             WHERE ' . implode(' AND ', $conditions) . '
             ORDER BY p.rab_code DESC, c.sort_order ASC, i.item_code ASC',
            $params
        );
    }

    /** Rincian item untuk RAB yang sudah disetujui, dipakai laporan anggaran per kategori. */
    public static function approvedByProjectCategory(int $projectId): array
    {
        return self::fetchAll(
            'SELECT c.id, c.category_code, c.category_name,
                    COALESCE(SUM(i.total_price), 0) AS subtotal,
                    COUNT(i.id) AS item_count
             FROM budget_items i
             INNER JOIN budget_plans p ON p.id = i.budget_plan_id
             INNER JOIN budget_categories c ON c.id = i.category_id
             WHERE p.project_id = :project_id AND p.status = \'approved\'
             GROUP BY c.id, c.category_code, c.category_name
             ORDER BY c.sort_order ASC, c.category_name ASC',
            ['project_id' => $projectId]
        );
    }

    /**
     * Anggaran per kategori dari seluruh RAB yang sudah disetujui.
     * Nomor proyek nol berarti seluruh proyek dihitung.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function approvedAmountsByCategory(int $projectId = 0): array
    {
        $conditions = ["p.status = 'approved'"];
        $params = [];

        if ($projectId > 0) {
            $conditions[] = 'p.project_id = :project_id';
            $params['project_id'] = $projectId;
        }

        return self::fetchAll(
            'SELECT c.id AS category_id, c.category_code, c.category_name, c.sort_order,
                    COALESCE(SUM(i.total_price), 0) AS planned,
                    COUNT(i.id) AS item_count
             FROM budget_items i
             INNER JOIN budget_plans p ON p.id = i.budget_plan_id
             INNER JOIN budget_categories c ON c.id = i.category_id
             WHERE ' . implode(' AND ', $conditions) . '
             GROUP BY c.id, c.category_code, c.category_name, c.sort_order
             ORDER BY c.sort_order ASC, c.category_name ASC',
            $params
        );
    }

    public static function codeExists(int $planId, string $code, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) AS total FROM budget_items WHERE budget_plan_id = :plan_id AND item_code = :code';
        $params = ['plan_id' => $planId, 'code' => $code];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }

        return (int) (self::fetchOne($sql, $params)['total'] ?? 0) > 0;
    }

    /** Kode item berikutnya untuk sebuah kategori, contoh "DAPUR-03". */
    public static function nextItemCode(int $planId, string $categoryCode): string
    {
        $prefix = mb_substr(trim($categoryCode), 0, 12) . '-';

        $rows = self::fetchAll(
            'SELECT item_code FROM budget_items
             WHERE budget_plan_id = :plan_id AND item_code LIKE :prefix',
            ['plan_id' => $planId, 'prefix' => $prefix . '%']
        );

        $highest = 0;
        foreach ($rows as $row) {
            $tail = substr((string) $row['item_code'], strlen($prefix));
            if (ctype_digit($tail)) {
                $highest = max($highest, (int) $tail);
            }
        }

        for ($sequence = $highest + 1; $sequence <= $highest + 500; $sequence++) {
            $candidate = $prefix . str_pad((string) $sequence, 2, '0', STR_PAD_LEFT);
            if (!self::codeExists($planId, $candidate)) {
                return $candidate;
            }
        }

        return $prefix . strtoupper(bin2hex(random_bytes(3)));
    }

    /** @return array<string, mixed> Baris kosong untuk form tambah item. */
    public static function emptyRecord(): array
    {
        return [
            'id' => 0,
            'budget_plan_id' => 0,
            'category_id' => 0,
            'item_code' => '',
            'item_name' => '',
            'volume' => '0',
            'unit' => '',
            'unit_price' => '0',
            'total_price' => 0,
            'notes' => '',
            'sort_order' => 0,
        ];
    }
}
