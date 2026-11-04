<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Csv;
use App\Core\Database;
use App\Core\Exceptions\NotFoundHttpException;
use App\Core\Request;
use App\Core\Session;
use App\Models\ActivityLog;
use App\Models\BudgetCategory;
use App\Models\BudgetPlan;
use App\Models\BudgetRevision;
use App\Models\BudgetSummary;
use App\Models\FinancialTransaction;
use App\Models\Notification;
use App\Models\Project;

/**
 * Manajemen anggaran: rekap anggaran dibandingkan realisasi, perubahan anggaran
 * berserta riwayat dan alasannya, serta ekspor. Nilai anggaran awal proyek tidak
 * pernah berubah tanpa persetujuan perubahan.
 */
final class BudgetController extends Controller
{
    public function index(Request $request): void
    {
        $center = $this->costCenter($request);
        $rows = BudgetSummary::perProject($center, 100);

        $totals = BudgetSummary::globalOverview($center);

        $this->view('anggaran/index', [
            'title' => 'Manajemen Anggaran',
            'pageTitle' => 'Anggaran dan Realisasi',
            'rows' => $rows,
            'totals' => $totals,
            'categories' => BudgetSummary::perCategory(0, $center),
            'costCenters' => BudgetSummary::byCostCenter(),
            'pendingRevisions' => BudgetRevision::pending(10),
            'pendingCount' => BudgetRevision::countPending(),
            'warningPercent' => BudgetSummary::warningPercent(),
            'center' => $center,
        ]);
    }

    public function show(Request $request, string $id): void
    {
        $project = $this->findProject($id);
        $projectId = (int) $project['id'];
        $center = $this->costCenter($request);
        $filters = $this->transactionFilters($request, $projectId);

        $this->view('anggaran/show', [
            'title' => 'Anggaran ' . (string) $project['name'],
            'pageTitle' => 'Anggaran Proyek',
            'project' => $project,
            'overview' => BudgetSummary::projectOverview($projectId, $center),
            'categories' => BudgetSummary::perCategory($projectId, $center),
            'plans' => BudgetPlan::forProject($projectId),
            'revisions' => BudgetRevision::historyForProject($projectId),
            'history' => Project::statusHistory($projectId),
            'transactions' => FinancialTransaction::paginate($filters, max(1, $request->integer('page', 1)), 10),
            'txFilters' => $filters,
            'filterCategories' => BudgetCategory::activeList(),
            'center' => $center,
            'warningPercent' => BudgetSummary::warningPercent(),
        ]);
    }

    /**
     * Ajukan perubahan anggaran. Nilai sebelumnya selalu diambil dari database,
     * bukan dari formulir, agar usulan tidak dapat menimpa dasar angka yang lama.
     */
    public function storeRevision(Request $request, string $id): void
    {
        $actor = $this->actor();
        $project = $this->findProject($id);
        $projectId = (int) $project['id'];

        $proposed = amount_normalize(trim($request->string('proposed_amount')));
        $reason = trim($request->string('reason'));

        if ($proposed === null || (float) $proposed < 0) {
            $this->fail('Usulan anggaran harus berupa angka nol atau lebih.', (string) $projectId);
        }

        if ((float) $proposed > 99999999999999.0) {
            $this->fail('Usulan anggaran terlalu besar untuk disimpan.', (string) $projectId);
        }

        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            $this->fail('Alasan perubahan wajib diisi 10 sampai 500 karakter. Anggaran tidak dapat diubah tanpa riwayat.', (string) $projectId);
        }

        if ((float) $proposed === (float) $project['budget_amount']) {
            $this->fail('Usulan anggaran sama dengan nilai yang berlaku, tidak perlu diajukan.', (string) $projectId);
        }

        if (BudgetRevision::hasPendingForProject($projectId)) {
            $this->fail('Proyek ini masih memiliki usulan perubahan yang menunggu keputusan.', (string) $projectId);
        }

        [$revisionId, $code] = BudgetRevision::create([
            'project_id' => $projectId,
            'previous_amount' => (float) $project['budget_amount'],
            'proposed_amount' => (float) $proposed,
            'reason' => $reason,
            'requested_by' => (int) $actor['id'],
        ]);

        $revision = BudgetRevision::findWithNames($revisionId);
        if ($revision !== null) {
            Notification::revisionSubmitted($revision, (int) $actor['id']);
        }

        ActivityLog::record(
            (int) $actor['id'],
            'budgets.revision.create',
            'budgets',
            'Mengajukan perubahan anggaran ' . $code . ' pada proyek "' . $project['project_code']
            . '": dari ' . rupiah((float) $project['budget_amount']) . ' menjadi ' . rupiah((float) $proposed) . '.',
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', 'Perubahan anggaran ' . $code . ' diajukan untuk persetujuan.');
        $this->redirect('/anggaran/perubahan/' . $revisionId);
    }

    public function showRevision(Request $request, string $id): void
    {
        $revision = $this->findRevision($id);

        $this->view('anggaran/revision', [
            'title' => 'Perubahan Anggaran ' . (string) $revision['revision_code'],
            'pageTitle' => 'Pengajuan Perubahan Anggaran',
            'revision' => $revision,
            'overview' => BudgetSummary::projectOverview((int) $revision['project_id']),
            'segregation' => BudgetSummary::segregationEnabled(),
        ]);
    }

    public function decideRevision(Request $request, string $id): void
    {
        $actor = $this->actor();
        $revision = $this->findRevision($id);

        $action = $request->string('action');
        if (!in_array($action, ['approve', 'reject'], true)) {
            $this->fail('Tindakan persetujuan tidak dikenali.', '');
        }

        if ((string) $revision['status'] !== BudgetRevision::STATUS_PENDING) {
            $this->fail('Usulan perubahan ini sudah diputus sebelumnya.', '');
        }

        $note = trim($request->string('note'));
        if ($action === 'reject' && $note === '') {
            $this->fail('Penolakan perubahan anggaran wajib disertai alasan.', '');
        }

        if ($action === 'approve' && BudgetSummary::segregationEnabled()
            && (int) $revision['requested_by'] === (int) $actor['id']) {
            $this->fail('Kebijakan pemisahan tugas berlaku: usulan yang Anda ajukan sendiri tidak dapat Anda setujui.', '');
        }

        $toStatus = $action === 'approve' ? BudgetRevision::STATUS_APPROVED : BudgetRevision::STATUS_REJECTED;

        // Persetujuan dan pembaruan anggaran proyek berjalan dalam satu transaksi
        // database agar nilai lama, keputusan, dan riwayat tidak pernah terpisah.
        $affected = Database::transaction(static fn (): int => BudgetRevision::decide(
            (int) $revision['id'],
            $toStatus,
            $note === '' ? null : $note,
            (int) $actor['id']
        ));

        if ($affected === 0) {
            $this->fail('Usulan ini baru saja diputus pengguna lain. Muat ulang halaman untuk melihat hasilnya.', '');
        }

        $fresh = BudgetRevision::findWithNames((int) $revision['id']);
        if ($fresh !== null) {
            Notification::revisionDecided($fresh, $toStatus, (int) $actor['id']);
        }

        ActivityLog::record(
            (int) $actor['id'],
            'budgets.revision.' . $action,
            'budgets',
            ($action === 'approve' ? 'Menyetujui' : 'Menolak') . ' perubahan anggaran ' . $revision['revision_code']
            . ' pada proyek ' . $revision['project_name'] . ' ('
            . rupiah((float) $revision['previous_amount']) . ' → ' . rupiah((float) $revision['proposed_amount']) . ').'
            . ($note !== '' ? ' Catatan: ' . mb_substr($note, 0, 140) : '.'),
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', $action === 'approve'
            ? 'Anggaran proyek diperbarui menjadi ' . rupiah((float) $revision['proposed_amount']) . '.'
            : 'Usulan perubahan anggaran ' . $revision['revision_code'] . ' ditolak.');
        $this->redirect('/anggaran/' . $revision['project_id']);
    }

    /**
     * Ekspor rekap anggaran per proyek beserta realisasinya.
     */
    public function export(Request $request): void
    {
        $actor = $this->actor();
        $center = $this->costCenter($request);
        $scope = $request->string('lingkup') === 'kategori' ? 'kategori' : 'proyek';

        $rows = [];

        if ($scope === 'kategori') {
            foreach (BudgetSummary::perCategory(0, $center) as $row) {
                $rows[] = [
                    $row['category_code'],
                    $row['category_name'],
                    $row['item_count'],
                    $row['planned'],
                    $row['realization'],
                    $row['remaining'],
                    $row['percent'] === null ? '' : $row['percent'],
                    budget_indicator_label($row['indicator']),
                ];
            }

            $header = ['Kode', 'Kategori', 'Item RAB', 'Anggaran RAB', 'Realisasi', 'Sisa', 'Penyerapan %', 'Indikator'];
        } else {
            foreach (BudgetSummary::perProject($center, 100) as $row) {
                $rows[] = [
                    $row['project_code'],
                    $row['project_name'],
                    $row['initial_budget'],
                    $row['approved_budget'],
                    $row['baseline'],
                    $row['realization'],
                    $row['remaining'],
                    $row['percent'] === null ? '' : $row['percent'],
                    budget_indicator_label($row['indicator']),
                    $row['pending_rab'],
                    $row['pending_transactions'],
                ];
            }

            $header = [
                'Kode Proyek', 'Nama Proyek', 'Anggaran Awal', 'RAB Disetujui', 'Dasar Anggaran',
                'Realisasi', 'Sisa', 'Penyerapan %', 'Indikator', 'RAB Menunggu', 'Transaksi Menunggu',
            ];
        }

        ActivityLog::record(
            (int) $actor['id'],
            'budgets.export',
            'budgets',
            'Mengekspor rekap anggaran lingkup ' . $scope . ' (pusat biaya ' . ($center === '' ? 'semua' : $center) . ').',
            $request->ip(),
            $request->userAgent()
        );

        Csv::send('anggaran-' . $scope . '-' . date('Y-m-d'), $header, $rows);
    }

    /** @return array<string, string> */
    private function transactionFilters(Request $request, int $projectId): array
    {
        return [
            'search' => mb_substr(preg_replace('/\s+/', ' ', trim($request->string('q'))) ?? '', 0, 60),
            'status' => $this->pick($request->string('status'), FinancialTransaction::STATUSES),
            'type' => $this->pick($request->string('jenis'), FinancialTransaction::TYPES),
            'cost_center' => $this->pick($request->string('pusat'), FinancialTransaction::COST_CENTERS),
            'project' => (string) $projectId,
            'category' => $request->string('kategori') !== '' && ctype_digit($request->string('kategori'))
                ? $request->string('kategori') : '',
            'from' => date_is_valid($request->string('dari')) ? $request->string('dari') : '',
            'to' => date_is_valid($request->string('sampai')) ? $request->string('sampai') : '',
            'sort' => array_key_exists($request->string('sort'), FinancialTransaction::SORTABLE)
                ? $request->string('sort') : 'date',
            'dir' => in_array(strtolower($request->string('dir')), ['asc', 'desc'], true) ? strtolower($request->string('dir')) : 'desc',
        ];
    }

    /**
     * Pusat biaya dibatasi pada nilai yang dikenal. Nilai kosong berarti seluruh
     * pusat biaya dihitung, dipakai pada laporan yang membandingkan keduanya.
     */
    private function costCenter(Request $request): string
    {
        $center = $request->string('pusat');

        return in_array($center, FinancialTransaction::COST_CENTERS, true) ? $center : FinancialTransaction::CENTER_CONSTRUCTION;
    }

    /**
     * @param array<int, string> $allowed
     */
    private function pick(string $value, array $allowed): string
    {
        return in_array($value, $allowed, true) ? $value : '';
    }

    /** @return array<string, mixed> */
    private function findProject(string $id): array
    {
        if (!ctype_digit($id)) {
            throw new NotFoundHttpException();
        }

        $project = Project::findWithNames((int) $id);
        if ($project === null) {
            throw new NotFoundHttpException();
        }

        return $project;
    }

    /** @return array<string, mixed> */
    private function findRevision(string $id): array
    {
        if (!ctype_digit($id)) {
            throw new NotFoundHttpException();
        }

        $revision = BudgetRevision::findWithNames((int) $id);
        if ($revision === null) {
            throw new NotFoundHttpException();
        }

        return $revision;
    }

    private function fail(string $message, string $projectId): never
    {
        Session::flash('danger', $message);
        $this->redirect($projectId === '' ? '/anggaran' : '/anggaran/' . $projectId);
    }
}
