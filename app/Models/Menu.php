<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;
use RuntimeException;

/**
 * Menu MBG beserta daftar bahan, riwayat persetujuan, dan alur revisinya.
 *
 * Nilai estimasi gizi dan biaya tidak pernah diambil dari isian formulir: keduanya
 * dihitung ulang di sisi server dari daftar bahan setiap kali komposisi berubah.
 * Menu yang sudah disetujui tidak dapat diubah langsung; perubahannya dibuat sebagai
 * menu turunan berversi agar riwayat dan jadwal lama tetap menunjuk komposisi aslinya.
 */
final class Menu extends Model
{
    protected static string $table = 'menus';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_DONE = 'done';
    public const STATUS_CANCELLED = 'cancelled';

    /** @var array<int, string> */
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_SCHEDULED,
        self::STATUS_DONE,
        self::STATUS_CANCELLED,
    ];

    /** Status yang masih boleh diedit langsung tanpa jalur revisi. */
    public const EDITABLE_STATUSES = [self::STATUS_DRAFT, self::STATUS_REJECTED];

    /** @var array<string, string> */
    public const SORTABLE = [
        'code' => 'm.menu_code',
        'name' => 'm.menu_name',
        'category' => 'c.category_name',
        'status' => 'm.menu_status',
        'energy' => 'm.estimated_energy_kcal',
        'cost' => 'm.estimated_cost_per_serving',
        'updated' => 'm.updated_at',
    ];

    private const FIELDS = 'm.*, c.category_name, c.meal_slot, c.requires_verification,
        u.full_name AS creator_name, r.full_name AS reviewer_name, s.full_name AS submitter_name';

    private const FROM = ' FROM menus m
         INNER JOIN menu_categories c ON c.id = m.category_id
         LEFT JOIN users u ON u.id = m.created_by
         LEFT JOIN users r ON r.id = m.reviewed_by
         LEFT JOIN users s ON s.id = m.submitted_by';

    /** @return array<string, mixed>|null */
    public static function findWithNames(int $id): ?array
    {
        return self::fetchOne('SELECT ' . self::FIELDS . self::FROM . ' WHERE m.id = :id LIMIT 1', ['id' => $id]);
    }

    /**
     * Menu beserta perhitungan gizinya, dipakai halaman detail, perencana menu, dan uji.
     *
     * @return array<string, mixed>|null
     */
    public static function findComputed(int $id): ?array
    {
        $menu = self::findWithNames($id);
        if ($menu === null) {
            return null;
        }

        $menu['computation'] = self::compute($id);
        $menu['lines'] = self::lines($id);

        return $menu;
    }

    /** @return array<int, array<string, mixed>> */
    public static function lines(int $menuId): array
    {
        return self::fetchAll(
            'SELECT i.id, i.menu_id, i.ingredient_id, i.quantity_per_serving, i.quantity_unit, i.notes,
                    f.ingredient_code, f.ingredient_name, f.base_unit, f.purchase_unit,
                    f.conversion_factor, f.edible_factor, f.reference_price, f.price_basis,
                    f.allergen_info, f.is_active, cat.category_name, cat.category_code
             FROM menu_ingredients i
             INNER JOIN food_ingredients f ON f.id = i.ingredient_id
             INNER JOIN ingredient_categories cat ON cat.id = f.category_id
             WHERE i.menu_id = :menu_id
             ORDER BY cat.sort_order ASC, f.ingredient_name ASC',
            ['menu_id' => $menuId]
        );
    }

    /**
     * Jalankan ulang perhitungan dari baris bahan yang tersimpan di database.
     *
     * @return array<string, mixed> hasil NutritionCalculator::menu()
     */
    public static function compute(int $menuId): array
    {
        $lines = [];
        $ids = [];
        foreach (self::lines($menuId) as $line) {
            $ingredient = self::ingredientFromLine($line);
            $ids[] = (int) $ingredient['id'];
            $lines[] = [
                'ingredient' => $ingredient,
                'quantity' => (float) $line['quantity_per_serving'],
                'unit' => (string) $line['quantity_unit'],
            ];
        }

        return NutritionCalculator::menu($lines, FoodIngredient::nutrientMap($ids));
    }

    /**
     * Hitung dari calon komposisi sebelum disimpan, dipakai pratinjau formulir
     * dan perhitungan usulan revisi.
     *
     * @param array<int, array{ingredient_id: int, quantity: float|string, unit: string}> $items
     * @return array<string, mixed>
     */
    public static function computeItems(array $items): array
    {
        $items = self::itemsToLines($items);
        $ids = array_column($items, 'ingredient_id');
        $ingredients = self::ingredientsById($ids);

        $lines = [];
        foreach ($items as $item) {
            $id = (int) $item['ingredient_id'];
            if (!isset($ingredients[$id])) {
                continue;
            }

            $unit = (string) $item['unit'];
            $lines[] = [
                'ingredient' => $ingredients[$id],
                'quantity' => (float) $item['quantity'],
                'unit' => $unit === '' ? (string) $ingredients[$id]['base_unit'] : $unit,
            ];
        }

        return NutritionCalculator::menu($lines, FoodIngredient::nutrientMap($ids));
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, array{ingredient_id: int, quantity: float|string, unit: string}> $items
     */
    public static function create(array $data, array $items, int $actorId): int
    {
        $code = trim((string) ($data['menu_code'] ?? ''));
        if ($code === '') {
            $code = self::nextCode();
        }

        return Database::transaction(static fn (): int => self::insert($data, $items, $actorId, $code));
    }

    /**
     * Sisipan menu tanpa membuka transaksi sendiri, dipakai jalur revisi yang
     * harus menulis menu baru, catatan approvals, dan usulan revisi sekaligus.
     *
     * @param array<string, mixed> $data
     * @param array<int, array{ingredient_id: int, quantity: float|string, unit: string}> $items
     */
    public static function insert(array $data, array $items, int $actorId, string $code): int
    {
        $result = self::computeItems($items);

        Database::execute(
            'INSERT INTO menus (
                menu_code, menu_name, description, category_id, photo_path, menu_status, version,
                revision_of, allergen_info, nutrition_notes, estimated_energy_kcal, estimated_protein_g,
                estimated_carb_g, estimated_fat_g, estimated_fiber_g, estimated_cost_per_serving,
                nutrition_status, created_by, updated_by, created_at, updated_at
             ) VALUES (
                :menu_code, :menu_name, :description, :category_id, :photo_path, \'draft\', :version,
                :revision_of, :allergen_info, :nutrition_notes, :estimated_energy_kcal, :estimated_protein_g,
                :estimated_carb_g, :estimated_fat_g, :estimated_fiber_g, :estimated_cost_per_serving,
                :nutrition_status, :created_by, :updated_by, NOW(), NOW()
             )',
            [
                'menu_code' => $code,
                'menu_name' => (string) $data['menu_name'],
                'description' => self::text($data['description'] ?? null, 500),
                'category_id' => (int) $data['category_id'],
                'photo_path' => self::text($data['photo_path'] ?? null, 255),
                'version' => max(1, (int) ($data['version'] ?? 1)),
                'revision_of' => isset($data['revision_of']) && $data['revision_of'] !== ''
                    ? (int) $data['revision_of']
                    : null,
                'allergen_info' => self::text($data['allergen_info'] ?? null, 500),
                'nutrition_notes' => self::text($data['nutrition_notes'] ?? null, 1000),
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ] + NutritionCalculator::snapshot($result)
        );

        $id = (int) Database::lastInsertId();
        self::replaceLines($id, $items, $actorId);

        return $id;
    }

    /**
     * Ubah menu yang masih dapat diedit. Daftar bahan diganti seluruhnya di dalam
     * transaksi yang sama agar komposisi dan ringkasan gizinya tidak pernah berbeda.
     *
     * @param array<string, mixed> $data
     * @param array<int, array{ingredient_id: int, quantity: float|string, unit: string}> $items
     */
    public static function update(int $id, array $data, array $items, int $actorId): void
    {
        Database::transaction(static function () use ($id, $data, $items, $actorId): void {
            self::lock($id);
            $result = self::computeItems($items);

            Database::execute(
                'UPDATE menus SET
                    menu_name = :menu_name, description = :description, category_id = :category_id,
                    allergen_info = :allergen_info, nutrition_notes = :nutrition_notes,
                    estimated_energy_kcal = :estimated_energy_kcal, estimated_protein_g = :estimated_protein_g,
                    estimated_carb_g = :estimated_carb_g, estimated_fat_g = :estimated_fat_g,
                    estimated_fiber_g = :estimated_fiber_g, estimated_cost_per_serving = :estimated_cost_per_serving,
                    nutrition_status = :nutrition_status, updated_by = :updated_by, updated_at = NOW()
                 WHERE id = :id',
                [
                    'menu_name' => (string) $data['menu_name'],
                    'description' => self::text($data['description'] ?? null, 500),
                    'category_id' => (int) $data['category_id'],
                    'allergen_info' => self::text($data['allergen_info'] ?? null, 500),
                    'nutrition_notes' => self::text($data['nutrition_notes'] ?? null, 1000),
                    'updated_by' => $actorId,
                    'id' => $id,
                ] + NutritionCalculator::snapshot($result)
            );

            self::replaceLines($id, $items, $actorId);
        });
    }

    public static function setPhoto(int $id, ?string $stored, int $actorId): void
    {
        Database::execute(
            'UPDATE menus SET photo_path = :photo_path, updated_by = :updated_by, updated_at = NOW() WHERE id = :id',
            ['photo_path' => $stored, 'updated_by' => $actorId, 'id' => $id]
        );
    }

    /** Simpan ulang ringkasan gizi setelah bahan ditambahkan atau dihapus di luar formulir menu. */
    public static function recalculate(int $id, int $actorId): array
    {
        return Database::transaction(static function () use ($id, $actorId): array {
            self::lock($id);
            $result = self::compute($id);

            Database::execute(
                'UPDATE menus SET
                    estimated_energy_kcal = :estimated_energy_kcal,
                    estimated_protein_g = :estimated_protein_g,
                    estimated_carb_g = :estimated_carb_g,
                    estimated_fat_g = :estimated_fat_g,
                    estimated_fiber_g = :estimated_fiber_g,
                    estimated_cost_per_serving = :estimated_cost_per_serving,
                    nutrition_status = :nutrition_status,
                    updated_by = :updated_by, updated_at = NOW()
                 WHERE id = :id',
                NutritionCalculator::snapshot($result) + ['id' => $id, 'updated_by' => $actorId]
            );

            return $result;
        });
    }

    public static function codeExists(string $code, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) AS total FROM menus WHERE menu_code = :code';
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
            $candidate = sprintf('MNU-%s-%03d', $year, $sequence);
            if (!self::codeExists($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Kode menu otomatis sudah habis untuk tahun ini.');
    }

    /**
     * @param array{search: string, category: string, status: string, nutrition: string, sort: string, dir: string} $filters
     * @return array{rows: array<int, array<string, mixed>>, total: int, page: int, pages: int, per_page: int}
     */
    public static function paginate(array $filters, int $page, int $perPage = 10): array
    {
        $conditions = [];
        $params = [];

        if ($filters['search'] !== '') {
            $conditions[] = '(LOCATE(:search_code, m.menu_code) > 0 OR LOCATE(:search_name, m.menu_name) > 0'
                . ' OR LOCATE(:search_allergen, COALESCE(m.allergen_info, \'\')) > 0)';
            foreach (['search_code', 'search_name', 'search_allergen'] as $key) {
                $params[$key] = $filters['search'];
            }
        }

        if ($filters['category'] !== '' && ctype_digit($filters['category'])) {
            $conditions[] = 'm.category_id = :category_id';
            $params['category_id'] = $filters['category'];
        }

        if (in_array($filters['status'], self::STATUSES, true)) {
            $conditions[] = 'm.menu_status = :menu_status';
            $params['menu_status'] = $filters['status'];
        }

        if (in_array($filters['nutrition'], ['lengkap', 'sebagian', 'belum_tersedia'], true)) {
            $conditions[] = 'm.nutrition_status = :nutrition_status';
            $params['nutrition_status'] = $filters['nutrition'];
        }

        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

        $total = (int) (self::fetchOne('SELECT COUNT(*) AS total' . self::FROM . $where, $params)['total'] ?? 0);

        $perPage = max(1, min($perPage, 50));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));

        $column = self::SORTABLE[$filters['sort']] ?? 'm.updated_at';
        $direction = strtolower($filters['dir']) === 'asc' ? 'ASC' : 'DESC';

        $rows = self::fetchAll(
            'SELECT ' . self::FIELDS
            . ', (SELECT COUNT(*) FROM menu_ingredients i WHERE i.menu_id = m.id) AS item_count'
            . self::FROM . $where
            . ' ORDER BY ' . $column . ' ' . $direction . ', m.id DESC'
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

    /**
     * Menu yang boleh dijadwalkan. Hanya menu yang sudah disetujui dan tidak butuh
     * verifikasi tambahan yang ditawarkan pada perencana.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function schedulableList(): array
    {
        return self::fetchAll(
            "SELECT m.id, m.menu_code, m.menu_name, m.category_id, c.category_name, c.meal_slot,
                    m.estimated_energy_kcal, m.estimated_cost_per_serving, m.nutrition_status,
                    m.allergen_info, m.version
             FROM menus m
             INNER JOIN menu_categories c ON c.id = m.category_id
             WHERE m.menu_status IN ('approved', 'scheduled')
             ORDER BY c.sort_order ASC, m.menu_name ASC, m.version DESC"
        );
    }

    public static function lock(int $id): void
    {
        Database::query('SELECT id FROM menus WHERE id = :id FOR UPDATE', ['id' => $id])->fetchAll();
    }

    /**
     * Menu yang memakai salah satu bahan ini dan boleh dihitung ulang, yaitu hanya
     * menu berstatus draf atau ditolak. Menu yang sudah disetujui tidak diubah diam-diam
     * karena nilainya menjadi dasar jadwal; perubahannya lewat jalur revisi.
     *
     * @param array<int, int> $ingredientIds
     * @return array<int, array<string, mixed>>
     */
    public static function recalculableByIngredients(array $ingredientIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $ingredientIds)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        return self::fetchAll(
            'SELECT DISTINCT m.id, m.menu_code, m.menu_name, m.menu_status
             FROM menus m
             INNER JOIN menu_ingredients mi ON mi.menu_id = m.id
             WHERE mi.ingredient_id IN (' . $placeholders . ')
               AND m.menu_status IN (\'draft\', \'rejected\')
             ORDER BY m.menu_name ASC',
            $ids
        );
    }

    public static function changeStatus(int $id, string $from, string $to, int $actorId): int
    {
        return Database::execute(
            'UPDATE menus SET menu_status = :to, updated_by = :updated_by, updated_at = NOW()
             WHERE id = :id AND menu_status = :from',
            ['to' => $to, 'from' => $from, 'updated_by' => $actorId, 'id' => $id]
        );
    }

    public static function submit(int $id, int $actorId): int
    {
        return Database::execute(
            'UPDATE menus
             SET menu_status = \'pending\', submitted_by = :actor_id, submitted_at = NOW(),
                 reviewed_by = NULL, reviewed_at = NULL, decision_note = NULL,
                 updated_by = :updated_by, updated_at = NOW()
             WHERE id = :id AND menu_status IN (\'draft\', \'rejected\')',
            ['actor_id' => $actorId, 'updated_by' => $actorId, 'id' => $id]
        );
    }

    /** Setujui atau tolak menu yang sedang menunggu pemeriksaan ahli gizi. */
    public static function decide(int $id, string $to, ?string $note, int $actorId): int
    {
        return Database::execute(
            'UPDATE menus
             SET menu_status = :to, reviewed_by = :actor_id, reviewed_at = NOW(), decision_note = :note,
                 updated_by = :updated_by, updated_at = NOW()
             WHERE id = :id AND menu_status = \'pending\'',
            [
                'to' => $to,
                'actor_id' => $actorId,
                'note' => $note === null || $note === '' ? null : mb_substr($note, 0, 500),
                'updated_by' => $actorId,
                'id' => $id,
            ]
        );
    }

    public static function log(
        int $menuId,
        string $action,
        string $fromStatus,
        string $toStatus,
        ?string $note,
        int $actorId
    ): void {
        Database::execute(
            'INSERT INTO menu_approvals (menu_id, action, from_status, to_status, note, actor_id, created_at)
             VALUES (:menu_id, :action, :from_status, :to_status, :note, :actor_id, NOW())',
            [
                'menu_id' => $menuId,
                'action' => $action,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'note' => $note === null || $note === '' ? null : mb_substr($note, 0, 500),
                'actor_id' => $actorId,
            ]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public static function approvals(int $menuId): array
    {
        return self::fetchAll(
            'SELECT a.*, u.full_name AS actor_name
             FROM menu_approvals a
             LEFT JOIN users u ON u.id = a.actor_id
             WHERE a.menu_id = :menu_id
             ORDER BY a.created_at DESC, a.id DESC
             LIMIT 50',
            ['menu_id' => $menuId]
        );
    }

    /** @return array<int, array<string, mixed>> Rantai versi turunan dari satu menu. */
    public static function lineage(int $menuId): array
    {
        return self::fetchAll(
            'SELECT m.id, m.menu_code, m.menu_name, m.version, m.menu_status, m.revision_of,
                    m.estimated_energy_kcal, m.estimated_cost_per_serving, u.full_name AS creator_name,
                    m.created_at
             FROM menus m
             LEFT JOIN users u ON u.id = m.created_by
             WHERE m.id = :id OR m.revision_of = :id2
             ORDER BY m.version ASC, m.id ASC',
            ['id' => $menuId, 'id2' => $menuId]
        );
    }

    /** Menu hanya boleh dihapus selama belum dipakai jadwal dan belum punya turunan. */
    public static function usageCount(int $id): int
    {
        $row = self::fetchOne(
            'SELECT (SELECT COUNT(*) FROM menu_schedules WHERE menu_id = :id) AS schedules,
                    (SELECT COUNT(*) FROM menus WHERE revision_of = :id2) AS revisions',
            ['id' => $id, 'id2' => $id]
        ) ?? [];

        return (int) ($row['schedules'] ?? 0) + (int) ($row['revisions'] ?? 0);
    }

    public static function delete(int $id): void
    {
        Database::execute('DELETE FROM menus WHERE id = :id', ['id' => $id]);
    }

    /** @return array<int, array<string, mixed>> */
    public static function statusDistribution(): array
    {
        return self::fetchAll(
            'SELECT menu_status AS status, COUNT(*) AS total
             FROM menus GROUP BY menu_status ORDER BY total DESC'
        );
    }

    public static function countByStatus(string $status): int
    {
        return (int) (self::fetchOne(
            'SELECT COUNT(*) AS total FROM menus WHERE menu_status = :status',
            ['status' => $status]
        )['total'] ?? 0);
    }

    /** @return array<string, int> jumlah menu per kelengkapan data gizi */
    public static function nutritionCounts(): array
    {
        $rows = self::fetchAll('SELECT nutrition_status, COUNT(*) AS total FROM menus GROUP BY nutrition_status');
        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['nutrition_status']] = (int) $row['total'];
        }

        return $counts;
    }

    /** @param array<string, mixed> $line @return array<string, mixed> */
    public static function ingredientFromLine(array $line): array
    {
        return [
            'id' => (int) $line['ingredient_id'],
            'ingredient_code' => (string) $line['ingredient_code'],
            'ingredient_name' => (string) $line['ingredient_name'],
            'category_name' => (string) ($line['category_name'] ?? ''),
            'category_code' => (string) ($line['category_code'] ?? ''),
            'base_unit' => (string) $line['base_unit'],
            'purchase_unit' => $line['purchase_unit'] ?? null,
            'conversion_factor' => $line['conversion_factor'] ?? null,
            'edible_factor' => $line['edible_factor'] ?? null,
            'reference_price' => $line['reference_price'] ?? null,
            'price_basis' => $line['price_basis'] ?? null,
            'allergen_info' => $line['allergen_info'] ?? null,
        ];
    }

    /**
     * @param array<int, int> $ids
     * @return array<int, array<string, mixed>> indexed by ingredient id
     */
    private static function ingredientsById(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = self::fetchAll(
            'SELECT f.id, f.ingredient_code, f.ingredient_name, f.base_unit, f.purchase_unit,
                    f.conversion_factor, f.edible_factor, f.reference_price, f.price_basis,
                    f.allergen_info, f.is_active, c.category_name, c.category_code, c.id AS category_id
             FROM food_ingredients f
             INNER JOIN ingredient_categories c ON c.id = f.category_id
             WHERE f.id IN (' . $placeholders . ')',
            $ids
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['id']] = $row;
        }

        return $map;
    }

    /** @param array<int, array{ingredient_id: int, quantity: float|string, unit: string}> $items */
    private static function replaceLines(int $menuId, array $items, int $actorId): void
    {
        Database::execute('DELETE FROM menu_ingredients WHERE menu_id = :menu_id', ['menu_id' => $menuId]);

        foreach (self::itemsToLines($items) as $item) {
            Database::execute(
                'INSERT INTO menu_ingredients (
                    menu_id, ingredient_id, quantity_per_serving, quantity_unit, notes,
                    created_by, updated_by, created_at, updated_at
                 ) VALUES (
                    :menu_id, :ingredient_id, :quantity_per_serving, :quantity_unit, :notes,
                    :created_by, :updated_by, NOW(), NOW()
                 )',
                [
                    'menu_id' => $menuId,
                    'ingredient_id' => $item['ingredient_id'],
                    'quantity_per_serving' => $item['quantity'],
                    'quantity_unit' => mb_substr($item['unit'], 0, 20),
                    'notes' => self::text($item['notes'] ?? null, 255),
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                ]
            );
        }
    }

    /**
     * @param array<int, array{ingredient_id: int, quantity: float|string, unit: string, notes?: string|null}> $items
     * @return array<int, array{ingredient_id: int, quantity: float, unit: string, notes?: string|null}>
     */
    public static function itemsToLines(array $items): array
    {
        $clean = [];
        foreach ($items as $item) {
            $id = (int) ($item['ingredient_id'] ?? 0);
            $quantity = round((float) ($item['quantity'] ?? 0), 4);
            if ($id <= 0 || $quantity <= 0) {
                continue;
            }
            $clean[$id] = [
                'ingredient_id' => $id,
                'quantity' => $quantity,
                'unit' => (string) ($item['unit'] ?? 'gram'),
                'notes' => $item['notes'] ?? null,
            ];
        }

        return array_values($clean);
    }

    private static function text(mixed $value, int $limit): ?string
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            return null;
        }

        return mb_substr($text, 0, $limit);
    }
}
