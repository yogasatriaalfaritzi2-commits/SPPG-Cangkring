<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;
use RuntimeException;

/**
 * Jadwal menu harian dan mingguan beserta sekolah yang dilayani. Total porsi dan
 * estimasi biaya selalu dihitung ulang di sisi server dari alokasi per sekolah,
 * sehingga nilai yang tampil tidak pernah bergantung pada isian browser.
 */
final class MenuSchedule extends Model
{
    protected static string $table = 'menu_schedules';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_DONE = 'done';

    /** @var array<int, string> */
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_SCHEDULED,
        self::STATUS_CANCELLED,
        self::STATUS_DONE,
    ];

    /** Status yang masih boleh diubah alokasi sekolahnya. */
    public const EDITABLE_STATUSES = [self::STATUS_DRAFT, self::STATUS_PENDING, self::STATUS_APPROVED];

    /** Status yang sudah dihitung sebagai kebutuhan riil pada rencana bahan. */
    public const PLANNED_STATUSES = [self::STATUS_APPROVED, self::STATUS_SCHEDULED, self::STATUS_DONE];

    // Slot pada sc.meal_slot adalah slot jadwal, sedangkan slot kategori menu diberi
    // nama lain agar keduanya tidak saling menimpa saat baris dibaca sebagai array.
    private const FIELDS = 'sc.*, m.menu_code, m.menu_name, m.menu_status, m.version,
        m.estimated_energy_kcal, m.estimated_cost_per_serving, m.nutrition_status, m.allergen_info,
        c.category_name, c.meal_slot AS category_slot, r.full_name AS reviewer_name, u.full_name AS creator_name';

    private const FROM = ' FROM menu_schedules sc
         INNER JOIN menus m ON m.id = sc.menu_id
         INNER JOIN menu_categories c ON c.id = m.category_id
         LEFT JOIN users r ON r.id = sc.reviewed_by
         LEFT JOIN users u ON u.id = sc.created_by';

    /** @return array<string, mixed>|null */
    public static function findWithNames(int $id): ?array
    {
        return self::fetchOne('SELECT ' . self::FIELDS . self::FROM . ' WHERE sc.id = :id LIMIT 1', ['id' => $id]);
    }

    /** @return array<int, array<string, mixed>> */
    public static function allocations(int $scheduleId): array
    {
        return self::fetchAll(
            'SELECT a.*, s.school_name, s.school_code, s.school_status, l.level_name
             FROM menu_schedule_schools a
             INNER JOIN schools s ON s.id = a.school_id
             INNER JOIN school_levels l ON l.id = s.level_id
             WHERE a.schedule_id = :schedule_id
             ORDER BY s.school_name ASC, a.class_group ASC',
            ['schedule_id' => $scheduleId]
        );
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, array{school_id: int, class_group: string, portion_count: int|string, notes?: string|null}> $allocations
     */
    public static function create(array $data, array $allocations, int $actorId): int
    {
        $code = trim((string) ($data['schedule_code'] ?? ''));
        if ($code === '') {
            $code = self::nextCode((string) $data['service_date']);
        }

        return Database::transaction(static function () use ($data, $allocations, $actorId, $code): int {
            Database::execute(
                'INSERT INTO menu_schedules (
                    schedule_code, service_date, menu_id, meal_slot, schedule_status, portion_total,
                    estimated_cost_total, copy_from_id, notes, created_by, updated_by, created_at, updated_at
                 ) VALUES (
                    :schedule_code, :service_date, :menu_id, :meal_slot, :schedule_status, 0,
                    NULL, :copy_from_id, :notes, :created_by, :updated_by, NOW(), NOW()
                 )',
                [
                    'schedule_code' => $code,
                    'service_date' => (string) $data['service_date'],
                    'menu_id' => (int) $data['menu_id'],
                    'meal_slot' => (string) ($data['meal_slot'] ?? 'makan_siang'),
                    'schedule_status' => (string) ($data['schedule_status'] ?? self::STATUS_DRAFT),
                    'copy_from_id' => isset($data['copy_from_id']) && $data['copy_from_id'] !== ''
                        ? (int) $data['copy_from_id']
                        : null,
                    'notes' => self::text($data['notes'] ?? null, 500),
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                ]
            );

            $id = (int) Database::lastInsertId();
            self::replaceAllocations($id, $allocations, $actorId);
            self::recompute($id, $actorId);

            return $id;
        });
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, array{school_id: int, class_group: string, portion_count: int|string, notes?: string|null}> $allocations
     */
    public static function update(int $id, array $data, array $allocations, int $actorId): void
    {
        Database::transaction(static function () use ($id, $data, $allocations, $actorId): void {
            self::lock($id);

            Database::execute(
                'UPDATE menu_schedules SET
                    service_date = :service_date, menu_id = :menu_id, meal_slot = :meal_slot,
                    schedule_status = :schedule_status, notes = :notes,
                    updated_by = :updated_by, updated_at = NOW()
                 WHERE id = :id',
                [
                    'service_date' => (string) $data['service_date'],
                    'menu_id' => (int) $data['menu_id'],
                    'meal_slot' => (string) ($data['meal_slot'] ?? 'makan_siang'),
                    'schedule_status' => (string) ($data['schedule_status'] ?? self::STATUS_DRAFT),
                    'notes' => self::text($data['notes'] ?? null, 500),
                    'updated_by' => $actorId,
                    'id' => $id,
                ]
            );

            self::replaceAllocations($id, $allocations, $actorId);
            self::recompute($id, $actorId);
        });
    }

    public static function lock(int $id): void
    {
        Database::query('SELECT id FROM menu_schedules WHERE id = :id FOR UPDATE', ['id' => $id])->fetchAll();
    }

    /**
     * Jumlah porsi dan estimasi biaya total diturunkan dari alokasi per sekolah.
     * Biaya per porsi diambil dari kolom hasil perhitungan server pada tabel menu.
     *
     * @return array{portion_total: int, cost_total: float|null}
     */
    public static function recompute(int $id, int $actorId): array
    {
        $row = self::fetchOne(
            'SELECT COALESCE(SUM(a.portion_count), 0) AS portions, COUNT(a.id) AS schools
             FROM menu_schedule_schools a WHERE a.schedule_id = :id',
            ['id' => $id]
        ) ?? [];

        $portions = (int) ($row['portions'] ?? 0);

        $perServing = self::fetchOne(
            'SELECT m.estimated_cost_per_serving
             FROM menu_schedules sc INNER JOIN menus m ON m.id = sc.menu_id WHERE sc.id = :id',
            ['id' => $id]
        );

        $cost = $perServing === null || $perServing['estimated_cost_per_serving'] === null
            ? null
            : round((float) $perServing['estimated_cost_per_serving'] * $portions, 2);

        Database::execute(
            'UPDATE menu_schedules
             SET portion_total = :portions, estimated_cost_total = :cost,
                 updated_by = :updated_by, updated_at = NOW()
             WHERE id = :id',
            ['portions' => $portions, 'cost' => $cost, 'updated_by' => $actorId, 'id' => $id]
        );

        return ['portion_total' => $portions, 'cost_total' => $cost];
    }

    /** Jumlah jadwal pada satu tanggal, dipakai untuk menghentikan penyalinan harian. */
    public static function countOn(string $date): int
    {
        return (int) (self::fetchOne(
            'SELECT COUNT(*) AS total FROM menu_schedules WHERE service_date = :date',
            ['date' => $date]
        )['total'] ?? 0);
    }

    public static function codeExists(string $code): bool
    {
        return (int) (self::fetchOne(
            'SELECT COUNT(*) AS total FROM menu_schedules WHERE schedule_code = :code',
            ['code' => $code]
        )['total'] ?? 0) > 0;
    }

    public static function slotTaken(string $date, int $menuId, string $mealSlot, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) AS total FROM menu_schedules
                WHERE service_date = :date AND menu_id = :menu_id AND meal_slot = :slot';
        $params = ['date' => $date, 'menu_id' => $menuId, 'slot' => $mealSlot];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }

        return (int) (self::fetchOne($sql, $params)['total'] ?? 0) > 0;
    }

    public static function nextCode(string $date): string
    {
        $stamp = str_replace('-', '', $date);

        for ($sequence = 1; $sequence <= 999; $sequence++) {
            $candidate = sprintf('JMK-%s-%02d', $stamp, $sequence);
            if (!self::codeExists($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Kode jadwal menu otomatis sudah habis untuk tanggal ini.');
    }

    public static function changeStatus(int $id, string $from, string $to, int $actorId): int
    {
        return Database::execute(
            'UPDATE menu_schedules
             SET schedule_status = :to, updated_by = :updated_by, updated_at = NOW()
             WHERE id = :id AND schedule_status = :from',
            ['to' => $to, 'from' => $from, 'updated_by' => $actorId, 'id' => $id]
        );
    }

    public static function submit(int $id, int $actorId): int
    {
        return Database::execute(
            'UPDATE menu_schedules
             SET schedule_status = \'pending\', submitted_by = :actor_id, submitted_at = NOW(),
                 reviewed_by = NULL, reviewed_at = NULL, decision_note = NULL,
                 updated_by = :updated_by, updated_at = NOW()
             WHERE id = :id AND schedule_status IN (\'draft\', \'cancelled\')',
            ['actor_id' => $actorId, 'updated_by' => $actorId, 'id' => $id]
        );
    }

    public static function decide(int $id, string $to, ?string $note, int $actorId): int
    {
        return Database::execute(
            'UPDATE menu_schedules
             SET schedule_status = :to, reviewed_by = :actor_id, reviewed_at = NOW(), decision_note = :note,
                 updated_by = :updated_by, updated_at = NOW()
             WHERE id = :id AND schedule_status = \'pending\'',
            [
                'to' => $to,
                'actor_id' => $actorId,
                'note' => $note === null || $note === '' ? null : mb_substr($note, 0, 500),
                'updated_by' => $actorId,
                'id' => $id,
            ]
        );
    }

    /**
     * @param array{from: string, to: string, menu: string, status: string, school: string} $filters
     * @return array<int, array<string, mixed>>
     */
    public static function between(array $filters, int $limit = 200): array
    {
        $conditions = ['sc.service_date BETWEEN :date_from AND :date_to'];
        $params = ['date_from' => $filters['from'], 'date_to' => $filters['to']];

        if ($filters['menu'] !== '' && ctype_digit($filters['menu'])) {
            $conditions[] = 'sc.menu_id = :menu_id';
            $params['menu_id'] = $filters['menu'];
        }

        if (in_array($filters['status'], self::STATUSES, true)) {
            $conditions[] = 'sc.schedule_status = :schedule_status';
            $params['schedule_status'] = $filters['status'];
        }

        if ($filters['school'] !== '' && ctype_digit($filters['school'])) {
            $conditions[] = 'EXISTS (SELECT 1 FROM menu_schedule_schools a
                                    WHERE a.schedule_id = sc.id AND a.school_id = :school_id)';
            $params['school_id'] = $filters['school'];
        }

        $limit = max(1, min($limit, 500));

        return self::fetchAll(
            'SELECT ' . self::FIELDS
            . ', (SELECT COUNT(*) FROM menu_schedule_schools a WHERE a.schedule_id = sc.id) AS school_count'
            . self::FROM
            . ' WHERE ' . implode(' AND ', $conditions)
            . ' ORDER BY sc.service_date ASC, sc.meal_slot ASC, m.menu_name ASC'
            . ' LIMIT ' . $limit,
            $params
        );
    }

    /**
     * Salin jadwal satu hari ke tanggal tujuan. Jadwal lama tetap utuh dan baris baru
     * menandai asal penyalinan agar mudah ditelusuri.
     *
     * @return array<int, int> id jadwal baru
     */
    public static function copyDay(string $sourceDate, string $targetDate, int $actorId): array
    {
        $sources = self::fetchAll(
            'SELECT * FROM menu_schedules WHERE service_date = :date ORDER BY meal_slot ASC, id ASC',
            ['date' => $sourceDate]
        );

        $created = [];

        foreach ($sources as $source) {
            if (self::slotTaken($targetDate, (int) $source['menu_id'], (string) $source['meal_slot'])) {
                continue;
            }

            $allocations = [];
            foreach (self::allocations((int) $source['id']) as $row) {
                $allocations[] = [
                    'school_id' => (int) $row['school_id'],
                    'class_group' => (string) $row['class_group'],
                    'portion_count' => (int) $row['portion_count'],
                    'notes' => $row['notes'],
                ];
            }

            $created[] = self::create([
                'schedule_code' => self::nextCode($targetDate),
                'service_date' => $targetDate,
                'menu_id' => (int) $source['menu_id'],
                'meal_slot' => (string) $source['meal_slot'],
                'schedule_status' => self::STATUS_DRAFT,
                'copy_from_id' => (int) $source['id'],
                'notes' => 'Disalin dari jadwal ' . $sourceDate . '.',
            ], $allocations, $actorId);
        }

        return $created;
    }

    /**
     * Rencana kebutuhan bahan untuk sejumlah jadwal. Hasilnya menjadi dasar modul
     * pengadaan dan gudang; stok tidak diubah pada tahap ini.
     *
     * @param array<int, array<string, mixed>> $schedules hasil between()
     * @return array<string, mixed>
     */
    public static function materialPlan(array $schedules): array
    {
        $totals = [];
        $costTotal = 0.0;
        $costKnown = false;
        $portions = 0;
        $issues = [];
        $days = [];

        foreach ($schedules as $schedule) {
            if (in_array((string) $schedule['schedule_status'], [self::STATUS_CANCELLED], true)) {
                continue;
            }

            $menuId = (int) $schedule['menu_id'];
            $count = (int) $schedule['portion_total'];
            if ($count <= 0) {
                continue;
            }

            $portions += $count;
            $day = (string) $schedule['service_date'];
            $days[$day] = ($days[$day] ?? 0) + $count;

            if ($schedule['estimated_cost_per_serving'] !== null) {
                $costTotal += (float) $schedule['estimated_cost_per_serving'] * $count;
                $costKnown = true;
            } else {
                $issues[] = sprintf('%s: harga bahan belum lengkap, estimasi biaya tidak dihitung.', $schedule['menu_name']);
            }

            $lines = [];
            $ids = [];
            foreach (Menu::lines($menuId) as $line) {
                $ingredient = Menu::ingredientFromLine($line);
                $ids[] = (int) $ingredient['id'];
                $lines[] = [
                    'ingredient' => $ingredient,
                    'quantity' => (float) $line['quantity_per_serving'],
                    'unit' => (string) $line['quantity_unit'],
                ];
            }

            if ($lines === []) {
                $issues[] = sprintf('%s: menu belum memiliki daftar bahan.', $schedule['menu_name']);
                continue;
            }

            $computation = NutritionCalculator::menu($lines, FoodIngredient::nutrientMap($ids));
            $materials = NutritionCalculator::requirements(
                $computation['materials'],
                array_fill_keys(array_column($computation['materials'], 'ingredient_id'), $count)
            );

            foreach ($computation['per_serving'] as $code => $value) {
                $totals['nutrition:' . $code] = ($totals['nutrition:' . $code] ?? 0) + ((float) $value * $count);
            }

            foreach ($materials as $material) {
                $key = $material['ingredient_id'] . '|' . $material['base_unit'];
                if (!isset($totals[$key])) {
                    // Nilai per jadwal dipakai sebagai label (satuan, faktor konversi),
                    // sedangkan jumlah yang diakumulasikan dimulai dari nol.
                    $totals[$key] = array_merge(
                        $material,
                        ['base_quantity' => 0.0, 'cost_estimate' => null, 'portions' => 0]
                    );
                }

                $totals[$key]['base_quantity'] = round((float) $totals[$key]['base_quantity'] + (float) $material['base_quantity'], 3);
                $totals[$key]['portions'] = (int) $totals[$key]['portions'] + (int) $material['portions'];

                if ($material['cost_estimate'] !== null) {
                    $totals[$key]['cost_estimate'] = round((float) ($totals[$key]['cost_estimate'] ?? 0)
                        + (float) $material['cost_estimate'], 2);
                }
            }
        }

        $rows = [];
        $nutrition = [];
        foreach ($totals as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'nutrition:')) {
                $nutrition[substr($key, 10)] = round((float) $value, 2);
                continue;
            }
            $rows[] = $value;
        }

        foreach ($rows as $index => $row) {
            $factor = (float) ($row['conversion_factor'] ?? 0);
            $purchase = (string) ($row['purchase_unit'] ?? '');
            $rows[$index]['purchase_quantity'] = $purchase !== '' && $purchase !== $row['base_unit'] && $factor > 0
                ? round((float) $row['base_quantity'] / $factor, 3)
                : null;
            $rows[$index]['portions'] = (int) $row['portions'];
        }

        usort($rows, static fn (array $a, array $b): int => [$a['category_name'], $a['ingredient_name']]
            <=> [$b['category_name'], $b['ingredient_name']]);

        return [
            'rows' => $rows,
            'nutrition' => $nutrition,
            'portions' => $portions,
            'days' => $days,
            'cost_total' => $costKnown ? round($costTotal, 2) : null,
            'issues' => array_values(array_unique($issues)),
        ];
    }

    /**
     * Deret porsi harian untuk grafik operasional. Jadwal yang dibatalkan tidak
     * ikut dihitung agar kurvanya tidak menampilkan porsi yang tidak dijalankan.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function dailySeries(string $from, string $to): array
    {
        return self::fetchAll(
            'SELECT sc.service_date AS date, COUNT(*) AS schedules,
                    COALESCE(SUM(sc.portion_total), 0) AS portions,
                    COALESCE(SUM(sc.estimated_cost_total), 0) AS cost
             FROM menu_schedules sc
             WHERE sc.service_date BETWEEN :date_from AND :date_to
               AND sc.schedule_status <> \'cancelled\'
             GROUP BY sc.service_date
             ORDER BY sc.service_date ASC',
            ['date_from' => $from, 'date_to' => $to]
        );
    }

    /** @return array<int, array<string, mixed>> Jadwal terdekat yang belum selesai. */
    public static function upcoming(int $limit = 8): array
    {
        $limit = max(1, min($limit, 30));

        return self::fetchAll(
            'SELECT ' . self::FIELDS . self::FROM
            . " WHERE sc.service_date >= CURDATE() AND sc.schedule_status IN ('approved', 'scheduled')
               ORDER BY sc.service_date ASC, sc.meal_slot ASC LIMIT " . $limit
        );
    }

    /** @return array<string, mixed> */
    public static function summary(string $from, string $to): array
    {
        $row = self::fetchOne(
            'SELECT COUNT(*) AS total,
                    COALESCE(SUM(portion_total), 0) AS portions,
                    COALESCE(SUM(estimated_cost_total), 0) AS cost,
                    COALESCE(SUM(schedule_status = \'pending\'), 0) AS pending,
                    COALESCE(SUM(schedule_status = \'draft\'), 0) AS draft,
                    COALESCE(SUM(schedule_status = \'scheduled\'), 0) AS scheduled,
                    COALESCE(SUM(schedule_status = \'done\'), 0) AS `done`
             FROM menu_schedules WHERE service_date BETWEEN :date_from AND :date_to',
            ['date_from' => $from, 'date_to' => $to]
        ) ?? [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'portions' => (int) ($row['portions'] ?? 0),
            'cost' => round((float) ($row['cost'] ?? 0), 2),
            'pending' => (int) ($row['pending'] ?? 0),
            'draft' => (int) ($row['draft'] ?? 0),
            'scheduled' => (int) ($row['scheduled'] ?? 0),
            'done' => (int) ($row['done'] ?? 0),
        ];
    }

    public static function delete(int $id): void
    {
        Database::execute('DELETE FROM menu_schedules WHERE id = :id', ['id' => $id]);
    }

    /**
     * @param array<int, array{school_id: int, class_group: string, portion_count: int|string, notes?: string|null}> $allocations
     */
    private static function replaceAllocations(int $scheduleId, array $allocations, int $actorId): void
    {
        Database::execute('DELETE FROM menu_schedule_schools WHERE schedule_id = :id', ['id' => $scheduleId]);

        foreach ($allocations as $allocation) {
            $count = (int) $allocation['portion_count'];
            if ((int) $allocation['school_id'] <= 0 || $count <= 0) {
                continue;
            }

            Database::execute(
                'INSERT INTO menu_schedule_schools (
                    schedule_id, school_id, portion_count, class_group, notes,
                    created_by, updated_by, created_at, updated_at
                 ) VALUES (
                    :schedule_id, :school_id, :portion_count, :class_group, :notes,
                    :created_by, :updated_by, NOW(), NOW()
                 )',
                [
                    'schedule_id' => $scheduleId,
                    'school_id' => (int) $allocation['school_id'],
                    'portion_count' => $count,
                    'class_group' => mb_substr((string) ($allocation['class_group'] ?? ''), 0, 60),
                    'notes' => self::text($allocation['notes'] ?? null, 255),
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                ]
            );
        }
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
