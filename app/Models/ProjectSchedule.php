<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;
use PDOException;
use RuntimeException;

final class ProjectSchedule extends Model
{
    protected static string $table = 'project_schedules';

    public const STATUS_NOT_STARTED = 'not_started';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_DELAYED = 'delayed';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    /** @var array<int, string> */
    public const STATUSES = [
        self::STATUS_NOT_STARTED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_DELAYED,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    /** Kegiatan yang masih menjadi pekerjaan berjalan. */
    private const OPEN_STATUSES = [
        self::STATUS_NOT_STARTED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_DELAYED,
    ];

    /** @var array<int, string> */
    public const PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    /** @var array<int, string> */
    public const DEPENDENCY_TYPES = ['FS', 'SS', 'FF'];

    /** @var array<string, string> */
    public const SORTABLE = [
        'code' => 's.activity_code',
        'name' => 's.name',
        'start' => 's.start_date',
        'end' => 's.end_date',
        'progress' => 's.progress',
        'status' => 's.status',
        'priority' => 'FIELD(s.priority, \'low\', \'medium\', \'high\', \'urgent\')',
    ];

    private const LATE_SQL = "(s.status IN ('not_started', 'in_progress', 'delayed') AND s.end_date < CURDATE())";

    /** Bidang yang perubahannya dicatat pada riwayat jadwal. */
    private const TRACKED = [
        'name' => 'Nama kegiatan',
        'description' => 'Deskripsi',
        'category_id' => 'Kategori pekerjaan',
        'start_date' => 'Tanggal mulai',
        'end_date' => 'Tanggal selesai',
        'owner_user_id' => 'Penanggung jawab',
        'owner_name' => 'Nama penanggung jawab',
        'priority' => 'Prioritas',
        'status' => 'Status pekerjaan',
        'progress' => 'Persentase progres',
        'notes' => 'Catatan',
    ];

    /** @return array<string, mixed>|null */
    public static function findWithNames(int $id): ?array
    {
        return self::fetchOne(
            'SELECT s.*, pr.name AS project_name, pr.project_code, pr.start_date AS project_start,
                    pr.target_date AS project_target, pr.is_archived,
                    o.full_name AS owner_user_name, c.full_name AS creator_name,
                    u.full_name AS updater_name, cat.category_code, cat.category_name,
                    ' . self::LATE_SQL . ' AS is_late
             FROM project_schedules s
             INNER JOIN projects pr ON pr.id = s.project_id
             LEFT JOIN users o ON o.id = s.owner_user_id
             LEFT JOIN users c ON c.id = s.created_by
             LEFT JOIN users u ON u.id = s.updated_by
             LEFT JOIN budget_categories cat ON cat.id = s.category_id
             WHERE s.id = :id AND s.is_deleted = 0
             LIMIT 1',
            ['id' => $id]
        );
    }

    public static function codeExists(int $projectId, string $code, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) AS total FROM project_schedules
                WHERE project_id = :project_id AND activity_code = :code AND is_deleted = 0';
        $params = ['project_id' => $projectId, 'code' => $code];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }

        return (int) (self::fetchOne($sql, $params)['total'] ?? 0) > 0;
    }

    /** Kode kegiatan otomatis dengan pola KGT-Urutan dalam satu proyek. */
    public static function nextActivityCode(int $projectId): string
    {
        for ($sequence = 1; $sequence <= 9999; $sequence++) {
            $candidate = sprintf('KGT-%03d', $sequence);
            if (!self::codeExists($projectId, $candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Kode kegiatan otomatis sudah habis pada proyek ini.');
    }

    /** Selisih hari inklusif; satu hari untuk kegiatan mulai dan selesai pada tanggal sama. */
    public static function duration(string $start, string $end): int
    {
        $first = date_create($start);
        $last = date_create($end);

        if ($first === false || $last === false) {
            return 1;
        }

        return max(1, (int) $first->diff($last)->days + 1);
    }

    /** @param array<string, mixed> $data */
    public static function create(array $data): int
    {
        $start = (string) $data['start_date'];
        $end = (string) $data['end_date'];

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $code = (string) ($data['activity_code'] ?? '') !== '' && $attempt === 1
                ? (string) $data['activity_code']
                : self::nextActivityCode((int) $data['project_id']);

            try {
                Database::execute(
                    'INSERT INTO project_schedules (
                        project_id, activity_code, name, description, category_id, start_date, end_date,
                        duration_days, owner_user_id, owner_name, priority, status, progress, notes,
                        created_by, updated_by, created_at, updated_at
                     ) VALUES (
                        :project_id, :activity_code, :name, :description, :category_id, :start_date, :end_date,
                        :duration_days, :owner_user_id, :owner_name, :priority, :status, :progress, :notes,
                        :created_by, :updated_by, NOW(), NOW()
                     )',
                    [
                        'project_id' => (int) $data['project_id'],
                        'activity_code' => $code,
                        'name' => (string) $data['name'],
                        'description' => $data['description'] ?? null,
                        'category_id' => $data['category_id'] ?? null,
                        'start_date' => $start,
                        'end_date' => $end,
                        'duration_days' => self::duration($start, $end),
                        'owner_user_id' => $data['owner_user_id'] ?? null,
                        'owner_name' => (string) $data['owner_name'],
                        'priority' => (string) ($data['priority'] ?? 'medium'),
                        'status' => (string) ($data['status'] ?? self::STATUS_NOT_STARTED),
                        'progress' => (int) ($data['progress'] ?? 0),
                        'notes' => $data['notes'] ?? null,
                        'created_by' => $data['created_by'] ?? null,
                        'updated_by' => $data['updated_by'] ?? null,
                    ]
                );

                return (int) Database::lastInsertId();
            } catch (PDOException $exception) {
                if ($exception->getCode() !== '23000' || $attempt === 5) {
                    throw $exception;
                }
            }
        }

        throw new RuntimeException('Kode kegiatan sedang dipakai, silakan simpan kembali.');
    }

    /**
     * Perbarui kegiatan dan catat setiap bidang yang berubah beserta alasannya.
     * Nilai lama diambil dari database, bukan dari isian formulir, sehingga riwayat
     * selalu menunjukkan kondisi sebelum perubahan.
     *
     * @param array<string, mixed> $data
     * @return array<int, string> Bidang yang berubah
     */
    public static function update(int $id, array $data, ?string $reason, int $actorId): array
    {
        $current = self::findWithNames($id);
        if ($current === null) {
            throw new RuntimeException('Kegiatan tidak ditemukan.');
        }

        $start = (string) $data['start_date'];
        $end = (string) $data['end_date'];

        Database::execute(
            'UPDATE project_schedules SET
                name = :name, description = :description, category_id = :category_id,
                start_date = :start_date, end_date = :end_date, duration_days = :duration_days,
                owner_user_id = :owner_user_id, owner_name = :owner_name, priority = :priority,
                status = :status, progress = :progress, notes = :notes,
                updated_by = :updated_by, updated_at = NOW()
             WHERE id = :id AND is_deleted = 0',
            [
                'name' => (string) $data['name'],
                'description' => $data['description'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'start_date' => $start,
                'end_date' => $end,
                'duration_days' => self::duration($start, $end),
                'owner_user_id' => $data['owner_user_id'] ?? null,
                'owner_name' => (string) $data['owner_name'],
                'priority' => (string) $data['priority'],
                'status' => (string) $data['status'],
                'progress' => (int) $data['progress'],
                'notes' => $data['notes'] ?? null,
                'updated_by' => $actorId,
                'id' => $id,
            ]
        );

        $changed = [];
        foreach (self::TRACKED as $field => $label) {
            $before = $current[$field] ?? null;
            $after = $data[$field] ?? null;

            if (self::normalise($before) !== self::normalise($after)) {
                self::recordHistory($id, $field, self::displayValue($field, $before), self::displayValue($field, $after), $reason, $actorId);
                $changed[] = $field;
            }
        }

        return $changed;
    }

    private static function normalise(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_float($value)) {
            return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
        }

        return trim((string) $value);
    }

    private static function displayValue(string $field, mixed $value): string
    {
        $text = self::normalise($value);

        if ($text === '') {
            return '(kosong)';
        }

        return match ($field) {
            'status' => schedule_status_label($text),
            'priority' => schedule_priority_label($text),
            'progress' => $text . '%',
            'start_date', 'end_date' => tanggal_id($text),
            'category_id' => self::categoryLabel($text),
            'owner_user_id' => self::userLabel($text),
            default => $text,
        };
    }

    private static function categoryLabel(string $id): string
    {
        if (!ctype_digit($id)) {
            return $id;
        }

        $row = self::fetchOne(
            'SELECT category_name FROM budget_categories WHERE id = :id LIMIT 1',
            ['id' => (int) $id]
        );

        return (string) ($row['category_name'] ?? $id);
    }

    private static function userLabel(string $id): string
    {
        if (!ctype_digit($id)) {
            return $id;
        }

        $row = self::fetchOne('SELECT full_name FROM users WHERE id = :id LIMIT 1', ['id' => (int) $id]);

        return (string) ($row['full_name'] ?? $id);
    }

    private static function activityLabel(string $id): string
    {
        if (!ctype_digit($id)) {
            return $id;
        }

        $row = self::fetchOne(
            'SELECT activity_code, name FROM project_schedules WHERE id = :id LIMIT 1',
            ['id' => (int) $id]
        );

        return $row === null ? $id : trim((string) $row['activity_code'] . ' — ' . (string) $row['name']);
    }

    public static function recordHistory(
        int $scheduleId,
        string $field,
        ?string $before,
        ?string $after,
        ?string $reason,
        int $actorId
    ): void {
        Database::execute(
            'INSERT INTO schedule_histories (schedule_id, field_name, old_value, new_value, reason, changed_by, changed_at)
             VALUES (:schedule_id, :field_name, :old_value, :new_value, :reason, :changed_by, NOW())',
            [
                'schedule_id' => $scheduleId,
                'field_name' => mb_substr($field, 0, 60),
                'old_value' => $before !== null ? mb_substr($before, 0, 255) : null,
                'new_value' => $after !== null ? mb_substr($after, 0, 255) : null,
                'reason' => $reason !== null && $reason !== '' ? mb_substr($reason, 0, 500) : null,
                'changed_by' => $actorId,
            ]
        );
    }

    public static function softDelete(int $id, int $actorId): int
    {
        return Database::execute(
            'UPDATE project_schedules
             SET is_deleted = 1, deleted_by = :deleted_by, deleted_at = NOW(),
                 updated_by = :updated_by, updated_at = NOW()
             WHERE id = :id AND is_deleted = 0',
            ['deleted_by' => $actorId, 'updated_by' => $actorId, 'id' => $id]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public static function history(int $scheduleId, int $limit = 40): array
    {
        $limit = max(1, min($limit, 200));

        return self::fetchAll(
            'SELECT h.*, u.full_name AS changed_by_name
             FROM schedule_histories h
             LEFT JOIN users u ON u.id = h.changed_by
             WHERE h.schedule_id = :schedule_id
             ORDER BY h.changed_at DESC, h.id DESC
             LIMIT ' . $limit,
            ['schedule_id' => $scheduleId]
        );
    }

    /**
     * @param array<string, string> $filters
     * @return array{rows: array<int, array<string, mixed>>, total: int, page: int, pages: int, per_page: int}
     */
    public static function paginate(array $filters, int $page, int $perPage = 15): array
    {
        [$where, $params] = self::buildWhere($filters);

        $total = (int) (self::fetchOne(
            'SELECT COUNT(*) AS total FROM project_schedules s
             INNER JOIN projects pr ON pr.id = s.project_id' . $where,
            $params
        )['total'] ?? 0);

        $perPage = max(1, min($perPage, 100));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;

        $column = self::SORTABLE[$filters['sort']] ?? 's.end_date';
        $direction = strtolower($filters['dir']) === 'asc' ? 'ASC' : 'DESC';

        $rows = self::fetchAll(
            'SELECT s.*, pr.name AS project_name, pr.project_code,
                    o.full_name AS owner_user_name, cat.category_name,
                    ' . self::LATE_SQL . ' AS is_late,
                    (SELECT COUNT(*) FROM schedule_dependencies d WHERE d.schedule_id = s.id) AS dependency_count
             FROM project_schedules s
             INNER JOIN projects pr ON pr.id = s.project_id
             LEFT JOIN users o ON o.id = s.owner_user_id
             LEFT JOIN budget_categories cat ON cat.id = s.category_id'
            . $where
            . ' ORDER BY ' . $column . ' ' . $direction . ', s.id DESC'
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
        $conditions = ['s.is_deleted = 0'];
        $params = [];

        if ($filters['search'] !== '') {
            $conditions[] = '(LOCATE(:search_code, s.activity_code) > 0 OR LOCATE(:search_name, s.name) > 0'
                . ' OR LOCATE(:search_owner, s.owner_name) > 0 OR LOCATE(:search_project, pr.name) > 0)';
            foreach (['search_code', 'search_name', 'search_owner', 'search_project'] as $key) {
                $params[$key] = $filters['search'];
            }
        }

        if (in_array($filters['status'], self::STATUSES, true)) {
            $conditions[] = 's.status = :status';
            $params['status'] = $filters['status'];
        }

        if (in_array($filters['priority'], self::PRIORITIES, true)) {
            $conditions[] = 's.priority = :priority';
            $params['priority'] = $filters['priority'];
        }

        if ($filters['project'] !== '' && ctype_digit($filters['project'])) {
            $conditions[] = 's.project_id = :project_id';
            $params['project_id'] = (int) $filters['project'];
        }

        if ($filters['category'] !== '' && ctype_digit($filters['category'])) {
            $conditions[] = 's.category_id = :category_id';
            $params['category_id'] = (int) $filters['category'];
        }

        if ($filters['from'] !== '') {
            $conditions[] = 's.end_date >= :date_from';
            $params['date_from'] = $filters['from'];
        }

        if ($filters['to'] !== '') {
            $conditions[] = 's.start_date <= :date_to';
            $params['date_to'] = $filters['to'];
        }

        if ($filters['late'] === '1') {
            $conditions[] = self::LATE_SQL;
        }

        return [' WHERE ' . implode(' AND ', $conditions), $params];
    }

    /**
     * Sumber data kalender. Satu kegiatan dapat keluar pada lebih dari satu hari bila
     * rentangnya memotong rentang tampilan kalender.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function calendarEvents(string $from, string $to, int $projectId = 0): array
    {
        $conditions = [
            's.is_deleted = 0',
            's.start_date <= :date_to',
            's.end_date >= :date_from',
        ];
        $params = ['date_from' => $from, 'date_to' => $to];

        if ($projectId > 0) {
            $conditions[] = 's.project_id = :project_id';
            $params['project_id'] = $projectId;
        }

        return self::fetchAll(
            'SELECT s.id, s.project_id, s.activity_code, s.name, s.status, s.priority, s.progress,
                    s.start_date, s.end_date, s.duration_days, s.owner_name, s.description,
                    o.full_name AS owner_user_name, cat.category_name, pr.name AS project_name,
                    pr.project_code, ' . self::LATE_SQL . ' AS is_late
             FROM project_schedules s
             INNER JOIN projects pr ON pr.id = s.project_id
             LEFT JOIN users o ON o.id = s.owner_user_id
             LEFT JOIN budget_categories cat ON cat.id = s.category_id
             WHERE ' . implode(' AND ', $conditions) . '
             ORDER BY s.start_date ASC, s.id ASC
             LIMIT 1000',
            $params
        );
    }

    /** @return array<int, array<string, mixed>> Baris Gantt Chart untuk satu proyek. */
    public static function ganttRows(int $projectId): array
    {
        return self::fetchAll(
            'SELECT s.id, s.activity_code, s.name, s.start_date, s.end_date, s.duration_days,
                    s.progress, s.status, s.priority, s.owner_name, cat.category_name,
                    ' . self::LATE_SQL . ' AS is_late
             FROM project_schedules s
             LEFT JOIN budget_categories cat ON cat.id = s.category_id
             WHERE s.project_id = :project_id AND s.is_deleted = 0
             ORDER BY s.start_date ASC, s.id ASC
             LIMIT 200',
            ['project_id' => $projectId]
        );
    }

    /** @return array<int, array<string, mixed>> Semua sisi ketergantungan pada satu proyek. */
    public static function dependencyEdges(int $projectId): array
    {
        return self::fetchAll(
            'SELECT d.id, d.schedule_id, d.predecessor_id, d.dependency_type, d.lag_days,
                    successor.activity_code AS successor_code, successor.name AS successor_name,
                    predecessor.activity_code AS predecessor_code, predecessor.name AS predecessor_name,
                    predecessor.start_date AS predecessor_start, predecessor.end_date AS predecessor_end,
                    predecessor.status AS predecessor_status,
                    successor.start_date AS successor_start, successor.end_date AS successor_end
             FROM schedule_dependencies d
             INNER JOIN project_schedules successor ON successor.id = d.schedule_id
             INNER JOIN project_schedules predecessor ON predecessor.id = d.predecessor_id
             WHERE successor.project_id = :project_id AND successor.is_deleted = 0 AND predecessor.is_deleted = 0
             ORDER BY successor.activity_code ASC, predecessor.activity_code ASC',
            ['project_id' => $projectId]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public static function predecessors(int $scheduleId): array
    {
        return self::fetchAll(
            'SELECT d.id AS dependency_id, d.dependency_type, d.lag_days,
                    p.id, p.activity_code, p.name, p.status, p.progress, p.start_date, p.end_date
             FROM schedule_dependencies d
             INNER JOIN project_schedules p ON p.id = d.predecessor_id
             WHERE d.schedule_id = :schedule_id AND p.is_deleted = 0
             ORDER BY p.start_date ASC, p.id ASC',
            ['schedule_id' => $scheduleId]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public static function successors(int $scheduleId): array
    {
        return self::fetchAll(
            'SELECT d.id AS dependency_id, d.dependency_type, d.lag_days,
                    s.id, s.activity_code, s.name, s.status, s.progress, s.start_date, s.end_date
             FROM schedule_dependencies d
             INNER JOIN project_schedules s ON s.id = d.schedule_id
             WHERE d.predecessor_id = :schedule_id AND s.is_deleted = 0
             ORDER BY s.start_date ASC, s.id ASC',
            ['schedule_id' => $scheduleId]
        );
    }

    /**
     * Periksa apakah menambahkan sisi successor <- predecessor membentuk lingkaran.
     * Penelusuran mengikuti seluruh rantai pendahulu dari predecessor.
     *
     * @param array<int, array<string, mixed>> $edges Sisi ketergantungan milik satu proyek
     */
    public static function createsCycle(int $successorId, int $predecessorId, array $edges): bool
    {
        $incoming = [];
        foreach ($edges as $edge) {
            $incoming[(int) $edge['schedule_id']][] = (int) $edge['predecessor_id'];
        }

        $stack = [$predecessorId];
        $seen = [];

        while ($stack !== []) {
            $node = (int) array_pop($stack);

            if ($node === $successorId) {
                return true;
            }

            if (isset($seen[$node])) {
                continue;
            }
            $seen[$node] = true;

            foreach ($incoming[$node] ?? [] as $next) {
                $stack[] = (int) $next;
            }
        }

        return false;
    }

    /**
     * Pastikan tanggal kedua kegiatan tidak bertentangan dengan jenis ketergantungan.
     * Mengembalikan pesan galat, atau null bila jadwal sudah selaras.
     */
    public static function dependencyDateError(
        string $successorStart,
        string $successorEnd,
        string $predecessorStart,
        string $predecessorEnd,
        string $type,
        int $lag
    ): ?string {
        $required = match ($type) {
            'FS' => date('Y-m-d', strtotime($predecessorEnd . ' +' . ($lag + 1) . ' day')),
            'SS' => date('Y-m-d', strtotime($predecessorStart . ' +' . $lag . ' day')),
            'FF' => date('Y-m-d', strtotime($predecessorEnd . ' +' . $lag . ' day')),
            default => null,
        };

        if ($required === false || $required === null) {
            return null;
        }

        [$actual, $label] = match ($type) {
            'FS', 'SS' => [$successorStart, 'tanggal mulai'],
            default => [$successorEnd, 'tanggal selesai'],
        };

        if ($actual >= $required) {
            return null;
        }

        $kind = match ($type) {
            'FS' => 'selesai-ke-mulai',
            'SS' => 'mulai-ke-mulai',
            default => 'selesai-ke-selesai',
        };

        return 'Ketergantungan ' . $kind . ' menuntut ' . $label . ' tidak sebelum '
            . tanggal_id($required) . '.';
    }

    public static function addDependency(
        int $successorId,
        int $predecessorId,
        string $type,
        int $lag,
        int $actorId
    ): void {
        Database::execute(
            'INSERT INTO schedule_dependencies (schedule_id, predecessor_id, dependency_type, lag_days, created_by, created_at)
             VALUES (:schedule_id, :predecessor_id, :dependency_type, :lag_days, :created_by, NOW())',
            [
                'schedule_id' => $successorId,
                'predecessor_id' => $predecessorId,
                'dependency_type' => $type,
                'lag_days' => $lag,
                'created_by' => $actorId,
            ]
        );

        self::recordHistory(
            $successorId,
            'dependency',
            '(tidak ada)',
            dependency_type_label($type) . ': ' . self::activityLabel((string) $predecessorId)
                . ($lag > 0 ? ' + ' . $lag . ' hari' : ''),
            'Ketergantungan pekerjaan ditambahkan.',
            $actorId
        );
    }

    public static function removeDependency(int $dependencyId, int $actorId): int
    {
        $edge = self::fetchOne(
            'SELECT schedule_id, predecessor_id FROM schedule_dependencies WHERE id = :id LIMIT 1',
            ['id' => $dependencyId]
        );

        $affected = Database::execute(
            'DELETE FROM schedule_dependencies WHERE id = :id',
            ['id' => $dependencyId]
        );

        if ($affected > 0 && $edge !== null) {
            self::recordHistory(
                (int) $edge['schedule_id'],
                'dependency',
                self::activityLabel((string) $edge['predecessor_id']),
                '(tidak ada)',
                'Ketergantungan pekerjaan dihapus.',
                $actorId
            );
        }

        return $affected;
    }

    /** @return array<int, array<string, mixed>> Kegiatan milik satu proyek beserta pendahulunya. */
    public static function forProject(int $projectId): array
    {
        return self::fetchAll(
            'SELECT s.*, cat.category_name, ' . self::LATE_SQL . ' AS is_late,
                    (SELECT COUNT(*) FROM schedule_dependencies d WHERE d.schedule_id = s.id) AS dependency_count
             FROM project_schedules s
             LEFT JOIN budget_categories cat ON cat.id = s.category_id
             WHERE s.project_id = :project_id AND s.is_deleted = 0
             ORDER BY s.start_date ASC, s.activity_code ASC',
            ['project_id' => $projectId]
        );
    }

    /** @return array<int, array<string, mixed>> Pilihan kegiatan untuk hubungan ketergantungan. */
    public static function options(int $projectId, int $excludeId = 0): array
    {
        $sql = 'SELECT id, activity_code, name, start_date, end_date, status
                FROM project_schedules
                WHERE project_id = :project_id AND is_deleted = 0';
        $params = ['project_id' => $projectId];

        if ($excludeId > 0) {
            $sql .= ' AND id <> :id';
            $params['id'] = $excludeId;
        }

        return self::fetchAll($sql . ' ORDER BY start_date ASC, activity_code ASC', $params);
    }

    /** @return array<string, mixed> Ringkasan kegiatan untuk dashboard keuangan dan jadwal. */
    public static function summary(int $projectId = 0): array
    {
        $conditions = ['s.is_deleted = 0'];
        $params = [];

        if ($projectId > 0) {
            $conditions[] = 's.project_id = :project_id';
            $params['project_id'] = $projectId;
        }

        $where = ' WHERE ' . implode(' AND ', $conditions);
        $dueDays = BudgetSummary::dueSoonDays();

        // Alias "delayed" dihindari karena kata itu termasuk pencadangan MySQL/MariaDB.
        $row = self::fetchOne(
            'SELECT COUNT(*) AS total,
                    COALESCE(SUM(s.status = \'in_progress\'), 0) AS cnt_running,
                    COALESCE(SUM(s.status = \'not_started\'), 0) AS cnt_not_started,
                    COALESCE(SUM(s.status = \'delayed\'), 0) AS cnt_delayed,
                    COALESCE(SUM(s.status = \'completed\'), 0) AS cnt_completed,
                    COALESCE(SUM(s.status = \'cancelled\'), 0) AS cnt_cancelled,
                    COALESCE(SUM(' . self::LATE_SQL . '), 0) AS cnt_late,
                    COALESCE(SUM(s.status IN (\'not_started\', \'in_progress\', \'delayed\')
                        AND s.end_date BETWEEN CURDATE()
                        AND DATE_ADD(CURDATE(), INTERVAL ' . $dueDays . ' DAY)), 0) AS cnt_due_soon,
                    COALESCE(ROUND(AVG(s.progress), 1), 0) AS avg_progress
             FROM project_schedules s' . $where,
            $params
        ) ?? [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'running' => (int) ($row['cnt_running'] ?? 0),
            'not_started' => (int) ($row['cnt_not_started'] ?? 0),
            'delayed' => (int) ($row['cnt_delayed'] ?? 0),
            'completed' => (int) ($row['cnt_completed'] ?? 0),
            'cancelled' => (int) ($row['cnt_cancelled'] ?? 0),
            'late' => (int) ($row['cnt_late'] ?? 0),
            'due_week' => (int) ($row['cnt_due_soon'] ?? 0),
            'due_days' => $dueDays,
            'avg_progress' => (float) ($row['avg_progress'] ?? 0),
        ];
    }

    /** @return array<int, array<string, mixed>> Jadwal terdekat yang masih berjalan. */
    public static function upcoming(int $days = 14, int $limit = 6, int $projectId = 0): array
    {
        $days = max(1, min($days, 90));
        $limit = max(1, min($limit, 20));
        $conditions = [
            's.is_deleted = 0',
            "s.status IN ('not_started', 'in_progress', 'delayed')",
            's.end_date >= CURDATE()',
            's.end_date <= DATE_ADD(CURDATE(), INTERVAL ' . $days . ' DAY)',
        ];
        $params = [];

        if ($projectId > 0) {
            $conditions[] = 's.project_id = :project_id';
            $params['project_id'] = $projectId;
        }

        return self::fetchAll(
            'SELECT s.id, s.activity_code, s.name, s.status, s.progress, s.start_date, s.end_date,
                    s.owner_name, pr.name AS project_name, pr.project_code,
                    DATEDIFF(s.end_date, CURDATE()) AS days_left
             FROM project_schedules s
             INNER JOIN projects pr ON pr.id = s.project_id
             WHERE ' . implode(' AND ', $conditions) . '
             ORDER BY s.end_date ASC, s.id ASC
             LIMIT ' . $limit,
            $params
        );
    }

    /** @return array<int, array<string, mixed>> Kegiatan yang melewati tanggal selesai. */
    public static function late(int $limit = 6, int $projectId = 0): array
    {
        $limit = max(1, min($limit, 50));
        $conditions = ['s.is_deleted = 0', self::LATE_SQL];
        $params = [];

        if ($projectId > 0) {
            $conditions[] = 's.project_id = :project_id';
            $params['project_id'] = $projectId;
        }

        return self::fetchAll(
            'SELECT s.id, s.activity_code, s.name, s.status, s.progress, s.end_date, s.owner_name,
                    pr.name AS project_name, pr.project_code,
                    DATEDIFF(CURDATE(), s.end_date) AS days_late
             FROM project_schedules s
             INNER JOIN projects pr ON pr.id = s.project_id
             WHERE ' . implode(' AND ', $conditions) . '
             ORDER BY s.end_date ASC, s.id ASC
             LIMIT ' . $limit,
            $params
        );
    }

    /**
     * Rata-rata progres kegiatan dibandingkan target waktu pada periode berjalan.
     * Target dihitung dari porsi waktu yang sudah berjalan, kegiatan yang selesai
     * dianggap mencapai target.
     *
     * @return array{actual: float, target: float}
     */
    public static function targetVersusActual(int $projectId = 0): array
    {
        $conditions = [
            's.is_deleted = 0',
            "s.status IN ('in_progress', 'delayed', 'completed')",
            's.start_date <= CURDATE()',
        ];
        $params = [];

        if ($projectId > 0) {
            $conditions[] = 's.project_id = :project_id';
            $params['project_id'] = $projectId;
        }

        $row = self::fetchOne(
            'SELECT
                COALESCE(ROUND(AVG(s.progress), 1), 0) AS actual,
                COALESCE(ROUND(AVG(CASE
                    WHEN s.status = \'completed\' THEN 100
                    WHEN DATEDIFF(CURDATE(), s.start_date) <= 0 THEN 0
                    WHEN DATEDIFF(s.end_date, s.start_date) = 0 THEN 100
                    WHEN DATEDIFF(CURDATE(), s.start_date) >= DATEDIFF(s.end_date, s.start_date) THEN 100
                    ELSE ROUND(DATEDIFF(CURDATE(), s.start_date) * 100 / DATEDIFF(s.end_date, s.start_date), 1)
                END), 1), 0) AS target
             FROM project_schedules s
             WHERE ' . implode(' AND ', $conditions),
            $params
        ) ?? [];

        return [
            'actual' => (float) ($row['actual'] ?? 0),
            'target' => (float) ($row['target'] ?? 0),
        ];
    }

    /** @return array<int, array<string, mixed>> Progres kegiatan per kategori pekerjaan. */
    public static function progressByCategory(int $projectId = 0, int $limit = 8): array
    {
        $limit = max(1, min($limit, 20));
        $conditions = ['s.is_deleted = 0', 's.category_id IS NOT NULL'];
        $params = [];

        if ($projectId > 0) {
            $conditions[] = 's.project_id = :project_id';
            $params['project_id'] = $projectId;
        }

        return self::fetchAll(
            'SELECT cat.category_code, cat.category_name,
                    COUNT(s.id) AS activity_count,
                    COALESCE(ROUND(AVG(s.progress), 1), 0) AS avg_progress
             FROM project_schedules s
             INNER JOIN budget_categories cat ON cat.id = s.category_id
             WHERE ' . implode(' AND ', $conditions) . '
             GROUP BY cat.category_code, cat.category_name
             ORDER BY avg_progress DESC, cat.sort_order ASC
             LIMIT ' . $limit,
            $params
        );
    }

    /** Transaksi harus berada di dalam rentang kegiatan yang ditunjuk. */
    public static function belongsToProject(int $scheduleId, int $projectId): bool
    {
        $row = self::fetchOne(
            'SELECT COUNT(*) AS total FROM project_schedules
             WHERE id = :id AND project_id = :project_id AND is_deleted = 0',
            ['id' => $scheduleId, 'project_id' => $projectId]
        );

        return (int) ($row['total'] ?? 0) > 0;
    }

    /** @return array<string, mixed> Baris kosong untuk form tambah kegiatan. */
    public static function emptyRecord(int $projectId = 0): array
    {
        return [
            'id' => 0,
            'project_id' => $projectId,
            'activity_code' => '',
            'name' => '',
            'description' => '',
            'category_id' => null,
            'start_date' => date('Y-m-d'),
            'end_date' => date('Y-m-d'),
            'owner_user_id' => null,
            'owner_name' => '',
            'priority' => 'medium',
            'status' => self::STATUS_NOT_STARTED,
            'progress' => 0,
            'notes' => '',
        ];
    }
}
