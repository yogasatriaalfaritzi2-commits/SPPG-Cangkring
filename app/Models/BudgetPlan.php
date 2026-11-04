<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;
use PDOException;
use RuntimeException;

final class BudgetPlan extends Model
{
    protected static string $table = 'budget_plans';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_REVISED = 'revised';

    /** @var array<int, string> */
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_REVISED,
    ];

    /** Status yang masih boleh diedit langsung tanpa proses revisi. */
    public const EDITABLE_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_REJECTED,
        self::STATUS_REVISED,
    ];

    /** @var array<string, string> */
    public const SORTABLE = [
        'code' => 'p.rab_code',
        'name' => 'p.name',
        'project' => 'pr.name',
        'period' => 'p.period_start',
        'total' => 'p.total_amount',
        'status' => 'p.status',
        'created' => 'p.created_at',
    ];

    /** @return array<string, mixed>|null */
    public static function findWithNames(int $id): ?array
    {
        return self::fetchOne(
            'SELECT p.*, pr.name AS project_name, pr.project_code, pr.is_archived AS is_project_archived,
                    pr.budget_amount AS project_budget,
                    c.full_name AS creator_name, s.full_name AS submitter_name,
                    v.full_name AS reviewer_name, o.rab_code AS revision_of_code
             FROM budget_plans p
             INNER JOIN projects pr ON pr.id = p.project_id
             LEFT JOIN users c ON c.id = p.created_by
             LEFT JOIN users s ON s.id = p.submitted_by
             LEFT JOIN users v ON v.id = p.reviewed_by
             LEFT JOIN budget_plans o ON o.id = p.revision_of
             WHERE p.id = :id
             LIMIT 1',
            ['id' => $id]
        );
    }

    /**
     * Kunci baris RAB selama perhitungan ulang total. Tanpa kunci ini dua pengguna
     * yang menambah item bersamaan dapat meninggalkan total yang tidak sama dengan
     * jumlah itemnya.
     */
    public static function lock(int $planId): void
    {
        Database::query(
            'SELECT id FROM budget_plans WHERE id = :id FOR UPDATE',
            ['id' => $planId]
        )->fetchAll();
    }

    public static function totalIsCurrent(int $planId): bool
    {
        return abs(BudgetItem::sumForPlan($planId) - self::storedTotal($planId)) < 0.01;
    }

    public static function storedTotal(int $planId): float
    {
        $row = self::fetchOne(
            'SELECT total_amount FROM budget_plans WHERE id = :id',
            ['id' => $planId]
        );

        return round((float) ($row['total_amount'] ?? 0), 2);
    }

    /**
     * Menghitung ulang total RAB dari item tersimpan. Wajib dipanggil di dalam
     * Database::transaction() bersama lock() agar nilainya konsisten.
     */
    public static function recalculateTotal(int $planId): float
    {
        $total = BudgetItem::sumForPlan($planId);

        Database::execute(
            'UPDATE budget_plans SET total_amount = :total, updated_at = NOW() WHERE id = :id',
            ['total' => $total, 'id' => $planId]
        );

        return $total;
    }

    public static function codeExists(string $code, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) AS total FROM budget_plans WHERE rab_code = :code';
        $params = ['code' => $code];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }

        return (int) (self::fetchOne($sql, $params)['total'] ?? 0) > 0;
    }

    /** Nomor RAB otomatis dengan pola RAB-Tahun-Urutan. */
    public static function nextCode(?string $year = null): string
    {
        $year = $year !== null && $year !== '' ? $year : date('Y');

        for ($sequence = 1; $sequence <= 9999; $sequence++) {
            $candidate = sprintf('RAB-%s-%03d', $year, $sequence);
            if (!self::codeExists($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Nomor RAB otomatis sudah habis untuk tahun ini.');
    }

    /**
     * @param array<string, mixed> $data
     * @return array{0: int, 1: string} Id RAB dan nomor yang benar-benar terpakai.
     */
    public static function create(array $data): array
    {
        $explicit = trim((string) ($data['rab_code'] ?? ''));
        $sql = 'INSERT INTO budget_plans (
                    rab_code, project_id, name, period_start, period_end, total_amount, status,
                    version, revision_of, notes, created_by, updated_by, created_at, updated_at
                 ) VALUES (
                    :rab_code, :project_id, :name, :period_start, :period_end, 0, :status,
                    :version, :revision_of, :notes, :created_by, :updated_by, NOW(), NOW()
                 )';

        $values = [
            'project_id' => (int) $data['project_id'],
            'name' => (string) $data['name'],
            'period_start' => (string) $data['period_start'],
            'period_end' => (string) $data['period_end'],
            'status' => (string) ($data['status'] ?? self::STATUS_DRAFT),
            'version' => (int) ($data['version'] ?? 1),
            'revision_of' => $data['revision_of'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $data['created_by'] ?? null,
            'updated_by' => $data['updated_by'] ?? null,
        ];

        if ($explicit !== '') {
            Database::execute($sql, $values + ['rab_code' => $explicit]);
            return [(int) Database::lastInsertId(), $explicit];
        }

        // Nomor dibuat otomatis. Bila dua pengguna mengajukan RAB pada detik yang sama,
        // kunci unik menolak yang kedua dan nomor berikutnya diambil ulang.
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                $code = self::nextCode();
                Database::execute($sql, $values + ['rab_code' => $code]);
                return [(int) Database::lastInsertId(), $code];
            } catch (PDOException $exception) {
                if ($exception->getCode() !== '23000' || $attempt === 5) {
                    throw $exception;
                }
            }
        }

        throw new RuntimeException('Nomor RAB otomatis sedang dipakai, silakan coba lagi.');
    }

    /** @param array<string, mixed> $data */
    public static function update(int $id, array $data): void
    {
        Database::execute(
            'UPDATE budget_plans SET
                rab_code = :rab_code, project_id = :project_id, name = :name,
                period_start = :period_start, period_end = :period_end, notes = :notes,
                updated_by = :updated_by, updated_at = NOW()
             WHERE id = :id',
            [
                'rab_code' => (string) $data['rab_code'],
                'project_id' => (int) $data['project_id'],
                'name' => (string) $data['name'],
                'period_start' => (string) $data['period_start'],
                'period_end' => (string) $data['period_end'],
                'notes' => $data['notes'] ?? null,
                'updated_by' => $data['updated_by'] ?? null,
                'id' => $id,
            ]
        );
    }

    public static function delete(int $id): void
    {
        Database::execute('DELETE FROM budget_plans WHERE id = :id', ['id' => $id]);
    }

    /**
     * Pindahkan RAB pada alur persetujuan. Pemanggil wajib berada di dalam
     * Database::transaction() supaya perubahan status dan jejak approvals ikut
     * commit atau rollback bersamaan. Kondisi status pada WHERE berfungsi sebagai
     * kunci optimistis: bila pengguna lain sudah mengubah status lebih dulu,
     * jumlah baris yang berubah bernilai nol dan pemanggil dapat membatalkan proses.
     */
    public static function changeStatus(
        int $planId,
        string $fromStatus,
        string $toStatus,
        string $action,
        ?string $note,
        int $actorId
    ): int {
        $trimmedNote = $note !== null && $note !== '' ? mb_substr($note, 0, 500) : null;

        // Setiap placeholder dipakai satu kali saja karena preparate statement asli
        // (ATTR_EMULATE_PREPARES = false) tidak mengizinkan nama yang berulang.
        $sets = ['status = :to_status', 'updated_by = :updated_by', 'updated_at = NOW()'];
        $params = [
            'to_status' => $toStatus,
            'updated_by' => $actorId,
            'plan_id' => $planId,
            'from_status' => $fromStatus,
        ];

        if ($action === 'submit') {
            $sets[] = 'submitted_by = :submitted_by';
            $sets[] = 'submitted_at = NOW()';
            $params['submitted_by'] = $actorId;
        }

        if ($action === 'approve' || $action === 'reject') {
            $sets[] = 'reviewed_by = :reviewed_by';
            $sets[] = 'reviewed_at = NOW()';
            $sets[] = 'decision_note = :decision_note';
            $params['reviewed_by'] = $actorId;
            $params['decision_note'] = $trimmedNote;
        }

        $affected = Database::execute(
            'UPDATE budget_plans SET ' . implode(', ', $sets) . '
             WHERE id = :plan_id AND status = :from_status',
            $params
        );

        Database::execute(
            'INSERT INTO budget_approvals (budget_plan_id, action, from_status, to_status, note, actor_id, created_at)
             VALUES (:plan_id, :action, :from_status, :to_status, :note, :actor_id, NOW())',
            [
                'plan_id' => $planId,
                'action' => $action,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'note' => $trimmedNote,
                'actor_id' => $actorId,
            ]
        );

        return $affected;
    }

    /** @return array<int, array<string, mixed>> */
    public static function approvals(int $planId): array
    {
        return self::fetchAll(
            'SELECT a.*, u.full_name AS actor_name
             FROM budget_approvals a
             LEFT JOIN users u ON u.id = a.actor_id
             WHERE a.budget_plan_id = :plan_id
             ORDER BY a.created_at DESC, a.id DESC',
            ['plan_id' => $planId]
        );
    }

    /** @return array<int, array<string, mixed>> Riwayat versi RAB turunan dari satu RAB induk. */
    public static function revisionsOf(int $planId): array
    {
        return self::fetchAll(
            'SELECT p.*, u.full_name AS creator_name
             FROM budget_plans p
             LEFT JOIN users u ON u.id = p.created_by
             WHERE p.revision_of = :plan_id
             ORDER BY p.version DESC, p.id DESC',
            ['plan_id' => $planId]
        );
    }

    /**
     * Salin RAB beserta itemnya menjadi RAB versi baru berstatus draft.
     * RAB induk dibiarkan berstatus approved sampai versi baru disetujui,
     * sehingga anggaran yang sedang berjalan tidak pernah kehilangan dasar.
     *
     * @return array{0: int, 1: string}
     */
    public static function copyAsRevision(int $planId, array $actor, string $name, ?string $note): array
    {
        $source = self::findWithNames($planId);
        if ($source === null) {
            throw new RuntimeException('RAB sumber tidak ditemukan.');
        }

        [$newId, $newCode] = self::create([
            'project_id' => (int) $source['project_id'],
            'name' => $name,
            'period_start' => (string) $source['period_start'],
            'period_end' => (string) $source['period_end'],
            'status' => self::STATUS_DRAFT,
            'version' => (int) $source['version'] + 1,
            'revision_of' => $planId,
            'notes' => $note,
            'created_by' => (int) $actor['id'],
            'updated_by' => (int) $actor['id'],
        ]);

        foreach (BudgetItem::forPlan($planId) as $item) {
            BudgetItem::create([
                'budget_plan_id' => $newId,
                'category_id' => (int) $item['category_id'],
                'item_code' => (string) $item['item_code'],
                'item_name' => (string) $item['item_name'],
                'volume' => (string) $item['volume'],
                'unit' => (string) $item['unit'],
                'unit_price' => (string) $item['unit_price'],
                'notes' => $item['notes'],
                'sort_order' => (int) $item['sort_order'],
                'created_by' => (int) $actor['id'],
                'updated_by' => (int) $actor['id'],
            ]);
        }

        self::recalculateTotal($newId);

        Database::execute(
            'INSERT INTO budget_approvals (budget_plan_id, action, from_status, to_status, note, actor_id, created_at)
             VALUES (:plan_id, :action, :from_status, :to_status, :note, :actor_id, NOW())',
            [
                'plan_id' => $newId,
                'action' => 'revise',
                'from_status' => (string) $source['status'],
                'to_status' => self::STATUS_DRAFT,
                'note' => 'Salinan revisi dari RAB ' . $source['rab_code'] . '.',
                'actor_id' => (int) $actor['id'],
            ]
        );

        return [$newId, $newCode];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{rows: array<int, array<string, mixed>>, total: int, page: int, pages: int, per_page: int}
     */
    public static function paginate(array $filters, int $page, int $perPage = 10): array
    {
        [$where, $params] = self::buildWhere($filters);

        $total = (int) (self::fetchOne(
            'SELECT COUNT(*) AS total FROM budget_plans p INNER JOIN projects pr ON pr.id = p.project_id' . $where,
            $params
        )['total'] ?? 0);

        $perPage = max(1, min($perPage, 50));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;

        $column = self::SORTABLE[$filters['sort']] ?? 'p.id';
        $direction = strtolower($filters['dir']) === 'asc' ? 'ASC' : 'DESC';

        $rows = self::fetchAll(
            'SELECT p.*, pr.name AS project_name, pr.project_code, pr.budget_amount AS project_budget,
                    c.full_name AS creator_name, v.full_name AS reviewer_name,
                    (SELECT COUNT(*) FROM budget_items i WHERE i.budget_plan_id = p.id) AS item_count
             FROM budget_plans p
             INNER JOIN projects pr ON pr.id = p.project_id
             LEFT JOIN users c ON c.id = p.created_by
             LEFT JOIN users v ON v.id = p.reviewed_by'
            . $where
            . ' ORDER BY ' . $column . ' ' . $direction . ', p.id DESC'
            . ' LIMIT ' . $perPage . ' OFFSET ' . $offset,
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
     * @param array<string, string> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function buildWhere(array $filters): array
    {
        $conditions = [];
        $params = [];

        if ($filters['search'] !== '') {
            $conditions[] = '(LOCATE(:search_code, p.rab_code) > 0 OR LOCATE(:search_name, p.name) > 0'
                . ' OR LOCATE(:search_project, pr.name) > 0 OR LOCATE(:search_project_code, pr.project_code) > 0)';
            foreach (['search_code', 'search_name', 'search_project', 'search_project_code'] as $key) {
                $params[$key] = $filters['search'];
            }
        }

        if (in_array($filters['status'], self::STATUSES, true)) {
            $conditions[] = 'p.status = :status';
            $params['status'] = $filters['status'];
        }

        if ($filters['project'] !== '' && ctype_digit($filters['project'])) {
            $conditions[] = 'p.project_id = :project_id';
            $params['project_id'] = (int) $filters['project'];
        }

        if ($filters['category'] !== '' && ctype_digit($filters['category'])) {
            $conditions[] = 'EXISTS (SELECT 1 FROM budget_items bi
                WHERE bi.budget_plan_id = p.id AND bi.category_id = :category_id)';
            $params['category_id'] = (int) $filters['category'];
        }

        if ($filters['year'] !== '' && ctype_digit($filters['year']) && strlen($filters['year']) === 4) {
            $conditions[] = 'YEAR(p.period_start) = :year';
            $params['year'] = (int) $filters['year'];
        }

        return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $params];
    }

    /** @return array<int, array<string, mixed>> */
    public static function forProject(int $projectId): array
    {
        return self::fetchAll(
            'SELECT p.*, c.full_name AS creator_name,
                    (SELECT COUNT(*) FROM budget_items i WHERE i.budget_plan_id = p.id) AS item_count
             FROM budget_plans p
             LEFT JOIN users c ON c.id = p.created_by
             WHERE p.project_id = :project_id
             ORDER BY p.status = \'approved\' DESC, p.version DESC, p.id DESC',
            ['project_id' => $projectId]
        );
    }

    /** Total anggaran yang sudah disetujui pada satu proyek. */
    public static function approvedTotalForProject(int $projectId): float
    {
        $row = self::fetchOne(
            "SELECT COALESCE(SUM(total_amount), 0) AS total
             FROM budget_plans
             WHERE project_id = :project_id AND status = 'approved'",
            ['project_id' => $projectId]
        );

        return round((float) ($row['total'] ?? 0), 2);
    }

    /** @return array<int, array<string, mixed>> */
    public static function approvedProjects(int $limit = 12): array
    {
        $limit = max(1, min($limit, 50));

        return self::fetchAll(
            "SELECT p.project_id, pr.project_code, pr.name AS project_name,
                    COALESCE(SUM(p.total_amount), 0) AS approved_amount
             FROM budget_plans p
             INNER JOIN projects pr ON pr.id = p.project_id
             WHERE p.status = 'approved' AND pr.is_archived = 0
             GROUP BY p.project_id, pr.project_code, pr.name
             ORDER BY approved_amount DESC
             LIMIT " . $limit
        );
    }

    /** @return array<int, array<string, mixed>> RAB yang menunggu persetujuan untuk pengingat. */
    public static function pending(int $limit = 20): array
    {
        $limit = max(1, min($limit, 100));

        return self::fetchAll(
            'SELECT p.*, pr.name AS project_name, pr.project_code, s.full_name AS submitter_name
             FROM budget_plans p
             INNER JOIN projects pr ON pr.id = p.project_id
             LEFT JOIN users s ON s.id = p.submitted_by
             WHERE p.status = \'pending\'
             ORDER BY p.submitted_at ASC, p.id ASC
             LIMIT ' . $limit
        );
    }

    public static function countByStatus(string $status): int
    {
        $row = self::fetchOne(
            'SELECT COUNT(*) AS total FROM budget_plans p
             INNER JOIN projects pr ON pr.id = p.project_id
             WHERE p.status = :status AND pr.is_archived = 0',
            ['status' => $status]
        );

        return (int) ($row['total'] ?? 0);
    }

    public static function years(): array
    {
        $rows = self::fetchAll('SELECT DISTINCT YEAR(period_start) AS y FROM budget_plans ORDER BY y DESC');

        return array_map(static fn (array $row): int => (int) $row['y'], $rows);
    }

    /** @return array<string, mixed> Baris kosong untuk form tambah RAB. */
    public static function emptyRecord(int $projectId = 0): array
    {
        return [
            'id' => 0,
            'rab_code' => '',
            'project_id' => $projectId,
            'name' => '',
            'period_start' => date('Y-01-01'),
            'period_end' => date('Y-12-31'),
            'total_amount' => 0,
            'status' => self::STATUS_DRAFT,
            'version' => 1,
            'revision_of' => null,
            'notes' => '',
        ];
    }
}
