<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

final class Notification extends Model
{
    protected static string $table = 'notifications';

    /** @var array<string, array<int, int>> Penerima per proyek, dihitung satu kali per permintaan. */
    private static array $audienceCache = [];

    /**
     * Menyimpan satu notifikasi. Kombinasi (user_id, type, dedup_key) unik, sehingga
     * peristiwa yang sama tidak membuat dua surat untuk orang yang sama.
     */
    public static function push(
        int $userId,
        string $type,
        string $title,
        ?string $body,
        ?string $link,
        ?int $projectId,
        string $dedupKey
    ): void {
        Database::execute(
            'INSERT IGNORE INTO notifications (
                user_id, type, title, body, link, project_id, dedup_key, is_read, created_at
             ) VALUES (
                :user_id, :type, :title, :body, :link, :project_id, :dedup_key, 0, NOW()
             )',
            [
                'user_id' => $userId,
                'type' => mb_substr($type, 0, 40),
                'title' => mb_substr($title, 0, 150),
                'body' => $body !== null && $body !== '' ? mb_substr($body, 0, 255) : null,
                'link' => $link !== null && $link !== '' ? mb_substr($link, 0, 190) : null,
                'project_id' => $projectId,
                'dedup_key' => mb_substr($dedupKey, 0, 100),
            ]
        );
    }

    /**
     * @param array<int, int> $userIds
     */
    public static function pushToMany(
        array $userIds,
        string $type,
        string $title,
        ?string $body,
        ?string $link,
        ?int $projectId,
        string $dedupKey,
        int $exceptUserId = 0
    ): int {
        $sent = 0;

        foreach (array_unique($userIds) as $userId) {
            $userId = (int) $userId;
            if ($userId <= 0 || $userId === $exceptUserId) {
                continue;
            }

            self::push($userId, $type, $title, $body, $link, $projectId, $dedupKey);
            $sent++;
        }

        return $sent;
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, total: int, page: int, pages: int, per_page: int}
     */
    public static function inbox(int $userId, int $page, int $perPage = 15): array
    {
        $countRow = self::fetchOne(
            'SELECT COUNT(*) AS total FROM notifications WHERE user_id = :user_id',
            ['user_id' => $userId]
        );
        $total = (int) ($countRow['total'] ?? 0);

        $perPage = max(1, min($perPage, 50));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;

        $rows = self::fetchAll(
            'SELECT * FROM notifications
             WHERE user_id = :user_id
             ORDER BY is_read ASC, created_at DESC, id DESC
             LIMIT ' . $perPage . ' OFFSET ' . $offset,
            ['user_id' => $userId]
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
     * Penerima notifikasi sebuah proyek: pemegang permission projects.view,
     * penanggung jawab, pembuat proyek, dan anggota tim aktif.
     *
     * @param array<string, mixed> $project
     * @return array<int, int>
     */
    public static function projectRecipients(array $project, int $exceptUserId = 0): array
    {
        $ids = User::idsWithPermission('projects.view');
        $ids[] = (int) ($project['pic_user_id'] ?? 0);
        $ids[] = (int) ($project['created_by'] ?? 0);

        foreach (ProjectTeam::active((int) $project['id']) as $member) {
            $ids[] = (int) $member['user_id'];
        }

        return self::filterOut($ids, $exceptUserId);
    }

    /**
     * Sama seperti projectRecipients(), tetapi cukup menerima nomor proyek. Hasilnya
     * disimpan sementara agar satu siklus notifikasi tidak mengulang query yang sama
     * untuk puluhan surat.
     *
     * @return array<int, int>
     */
    public static function projectRecipientsById(int $projectId, int $exceptUserId = 0): array
    {
        if ($projectId <= 0) {
            return [];
        }

        $key = 'project-' . $projectId;
        if (!isset(self::$audienceCache[$key])) {
            $project = Project::find($projectId);
            self::$audienceCache[$key] = $project === null ? [] : self::projectRecipients($project, 0);
        }

        return self::filterOut(self::$audienceCache[$key], $exceptUserId);
    }

    /**
     * Penerima notifikasi RAB dan anggaran: pemegang budgets.approve ditambah penerima
     * proyek. Yang mengajukan tidak menerima suratnya sendiri.
     *
     * @return array<int, int>
     */
    public static function budgetAudience(int $projectId, int $exceptUserId = 0): array
    {
        $key = 'budget-' . $projectId;
        if (!isset(self::$audienceCache[$key])) {
            $ids = User::idsWithPermission('budgets.approve');
            foreach (self::projectRecipientsById($projectId, 0) as $id) {
                $ids[] = $id;
            }

            self::$audienceCache[$key] = self::filterOut($ids, 0);
        }

        return self::filterOut(self::$audienceCache[$key], $exceptUserId);
    }

    /**
     * Penerima notifikasi transaksi: pemegang transactions.approve, penerima proyek,
     * pencatat, dan pengaju transaksi.
     *
     * @param array<string, mixed> $transaction
     * @return array<int, int>
     */
    public static function transactionAudience(array $transaction, int $exceptUserId = 0): array
    {
        $ids = User::idsWithPermission('transactions.approve');
        foreach (self::projectRecipientsById((int) $transaction['project_id'], 0) as $id) {
            $ids[] = $id;
        }

        $ids[] = (int) ($transaction['recorded_by'] ?? 0);
        $ids[] = (int) ($transaction['submitted_by'] ?? 0);

        return self::filterOut($ids, $exceptUserId);
    }

    /**
     * Penerima notifikasi jadwal: penanggung jawab kegiatan, pembuat kegiatan, dan
     * penerima proyek.
     *
     * @param array<string, mixed> $task
     * @return array<int, int>
     */
    public static function scheduleAudience(array $task, int $exceptUserId = 0): array
    {
        $ids = self::projectRecipientsById((int) $task['project_id'], 0);
        $ids[] = (int) ($task['owner_user_id'] ?? 0);
        $ids[] = (int) ($task['created_by'] ?? 0);

        return self::filterOut($ids, $exceptUserId);
    }

    /**
     * @param array<int, int> $ids
     * @return array<int, int>
     */
    private static function filterOut(array $ids, int $exceptUserId): array
    {
        return array_values(array_unique(array_filter(
            $ids,
            static fn (int $id): bool => $id > 0 && $id !== $exceptUserId
        )));
    }

    /**
     * Penanda waktu peristiwa. Peristiwa alur kerja (mengajukan, memutus, membatalkan)
     * dapat terjadi lebih dari satu kali pada baris yang sama, sehingga kunci dedup
     * harus ikut berubah; pengingat berbasis tanggal memakai kunci sendiri di bawah.
     */
    private static function stamp(): string
    {
        return date('YmdHis');
    }

    /**
     * @param array<string, mixed> $project
     */
    public static function projectCreated(array $project, int $actorId): void
    {
        self::pushToMany(
            self::projectRecipients($project, $actorId),
            'project.created',
            'Proyek baru: ' . (string) $project['name'],
            'Kode ' . (string) $project['project_code'] . ' di ' . (string) $project['location'] . '.',
            '/proyek/' . (int) $project['id'],
            (int) $project['id'],
            'created-' . (int) $project['id']
        );
    }

    /**
     * @param array<string, mixed> $project
     */
    public static function statusChanged(array $project, string $toStatus, int $logId, int $actorId): void
    {
        self::pushToMany(
            self::projectRecipients($project, $actorId),
            'project.status',
            'Status berubah: ' . (string) $project['name'],
            'Status proyek kini ' . project_status_label($toStatus) . '.',
            '/proyek/' . (int) $project['id'],
            (int) $project['id'],
            'status-' . $logId
        );
    }

    /**
     * @param array<string, mixed> $project
     */
    public static function progressReported(
        array $project,
        int $reportId,
        int $progress,
        int $actorId,
        ?string $workDescription
    ): void {
        self::pushToMany(
            self::projectRecipients($project, $actorId),
            'project.progress',
            'Progres diperbarui: ' . (string) $project['name'],
            'Progres tercatat ' . $progress . '%.'
            . ($workDescription !== null && $workDescription !== '' ? ' ' . mb_substr($workDescription, 0, 150) : ''),
            '/proyek/' . (int) $project['id'],
            (int) $project['id'],
            'progress-' . $reportId
        );
    }

    /**
     * @param array<string, mixed> $project
     */
    public static function documentUploaded(array $project, int $documentId, string $title, int $actorId): void
    {
        self::pushToMany(
            self::projectRecipients($project, $actorId),
            'project.document',
            'Dokumen baru: ' . (string) $project['name'],
            mb_substr($title, 0, 150),
            '/proyek/' . (int) $project['id'] . '#dokumen',
            (int) $project['id'],
            'document-' . $documentId
        );
    }

    /**
     * RAB diajukan: hanya pemegang budgets.approve yang perlu tahu, dan pengajunya
     * sendiri tidak dikirimi surat (kebijakan pemisahan tugas).
     *
     * @param array<string, mixed> $plan
     */
    public static function budgetSubmitted(array $plan, int $actorId): void
    {
        self::pushToMany(
            self::budgetAudience((int) $plan['project_id'], $actorId),
            'budget.submitted',
            'RAB menunggu persetujuan: ' . (string) $plan['rab_code'],
            (string) $plan['name'] . ' senilai ' . rupiah((float) $plan['total_amount'])
                . ' diajukan oleh ' . self::person($plan, 'creator_name', $actorId) . '.',
            '/rab/' . (int) $plan['id'],
            (int) $plan['project_id'],
            'submitted-' . (int) $plan['id'] . '-' . self::stamp()
        );
    }

    /**
     * Keputusan RAB disampaikan ke pembuat, pengaju, dan penerima proyek.
     *
     * @param array<string, mixed> $plan
     */
    public static function budgetDecided(array $plan, string $decision, int $actorId): void
    {
        $approved = $decision === BudgetPlan::STATUS_APPROVED;
        $note = (string) ($plan['decision_note'] ?? '');
        $ids = self::projectRecipientsById((int) $plan['project_id'], $actorId);
        $ids[] = (int) ($plan['created_by'] ?? 0);
        $ids[] = (int) ($plan['submitted_by'] ?? 0);

        self::pushToMany(
            self::filterOut($ids, $actorId),
            'budget.decided',
            ($approved ? 'RAB disetujui: ' : 'RAB ditolak: ') . (string) $plan['rab_code'],
            (string) $plan['name'] . ' senilai ' . rupiah((float) $plan['total_amount'])
                . ' diputus oleh ' . self::person($plan, 'reviewer_name', $actorId) . '.'
                . ($note !== '' ? ' Catatan: ' . mb_substr($note, 0, 120) : ''),
            '/rab/' . (int) $plan['id'],
            (int) $plan['project_id'],
            'decided-' . (int) $plan['id'] . '-' . self::stamp()
        );
    }

    /**
     * @param array<string, mixed> $revision
     */
    public static function revisionSubmitted(array $revision, int $actorId): void
    {
        self::pushToMany(
            self::budgetAudience((int) $revision['project_id'], $actorId),
            'revision.submitted',
            'Perubahan anggaran diajukan: ' . (string) $revision['revision_code'],
            'Usulan ' . rupiah((float) $revision['proposed_amount']) . ' dari '
                . rupiah((float) $revision['previous_amount']) . '. Alasan: '
                . mb_substr((string) $revision['reason'], 0, 120),
            '/anggaran/perubahan/' . (int) $revision['id'],
            (int) $revision['project_id'],
            'revision-' . (int) $revision['id'] . '-submitted'
        );
    }

    /**
     * @param array<string, mixed> $revision
     */
    public static function revisionDecided(array $revision, string $decision, int $actorId): void
    {
        $approved = $decision === BudgetRevision::STATUS_APPROVED;
        $ids = self::projectRecipientsById((int) $revision['project_id'], $actorId);
        $ids[] = (int) ($revision['requested_by'] ?? 0);

        self::pushToMany(
            self::filterOut($ids, $actorId),
            'revision.decided',
            ($approved ? 'Perubahan anggaran disetujui: ' : 'Perubahan anggaran ditolak: ')
                . (string) $revision['revision_code'],
            'Anggaran proyek menjadi ' . rupiah((float) $revision['proposed_amount'])
                . '.' . ($approved ? '' : ' Alasan: ' . mb_substr((string) $revision['decision_note'], 0, 120)),
            '/anggaran/' . (int) $revision['project_id'],
            (int) $revision['project_id'],
            'revision-' . (int) $revision['id'] . '-decided'
        );
    }

    /**
     * Transaksi diajukan untuk persetujuan.
     *
     * @param array<string, mixed> $transaction
     */
    public static function transactionSubmitted(array $transaction, int $actorId): void
    {
        self::pushToMany(
            self::filterOut(User::idsWithPermission('transactions.approve'), $actorId),
            'transaction.submitted',
            'Transaksi menunggu persetujuan: ' . (string) $transaction['transaction_code'],
            transaction_type_label($transaction['transaction_type']) . ' '
                . rupiah((float) $transaction['amount']) . ' pada ' . (string) $transaction['project_name']
                . '. ' . mb_substr((string) $transaction['description'], 0, 100),
            '/transaksi/' . (int) $transaction['id'],
            (int) $transaction['project_id'],
            'submitted-' . (int) $transaction['id'] . '-' . self::stamp()
        );
    }

    /**
     * @param array<string, mixed> $transaction
     */
    public static function transactionDecided(array $transaction, string $decision, int $actorId): void
    {
        $approved = $decision === FinancialTransaction::STATUS_APPROVED;
        $note = (string) ($transaction['decision_note'] ?? '');
        $ids = self::projectRecipientsById((int) $transaction['project_id'], $actorId);
        $ids[] = (int) ($transaction['recorded_by'] ?? 0);
        $ids[] = (int) ($transaction['submitted_by'] ?? 0);

        self::pushToMany(
            self::filterOut($ids, $actorId),
            'transaction.decided',
            ($approved ? 'Transaksi disetujui: ' : 'Transaksi ditolak: ')
                . (string) $transaction['transaction_code'],
            transaction_type_label($transaction['transaction_type']) . ' '
                . rupiah((float) $transaction['amount']) . ' '
                . ($approved ? 'sudah disetujui' : 'ditolak') . ' oleh '
                . self::person($transaction, 'approver_name', $actorId) . '.'
                . ($note !== '' ? ' Catatan: ' . mb_substr($note, 0, 120) : ''),
            '/transaksi/' . (int) $transaction['id'],
            (int) $transaction['project_id'],
            'decided-' . (int) $transaction['id'] . '-' . self::stamp()
        );
    }

    /**
     * Pembatalan transaksi disetujui dikirim ke semua pihak yang terlibat, karena
     * angka realisasi proyek berubah akibat tindakan ini.
     *
     * @param array<string, mixed> $transaction
     */
    public static function transactionCancelled(array $transaction, int $actorId): void
    {
        self::pushToMany(
            self::transactionAudience($transaction, $actorId),
            'transaction.cancelled',
            'Transaksi dibatalkan: ' . (string) $transaction['transaction_code'],
            rupiah((float) $transaction['amount']) . ' dibatalkan oleh '
                . self::person($transaction, 'canceller_name', $actorId) . '. Alasan: '
                . mb_substr((string) $transaction['cancel_reason'], 0, 140),
            '/transaksi/' . (int) $transaction['id'],
            (int) $transaction['project_id'],
            'cancelled-' . (int) $transaction['id'] . '-' . self::stamp()
        );
    }

    /**
     * @param array<string, mixed> $task
     */
    public static function scheduleCreated(array $task, int $actorId): void
    {
        self::pushToMany(
            self::scheduleAudience($task, $actorId),
            'schedule.changed',
            'Kegiatan baru: ' . (string) $task['activity_code'] . ' ' . (string) $task['name'],
            'Jadwal ' . tanggal_id((string) $task['start_date']) . ' sampai '
                . tanggal_id((string) $task['end_date']) . ' pada ' . (string) $task['project_name'] . '.',
            '/jadwal/' . (int) $task['id'],
            (int) $task['project_id'],
            'created-' . (int) $task['id']
        );
    }

    /**
     * Perubahan jadwal dinotifikasi sekali per proses penyimpanan, bukan per bidang
     * yang berubah, supaya kotak masuk tidak terisi banyak surat sekaligus.
     *
     * @param array<string, mixed> $task
     * @param array<int, string> $changed
     */
    public static function scheduleChanged(array $task, array $changed, ?string $reason, int $actorId): void
    {
        if ($changed === []) {
            return;
        }

        self::pushToMany(
            self::scheduleAudience($task, $actorId),
            'schedule.changed',
            'Jadwal berubah: ' . (string) $task['activity_code'] . ' ' . (string) $task['name'],
            'Bidang yang berubah: ' . implode(', ', array_slice($changed, 0, 4))
                . '.' . ($reason !== null && $reason !== '' ? ' Alasan: ' . mb_substr($reason, 0, 120) : ''),
            '/jadwal/' . (int) $task['id'],
            (int) $task['project_id'],
            'changed-' . (int) $task['id'] . '-' . self::stamp()
        );
    }

    /**
     * Pengingat anggaran mendekati batas dan terlampaui. Kunci dedup memuat nama
     * indikator dan bulan berjalan, sehingga proyek yang sama tidak mengirim surat
     * berulang selama statusnya tidak berubah, dan tetap mengingatkan lagi bulan depan.
     *
     * @param array<string, mixed> $overview Hasil BudgetSummary::projectOverview().
     */
    public static function budgetThreshold(array $overview): bool
    {
        $indicator = (string) $overview['indicator'];
        $type = match ($indicator) {
            BudgetSummary::INDICATOR_WARNING => 'budget.warning',
            BudgetSummary::INDICATOR_EXCEEDED => 'budget.exceeded',
            default => '',
        };
        if ($type === '') {
            return false;
        }

        $projectId = (int) $overview['project_id'];
        $name = (string) $overview['project_name'];
        $percent = (float) ($overview['percent'] ?? 0);
        $body = 'Realisasi ' . rupiah((float) $overview['realization']) . ' dari anggaran '
            . rupiah((float) $overview['baseline']) . ' (' . percent_id($percent) . ').'
            . ' Sisa ' . rupiah((float) $overview['remaining']) . '.';

        self::pushToMany(
            self::projectRecipientsById($projectId, 0),
            $type,
            ($type === 'budget.warning' ? 'Anggaran mendekati batas: ' : 'Anggaran terlampaui: ') . $name,
            $body,
            '/anggaran/' . $projectId,
            $projectId,
            $type . '-' . $projectId . '-' . date('Y-m')
        );

        return true;
    }

    /**
     * Pengingat tenggat dan keterlambatan kegiatan. Kunci dedup memuat tanggal selesai
     * (menjelang tenggat) atau nomor pekan (terlambat) sehingga pengingat muncul satu
     * kali per siklus, tidak peduli berapa kali halaman dibuka.
     *
     * @return array{due: int, late: int}
     */
    public static function generateScheduleReminders(): array
    {
        $days = BudgetSummary::dueSoonDays();

        $due = Database::query(
            'SELECT s.*, pr.name AS project_name, pr.project_code
             FROM project_schedules s
             INNER JOIN projects pr ON pr.id = s.project_id
             WHERE s.is_deleted = 0 AND pr.is_archived = 0
               AND s.status IN (\'not_started\', \'in_progress\', \'delayed\')
               AND s.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ' . $days . ' DAY)
             ORDER BY s.end_date ASC, s.id ASC
             LIMIT 200'
        )->fetchAll();

        $late = Database::query(
            'SELECT s.*, pr.name AS project_name, pr.project_code
             FROM project_schedules s
             INNER JOIN projects pr ON pr.id = s.project_id
             WHERE s.is_deleted = 0 AND pr.is_archived = 0
               AND s.status IN (\'not_started\', \'in_progress\', \'delayed\')
               AND s.end_date < CURDATE()
             ORDER BY s.end_date ASC, s.id ASC
             LIMIT 200'
        )->fetchAll();

        foreach ($due as $task) {
            $left = (int) date_diff(date_create('today'), date_create((string) $task['end_date']))->format('%a');
            self::pushToMany(
                self::scheduleAudience($task, 0),
                'schedule.due',
                'Menjelang tenggat: ' . (string) $task['name'],
                'Kegiatan ' . (string) $task['activity_code'] . ' pada ' . (string) $task['project_name']
                    . ' selesai ' . tanggal_id((string) $task['end_date'])
                    . ($left === 0 ? ' (hari ini)' : ' (' . $left . ' hari lagi)') . '.',
                '/jadwal/' . (int) $task['id'],
                (int) $task['project_id'],
                'due-' . (int) $task['id'] . '-' . (string) $task['end_date']
            );
        }

        foreach ($late as $task) {
            $lateDays = (int) date_diff(date_create((string) $task['end_date']), date_create('today'))->format('%a');
            self::pushToMany(
                self::scheduleAudience($task, 0),
                'schedule.late',
                'Kegiatan terlambat: ' . (string) $task['name'],
                'Kegiatan ' . (string) $task['activity_code'] . ' pada ' . (string) $task['project_name']
                    . ' melewati ' . tanggal_id((string) $task['end_date']) . ' selama ' . $lateDays . ' hari.',
                '/jadwal/' . (int) $task['id'],
                (int) $task['project_id'],
                'late-' . (int) $task['id'] . '-' . date('Y-W')
            );
        }

        return ['due' => count($due), 'late' => count($late)];
    }

    /**
     * Pengingat anggaran untuk seluruh proyek aktif. Dipanggil dari dashboard sehingga
     * tidak perlu cron terpisah; kunci dedup menjaga agar surat hanya muncul sekali
     * per indikator per bulan.
     *
     * @return array{warning: int, exceeded: int, checked: int}
     */
    public static function generateBudgetWarnings(int $limit = 100): array
    {
        $counts = ['warning' => 0, 'exceeded' => 0, 'checked' => 0];

        foreach (BudgetSummary::perProject('', $limit) as $row) {
            $overview = self::budgetThresholdRow($row);
            $counts['checked']++;
            if (self::budgetThreshold($overview)) {
                $counts[$overview['indicator']] = ($counts[$overview['indicator']] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * @param array<string, mixed> $row Hasil BudgetSummary::perProject().
     * @return array<string, mixed>
     */
    private static function budgetThresholdRow(array $row): array
    {
        return [
            'project_id' => (int) $row['project_id'],
            'project_name' => (string) $row['project_name'],
            'baseline' => (float) $row['baseline'],
            'realization' => (float) $row['realization'],
            'remaining' => (float) $row['remaining'],
            'percent' => $row['percent'],
            'indicator' => (string) $row['indicator'],
        ];
    }

    /**
     * Nama orang yang menampilkan peristiwa di surat; memakai kolom nama hasil join
     * bila ada, jika tidak memakai nomor pengguna.
     *
     * @param array<string, mixed> $row
     */
    private static function person(array $row, string $column, int $fallbackId): string
    {
        $name = trim((string) ($row[$column] ?? ''));

        return $name !== '' ? $name : ('pengguna #' . $fallbackId);
    }

    /**
     * Pengingat tenggat dan keterlambatan. Kunci dedup berisi tanggal target sehingga
     * satu proyek hanya memunculkan satu pengingat per siklus tenggat, tidak peduli
     * berapa kali dashboard dibuka.
     *
     * @return array{due: int, late: int}
     */
    public static function generateDeadlineReminders(): array
    {
        $due = Database::query(
            'SELECT p.*, u.full_name AS pic_user_name
             FROM projects p
             LEFT JOIN users u ON u.id = p.pic_user_id
             WHERE p.is_archived = 0
               AND p.status IN (\'planning\', \'preparation\', \'running\', \'delayed\')
               AND p.target_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)'
        )->fetchAll();

        $late = Database::query(
            'SELECT p.*, u.full_name AS pic_user_name
             FROM projects p
             LEFT JOIN users u ON u.id = p.pic_user_id
             WHERE p.is_archived = 0
               AND p.status IN (\'planning\', \'preparation\', \'running\', \'delayed\')
               AND p.target_date < CURDATE()'
        )->fetchAll();

        foreach ($due as $project) {
            $days = (int) date_diff(date_create('today'), date_create((string) $project['target_date']))->format('%a');
            self::pushToMany(
                self::projectRecipients($project),
                'project.due',
                'Menjelang tenggat: ' . (string) $project['name'],
                'Target selesai ' . tanggal_id((string) $project['target_date'])
                . ($days === 0 ? ' (hari ini)' : ' (' . $days . ' hari lagi)'),
                '/proyek/' . (int) $project['id'],
                (int) $project['id'],
                'due-' . (int) $project['id'] . '-' . (string) $project['target_date']
            );
        }

        foreach ($late as $project) {
            $days = (int) date_diff(date_create((string) $project['target_date']), date_create('today'))->format('%a');
            self::pushToMany(
                self::projectRecipients($project),
                'project.late',
                'Terlambat: ' . (string) $project['name'],
                'Melewati target selesai ' . tanggal_id((string) $project['target_date'])
                . ' selama ' . $days . ' hari.',
                '/proyek/' . (int) $project['id'],
                (int) $project['id'],
                'late-' . (int) $project['id'] . '-' . date('Y-W')
            );
        }

        return ['due' => count($due), 'late' => count($late)];
    }

    /** @return array<string, mixed>|null Hanya milik pengguna yang bersangkutan. */
    public static function findForUser(int $userId, int $id): ?array
    {
        return self::fetchOne(
            'SELECT * FROM notifications WHERE id = :id AND user_id = :user_id LIMIT 1',
            ['id' => $id, 'user_id' => $userId]
        );
    }

    public static function unreadCount(int $userId): int
    {
        $row = self::fetchOne(
            'SELECT COUNT(*) AS total FROM notifications WHERE user_id = :user_id AND is_read = 0',
            ['user_id' => $userId]
        );

        return (int) ($row['total'] ?? 0);
    }

    public static function markRead(int $userId, int $id): void
    {
        Database::execute(
            'UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = :id AND user_id = :user_id',
            ['id' => $id, 'user_id' => $userId]
        );
    }

    public static function markAllRead(int $userId): void
    {
        Database::execute(
            'UPDATE notifications SET is_read = 1, read_at = NOW()
             WHERE user_id = :user_id AND is_read = 0',
            ['user_id' => $userId]
        );
    }

    /* ----------------------------------------------------------------------
     * Modul MBG. Penerima dihitung dari izin, bukan dari nama peran, supaya
     * penambahan peran baru tidak menuntut perubahan kode.
     * -------------------------------------------------------------------- */

    /** @return array<int, int> Pemegang izin yang berwenang memutus menu. */
    public static function menuApproverAudience(int $exceptUserId = 0): array
    {
        return self::filterOut(User::idsWithPermission('menus.approve'), $exceptUserId);
    }

    /** @param array<string, mixed> $menu */
    public static function menuSubmitted(array $menu, int $actorId): void
    {
        self::pushToMany(
            self::menuApproverAudience($actorId),
            'menu.submitted',
            'Menu menunggu ahli gizi: ' . (string) $menu['menu_code'],
            (string) $menu['menu_name'] . ' versi ' . (int) ($menu['version'] ?? 1)
                . ' diajukan oleh ' . self::person($menu, 'creator_name', $actorId) . '.',
            '/menu/' . (int) $menu['id'],
            null,
            'menu-submitted-' . (int) $menu['id'] . '-' . self::stamp()
        );
    }

    /** @param array<string, mixed> $menu */
    public static function menuDecided(array $menu, string $decision, int $actorId): void
    {
        $ids = [(int) ($menu['created_by'] ?? 0), (int) ($menu['submitted_by'] ?? 0)];
        $note = (string) ($menu['decision_note'] ?? '');

        self::pushToMany(
            self::filterOut($ids, $actorId),
            'menu.decided',
            ($decision === Menu::STATUS_APPROVED ? 'Menu disetujui: ' : 'Menu ditolak: ')
                . (string) $menu['menu_code'],
            (string) $menu['menu_name'] . ' diputus oleh '
                . self::person($menu, 'reviewer_name', $actorId) . '.'
                . ($note !== '' ? ' Catatan: ' . mb_substr($note, 0, 120) : ''),
            '/menu/' . (int) $menu['id'],
            null,
            'menu-decided-' . (int) $menu['id'] . '-' . self::stamp()
        );
    }

    /** @param array<string, mixed> $revision */
    public static function menuRevisionSubmitted(array $revision, int $actorId): void
    {
        self::pushToMany(
            self::menuApproverAudience($actorId),
            'menu.revision',
            'Usulan revisi menu: ' . (string) $revision['revision_code'],
            'Menu ' . (string) $revision['menu_code'] . ' diusulkan berubah. Alasan: '
                . mb_substr((string) $revision['reason'], 0, 120),
            '/menu/' . (int) $revision['menu_id'],
            null,
            'menu-revision-' . (int) $revision['id'] . '-submitted'
        );
    }

    /** @param array<string, mixed> $revision */
    public static function menuRevisionDecided(array $revision, string $decision, int $actorId): void
    {
        self::pushToMany(
            self::filterOut([(int) ($revision['requested_by'] ?? 0)], $actorId),
            'menu.revision.decided',
            ($decision === MenuRevision::STATUS_APPROVED ? 'Revisi menu disetujui: ' : 'Revisi menu ditolak: ')
                . (string) $revision['revision_code'],
            'Keputusan atas usulan perubahan menu ' . (string) ($revision['menu_code'] ?? '') . '.'
                . ((string) ($revision['decision_note'] ?? '') !== ''
                    ? ' Catatan: ' . mb_substr((string) $revision['decision_note'], 0, 120) : ''),
            '/menu/' . (int) $revision['menu_id'],
            null,
            'menu-revision-' . (int) $revision['id'] . '-decided'
        );
    }

    /** @param array<string, mixed> $schedule */
    public static function menuScheduleSubmitted(array $schedule, int $actorId): void
    {
        self::pushToMany(
            self::filterOut(User::idsWithPermission('menu_schedules.manage'), $actorId),
            'menu_schedule.submitted',
            'Jadwal menu menunggu persetujuan: ' . (string) $schedule['schedule_code'],
            (string) $schedule['service_date'] . ' ' . meal_slot_label((string) $schedule['meal_slot'])
                . ' untuk ' . (int) $schedule['portion_total'] . ' porsi.',
            '/jadwal-menu/' . (int) $schedule['id'],
            null,
            'menu-schedule-' . (int) $schedule['id'] . '-' . self::stamp()
        );
    }

    /** @param array<string, mixed> $schedule */
    public static function menuScheduleDecided(array $schedule, string $decision, int $actorId): void
    {
        self::pushToMany(
            self::filterOut([(int) ($schedule['submitted_by'] ?? 0), (int) ($schedule['created_by'] ?? 0)], $actorId),
            'menu_schedule.decided',
            ($decision === MenuSchedule::STATUS_APPROVED ? 'Jadwal menu disetujui: ' : 'Jadwal menu ditolak: ')
                . (string) $schedule['schedule_code'],
            (string) $schedule['service_date'] . ' ' . meal_slot_label((string) $schedule['meal_slot'])
                . ' diputus oleh ' . self::person($schedule, 'reviewer_name', $actorId) . '.',
            '/jadwal-menu/' . (int) $schedule['id'],
            null,
            'menu-schedule-' . (int) $schedule['id'] . '-decided-' . self::stamp()
        );
    }

    /** Peringatan MoA sekolah yang akan berakhir, dipakai dashboard MBG. */
    public static function partnershipExpiring(array $school): void
    {
        self::pushToMany(
            User::idsWithPermission('schools.manage'),
            'school.partnership',
            'Kerja sama sekolah akan berakhir: ' . (string) $school['school_name'],
            'MoA berakhir pada ' . (string) $school['partnership_end']
                . '. Jumlah penerima manfaat ' . (int) ($school['beneficiary_total'] ?? 0) . ' siswa.',
            '/sekolah/' . (int) $school['id'],
            null,
            'school-partnership-' . (int) $school['id'] . '-' . mb_substr((string) $school['partnership_end'], 0, 10)
        );
    }
}
