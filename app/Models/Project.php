<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;
use RuntimeException;

final class Project extends Model
{
    protected static string $table = 'projects';

    public const STATUS_PLANNING = 'planning';
    public const STATUS_PREPARATION = 'preparation';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DELAYED = 'delayed';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    /** @var array<int, string> */
    public const STATUSES = [
        self::STATUS_PLANNING,
        self::STATUS_PREPARATION,
        self::STATUS_RUNNING,
        self::STATUS_DELAYED,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    /** Status yang masih dihitung sebagai pekerjaan berjalan. */
    private const OPEN_STATUSES = [
        self::STATUS_PLANNING,
        self::STATUS_PREPARATION,
        self::STATUS_RUNNING,
        self::STATUS_DELAYED,
    ];

    /** @var array<string, string> */
    public const SORTABLE = [
        'code' => 'p.project_code',
        'name' => 'p.name',
        'location' => 'p.location',
        'pic' => 'p.pic_name',
        'start' => 'p.start_date',
        'target' => 'p.target_date',
        'budget' => 'p.budget_amount',
        'progress' => 'p.progress',
        'status' => 'p.status',
    ];

    private const OVERDUE_SQL =
        "(p.status IN ('planning', 'preparation', 'running', 'delayed') AND p.target_date < CURDATE())";

    private const SELECT_FIELDS =
        'p.*, u.full_name AS pic_user_name, c.full_name AS creator_name';

    private const SELECT_FROM =
        ' FROM projects p
         LEFT JOIN users u ON u.id = p.pic_user_id
         LEFT JOIN users c ON c.id = p.created_by';

    /** @return array<string, mixed>|null */
    public static function findWithNames(int $id): ?array
    {
        return self::fetchOne(
            'SELECT ' . self::SELECT_FIELDS . self::SELECT_FROM . ' WHERE p.id = :id LIMIT 1',
            ['id' => $id]
        );
    }

    /**
     * Daftar proyek untuk pilihan pada formulir dan penyaring modul keuangan serta
     * jadwal. Kolom nama dan kode ikut terbaca sehingga view tidak perlu query tambahan.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function optionList(bool $includeArchived = false, int $limit = 200): array
    {
        $limit = max(1, min($limit, 500));

        return self::fetchAll(
            'SELECT ' . self::SELECT_FIELDS . self::SELECT_FROM
            . ($includeArchived ? '' : ' WHERE p.is_archived = 0')
            . ' ORDER BY p.name ASC, p.project_code ASC LIMIT ' . $limit
        );
    }

    public static function codeExists(string $code, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) AS total FROM projects WHERE project_code = :code';
        $params = ['code' => $code];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }

        $row = self::fetchOne($sql, $params);

        return (int) ($row['total'] ?? 0) > 0;
    }

    /**
     * Kode berikutnya dengan pola PRK-Tahun-Urutan. Urutan dihitung per tahun
     * dan dinaikkan sampai menemukan kode yang benar-benar belum terpakai.
     */
    public static function nextCode(?string $year = null): string
    {
        $year = $year !== null && $year !== '' ? $year : date('Y');

        for ($sequence = 1; $sequence <= 9999; $sequence++) {
            $candidate = sprintf('PRK-%s-%03d', $year, $sequence);
            if (!self::codeExists($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Kode proyek otomatis sudah habis untuk tahun ini.');
    }

    /**
     * @param array{search: string, status: string, from: string, to: string, archived: string, sort: string, dir: string} $filters
     * @return array{rows: array<int, array<string, mixed>>, total: int, page: int, pages: int, per_page: int}
     */
    public static function paginate(array $filters, int $page, int $perPage = 10): array
    {
        $conditions = [];
        $params = [];

        if ($filters['search'] !== '') {
            $conditions[] = '(LOCATE(:search_code, p.project_code) > 0 OR LOCATE(:search_name, p.name) > 0'
                . ' OR LOCATE(:search_location, p.location) > 0 OR LOCATE(:search_pic, p.pic_name) > 0'
                . ' OR LOCATE(:search_contractor, COALESCE(p.contractor, \'\')) > 0)';
            foreach (['search_code', 'search_name', 'search_location', 'search_pic', 'search_contractor'] as $key) {
                $params[$key] = $filters['search'];
            }
        }

        if (in_array($filters['status'], self::STATUSES, true)) {
            $conditions[] = 'p.status = :status';
            $params['status'] = $filters['status'];
        }

        if ($filters['from'] !== '') {
            $conditions[] = 'p.target_date >= :date_from';
            $params['date_from'] = $filters['from'];
        }

        if ($filters['to'] !== '') {
            $conditions[] = 'p.target_date <= :date_to';
            $params['date_to'] = $filters['to'];
        }

        if ($filters['archived'] === '1') {
            $conditions[] = 'p.is_archived = 1';
        } elseif ($filters['archived'] !== 'all') {
            $conditions[] = 'p.is_archived = 0';
        }

        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

        $countRow = self::fetchOne('SELECT COUNT(*) AS total FROM projects p' . $where, $params);
        $total = (int) ($countRow['total'] ?? 0);

        $perPage = max(1, min($perPage, 50));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;

        $column = self::SORTABLE[$filters['sort']] ?? 'p.id';
        $direction = strtolower($filters['dir']) === 'asc' ? 'ASC' : 'DESC';

        $rows = self::fetchAll(
            'SELECT ' . self::SELECT_FIELDS
            . ', ' . self::OVERDUE_SQL . ' AS is_overdue'
            . self::SELECT_FROM
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

    /** @return array<string, mixed> Ringkasan proyek aktif untuk dashboard. */
    public static function summary(): array
    {
        $row = self::fetchOne(
            'SELECT COUNT(*) AS `total`,
                    COALESCE(SUM(status = \'running\'), 0) AS `running`,
                    COALESCE(SUM(status = \'completed\'), 0) AS `completed`,
                    COALESCE(SUM(status = \'delayed\'), 0) AS `delayed`,
                    COALESCE(SUM(status = \'planning\'), 0) AS `planning`,
                    COALESCE(SUM(status = \'preparation\'), 0) AS `preparation`,
                    COALESCE(SUM(status = \'cancelled\'), 0) AS `cancelled`,
                    COALESCE(SUM(budget_amount), 0) AS `budget_total`,
                    COALESCE(ROUND(AVG(progress), 1), 0) AS `avg_progress`,
                    COALESCE(SUM(' . str_replace('p.', '', self::OVERDUE_SQL) . '), 0) AS `overdue`
             FROM projects
             WHERE is_archived = 0'
        ) ?? [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'running' => (int) ($row['running'] ?? 0),
            'completed' => (int) ($row['completed'] ?? 0),
            'delayed' => (int) ($row['delayed'] ?? 0),
            'planning' => (int) ($row['planning'] ?? 0),
            'preparation' => (int) ($row['preparation'] ?? 0),
            'cancelled' => (int) ($row['cancelled'] ?? 0),
            'budget_total' => (float) ($row['budget_total'] ?? 0),
            'avg_progress' => (float) ($row['avg_progress'] ?? 0),
            'overdue' => (int) ($row['overdue'] ?? 0),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public static function statusDistribution(): array
    {
        return self::fetchAll(
            "SELECT status, COUNT(*) AS total
             FROM projects
             WHERE is_archived = 0
             GROUP BY status
             ORDER BY total DESC, status ASC"
        );
    }

    /** @return array<int, array<string, mixed>> Proyek dengan target selesai terdekat. */
    public static function upcoming(int $limit = 5): array
    {
        $limit = max(1, min($limit, 20));
        $placeholders = "'" . implode("', '", self::OPEN_STATUSES) . "'";

        return self::fetchAll(
            'SELECT p.id, p.project_code, p.name, p.location, p.target_date, p.status, p.progress
             FROM projects p
             WHERE p.is_archived = 0
               AND p.status IN (' . $placeholders . ')
               AND p.target_date >= CURDATE()
             ORDER BY p.target_date ASC, p.id ASC
             LIMIT ' . $limit
        );
    }

    /** @return array<int, array<string, mixed>> Proyek yang melewati target selesai. */
    public static function overdue(int $limit = 5): array
    {
        $limit = max(1, min($limit, 20));

        return self::fetchAll(
            'SELECT ' . self::SELECT_FIELDS . ', DATEDIFF(CURDATE(), p.target_date) AS days_late'
            . self::SELECT_FROM
            . ' WHERE p.is_archived = 0 AND ' . self::OVERDUE_SQL
            . ' ORDER BY p.target_date ASC, p.id ASC'
            . ' LIMIT ' . $limit
        );
    }

    /**
     * Anggaran per proyek. Bila rentang tanggal diberikan, proyek disaring
     * berdasarkan target selesai di dalam rentang tersebut.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function budgetByProject(string $from, string $to, int $limit = 6): array
    {
        $limit = max(1, min($limit, 12));
        $conditions = ['p.is_archived = 0'];
        $params = [];

        if ($from !== '') {
            $conditions[] = 'p.target_date >= :date_from';
            $params['date_from'] = $from;
        }

        if ($to !== '') {
            $conditions[] = 'p.target_date <= :date_to';
            $params['date_to'] = $to;
        }

        return self::fetchAll(
            'SELECT p.id, p.project_code, p.name, p.budget_amount, p.progress
             FROM projects p
             WHERE ' . implode(' AND ', $conditions) . '
             ORDER BY p.budget_amount DESC, p.id ASC
             LIMIT ' . $limit,
            $params
        );
    }

    /**
     * Progres tertinggi pada proyek aktif, untuk grafik perbandingan antar proyek.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function topProgress(int $limit = 8): array
    {
        $limit = max(1, min($limit, 12));

        return self::fetchAll(
            'SELECT p.id, p.project_code, p.name, p.progress, p.status
             FROM projects p
             WHERE p.is_archived = 0
             ORDER BY p.progress DESC, p.target_date ASC, p.id ASC
             LIMIT ' . $limit
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function create(array $data): int
    {
        $values = self::values($data);

        Database::execute(
            'INSERT INTO projects (
                project_code, name, description, location, address, latitude, longitude,
                pic_name, pic_user_id, contractor, start_date, target_date, budget_amount,
                status, progress, notes, created_by, updated_by, created_at, updated_at
             ) VALUES (
                :project_code, :name, :description, :location, :address, :latitude, :longitude,
                :pic_name, :pic_user_id, :contractor, :start_date, :target_date, :budget_amount,
                :status, :progress, :notes, :created_by, :updated_by, NOW(), NOW()
             )',
            $values + [
                'progress' => max(0, min(100, (int) ($data['progress'] ?? 0))),
                'created_by' => $data['created_by'] ?? null,
                'updated_by' => $data['updated_by'] ?? null,
            ]
        );

        return (int) Database::lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function update(int $id, array $data): void
    {
        $values = self::values($data);

        Database::execute(
            'UPDATE projects SET
                project_code = :project_code, name = :name, description = :description,
                location = :location, address = :address, latitude = :latitude, longitude = :longitude,
                pic_name = :pic_name, pic_user_id = :pic_user_id, contractor = :contractor,
                start_date = :start_date, target_date = :target_date, budget_amount = :budget_amount,
                status = :status, notes = :notes,
                updated_by = :updated_by, updated_at = NOW()
             WHERE id = :id',
            $values + [
                'updated_by' => $data['updated_by'] ?? null,
                'id' => $id,
            ]
        );
    }

    /**
     * Progres tidak ikut diubah di sini. Nilai progres hanya berubah lewat
     * laporan progres supaya setiap perubahan punya catatan aktivitas.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function values(array $data): array
    {
        $fields = [
            'project_code', 'name', 'description', 'location', 'address', 'latitude', 'longitude',
            'pic_name', 'pic_user_id', 'contractor', 'start_date', 'target_date', 'budget_amount',
            'status', 'notes',
        ];

        $values = [];
        foreach ($fields as $field) {
            $values[$field] = $data[$field] ?? null;
        }

        return $values;
    }

    public static function updateProgress(int $id, int $progress, int $userId): void
    {
        Database::execute(
            'UPDATE projects SET progress = :progress, updated_by = :updated_by, updated_at = NOW() WHERE id = :id',
            ['progress' => $progress, 'updated_by' => $userId, 'id' => $id]
        );
    }

    public static function updateStatus(int $id, string $status, int $userId): void
    {
        Database::execute(
            'UPDATE projects SET status = :status, updated_by = :updated_by, updated_at = NOW() WHERE id = :id',
            ['status' => $status, 'updated_by' => $userId, 'id' => $id]
        );
    }

    /**
     * Kunci baris proyek selama proses keuangan berjalan. Pemanggil wajib berada di
     * dalam Database::transaction(); tujuannya agar dua pengguna yang mencatat atau
     * menyetujui transaksi pada proyek yang sama tidak saling menimpa perhitungan.
     */
    public static function lockForUpdate(int $id): void
    {
        Database::query(
            'SELECT id FROM projects WHERE id = :id FOR UPDATE',
            ['id' => $id]
        )->fetchAll();
    }

    /**
     * Anggaran awal proyek hanya boleh berubah lewat persetujuan perubahan anggaran,
     * sehingga pemanggil wajib berada di dalam transaksi database yang sama dengan
     * pencatatan riwayatnya.
     */
    public static function updateBudget(int $id, float $amount, int $userId): void
    {
        Database::execute(
            'UPDATE projects SET budget_amount = :amount, updated_by = :updated_by, updated_at = NOW() WHERE id = :id',
            ['amount' => round($amount, 2), 'updated_by' => $userId, 'id' => $id]
        );
    }

    public static function setArchived(int $id, bool $archived, int $userId): void
    {
        Database::execute(
            'UPDATE projects
             SET is_archived = :archived,
                 archived_at = :archived_at,
                 archived_by = :archived_by,
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'archived' => $archived ? 1 : 0,
                'archived_at' => $archived ? date('Y-m-d H:i:s') : null,
                'archived_by' => $archived ? $userId : null,
                'updated_by' => $userId,
                'id' => $id,
            ]
        );
    }

    /** Menautkan penanggung jawab proyek dengan akun pengguna yang sudah ada. */
    public static function setPrincipal(int $id, int $userId, string $picName, int $actorId): void
    {
        Database::execute(
            'UPDATE projects
             SET pic_user_id = :pic_user_id, pic_name = :pic_name,
                 updated_by = :updated_by, updated_at = NOW()
             WHERE id = :id',
            [
                'pic_user_id' => $userId,
                'pic_name' => mb_substr($picName, 0, 120),
                'updated_by' => $actorId,
                'id' => $id,
            ]
        );
    }

    public static function logStatus(
        int $projectId,
        ?string $fromStatus,
        string $toStatus,
        ?string $reason,
        int $userId
    ): void {
        Database::execute(
            'INSERT INTO project_status_logs (project_id, from_status, to_status, reason, changed_by, changed_at)
             VALUES (:project_id, :from_status, :to_status, :reason, :changed_by, NOW())',
            [
                'project_id' => $projectId,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'reason' => $reason !== null && $reason !== '' ? mb_substr($reason, 0, 255) : null,
                'changed_by' => $userId,
            ]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public static function statusHistory(int $projectId): array
    {
        return self::fetchAll(
            'SELECT s.*, u.full_name AS changed_by_name
             FROM project_status_logs s
             LEFT JOIN users u ON u.id = s.changed_by
             WHERE s.project_id = :project_id
             ORDER BY s.changed_at DESC, s.id DESC
             LIMIT 50',
            ['project_id' => $projectId]
        );
    }

    public static function delete(int $id): void
    {
        Database::execute('DELETE FROM projects WHERE id = :id', ['id' => $id]);
    }

    /** @return array<string, mixed> */
    public static function emptyRecord(): array
    {
        return [
            'id' => 0,
            'project_code' => '',
            'name' => '',
            'description' => '',
            'location' => '',
            'address' => '',
            'latitude' => null,
            'longitude' => null,
            'pic_name' => '',
            'pic_user_id' => null,
            'contractor' => '',
            'start_date' => date('Y-m-d'),
            'target_date' => date('Y-m-d'),
            'budget_amount' => 0,
            'status' => self::STATUS_PLANNING,
            'progress' => 0,
            'notes' => '',
            'is_archived' => 0,
        ];
    }
}
