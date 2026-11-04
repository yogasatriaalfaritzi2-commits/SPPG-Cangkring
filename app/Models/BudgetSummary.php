<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\App;
use App\Core\Model;

/**
 * Rekap anggaran dan realisasi.
 *
 * Nilai uang di kelas ini selalu dihitung ulang dari database saat dibaca, tidak
 * pernah diambil dari kolom cache, sehingga realisasi tidak bisa tertinggal dari
 * transaksi terbaru. Semua rumus penyerapan berada di sini supaya halaman RAB,
 * anggaran, transaksi, dan dashboard memakai angka yang sama.
 */
final class BudgetSummary extends Model
{
    public const INDICATOR_SAFE = 'safe';
    public const INDICATOR_WARNING = 'warning';
    public const INDICATOR_EXCEEDED = 'exceeded';
    public const INDICATOR_UNKNOWN = 'unknown';

    public const SOURCE_APPROVED_RAB = 'approved_rab';
    public const SOURCE_PROJECT_BUDGET = 'project_budget';
    public const SOURCE_NONE = 'none';

    /** Ambang peringatan penyerapan (%) agar indikator tidak perlu diubah di kode. */
    public static function warningPercent(): int
    {
        $value = (int) trim((string) App::setting('budget_warning_percent', '85'));

        return $value < 1 || $value > 100 ? 85 : $value;
    }

    /** Kebijakan pemisahan tugas: pemohon tidak boleh menyetujui RAB/transaksi sendiri. */
    public static function segregationEnabled(): bool
    {
        return (string) App::setting('finance_segregation_of_duties', '1') === '1';
    }

    /** Batas "mendekati tenggat" untuk kegiatan jadwal. */
    public static function dueSoonDays(): int
    {
        $value = (int) trim((string) App::setting('schedule_due_days', '7'));

        return $value < 1 || $value > 90 ? 7 : $value;
    }

    /** Persentase penyerapan; null menandakan pembaginya nol sehingga tak dapat dihitung. */
    public static function percent(float $baseline, float $realization): ?float
    {
        if ($baseline <= 0.0) {
            return null;
        }

        return round($realization / $baseline * 100, 1);
    }

    public static function indicator(float $baseline, float $realization): string
    {
        $percent = self::percent($baseline, $realization);

        if ($percent === null) {
            return self::INDICATOR_UNKNOWN;
        }

        if ($percent > 100.0) {
            return self::INDICATOR_EXCEEDED;
        }

        return $percent >= (float) self::warningPercent() ? self::INDICATOR_WARNING : self::INDICATOR_SAFE;
    }

    /**
     * Anggaran acuan sebuah proyek. RAB yang sudah disetujui dipakai lebih dulu;
     * bila belum ada, anggaran awal proyek menjadi acuan agar halaman tetap dapat
     * menampilkan penyerapan sejak tahap perencanaan.
     */
    public static function baselineFor(float $approvedRab, float $initialBudget): array
    {
        if ($approvedRab > 0.0) {
            return ['baseline' => round($approvedRab, 2), 'source' => self::SOURCE_APPROVED_RAB];
        }

        if ($initialBudget > 0.0) {
            return ['baseline' => round($initialBudget, 2), 'source' => self::SOURCE_PROJECT_BUDGET];
        }

        return ['baseline' => 0.0, 'source' => self::SOURCE_NONE];
    }

    /**
     * Ringkasan satu proyek: anggaran awal, RAB disetujui, realisasi, sisa, dan
     * penyerapan. Nilai money dibulatkan dua desimal supaya penjumlahan di tampilan
     * tidak menghasilkan selisih satu sen.
     *
     * @return array<string, mixed>
     */
    public static function projectOverview(int $projectId, string $costCenter = FinancialTransaction::CENTER_CONSTRUCTION): array
    {
        $project = Project::findWithNames($projectId);
        $row = self::fetchOne(
            'SELECT COALESCE(SUM(b.total_amount), 0) AS approved_budget,
                    COUNT(b.id) AS approved_plan_count
             FROM budget_plans b
             WHERE b.project_id = :project_id AND b.status = \'approved\'',
            ['project_id' => $projectId]
        );

        $approvedRab = round((float) ($row['approved_budget'] ?? 0), 2);
        $initial = round((float) ($project['budget_amount'] ?? 0), 2);
        $realization = FinancialTransaction::realization($projectId, $costCenter);
        $income = FinancialTransaction::income($projectId, $costCenter);
        [$plannedAll, $planCount] = self::plannedAllForProject($projectId);

        $baseline = self::baselineFor($approvedRab, $initial);

        return [
            'project_id' => $projectId,
            'project_code' => (string) ($project['project_code'] ?? ''),
            'project_name' => (string) ($project['name'] ?? ''),
            'initial_budget' => $initial,
            'approved_budget' => $approvedRab,
            'approved_plan_count' => (int) ($row['approved_plan_count'] ?? 0),
            'planned_all_status' => $plannedAll,
            'plan_count' => $planCount,
            'baseline' => $baseline['baseline'],
            'baseline_source' => $baseline['source'],
            'realization' => $realization,
            'income' => $income,
            'remaining' => round($baseline['baseline'] - $realization, 2),
            'percent' => self::percent($baseline['baseline'], $realization),
            'indicator' => self::indicator($baseline['baseline'], $realization),
            'warning_percent' => self::warningPercent(),
            'cost_center' => $costCenter,
        ];
    }

    /**
     * Rekap anggaran dibandingkan realisasi untuk seluruh proyek aktif.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function perProject(
        string $costCenter = FinancialTransaction::CENTER_CONSTRUCTION,
        int $limit = 25
    ): array {
        $limit = max(1, min($limit, 100));
        $centerFilter = $costCenter === '' ? '' : ' AND t.cost_center = :cost_center';
        $params = [];
        if ($costCenter !== '') {
            $params['cost_center'] = $costCenter;
        }

        $rows = self::fetchAll(
            'SELECT pr.id AS project_id, pr.project_code, pr.name AS project_name,
                    pr.status AS project_status, pr.progress,
                    pr.budget_amount AS initial_budget,
                    COALESCE((SELECT SUM(b.total_amount) FROM budget_plans b
                              WHERE b.project_id = pr.id AND b.status = \'approved\'), 0) AS approved_budget,
                    COALESCE((SELECT SUM(' . FinancialTransaction::OUTFLOW_SQL . ')
                              FROM financial_transactions t
                              WHERE t.project_id = pr.id AND t.status = \'approved\'' . $centerFilter . '), 0) AS realization,
                    (SELECT COUNT(*) FROM financial_transactions q
                     WHERE q.project_id = pr.id AND q.status = \'pending\') AS pending_transactions,
                    (SELECT COUNT(*) FROM budget_plans w
                     WHERE w.project_id = pr.id AND w.status = \'pending\') AS pending_rab
             FROM projects pr
             WHERE pr.is_archived = 0
             ORDER BY approved_budget DESC, initial_budget DESC, pr.id ASC
             LIMIT ' . $limit,
            $params
        );

        $decorated = [];
        foreach ($rows as $row) {
            $row['realization'] = round((float) $row['realization'], 2);
            $baseline = self::baselineFor((float) $row['approved_budget'], (float) $row['initial_budget']);
            $row['baseline'] = $baseline['baseline'];
            $row['baseline_source'] = $baseline['source'];
            $row['remaining'] = round($baseline['baseline'] - $row['realization'], 2);
            $row['percent'] = self::percent($baseline['baseline'], $row['realization']);
            $row['indicator'] = self::indicator($baseline['baseline'], $row['realization']);
            $decorated[] = $row;
        }

        return $decorated;
    }

    /**
     * Anggaran per kategori pekerjaan dibandingkan realisasi transaksi.
     * Kategori yang hanya muncul di RAB atau hanya di transaksi tetap ditampilkan
     * supaya selisih rencana dan belanja tidak tersembunyi.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function perCategory(
        int $projectId = 0,
        string $costCenter = FinancialTransaction::CENTER_CONSTRUCTION
    ): array {
        $merged = [];

        foreach (BudgetItem::approvedAmountsByCategory($projectId) as $row) {
            $id = (int) $row['category_id'];
            $merged[$id] = [
                'category_id' => $id,
                'category_code' => (string) $row['category_code'],
                'category_name' => (string) $row['category_name'],
                'sort_order' => (int) $row['sort_order'],
                'planned' => round((float) $row['planned'], 2),
                'item_count' => (int) $row['item_count'],
                'realization' => 0.0,
                'transaction_count' => 0,
            ];
        }

        foreach (FinancialTransaction::realizationByCategory($projectId, $costCenter) as $row) {
            $id = (int) $row['category_id'];
            if (!isset($merged[$id])) {
                $merged[$id] = [
                    'category_id' => $id,
                    'category_code' => (string) $row['category_code'],
                    'category_name' => (string) $row['category_name'],
                    'sort_order' => 9999,
                    'planned' => 0.0,
                    'item_count' => 0,
                ];
            }

            $merged[$id]['realization'] = round((float) $row['spent'], 2);
            $merged[$id]['transaction_count'] = (int) $row['transaction_count'];
        }

        foreach ($merged as $id => $row) {
            $merged[$id]['remaining'] = round($row['planned'] - $row['realization'], 2);
            $merged[$id]['percent'] = self::percent($row['planned'], $row['realization']);
            $merged[$id]['indicator'] = self::indicator($row['planned'], $row['realization']);
        }

        usort(
            $merged,
            static fn (array $a, array $b): int => $b['planned'] <=> $a['planned']
                ?: $b['realization'] <=> $a['realization']
                ?: strcmp($a['category_name'], $b['category_name'])
        );

        return $merged;
    }

    /**
     * Angka agregat nasional untuk dashboard keuangan.
     *
     * @return array<string, mixed>
     */
    public static function globalOverview(string $costCenter = ''): array
    {
        $totals = self::fetchOne(
            'SELECT COALESCE(SUM(pr.budget_amount), 0) AS initial_budget,
                    COUNT(pr.id) AS project_count
             FROM projects pr
             WHERE pr.is_archived = 0'
        );

        $plans = self::fetchOne(
            "SELECT COALESCE(SUM(b.total_amount), 0) AS approved_budget, COUNT(b.id) AS approved_plan_count
             FROM budget_plans b
             INNER JOIN projects pr ON pr.id = b.project_id
             WHERE b.status = 'approved' AND pr.is_archived = 0"
        );

        $realization = self::realizationTotals($costCenter);
        $initial = round((float) ($totals['initial_budget'] ?? 0), 2);
        $approvedRab = round((float) ($plans['approved_budget'] ?? 0), 2);
        $baseline = self::baselineFor($approvedRab, $initial);

        return [
            'initial_budget' => $initial,
            'approved_budget' => $approvedRab,
            'approved_plan_count' => (int) ($plans['approved_plan_count'] ?? 0),
            'project_count' => (int) ($totals['project_count'] ?? 0),
            'baseline' => $baseline['baseline'],
            'baseline_source' => $baseline['source'],
            'realization' => $realization['realization'],
            'income' => $realization['income'],
            'remaining' => round($baseline['baseline'] - $realization['realization'], 2),
            'percent' => self::percent($baseline['baseline'], $realization['realization']),
            'indicator' => self::indicator($baseline['baseline'], $realization['realization']),
            'warning_percent' => self::warningPercent(),
            'approved_transaction_count' => $realization['approved_transaction_count'],
            'pending_transaction_count' => $realization['pending_transaction_count'],
            'cost_center' => $costCenter,
        ];
    }

    /**
     * Realisasi dan pemasukan yang dipisahkan per cost center, sehingga biaya
     * pembangunan dan biaya operasional MBG dapat dilaporkan terpisah.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function byCostCenter(): array
    {
        $rows = self::fetchAll(
            'SELECT t.cost_center,
                    COALESCE(SUM(' . FinancialTransaction::OUTFLOW_SQL . '), 0) AS realization,
                    COALESCE(SUM(CASE WHEN t.transaction_type = \'income\' THEN t.amount ELSE 0 END), 0) AS income,
                    COUNT(t.id) AS approved_count
             FROM financial_transactions t
             INNER JOIN projects pr ON pr.id = t.project_id
             WHERE t.status = \'approved\' AND pr.is_archived = 0
             GROUP BY t.cost_center'
        );

        $byCenter = [];
        foreach ($rows as $row) {
            $byCenter[(string) $row['cost_center']] = $row;
        }

        $result = [];
        foreach (FinancialTransaction::COST_CENTERS as $center) {
            $row = $byCenter[$center] ?? null;
            $result[$center] = [
                'cost_center' => $center,
                'realization' => round((float) ($row['realization'] ?? 0), 2),
                'income' => round((float) ($row['income'] ?? 0), 2),
                'approved_count' => (int) ($row['approved_count'] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * Jumlah transaksi per status pada proyek aktif. Dipakai dashboard dan
     * pengingat persetujuan agar keduanya membaca angka yang sama.
     *
     * @return array<string, int>
     */
    public static function transactionStatusCounts(string $costCenter = ''): array
    {
        $conditions = ['pr.is_archived = 0'];
        $params = [];

        if ($costCenter !== '') {
            $conditions[] = 't.cost_center = :cost_center';
            $params['cost_center'] = $costCenter;
        }

        $rows = self::fetchAll(
            'SELECT t.status, COUNT(*) AS total
             FROM financial_transactions t
             INNER JOIN projects pr ON pr.id = t.project_id
             WHERE ' . implode(' AND ', $conditions) . '
             GROUP BY t.status',
            $params
        );

        $counts = array_fill_keys(FinancialTransaction::STATUSES, 0);
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    /** @return array{realization: float, income: float, approved_transaction_count: int, pending_transaction_count: int} */
    private static function realizationTotals(string $costCenter): array
    {
        $conditions = ["t.status = 'approved'", 'pr.is_archived = 0'];
        $params = [];

        if ($costCenter !== '') {
            $conditions[] = 't.cost_center = :cost_center';
            $params['cost_center'] = $costCenter;
        }

        $row = self::fetchOne(
            'SELECT COALESCE(SUM(' . FinancialTransaction::OUTFLOW_SQL . '), 0) AS realization,
                    COALESCE(SUM(CASE WHEN t.transaction_type = \'income\' THEN t.amount ELSE 0 END), 0) AS income,
                    COUNT(t.id) AS approved_count
             FROM financial_transactions t
             INNER JOIN projects pr ON pr.id = t.project_id
             WHERE ' . implode(' AND ', $conditions),
            $params
        );

        $statusCounts = self::transactionStatusCounts($costCenter);

        return [
            'realization' => round((float) ($row['realization'] ?? 0), 2),
            'income' => round((float) ($row['income'] ?? 0), 2),
            'approved_transaction_count' => (int) ($row['approved_count'] ?? 0),
            'pending_transaction_count' => $statusCounts[FinancialTransaction::STATUS_PENDING],
        ];
    }

    /** @return array{0: float, 1: int} Total RAB semua status beserta jumlahnya. */
    private static function plannedAllForProject(int $projectId): array
    {
        $row = self::fetchOne(
            'SELECT COALESCE(SUM(total_amount), 0) AS total, COUNT(*) AS total_count
             FROM budget_plans
             WHERE project_id = :project_id AND status <> \'revised\'',
            ['project_id' => $projectId]
        );

        return [round((float) ($row['total'] ?? 0), 2), (int) ($row['total_count'] ?? 0)];
    }
}
