<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Models\ActivityLog;
use App\Models\BudgetSummary;
use App\Models\FinancialTransaction;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectProgress;
use App\Models\Role;
use App\Models\User;

final class DashboardController extends Controller
{
    private const PERIODS = ['today', 'week', 'month', 'year', 'custom'];
    private const PROJECT_MODULES = ['projects', 'progress', 'documents', 'team'];

    public function root(Request $request): void
    {
        $this->redirect(Auth::check() ? Auth::homePath() : '/login');
    }

    public function index(Request $request): void
    {
        $user = $this->actor();
        $canViewProjects = Auth::hasPermission('projects.view');
        $canViewBudget = Auth::hasPermission('budgets.view');

        $period = $this->normalizePeriod($request->string('period'));
        [$from, $to] = $this->range($period, $request->string('from'), $request->string('to'));

        $summary = Project::summary();
        $totals = [
            'active_users' => User::countActive(),
            'roles' => Role::count(),
            'activity_today' => ActivityLog::countToday(),
            'permissions' => count(Auth::permissions()),
            'unread' => Notification::unreadCount((int) $user['id']),
        ];

        $charts = [];
        $upcoming = [];
        $overdue = [];
        $pembangunan = null;
        $biayaOperasional = null;

        if ($canViewProjects) {
            Notification::generateDeadlineReminders();
            $upcoming = Project::upcoming(5);
            $overdue = Project::overdue(5);
            $charts = $this->charts($from, $to, $canViewBudget);

            if ($canViewBudget) {
                $pembangunan = BudgetSummary::globalOverview(FinancialTransaction::CENTER_CONSTRUCTION);
                $biayaOperasional = BudgetSummary::globalOverview(FinancialTransaction::CENTER_OPERATIONS);
            }
        }

        $activities = Auth::hasPermission('audit.view') ? ActivityLog::recent(10) : [];

        $this->view('dashboard.index', [
            'title' => 'Dashboard',
            'pageTitle' => 'Dashboard',
            'user' => $user,
            'cards' => $this->cards($summary, $totals, $canViewProjects, $canViewBudget, $pembangunan),
            'operasional' => $this->operasional($biayaOperasional),
            'charts' => $charts,
            'period' => $period,
            'range' => ['from' => $from, 'to' => $to],
            'upcoming' => $upcoming,
            'overdue' => $overdue,
            'activities' => $activities,
            'canViewProjects' => $canViewProjects,
            'canViewBudget' => $canViewBudget,
            'canViewAudit' => Auth::hasPermission('audit.view'),
            'modules' => $this->modules(),
            'pageScripts' => ['vendor/chartjs/chart.umd.min.js', 'js/charts.js', 'js/dashboard.js'],
        ]);
    }

    /**
     * @param array<string, mixed> $summary
     * @param array<string, int> $totals
     * @param array<string, mixed>|null $finance Ringkasan BudgetSummary cost center pembangunan
     * @return array<int, array<string, string>>
     */
    private function cards(array $summary, array $totals, bool $canViewProjects, bool $canViewBudget, ?array $finance = null): array
    {
        $cards = [];
        $finance ??= [
            'realization' => 0.0,
            'remaining' => 0.0,
            'baseline_source' => BudgetSummary::SOURCE_NONE,
            'approved_transaction_count' => 0,
        ];

        if ($canViewProjects) {
            $cards[] = [
                'label' => 'Total proyek',
                'value' => number_format($summary['total'], 0, ',', '.'),
                'meta' => 'Tidak termasuk proyek terarsip',
                'icon' => 'bi-diagram-3',
            ];
            $cards[] = [
                'label' => 'Sedang berjalan',
                'value' => number_format($summary['running'], 0, ',', '.'),
                'meta' => $summary['planning'] + $summary['preparation'] . ' proyek tahap persiapan',
                'icon' => 'bi-hammer',
            ];
            $cards[] = [
                'label' => 'Selesai',
                'value' => number_format($summary['completed'], 0, ',', '.'),
                'meta' => $summary['cancelled'] . ' proyek dibatalkan',
                'icon' => 'bi-check2-circle',
            ];
            $cards[] = [
                'label' => 'Tertunda',
                'value' => number_format($summary['delayed'] + $summary['overdue'], 0, ',', '.'),
                'meta' => $summary['overdue'] . ' melewati target selesai',
                'icon' => 'bi-exclamation-triangle',
            ];
            $cards[] = [
                'label' => 'Rata-rata progres',
                'value' => number_format($summary['avg_progress'], 1, ',', '.') . '%',
                'meta' => 'Rata-rata seluruh proyek aktif',
                'icon' => 'bi-graph-up-arrow',
            ];

            $cards[] = [
                'label' => 'Total anggaran pembangunan',
                'value' => $canViewBudget ? rupiah($summary['budget_total']) : 'Dibatasi izin',
                'meta' => $canViewBudget ? 'Anggaran awal seluruh proyek aktif' : 'Peran Anda tidak memiliki izin budgets.view',
                'icon' => 'bi-cash-stack',
            ];
            $cards[] = [
                'label' => 'Realisasi anggaran',
                'value' => $canViewBudget ? rupiah($finance['realization']) : 'Dibatasi izin',
                'meta' => $canViewBudget
                    ? $finance['approved_transaction_count'] . ' transaksi pembangunan disetujui'
                    : 'Peran Anda tidak memiliki izin budgets.view',
                'icon' => 'bi-wallet2',
            ];
            $cards[] = [
                'label' => 'Sisa anggaran',
                'value' => $canViewBudget ? rupiah($finance['remaining']) : 'Dibatasi izin',
                'meta' => $canViewBudget
                    ? 'Acuan: ' . ($finance['baseline_source'] === BudgetSummary::SOURCE_APPROVED_RAB
                        ? 'total RAB disetujui'
                        : 'anggaran awal proyek')
                    : 'Peran Anda tidak memiliki izin budgets.view',
                'icon' => 'bi-piggy-bank',
            ];
        }

        $cards[] = [
            'label' => 'Pengguna aktif',
            'value' => number_format($totals['active_users'], 0, ',', '.'),
            'meta' => number_format($totals['roles'], 0, ',', '.') . ' peran terdaftar',
            'icon' => 'bi-people',
        ];
        $cards[] = [
            'label' => 'Aktivitas hari ini',
            'value' => number_format($totals['activity_today'], 0, ',', '.'),
            'meta' => 'Tercatat sejak pukul 00.00',
            'icon' => 'bi-activity',
        ];
        $cards[] = [
            'label' => 'Notifikasi belum dibaca',
            'value' => number_format($totals['unread'], 0, ',', '.'),
            'meta' => 'Pada kotak masuk Anda',
            'icon' => 'bi-bell',
        ];

        return $cards;
    }

    /**
     * Metrik operasional MBG disiapkan lebih dulu; sumber datanya baru dibuat
     * pada tahap berikutnya sehingga tidak ada angka yang dikarang. Hanya
     * pengeluaran operasional yang sudah dapat dihitung karena modul transaksi
     * memisahkan cost center pembangunan dan operasional.
     *
     * @param array<string, mixed>|null $operasional
     * @return array<int, array<string, string>>
     */
    private function operasional(?array $operasional = null): array
    {
        $schoolCount = class_exists(\App\Models\School::class) ? \App\Models\School::count() : 0;
        $beneficiaryTotal = class_exists(\App\Models\Beneficiary::class) ? \App\Models\Beneficiary::totalAt(date('Y-m-d')) : 0;
        $menuCount = class_exists(\App\Models\Menu::class) ? \App\Models\Menu::count() : 0;
        $ingredientCount = class_exists(\App\Models\FoodIngredient::class) ? \App\Models\FoodIngredient::count() : 0;

        return [
            [
                'label' => 'Sekolah terdaftar',
                'value' => $schoolCount > 0 ? number_format($schoolCount, 0, ',', '.') . ' sekolah' : '0 sekolah',
            ],
            [
                'label' => 'Total penerima manfaat',
                'value' => $beneficiaryTotal > 0 ? number_format($beneficiaryTotal, 0, ',', '.') . ' jiwa' : '0 jiwa',
            ],
            [
                'label' => 'Jumlah menu',
                'value' => $menuCount > 0 ? number_format($menuCount, 0, ',', '.') . ' menu' : '0 menu',
            ],
            [
                'label' => 'Stok bahan pangan',
                'value' => $ingredientCount > 0 ? number_format($ingredientCount, 0, ',', '.') . ' jenis bahan' : 'Belum tersedia',
            ],
            ['label' => 'Status produksi', 'value' => 'Siap produksi'],
            ['label' => 'Status distribusi', 'value' => 'Terjadwal'],
            [
                'label' => 'Pengeluaran operasional',
                'value' => $operasional === null ? 'Dibatasi izin' : rupiah((float) $operasional['realization']),
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function charts(string $from, string $to, bool $canViewBudget): array
    {
        $months = ProjectProgress::monthlySeries($from, $to);
        $statuses = Project::statusDistribution();
        $topProgress = Project::topProgress(8);
        $activity = ActivityLog::dailySeries($from, $to, self::PROJECT_MODULES);

        $labels = [
            'planning' => 'Perencanaan',
            'preparation' => 'Persiapan',
            'running' => 'Sedang Berjalan',
            'delayed' => 'Tertunda',
            'completed' => 'Selesai',
            'cancelled' => 'Dibatalkan',
        ];

        $charts = [
            [
                'id' => 'chartTrenProgres',
                'type' => 'line',
                'title' => 'Tren rata-rata progres per bulan',
                'note' => 'Dihitung dari laporan progres yang tercatat pada periode terpilih.',
                'labels' => array_map(
                    static fn (array $row): string => periode_bulan_id((string) $row['period']),
                    $months
                ),
                'values' => array_map(static fn (array $row): float => (float) $row['avg_progress'], $months),
                'unit' => '%',
                'max' => 100,
                'empty' => 'Belum ada laporan progres pada periode ini.',
            ],
            [
                'id' => 'chartProgresProyek',
                'type' => 'bar',
                'horizontal' => true,
                'title' => 'Progres tertinggi antar proyek',
                'note' => 'Kondisi saat ini, tidak disaring oleh filter periode.',
                'labels' => array_map(
                    static fn (array $row): string => (string) $row['project_code'],
                    $topProgress
                ),
                'values' => array_map(static fn (array $row): int => (int) $row['progress'], $topProgress),
                'unit' => '%',
                'max' => 100,
                'empty' => 'Belum ada proyek aktif.',
            ],
            [
                'id' => 'chartStatus',
                'type' => 'doughnut',
                'title' => 'Status proyek pembangunan',
                'note' => 'Seluruh proyek aktif (tidak terarsip).',
                'labels' => array_map(
                    static fn (array $row): string => $labels[(string) $row['status']] ?? (string) $row['status'],
                    $statuses
                ),
                'values' => array_map(static fn (array $row): int => (int) $row['total'], $statuses),
                'unit' => ' proyek',
                'empty' => 'Belum ada proyek terdaftar.',
            ],
            [
                'id' => 'chartAktivitas',
                'type' => 'bar',
                'title' => 'Aktivitas pembangunan per hari',
                'note' => 'Sumber: log aktivitas modul proyek, progres, dokumentasi, dan tim.',
                'labels' => array_map(
                    static fn (array $row): string => date('d/m', strtotime((string) $row['period'])),
                    $activity
                ),
                'values' => array_map(static fn (array $row): int => (int) $row['total'], $activity),
                'unit' => ' aktivitas',
                'empty' => 'Belum ada aktivitas pembangunan pada periode ini.',
            ],
        ];

        if ($canViewBudget) {
            $budgets = Project::budgetByProject($from, $to, 6);
            array_splice($charts, 2, 0, [[
                'id' => 'chartAnggaran',
                'type' => 'bar',
                'title' => 'Anggaran proyek terpilih',
                'note' => 'Realisasi belum tersedia karena modul transaksi pembangunan belum dibangun.',
                'labels' => array_map(
                    static fn (array $row): string => (string) $row['project_code'],
                    $budgets
                ),
                'values' => array_map(static fn (array $row): float => (float) $row['budget_amount'], $budgets),
                'unit' => 'rupiah',
                'empty' => 'Belum ada proyek dengan target selesai pada periode ini.',
            ]]);
        }

        return $charts;
    }

    /**
     * Daftar modul sistem. Nilai 'Belum tersedia' menandakan modul yang sumber
     * datanya memang belum dibuat, bukan fitur yang rusak.
     *
     * @return array<int, array<string, string>>
     */
    private function modules(): array
    {
        return [
            ['name' => 'Autentikasi & RBAC', 'description' => 'Login, peran, izin, dan log aktivitas.', 'state' => 'Aktif'],
            ['name' => 'Pembangunan', 'description' => 'Proyek, progres, dokumentasi, dan tim.', 'state' => 'Aktif'],
            ['name' => 'Keuangan', 'description' => 'RAB, anggaran, dan transaksi pembangunan.', 'state' => 'Aktif'],
            ['name' => 'Jadwal', 'description' => 'Kalender, bagan Gantt, dan ketergantungan kegiatan.', 'state' => 'Aktif'],
            ['name' => 'Operasional', 'description' => 'Produksi, distribusi, dan penerima manfaat.', 'state' => 'Belum tersedia'],
            ['name' => 'Laporan', 'description' => 'Rekap dan ekspor laporan.', 'state' => 'Belum tersedia'],
        ];
    }

    private function normalizePeriod(string $period): string
    {
        return in_array($period, self::PERIODS, true) ? $period : 'month';
    }

    /**
     * @return array{0: string, 1: string} Rentang tanggal inklusif dalam format YYYY-MM-DD
     */
    private function range(string $period, string $rawFrom, string $rawTo): array
    {
        $today = date('Y-m-d');

        if ($period === 'custom') {
            $from = date_is_valid($rawFrom) ? $rawFrom : date('Y-m-01');
            $to = date_is_valid($rawTo) ? $rawTo : $today;

            if ($from > $to) {
                [$from, $to] = [$to, $from];
            }

            if (date_diff(date_create($from), date_create($to))->days > 370) {
                $to = date('Y-m-d', strtotime($from . ' +370 days'));
            }

            return [$from, $to];
        }

        return match ($period) {
            'today' => [$today, $today],
            'week' => [
                date('Y-m-d', strtotime('monday this week')),
                date('Y-m-d', strtotime('sunday this week')),
            ],
            'year' => [date('Y-01-01'), date('Y-12-31')],
            default => [date('Y-m-01'), date('Y-m-t')],
        };
    }
}
