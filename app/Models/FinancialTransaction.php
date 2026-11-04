<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;
use PDOException;
use RuntimeException;

final class FinancialTransaction extends Model
{
    protected static string $table = 'financial_transactions';

    public const TYPE_EXPENSE = 'expense';
    public const TYPE_INCOME = 'income';
    public const TYPE_REFUND = 'refund';
    public const TYPE_ADJUSTMENT = 'adjustment';

    /** @var array<int, string> */
    public const TYPES = [
        self::TYPE_EXPENSE,
        self::TYPE_INCOME,
        self::TYPE_REFUND,
        self::TYPE_ADJUSTMENT,
    ];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    /** @var array<int, string> */
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
    ];

    /** Status yang masih boleh diubah isinya sebelum masuk proses persetujuan. */
    public const EDITABLE_STATUSES = [self::STATUS_DRAFT, self::STATUS_REJECTED];

    /** @var array<int, string> */
    public const METHODS = ['cash', 'transfer', 'budget', 'other'];

    /** @var array<int, string> */
    public const CENTER_CONSTRUCTION = 'construction';
    public const CENTER_OPERATIONS = 'operations';

    /** @var array<int, string> */
    public const COST_CENTERS = [self::CENTER_CONSTRUCTION, self::CENTER_OPERATIONS];

    /** @var array<string, string> */
    public const SORTABLE = [
        'code' => 't.transaction_code',
        'date' => 't.transaction_date',
        'project' => 'pr.name',
        'category' => 'c.category_name',
        'type' => 't.transaction_type',
        'amount' => 't.amount',
        'status' => 't.status',
    ];

    /**
     * Efek satu transaksi terhadap realisasi pengeluaran. Pemasukan tidak dihitung
     * sebagai realisasi, pengembalian mengurangi realisasi, dan penyesuaian dapat
     * bernilai positif maupun negatif sebagai koreksi atas transaksi sebelumnya.
     */
    public const OUTFLOW_SQL = 'CASE t.transaction_type
        WHEN \'expense\' THEN t.amount
        WHEN \'refund\' THEN -t.amount
        WHEN \'adjustment\' THEN t.amount
        ELSE 0 END';

    /** @return array<string, mixed>|null */
    public static function findWithNames(int $id): ?array
    {
        return self::fetchOne(
            'SELECT t.*, pr.name AS project_name, pr.project_code, pr.is_archived AS is_project_archived,
                    c.category_code, c.category_name,
                    p.rab_code, p.name AS plan_name,
                    r.full_name AS recorder_name, s.full_name AS submitter_name,
                    a.full_name AS approver_name, x.full_name AS canceller_name,
                    o.transaction_code AS original_code, o.amount AS original_amount,
                    o.transaction_type AS original_type
             FROM financial_transactions t
             INNER JOIN projects pr ON pr.id = t.project_id
             LEFT JOIN budget_categories c ON c.id = t.category_id
             LEFT JOIN budget_plans p ON p.id = t.budget_plan_id
             LEFT JOIN users r ON r.id = t.recorded_by
             LEFT JOIN users s ON s.id = t.submitted_by
             LEFT JOIN users a ON a.id = t.approved_by
             LEFT JOIN users x ON x.id = t.cancelled_by
             LEFT JOIN financial_transactions o ON o.id = t.reversal_of
             WHERE t.id = :id
             LIMIT 1',
            ['id' => $id]
        );
    }

    public static function codeExists(string $code): bool
    {
        return (int) (self::fetchOne(
            'SELECT COUNT(*) AS total FROM financial_transactions WHERE transaction_code = :code',
            ['code' => $code]
        )['total'] ?? 0) > 0;
    }

    /** Nomor referensi hanya unik di dalam satu proyek, sesuai kunci unik tabelnya. */
    public static function referenceExists(string $reference, int $projectId, ?int $exceptId = null): bool
    {
        if ($reference === '') {
            return false;
        }

        $sql = 'SELECT COUNT(*) AS total FROM financial_transactions
                WHERE reference_number = :reference AND project_id = :project_id';
        $params = ['reference' => $reference, 'project_id' => $projectId];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }

        return (int) (self::fetchOne($sql, $params)['total'] ?? 0) > 0;
    }

    /** Nomor transaksi otomatis dengan pola TRX-Tahun-Urutan. */
    public static function nextCode(?string $year = null): string
    {
        $year = $year !== null && $year !== '' ? $year : date('Y');

        for ($sequence = 1; $sequence <= 9999; $sequence++) {
            $candidate = sprintf('TRX-%s-%03d', $year, $sequence);
            if (!self::codeExists($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Nomor transaksi otomatis sudah habis untuk tahun ini.');
    }

    /**
     * @param array<string, mixed> $data
     * @return array{0: int, 1: string}
     */
    public static function create(array $data): array
    {
        $sql = 'INSERT INTO financial_transactions (
                    transaction_code, project_id, budget_plan_id, budget_item_id, category_id, cost_center,
                    transaction_type, transaction_date, description, amount, payment_method, recipient,
                    reference_number, status, reversal_of, recorded_by, created_at, updated_at
                 ) VALUES (
                    :transaction_code, :project_id, :budget_plan_id, :budget_item_id, :category_id, :cost_center,
                    :transaction_type, :transaction_date, :description, :amount, :payment_method, :recipient,
                    :reference_number, :status, :reversal_of, :recorded_by, NOW(), NOW()
                 )';

        $values = [
            'project_id' => (int) $data['project_id'],
            'budget_plan_id' => $data['budget_plan_id'] ?? null,
            'budget_item_id' => $data['budget_item_id'] ?? null,
            'category_id' => $data['category_id'] ?? null,
            'cost_center' => (string) ($data['cost_center'] ?? 'construction'),
            'transaction_type' => (string) $data['transaction_type'],
            'transaction_date' => (string) $data['transaction_date'],
            'description' => (string) $data['description'],
            'amount' => (float) $data['amount'],
            'payment_method' => (string) $data['payment_method'],
            'recipient' => (string) $data['recipient'],
            'reference_number' => $data['reference_number'] ?? null,
            'status' => (string) ($data['status'] ?? self::STATUS_DRAFT),
            'reversal_of' => $data['reversal_of'] ?? null,
            'recorded_by' => $data['recorded_by'] ?? null,
        ];

        $explicit = trim((string) ($data['transaction_code'] ?? ''));
        if ($explicit !== '') {
            Database::execute($sql, $values + ['transaction_code' => $explicit]);
            return [(int) Database::lastInsertId(), $explicit];
        }

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                $code = self::nextCode();
                Database::execute($sql, $values + ['transaction_code' => $code]);
                return [(int) Database::lastInsertId(), $code];
            } catch (PDOException $exception) {
                if ($exception->getCode() !== '23000' || $attempt === 5) {
                    throw $exception;
                }
            }
        }

        throw new RuntimeException('Nomor transaksi sedang dipakai, silakan simpan kembali.');
    }

    /**
     * Transaksi yang sudah disetujui tidak boleh diubah nilainya. Pemeriksaan status
     * dilakukan ulang pada saat eksekusi agar perubahan bersamaan tidak tembus.
     *
     * @param array<string, mixed> $data
     */
    public static function update(int $id, array $data): int
    {
        return Database::execute(
            'UPDATE financial_transactions SET
                project_id = :project_id, budget_plan_id = :budget_plan_id, budget_item_id = :budget_item_id,
                category_id = :category_id, cost_center = :cost_center, transaction_type = :transaction_type,
                transaction_date = :transaction_date, description = :description, amount = :amount,
                payment_method = :payment_method, recipient = :recipient, reference_number = :reference_number,
                updated_at = NOW()
             WHERE id = :id AND status IN (\'draft\', \'rejected\')',
            [
                'project_id' => (int) $data['project_id'],
                'budget_plan_id' => $data['budget_plan_id'] ?? null,
                'budget_item_id' => $data['budget_item_id'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'cost_center' => (string) ($data['cost_center'] ?? 'construction'),
                'transaction_type' => (string) $data['transaction_type'],
                'transaction_date' => (string) $data['transaction_date'],
                'description' => (string) $data['description'],
                'amount' => (float) $data['amount'],
                'payment_method' => (string) $data['payment_method'],
                'recipient' => (string) $data['recipient'],
                'reference_number' => $data['reference_number'] ?? null,
                'id' => $id,
            ]
        );
    }

    public static function delete(int $id): int
    {
        return Database::execute(
            'DELETE FROM financial_transactions WHERE id = :id AND status IN (\'draft\', \'rejected\')',
            ['id' => $id]
        );
    }

    /**
     * Pindahkan status transaksi dengan kunci optimistis pada status asal.
     * Mengembalikan jumlah baris yang berubah; nol berarti status sudah diubah orang lain.
     */
    public static function changeStatus(
        int $id,
        string $fromStatus,
        string $toStatus,
        string $action,
        ?string $note,
        int $actorId
    ): int {
        $trimmedNote = $note !== null && $note !== '' ? mb_substr($note, 0, 500) : null;

        // Placeholder dibuat unik per kemunculan: preparate statement asli
        // (ATTR_EMULATE_PREPARES = false) menolak nama parameter yang dipakai dua kali
        // dan parameter yang tidak terpakai.
        $sets = ['status = :to_status', 'updated_at = NOW()'];
        $params = [
            'to_status' => $toStatus,
            'id' => $id,
            'from_status' => $fromStatus,
        ];

        if ($action === 'submit') {
            $sets[] = 'submitted_by = :submitted_by';
            $sets[] = 'submitted_at = NOW()';
            $params['submitted_by'] = $actorId;
        }

        if ($action === 'approve' || $action === 'reject') {
            $sets[] = 'approved_by = :approved_by';
            $sets[] = 'approved_at = NOW()';
            $sets[] = 'decision_note = :decision_note';
            $params['approved_by'] = $actorId;
            $params['decision_note'] = $trimmedNote;
        }

        if ($action === 'cancel') {
            $sets[] = 'cancelled_by = :cancelled_by';
            $sets[] = 'cancelled_at = NOW()';
            $sets[] = 'cancel_reason = :cancel_reason';
            $params['cancelled_by'] = $actorId;
            $params['cancel_reason'] = $trimmedNote;
        }

        $affected = Database::execute(
            'UPDATE financial_transactions SET ' . implode(', ', $sets) . '
             WHERE id = :id AND status = :from_status',
            $params
        );

        if ($affected > 0) {
            self::recordTrail($id, $action, $fromStatus, $toStatus, $note, null, null, $actorId);
        }

        return $affected;
    }

    /** Jejak audit perubahan transaksi; dipakai juga untuk mencatat nilai sebelum/sesudah. */
    public static function recordTrail(
        int $transactionId,
        string $action,
        ?string $fromStatus,
        string $toStatus,
        ?string $note,
        ?float $amountBefore,
        ?float $amountAfter,
        int $actorId
    ): void {
        Database::execute(
            'INSERT INTO transaction_approvals (
                transaction_id, action, from_status, to_status, note,
                amount_before, amount_after, actor_id, created_at
             ) VALUES (
                :transaction_id, :action, :from_status, :to_status, :note,
                :amount_before, :amount_after, :actor_id, NOW()
             )',
            [
                'transaction_id' => $transactionId,
                'action' => $action,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'note' => $note !== null && $note !== '' ? mb_substr($note, 0, 500) : null,
                'amount_before' => $amountBefore,
                'amount_after' => $amountAfter,
                'actor_id' => $actorId,
            ]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public static function approvals(int $transactionId): array
    {
        return self::fetchAll(
            'SELECT a.*, u.full_name AS actor_name
             FROM transaction_approvals a
             LEFT JOIN users u ON u.id = a.actor_id
             WHERE a.transaction_id = :transaction_id
             ORDER BY a.created_at DESC, a.id DESC',
            ['transaction_id' => $transactionId]
        );
    }

    /**
     * Realisasi pengeluaran. Hanya transaksi berstatus approved yang dihitung, dan
     * biaya pembangunan dipisahkan dari biaya operasional MBG melalui cost_center.
     */
    public static function realization(
        int $projectId = 0,
        string $costCenter = 'construction',
        string $from = '',
        string $to = ''
    ): float {
        $conditions = ["t.status = 'approved'"];
        $params = [];

        if ($costCenter !== '') {
            $conditions[] = 't.cost_center = :cost_center';
            $params['cost_center'] = $costCenter;
        }

        if ($projectId > 0) {
            $conditions[] = 't.project_id = :project_id';
            $params['project_id'] = $projectId;
        }

        if ($from !== '') {
            $conditions[] = 't.transaction_date >= :date_from';
            $params['date_from'] = $from;
        }

        if ($to !== '') {
            $conditions[] = 't.transaction_date <= :date_to';
            $params['date_to'] = $to;
        }

        $row = self::fetchOne(
            'SELECT COALESCE(SUM(' . self::OUTFLOW_SQL . '), 0) AS total
             FROM financial_transactions t
             WHERE ' . implode(' AND ', $conditions),
            $params
        );

        return round((float) ($row['total'] ?? 0), 2);
    }

    /** Total dana yang masuk sebagai sumber pembiayaan. */
    public static function income(int $projectId = 0, string $costCenter = 'construction'): float
    {
        $conditions = ["t.status = 'approved'", "t.transaction_type = 'income'"];
        $params = [];

        if ($costCenter !== '') {
            $conditions[] = 't.cost_center = :cost_center';
            $params['cost_center'] = $costCenter;
        }

        if ($projectId > 0) {
            $conditions[] = 't.project_id = :project_id';
            $params['project_id'] = $projectId;
        }

        $row = self::fetchOne(
            'SELECT COALESCE(SUM(t.amount), 0) AS total
             FROM financial_transactions t
             WHERE ' . implode(' AND ', $conditions),
            $params
        );

        return round((float) ($row['total'] ?? 0), 2);
    }

    /**
     * Realisasi per kategori pekerjaan untuk satu proyek.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function realizationByCategory(int $projectId = 0, string $costCenter = 'construction'): array
    {
        $conditions = ["t.status = 'approved'"];
        $params = [];

        if ($costCenter !== '') {
            $conditions[] = 't.cost_center = :cost_center';
            $params['cost_center'] = $costCenter;
        }

        if ($projectId > 0) {
            $conditions[] = 't.project_id = :project_id';
            $params['project_id'] = $projectId;
        }

        return self::fetchAll(
            'SELECT COALESCE(c.id, 0) AS category_id,
                    COALESCE(c.category_code, \'LAIN\') AS category_code,
                    COALESCE(c.category_name, \'Tanpa Kategori\') AS category_name,
                    COUNT(t.id) AS transaction_count,
                    COALESCE(SUM(' . self::OUTFLOW_SQL . '), 0) AS spent
             FROM financial_transactions t
             LEFT JOIN budget_categories c ON c.id = t.category_id
             WHERE ' . implode(' AND ', $conditions) . '
             GROUP BY category_id, category_code, category_name
             ORDER BY spent DESC',
            $params
        );
    }

    /**
     * Tren pengeluaran bulanan. Bulan yang tidak memiliki transaksi tetap diisi nol
     * supaya sumbu waktu grafik tidak melompat.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function monthlySeries(string $from, string $to, string $costCenter = 'construction'): array
    {
        $conditions = ["t.status = 'approved'", 't.transaction_date BETWEEN :date_from AND :date_to'];
        $params = ['date_from' => $from, 'date_to' => $to];

        if ($costCenter !== '') {
            $conditions[] = 't.cost_center = :cost_center';
            $params['cost_center'] = $costCenter;
        }

        $rows = self::fetchAll(
            'SELECT DATE_FORMAT(t.transaction_date, \'%Y-%m\') AS period,
                    COALESCE(SUM(' . self::OUTFLOW_SQL . '), 0) AS spent,
                    COALESCE(SUM(CASE WHEN t.transaction_type = \'income\' THEN t.amount ELSE 0 END), 0) AS income,
                    COUNT(*) AS transaction_count
             FROM financial_transactions t
             WHERE ' . implode(' AND ', $conditions) . '
             GROUP BY period
             ORDER BY period ASC',
            $params
        );

        $byPeriod = [];
        foreach ($rows as $row) {
            $byPeriod[(string) $row['period']] = $row;
        }

        $series = [];
        $cursor = date_create(substr($from, 0, 7) . '-01');
        $last = date_create(substr($to, 0, 7) . '-01');

        if ($cursor === false || $last === false) {
            return $rows;
        }

        while ($cursor <= $last && count($series) < 36) {
            $period = $cursor->format('Y-m');
            $entry = $byPeriod[$period] ?? null;

            $series[] = [
                'period' => $period,
                'spent' => round((float) ($entry['spent'] ?? 0), 2),
                'income' => round((float) ($entry['income'] ?? 0), 2),
                'transaction_count' => (int) ($entry['transaction_count'] ?? 0),
            ];

            $cursor = date_create($cursor->format('Y-m-01') . ' +1 month');
            if ($cursor === false) {
                break;
            }
        }

        return $series;
    }

    /** @return array<int, array<string, mixed>> Realisasi per proyek untuk grafik perbandingan. */
    public static function realizationByProject(string $costCenter = 'construction', int $limit = 8): array
    {
        $limit = max(1, min($limit, 20));
        $conditions = ["t.status = 'approved'", 'pr.is_archived = 0'];
        $params = [];

        if ($costCenter !== '') {
            $conditions[] = 't.cost_center = :cost_center';
            $params['cost_center'] = $costCenter;
        }

        return self::fetchAll(
            'SELECT pr.id, pr.project_code, pr.name AS project_name,
                    COALESCE(SUM(' . self::OUTFLOW_SQL . '), 0) AS spent,
                    COUNT(t.id) AS transaction_count
             FROM financial_transactions t
             INNER JOIN projects pr ON pr.id = t.project_id
             WHERE ' . implode(' AND ', $conditions) . '
             GROUP BY pr.id, pr.project_code, pr.name
             ORDER BY spent DESC
             LIMIT ' . $limit,
            $params
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
            'SELECT COUNT(*) AS total FROM financial_transactions t
             INNER JOIN projects pr ON pr.id = t.project_id' . $where,
            $params
        )['total'] ?? 0);

        $perPage = max(1, min($perPage, 100));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;

        $column = self::SORTABLE[$filters['sort']] ?? 't.id';
        $direction = strtolower($filters['dir']) === 'asc' ? 'ASC' : 'DESC';

        $rows = self::fetchAll(
            'SELECT t.*, pr.name AS project_name, pr.project_code,
                    c.category_code, c.category_name, p.rab_code,
                    r.full_name AS recorder_name, a.full_name AS approver_name,
                    (SELECT COUNT(*) FROM project_documents d
                     WHERE d.financial_transaction_id = t.id) AS attachment_count
             FROM financial_transactions t
             INNER JOIN projects pr ON pr.id = t.project_id
             LEFT JOIN budget_categories c ON c.id = t.category_id
             LEFT JOIN budget_plans p ON p.id = t.budget_plan_id
             LEFT JOIN users r ON r.id = t.recorded_by
             LEFT JOIN users a ON a.id = t.approved_by'
            . $where
            . ' ORDER BY ' . $column . ' ' . $direction . ', t.id DESC'
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
            $conditions[] = '(LOCATE(:search_code, t.transaction_code) > 0 OR LOCATE(:search_desc, t.description) > 0'
                . ' OR LOCATE(:search_recipient, t.recipient) > 0 OR LOCATE(:search_ref, COALESCE(t.reference_number, \'\')) > 0'
                . ' OR LOCATE(:search_project, pr.name) > 0)';
            foreach (['search_code', 'search_desc', 'search_recipient', 'search_ref', 'search_project'] as $key) {
                $params[$key] = $filters['search'];
            }
        }

        if (in_array($filters['status'], self::STATUSES, true)) {
            $conditions[] = 't.status = :status';
            $params['status'] = $filters['status'];
        }

        if (in_array($filters['type'], self::TYPES, true)) {
            $conditions[] = 't.transaction_type = :type';
            $params['type'] = $filters['type'];
        }

        if (in_array($filters['cost_center'], self::COST_CENTERS, true)) {
            $conditions[] = 't.cost_center = :cost_center';
            $params['cost_center'] = $filters['cost_center'];
        }

        if ($filters['project'] !== '' && ctype_digit($filters['project'])) {
            $conditions[] = 't.project_id = :project_id';
            $params['project_id'] = (int) $filters['project'];
        }

        if ($filters['category'] !== '' && ctype_digit($filters['category'])) {
            $conditions[] = 't.category_id = :category_id';
            $params['category_id'] = (int) $filters['category'];
        }

        if ($filters['from'] !== '') {
            $conditions[] = 't.transaction_date >= :date_from';
            $params['date_from'] = $filters['from'];
        }

        if ($filters['to'] !== '') {
            $conditions[] = 't.transaction_date <= :date_to';
            $params['date_to'] = $filters['to'];
        }

        return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $params];
    }

    /**
     * Daftar transaksi untuk ekspor CSV. Batas taken dari konfigurasi pemanggil dan
     * selalu dibatasi agar tidak menarik seluruh isi tabel.
     *
     * @param array<string, string> $filters
     * @return array<int, array<string, mixed>>
     */
    public static function forExport(array $filters, int $limit = 2000): array
    {
        [$where, $params] = self::buildWhere($filters);
        $limit = max(1, min($limit, 5000));

        return self::fetchAll(
            'SELECT t.*, pr.project_code, pr.name AS project_name,
                    c.category_code, c.category_name,
                    r.full_name AS recorder_name, a.full_name AS approver_name
             FROM financial_transactions t
             INNER JOIN projects pr ON pr.id = t.project_id
             LEFT JOIN budget_categories c ON c.id = t.category_id
             LEFT JOIN users r ON r.id = t.recorded_by
             LEFT JOIN users a ON a.id = t.approved_by'
            . $where
            . ' ORDER BY t.transaction_date ASC, t.id ASC LIMIT ' . $limit,
            $params
        );
    }

    /**
     * Pencegahan transaksi ganda: kembar pada proyek, tanggal, jenis, dan nominal yang
     * masih berada pada draft atau pending. Transaksi yang sudah disetujui ditolak
     * karena harus melalui mekanisme pembatalan, bukan dihapus.
     */
    public static function isDuplicate(
        int $projectId,
        string $date,
        string $type,
        float $amount,
        string $description,
        ?string $reference,
        ?int $exceptId = null
    ): bool {
        $hasReference = $reference !== null && $reference !== '';

        $sql = 'SELECT COUNT(*) AS total FROM financial_transactions
                WHERE project_id = :project_id AND transaction_date = :date
                  AND transaction_type = :type AND amount = :amount AND description = :description
                  AND status IN (\'draft\', \'pending\', \'approved\')
                  AND ' . ($hasReference ? 'reference_number = :reference' : 'reference_number IS NULL');

        $params = [
            'project_id' => $projectId,
            'date' => $date,
            'type' => $type,
            'amount' => round($amount, 2),
            'description' => $description,
        ];

        if ($hasReference) {
            $params['reference'] = $reference;
        }

        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }

        return (int) (self::fetchOne($sql, $params)['total'] ?? 0) > 0;
    }

    /** @return array<int, array<string, mixed>> Transaksi menunggu persetujuan. */
    public static function pending(int $limit = 20): array
    {
        $limit = max(1, min($limit, 100));

        return self::fetchAll(
            'SELECT t.*, pr.name AS project_name, pr.project_code, s.full_name AS submitter_name
             FROM financial_transactions t
             INNER JOIN projects pr ON pr.id = t.project_id
             LEFT JOIN users s ON s.id = t.submitted_by
             WHERE t.status = \'pending\'
             ORDER BY t.submitted_at ASC, t.id ASC
             LIMIT ' . $limit
        );
    }

    public static function countByStatus(string $status, int $projectId = 0, string $costCenter = ''): int
    {
        $conditions = ['status = :status'];
        $params = ['status' => $status];

        if ($projectId > 0) {
            $conditions[] = 'project_id = :project_id';
            $params['project_id'] = $projectId;
        }

        if ($costCenter !== '') {
            $conditions[] = 'cost_center = :cost_center';
            $params['cost_center'] = $costCenter;
        }

        $row = self::fetchOne(
            'SELECT COUNT(*) AS total FROM financial_transactions WHERE ' . implode(' AND ', $conditions),
            $params
        );

        return (int) ($row['total'] ?? 0);
    }

    public static function countAll(int $projectId = 0): int
    {
        $sql = 'SELECT COUNT(*) AS total FROM financial_transactions';
        $params = [];

        if ($projectId > 0) {
            $sql .= ' WHERE project_id = :project_id';
            $params['project_id'] = $projectId;
        }

        return (int) (self::fetchOne($sql, $params)['total'] ?? 0);
    }

    /** Transaksi approved yang dapat dikoreksi; transaksi draft tidak dapat dibatalkan. */
    public static function correctable(int $projectId, int $limit = 50): array
    {
        $limit = max(1, min($limit, 200));

        return self::fetchAll(
            "SELECT t.id, t.transaction_code, t.transaction_type, t.amount, t.description, t.transaction_date
             FROM financial_transactions t
             WHERE t.project_id = :project_id AND t.status = 'approved' AND t.cancelled_at IS NULL
             ORDER BY t.transaction_date DESC, t.id DESC
             LIMIT " . $limit,
            ['project_id' => $projectId]
        );
    }

    /** @return array<int, array<string, mixed>> Bukti transaksi milik satu pencatatan. */
    public static function attachments(int $transactionId): array
    {
        return self::fetchAll(
            'SELECT d.*, u.full_name AS uploader_name
             FROM project_documents d
             LEFT JOIN users u ON u.id = d.uploaded_by
             WHERE d.financial_transaction_id = :transaction_id
             ORDER BY d.uploaded_at DESC, d.id DESC',
            ['transaction_id' => $transactionId]
        );
    }

    /**
     * Koreksi yang menunjuk ke satu transaksi. Transaksi yang dibatalkan maupun
     * dikoreksi tidak pernah dihapus, sehingga daftar ini menjadi jejak lanjutannya.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function correctionsOf(int $transactionId): array
    {
        return self::fetchAll(
            "SELECT t.id, t.transaction_code, t.transaction_type, t.amount, t.description,
                    t.transaction_date, t.status, t.created_at,
                    pr.name AS project_name, u.full_name AS recorder_name
             FROM financial_transactions t
             INNER JOIN projects pr ON pr.id = t.project_id
             LEFT JOIN users u ON u.id = t.recorded_by
             WHERE t.reversal_of = :transaction_id
             ORDER BY t.transaction_date DESC, t.id DESC",
            ['transaction_id' => $transactionId]
        );
    }

    /** @return array<string, mixed> Baris kosong untuk form tambah transaksi. */
    public static function emptyRecord(int $projectId = 0): array
    {
        return [
            'id' => 0,
            'transaction_code' => '',
            'project_id' => $projectId,
            'budget_plan_id' => null,
            'category_id' => null,
            'cost_center' => 'construction',
            'transaction_type' => self::TYPE_EXPENSE,
            'transaction_date' => date('Y-m-d'),
            'description' => '',
            'amount' => '',
            'payment_method' => 'cash',
            'recipient' => '',
            'reference_number' => '',
            'status' => self::STATUS_DRAFT,
            'reversal_of' => null,
        ];
    }
}
