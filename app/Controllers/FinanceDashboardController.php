<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Models\BudgetPlan;
use App\Models\BudgetRevision;
use App\Models\BudgetSummary;
use App\Models\FinancialTransaction;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectSchedule;

/**
 * Dashboard keuangan dan jadwal. Semua angka dibaca dari MySQL pada permintaan
 * ini juga; tidak ada nilai sementara yang disimpan di sisi browser.
 */
final class FinanceDashboardController extends Controller
{
    public function index(Request $request): void
    {
        $projectId = $this->projectId($request);
        $center = $this->costCenter($request);
        [$from, $to] = $this->period($request);

        $overview = BudgetSummary::globalOverview($center);
        $dueDays = BudgetSummary::dueSoonDays();

        // Pengingat anggaran dan jadwal dibuat di sini agar tidak perlu cron terpisah;
        // kunci dedup pada model menjaga satu surat per kegiatan per siklus tenggat.
        Notification::generateScheduleReminders();
        Notification::generateBudgetWarnings();

        $this->view('keuangan/index', [
            'title' => 'Dashboard Keuangan & Jadwal',
            'pageTitle' => 'Ringkasan Keuangan dan Jadwal',
            'overview' => $overview,
            'projects' => Project::optionList(true),
            'projectId' => $projectId,
            'center' => $center,
            'centers' => FinancialTransaction::COST_CENTERS,
            'byCenter' => BudgetSummary::byCostCenter(),
            'perProject' => BudgetSummary::perProject($center, 12),
            'categories' => BudgetSummary::perCategory($projectId, $center),
            'series' => FinancialTransaction::monthlySeries($from, $to, $center),
            'from' => $from,
            'to' => $to,
            'counts' => BudgetSummary::transactionStatusCounts($center),
            'pendingTransactions' => FinancialTransaction::pending(6),
            'pendingPlans' => BudgetPlan::pending(6),
            'pendingRevisions' => BudgetRevision::pending(5),
            'scheduleSummary' => ProjectSchedule::summary($projectId),
            'upcoming' => ProjectSchedule::upcoming($dueDays, 6, $projectId),
            'lateTasks' => ProjectSchedule::late(6, $projectId),
            'targetActual' => ProjectSchedule::targetVersusActual($projectId),
            'progressByCategory' => ProjectSchedule::progressByCategory($projectId, 8),
            'dueDays' => $dueDays,
            'warningPercent' => BudgetSummary::warningPercent(),
            'pageScripts' => ['vendor/chartjs/chart.umd.min.js', 'js/charts.js', 'js/finance.js'],
        ]);
    }

    /** @return array{0: string, 1: string} */
    private function period(Request $request): array
    {
        $from = $request->string('dari');
        $to = $request->string('sampai');

        if (!date_is_valid($from)) {
            $from = date('Y-01-01');
        }

        if (!date_is_valid($to)) {
            $to = date('Y-12-31');
        }

        return $to < $from ? [$to, $from] : [$from, $to];
    }

    private function projectId(Request $request): int
    {
        $value = $request->string('proyek');

        return ctype_digit($value) ? max(0, (int) $value) : 0;
    }

    /**
     * Pusat biaya dibatasi pada nilai yang dikenal. Kosong berarti pembangunan dan
     * operasional MBG dihitung bersama-sama.
     */
    private function costCenter(Request $request): string
    {
        $center = $request->string('pusat');

        return in_array($center, FinancialTransaction::COST_CENTERS, true) ? $center : '';
    }
}
