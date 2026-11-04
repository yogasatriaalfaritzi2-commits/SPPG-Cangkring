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
use App\Models\BudgetItem;
use App\Models\BudgetPlan;
use App\Models\BudgetSummary;
use App\Models\Notification;
use App\Models\Project;

/**
 * RAB: daftar, rincian, alur persetujuan, dan impor/ekspor item.
 * Seluruh nilai uang dihitung ulang di sisi server; formulir hanya mengirim
 * volume dan harga satuan, total selalu berasal dari perkalian di model.
 */
final class RabController extends Controller
{
    private const PER_PAGE = 10;

    public function index(Request $request): void
    {
        $filters = $this->filters($request);
        $result = BudgetPlan::paginate($filters, max(1, $request->integer('page', 1)), self::PER_PAGE);

        $this->view('rab/index', [
            'title' => 'Rencana Anggaran Biaya',
            'pageTitle' => 'RAB Pembangunan',
            'filters' => $filters,
            'result' => $result,
            'plans' => $result['rows'],
            'statuses' => BudgetPlan::STATUSES,
            'categories' => BudgetCategory::activeList(),
            'projects' => Project::optionList(true),
            'years' => BudgetPlan::years(),
            'pendingCount' => BudgetPlan::countByStatus(BudgetPlan::STATUS_PENDING),
        ]);
    }

    public function show(Request $request, string $id): void
    {
        $plan = $this->findPlan($id);
        $planId = (int) $plan['id'];

        $term = mb_substr(preg_replace('/\s+/', ' ', trim($request->string('q'))) ?? '', 0, 60);
        $categoryId = $request->string('kategori');
        $items = BudgetItem::forPlan($planId, $term, ctype_digit($categoryId) ? (int) $categoryId : 0);

        // Item yang sedang disunting diambil dari daftar yang tampil, sehingga
        // formulir tidak pernah membuka item RAB lain lewat parameter URL.
        $sunting = $request->integer('sunting');
        $editing = null;
        foreach ($items as $row) {
            if ((int) $row['id'] === $sunting) {
                $editing = $row;
                break;
            }
        }

        $this->view('rab/show', [
            'title' => (string) $plan['rab_code'],
            'pageTitle' => 'Rincian RAB',
            'plan' => $plan,
            'items' => $items,
            'categoryTotals' => BudgetItem::categoryTotals($planId),
            'itemCount' => BudgetItem::countForPlan($planId),
            'totalIsCurrent' => BudgetPlan::totalIsCurrent($planId),
            'approvals' => BudgetPlan::approvals($planId),
            'revisions' => BudgetPlan::revisionsOf($planId),
            'categories' => BudgetCategory::activeList(),
            'search' => $term,
            'categoryFilter' => $categoryId,
            'summary' => BudgetSummary::projectOverview((int) $plan['project_id']),
            'segregation' => BudgetSummary::segregationEnabled(),
            'editing' => $editing,
            'canEditItems' => in_array((string) $plan['status'], BudgetPlan::EDITABLE_STATUSES, true)
                && (int) $plan['is_project_archived'] !== 1,
            'pageScripts' => ['js/finance.js'],
        ]);
    }

    public function create(Request $request): void
    {
        $projectId = max(0, $request->integer('proyek'));
        $old = Session::pull('_old', []);
        $old = is_array($old) ? $old : [];

        $form = BudgetPlan::emptyRecord($projectId);
        foreach ($old as $key => $value) {
            if (array_key_exists($key, $form)) {
                $form[$key] = (string) $value;
            }
        }

        if ((string) $form['rab_code'] === '') {
            $form['rab_code'] = BudgetPlan::nextCode();
        }

        $this->view('rab/form', [
            'title' => 'Tambah RAB',
            'pageTitle' => 'Tambah Rencana Anggaran Biaya',
            'mode' => 'create',
            'form' => $form,
            'plan' => null,
            'projects' => Project::optionList(),
            'statuses' => BudgetPlan::STATUSES,
        ]);
    }

    public function store(Request $request): void
    {
        $actor = $this->actor();
        $input = $this->readForm($request);
        [$data, $errors] = $this->validate($input, null);

        if ($errors !== []) {
            $this->reject('/rab/tambah', $errors[0], $input);
        }

        /** @var array<string, mixed> $data */
        $data['created_by'] = (int) $actor['id'];
        $data['updated_by'] = (int) $actor['id'];
        [$planId, $code] = BudgetPlan::create($data);

        ActivityLog::record(
            (int) $actor['id'],
            'budgets.create',
            'budgets',
            'Membuat RAB "' . $code . '" — ' . $data['name'] . ' pada proyek ' . $data['project_name'] . '.',
            $request->ip(),
            $request->userAgent()
        );

        Session::clearOld();
        Session::flash('success', 'RAB ' . $code . ' berhasil dibuat. Silakan isi rincian itemnya.');
        $this->redirect('/rab/' . $planId);
    }

    public function edit(Request $request, string $id): void
    {
        $plan = $this->findEditable($id);
        $old = Session::pull('_old', []);
        $old = is_array($old) ? $old : [];

        $form = [];
        foreach (BudgetPlan::emptyRecord((int) $plan['project_id']) as $key => $unused) {
            $form[$key] = (string) ($old[$key] ?? ($plan[$key] ?? ''));
        }

        $this->view('rab/form', [
            'title' => 'Sunting RAB',
            'pageTitle' => 'Sunting Rencana Anggaran Biaya',
            'mode' => 'edit',
            'form' => $form,
            'plan' => $plan,
            'projects' => Project::optionList(true),
            'statuses' => BudgetPlan::STATUSES,
        ]);
    }

    public function update(Request $request, string $id): void
    {
        $actor = $this->actor();
        $plan = $this->findEditable($id);
        $input = $this->readForm($request);

        [$data, $errors] = $this->validate($input, (int) $plan['id']);

        if ($errors !== []) {
            $this->reject('/rab/' . $plan['id'] . '/ubah', $errors[0], $input);
        }

        /** @var array<string, mixed> $data */
        $data['updated_by'] = (int) $actor['id'];
        BudgetPlan::update((int) $plan['id'], $data);

        ActivityLog::record(
            (int) $actor['id'],
            'budgets.update',
            'budgets',
            'Memperbarui RAB "' . $data['rab_code'] . '" — ' . $data['name'] . '.',
            $request->ip(),
            $request->userAgent()
        );

        Session::clearOld();
        Session::flash('success', 'RAB ' . $data['rab_code'] . ' berhasil diperbarui.');
        $this->redirect('/rab/' . $plan['id']);
    }

    public function destroy(Request $request, string $id): void
    {
        $actor = $this->actor();
        $plan = $this->findEditable($id);

        if (BudgetItem::countForPlan((int) $plan['id']) > 0) {
            $this->fail('RAB masih memiliki item. Hapus itemnya lebih dahulu sebelum menghapus RAB.', (string) $plan['id']);
        }

        BudgetPlan::delete((int) $plan['id']);

        ActivityLog::record(
            (int) $actor['id'],
            'budgets.delete',
            'budgets',
            'Menghapus RAB draft "' . $plan['rab_code'] . '" — ' . $plan['name'] . '.',
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', 'RAB ' . $plan['rab_code'] . ' telah dihapus.');
        $this->redirect('/rab');
    }

    /**
     * Ajukan RAB untuk diperiksa. RAB tanpa item tidak dapat diajukan karena
     * persetujuannya tidak akan punya dasar angka.
     */
    public function submit(Request $request, string $id): void
    {
        $actor = $this->actor();
        $plan = $this->findPlan($id);

        if ((string) $plan['status'] !== BudgetPlan::STATUS_DRAFT && (string) $plan['status'] !== BudgetPlan::STATUS_REJECTED) {
            $this->fail('Hanya RAB berstatus draft atau ditolak yang dapat diajukan.', (string) $plan['id']);
        }

        if ((int) $plan['is_project_archived'] === 1) {
            $this->fail('Proyek sudah diarsipkan, pengajuan RAB tidak dapat dilakukan.', (string) $plan['id']);
        }

        if (BudgetItem::countForPlan((int) $plan['id']) === 0) {
            $this->fail('RAB belum memiliki item. Tambah minimal satu item sebelum mengajukan.', (string) $plan['id']);
        }

        $affected = Database::transaction(static function () use ($plan, $actor): int {
            BudgetPlan::lock((int) $plan['id']);
            $total = BudgetPlan::recalculateTotal((int) $plan['id']);

            $changed = BudgetPlan::changeStatus(
                (int) $plan['id'],
                (string) $plan['status'],
                BudgetPlan::STATUS_PENDING,
                'submit',
                null,
                (int) $actor['id']
            );

            return $changed > 0 ? (int) $total : 0;
        });

        if ($affected === 0) {
            $this->fail('Status RAB baru saja diubah pengguna lain. Muat ulang halaman lalu ajukan kembali.', (string) $plan['id']);
        }

        $fresh = BudgetPlan::findWithNames((int) $plan['id']);
        if ($fresh !== null) {
            Notification::budgetSubmitted($fresh, (int) $actor['id']);
        }

        ActivityLog::record(
            (int) $actor['id'],
            'budgets.submit',
            'budgets',
            'Mengajukan RAB "' . $plan['rab_code'] . '" senilai ' . rupiah(BudgetPlan::storedTotal((int) $plan['id'])) . '.',
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', 'RAB ' . $plan['rab_code'] . ' diajukan untuk persetujuan.');
        $this->redirect('/rab/' . $plan['id']);
    }

    /**
     * Setujui atau tolak RAB. Kebijakan pemisahan tugas menolak pemeriksa yang
     * sama dengan pengaju, dan pemeriksaan ini dilakukan di sisi server.
     */
    public function decide(Request $request, string $id): void
    {
        $actor = $this->actor();
        $plan = $this->findPlan($id);

        $action = $request->string('action');
        if (!in_array($action, ['approve', 'reject'], true)) {
            $this->fail('Tindakan persetujuan tidak dikenali.', (string) $plan['id']);
        }

        if ((string) $plan['status'] !== BudgetPlan::STATUS_PENDING) {
            $this->fail('RAB ini tidak sedang menunggu persetujuan.', (string) $plan['id']);
        }

        $note = $request->string('note');
        if ($action === 'reject' && $note === '') {
            $this->fail('Penolakan RAB wajib disertai catatan alasan.', (string) $plan['id']);
        }

        if ($action === 'approve' && BudgetSummary::segregationEnabled() && (int) $plan['submitted_by'] === (int) $actor['id']) {
            $this->fail('Kebijakan pemisahan tugas berlaku: RAB yang Anda ajukan sendiri tidak dapat Anda setujui.', (string) $plan['id']);
        }

        $toStatus = $action === 'approve' ? BudgetPlan::STATUS_APPROVED : BudgetPlan::STATUS_REJECTED;

        $affected = Database::transaction(function () use ($plan, $action, $toStatus, $note, $actor): int {
            BudgetPlan::lock((int) $plan['id']);
            BudgetPlan::recalculateTotal((int) $plan['id']);

            $changed = BudgetPlan::changeStatus(
                (int) $plan['id'],
                BudgetPlan::STATUS_PENDING,
                $toStatus,
                $action,
                $note === '' ? null : $note,
                (int) $actor['id']
            );

            $parentId = (int) ($plan['revision_of'] ?? 0);
            if ($changed > 0 && $action === 'approve' && $parentId > 0) {
                // RAB induk baru dinyatakan tersusun ulang setelah versi penggantinya
                // disetujui. Selama revisi masih draft, dasar anggaran proyek tidak
                // pernah kehilangan angka yang sah.
                BudgetPlan::changeStatus(
                    $parentId,
                    BudgetPlan::STATUS_APPROVED,
                    BudgetPlan::STATUS_REVISED,
                    'revise',
                    'Digantikan oleh RAB ' . $plan['rab_code'] . '.',
                    (int) $actor['id']
                );
            }

            return $changed;
        });

        if ($affected === 0) {
            $this->fail('Status RAB baru saja diubah pengguna lain. Muat ulang halaman untuk melihat kondisi terbaru.', (string) $plan['id']);
        }

        $fresh = BudgetPlan::findWithNames((int) $plan['id']);
        if ($fresh !== null) {
            Notification::budgetDecided($fresh, $toStatus, (int) $actor['id']);
        }

        ActivityLog::record(
            (int) $actor['id'],
            'budgets.' . $action,
            'budgets',
            ($action === 'approve' ? 'Menyetujui' : 'Menolak') . ' RAB "' . $plan['rab_code'] . '" — '
            . $plan['name'] . ($note !== '' ? '. Catatan: ' . mb_substr($note, 0, 140) : '.'),
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', $action === 'approve'
            ? 'RAB ' . $plan['rab_code'] . ' disetujui dan menjadi dasar anggaran proyek.'
            : 'RAB ' . $plan['rab_code'] . ' ditolak. Perbaiki itemnya lalu ajukan kembali.');
        $this->redirect('/rab/' . $plan['id']);
    }

    /**
     * Buat versi baru dari RAB yang sudah disetujui. RAB induk tetap menjadi dasar
     * anggaran sampai versi revisi disetujui.
     */
    public function revise(Request $request, string $id): void
    {
        $actor = $this->actor();
        $plan = $this->findPlan($id);

        if (!in_array((string) $plan['status'], [BudgetPlan::STATUS_APPROVED, BudgetPlan::STATUS_REVISED], true)) {
            $this->fail('Revisi hanya dapat dibuat dari RAB yang sudah disetujui.', (string) $plan['id']);
        }

        $name = $request->string('name');
        if ($name === '') {
            $name = (string) $plan['name'] . ' (revisi)';
        }

        if (mb_strlen($name) > 150) {
            $this->fail('Nama RAB hasil revisi maksimal 150 karakter.', (string) $plan['id']);
        }

        $note = $request->string('note');
        [$newId, $code] = Database::transaction(
            static fn (): array => BudgetPlan::copyAsRevision((int) $plan['id'], $actor, $name, $note === '' ? null : $note)
        );

        ActivityLog::record(
            (int) $actor['id'],
            'budgets.revise',
            'budgets',
            'Membuat revisi RAB "' . $code . '" dari "' . $plan['rab_code'] . '".',
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', 'Revisi ' . $code . ' dibuat sebagai draft. RAB ' . $plan['rab_code']
            . ' tetap menjadi dasar anggaran sampai revisi ini disetujui.');
        $this->redirect('/rab/' . $newId);
    }

    public function export(Request $request, string $id): void
    {
        $plan = $this->findPlan($id);
        $items = BudgetItem::forPlan((int) $plan['id']);

        ActivityLog::record(
            (int) $this->actor()['id'],
            'budgets.export',
            'budgets',
            'Mengekspor rincian RAB "' . $plan['rab_code'] . '" (' . count($items) . ' item).',
            $request->ip(),
            $request->userAgent()
        );

        $rows = [];
        foreach ($items as $item) {
            $rows[] = [
                $item['category_code'],
                $item['category_name'],
                $item['item_code'],
                $item['item_name'],
                $item['volume'],
                $item['unit'],
                $item['unit_price'],
                $item['total_price'],
                $item['notes'] ?? '',
            ];
        }

        Csv::send(
            'rab-' . strtolower((string) $plan['rab_code']),
            ['Kode Kategori', 'Kategori', 'Kode Item', 'Uraian Pekerjaan', 'Volume', 'Satuan', 'Harga Satuan', 'Total', 'Keterangan'],
            $rows
        );
    }

    /**
     * Impor item dari CSV. Kolom yang diharapkan sama dengan hasil ekspor:
     * kategori, kode item, uraian, volume, satuan, harga satuan, keterangan.
     * Harga total tidak dibaca dari berkas; selalu dihitung ulang di server.
     */
    public function import(Request $request, string $id): void
    {
        $actor = $this->actor();
        $plan = $this->findEditable($id);
        $path = '/rab/' . $plan['id'];

        $file = $request->file('berkas');
        if ($file === null) {
            $this->fail('Berkas CSV wajib dipilih.', (string) $plan['id']);
        }

        if (!in_array(strtolower((string) pathinfo($file['name'], PATHINFO_EXTENSION)), ['csv', 'txt'], true)) {
            $this->fail('Format berkas harus CSV atau TXT.', (string) $plan['id']);
        }

        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            $this->fail('Berkas impor tidak terunggah dengan benar.', (string) $plan['id']);
        }

        if ($file['size'] > 2 * 1048576) {
            $this->fail('Ukuran berkas impor maksimal 2 MB.', (string) $plan['id']);
        }

        $lines = Csv::read($file['tmp_name'], 500);
        if ($lines === []) {
            $this->fail('Berkas impor kosong atau tidak dapat dibaca.', (string) $plan['id']);
        }

        // Baris pertama dianggap judul kolom bila tidak memuat angka sama sekali.
        if (!preg_match('/\d/', implode('|', $lines[0]))) {
            array_shift($lines);
        }

        if ($lines === []) {
            $this->fail('Berkas impor tidak memiliki baris data.', (string) $plan['id']);
        }

        $categories = $this->categoryIndex();
        $errors = [];
        $parsed = [];

        // Kolom mengikuti hasil ekspor: kode kategori, kategori, kode item, uraian,
        // volume, satuan, harga satuan, total, keterangan. Kolom total diabaikan
        // karena server yang menghitung ulang.
        foreach ($lines as $index => $line) {
            $number = $index + 2;

            if (count($line) < 7) {
                $errors[] = 'Baris ' . $number . ': jumlah kolom kurang. Gunakan format berkas hasil ekspor.';
                continue;
            }

            $cells = array_pad($line, 9, '');
            $categoryId = $this->matchCategory($cells[0], $categories);
            if ($categoryId === 0) {
                $categoryId = $this->matchCategory($cells[1], $categories);
            }

            if ($categoryId === 0) {
                $errors[] = 'Baris ' . $number . ': kategori tidak dikenali atau tidak aktif.';
                continue;
            }

            $name = $cells[3];
            $volume = volume_normalize($cells[4]);
            $unit = $cells[5];
            $price = amount_normalize($cells[6]);

            if (mb_strlen($name) < 3 || mb_strlen($name) > 180) {
                $errors[] = 'Baris ' . $number . ': uraian pekerjaan harus 3-180 karakter.';
                continue;
            }

            if ($volume === null || (float) $volume <= 0) {
                $errors[] = 'Baris ' . $number . ': volume harus angka lebih dari nol.';
                continue;
            }

            if ($unit === '' || mb_strlen($unit) > 30) {
                $errors[] = 'Baris ' . $number . ': satuan wajib diisi maksimal 30 karakter.';
                continue;
            }

            if ($price === null || (float) $price < 0) {
                $errors[] = 'Baris ' . $number . ': harga satuan harus angka nol atau lebih.';
                continue;
            }

            $parsed[] = [
                'category_id' => $categoryId,
                'item_code' => $cells[2],
                'item_name' => $name,
                'volume' => $volume,
                'unit' => $unit,
                'unit_price' => $price,
                'notes' => $cells[8] === '' ? null : mb_substr($cells[8], 0, 255),
            ];
        }

        if ($errors !== []) {
            $this->fail('Impor dibatalkan: ' . $errors[0], (string) $plan['id']);
        }

        if ($parsed === []) {
            $this->fail('Tidak ada baris yang dapat diimpor.', (string) $plan['id']);
        }

        $inserted = Database::transaction(function () use ($plan, $parsed, $actor): int {
            BudgetPlan::lock((int) $plan['id']);
            $count = 0;

            foreach ($parsed as $row) {
                $categoryId = (int) $row['category_id'];
                $code = (string) $row['item_code'];
                $planId = (int) $plan['id'];

                // Kode item dari berkas dipakai bila bentuknya sah dan belum terpakai;
                // selain itu nomor berikutnya diambil otomatis.
                if ($code === '' || !preg_match('/^[A-Z0-9][A-Z0-9._-]{1,29}$/', $code)
                    || BudgetItem::codeExists($planId, $code)) {
                    $code = BudgetItem::nextItemCode($planId, $this->categoryCode($categoryId));
                }

                BudgetItem::create([
                    'budget_plan_id' => $planId,
                    'category_id' => $categoryId,
                    'item_code' => $code,
                    'item_name' => $row['item_name'],
                    'volume' => $row['volume'],
                    'unit' => $row['unit'],
                    'unit_price' => $row['unit_price'],
                    'notes' => $row['notes'],
                    'sort_order' => 0,
                    'created_by' => (int) $actor['id'],
                    'updated_by' => (int) $actor['id'],
                ]);
                $count++;
            }

            BudgetPlan::recalculateTotal((int) $plan['id']);

            return $count;
        });

        ActivityLog::record(
            (int) $actor['id'],
            'budgets.import',
            'budgets',
            'Mengimpor ' . $inserted . ' item ke RAB "' . $plan['rab_code'] . '".',
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', $inserted . ' item berhasil diimpor. Total RAB dihitung ulang oleh server.');
        $this->redirect($path);
    }

    /**
     * Kode dan nama kategori aktif dipetakan ke nomornya, sehingga berkas impor
     * dapat memakai salah satunya.
     *
     * @return array<string, string>
     */
    private function categoryIndex(): array
    {
        $index = [];

        foreach (BudgetCategory::activeList() as $category) {
            $id = (string) $category['id'];
            $index[strtoupper(trim((string) $category['category_code']))] = $id;
            $index[strtoupper(trim((string) $category['category_name']))] = $id;
        }

        return $index;
    }

    /** @param array<string, string> $index */
    private function matchCategory(string $value, array $index): int
    {
        $key = strtoupper(trim($value));

        return $key === '' ? 0 : (int) ($index[$key] ?? 0);
    }

    private function categoryCode(int $categoryId): string
    {
        $category = BudgetCategory::find($categoryId);

        return (string) ($category['category_code'] ?? 'UMUM');
    }

    /** @return array<string, string> */
    private function readForm(Request $request): array
    {
        return [
            'rab_code' => (string) $request->input('rab_code', ''),
            'project_id' => (string) $request->input('project_id', ''),
            'name' => (string) $request->input('name', ''),
            'period_start' => (string) $request->input('period_start', ''),
            'period_end' => (string) $request->input('period_end', ''),
            'notes' => (string) $request->input('notes', ''),
        ];
    }

    /**
     * @param array<string, string> $input
     * @return array{0: array<string, mixed>|null, 1: array<int, string>}
     */
    private function validate(array $input, ?int $exceptId): array
    {
        $errors = [];

        $code = mb_strtoupper(preg_replace('/\s+/', '', trim($input['rab_code'])) ?? '', 'UTF-8');
        $projectId = trim($input['project_id']);
        $name = trim($input['name']);
        $periodStart = trim($input['period_start']);
        $periodEnd = trim($input['period_end']);
        $notes = trim($input['notes']);
        $projectName = '';

        if ($code !== '' && !preg_match('/^RAB-\d{4}-\d{1,4}$/', $code)) {
            $errors[] = 'Nomor RAB harus bermotif RAB-TAHUN-NOMOR, contoh RAB-2026-001.';
        } elseif ($code !== '' && BudgetPlan::codeExists($code, $exceptId)) {
            $errors[] = 'Nomor RAB sudah dipakai oleh RAB lain.';
        }

        $project = null;
        if ($projectId === '' || !ctype_digit($projectId) || (int) $projectId <= 0) {
            $errors[] = 'Proyek wajib dipilih.';
        } else {
            $project = Project::findWithNames((int) $projectId);
            if ($project === null) {
                $errors[] = 'Proyek yang dipilih tidak ditemukan.';
            } elseif ((int) $project['is_archived'] === 1) {
                $errors[] = 'Proyek yang sudah diarsipkan tidak dapat dibuatkan RAB baru.';
            } else {
                $projectName = (string) $project['name'];
            }
        }

        if (mb_strlen($name) < 3 || mb_strlen($name) > 150) {
            $errors[] = 'Nama RAB harus terdiri dari 3 sampai 150 karakter.';
        }

        if (!date_is_valid($periodStart)) {
            $errors[] = 'Tanggal mulai periode wajib diisi dengan format yang benar (contoh 2026-01-01).';
        }

        if (!date_is_valid($periodEnd)) {
            $errors[] = 'Tanggal akhir periode wajib diisi dengan format yang benar (contoh 2026-12-31).';
        }

        if (date_is_valid($periodStart) && date_is_valid($periodEnd) && $periodEnd < $periodStart) {
            $errors[] = 'Tanggal akhir periode tidak boleh lebih awal dari tanggal mulai.';
        }

        if (mb_strlen($notes) > 1000) {
            $errors[] = 'Catatan maksimal 1000 karakter.';
        }

        if ($errors !== []) {
            return [null, $errors];
        }

        return [[
            'rab_code' => $code,
            'project_id' => (int) $projectId,
            'project_name' => $projectName,
            'name' => $name,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'notes' => $notes === '' ? null : $notes,
        ], []];
    }

    /** @return array<string, string> */
    private function filters(Request $request): array
    {
        $search = preg_replace('/\s+/', ' ', trim($request->string('q'))) ?? '';
        $status = $request->string('status');
        $project = $request->string('proyek');
        $category = $request->string('kategori');
        $year = $request->string('tahun');
        $sort = $request->string('sort');
        $dir = strtolower($request->string('dir'));

        return [
            'search' => mb_substr($search, 0, 60),
            'status' => in_array($status, BudgetPlan::STATUSES, true) ? $status : '',
            'project' => ctype_digit($project) ? $project : '',
            'category' => ctype_digit($category) ? $category : '',
            'year' => ctype_digit($year) && strlen($year) === 4 ? $year : '',
            'sort' => array_key_exists($sort, BudgetPlan::SORTABLE) ? $sort : 'created',
            'dir' => in_array($dir, ['asc', 'desc'], true) ? $dir : 'desc',
        ];
    }

    /** @return array<string, mixed> */
    private function findPlan(string $id): array
    {
        if (!ctype_digit($id)) {
            throw new NotFoundHttpException();
        }

        $plan = BudgetPlan::findWithNames((int) $id);
        if ($plan === null) {
            throw new NotFoundHttpException();
        }

        return $plan;
    }

    /**
     * RAB yang boleh disunting. Pemeriksaan status dilakukan di server, bukan hanya
     * lewat tombol yang disembunyikan.
     *
     * @return array<string, mixed>
     */
    private function findEditable(string $id): array
    {
        $plan = $this->findPlan($id);

        if (!in_array((string) $plan['status'], BudgetPlan::EDITABLE_STATUSES, true)) {
            $this->fail(
                'RAB berstatus ' . budget_status_label((string) $plan['status'])
                . ' tidak dapat diubah. Buat revisi lalu ajukan untuk persetujuan ulang.',
                (string) $plan['id']
            );
        }

        return $plan;
    }

    /**
     * @param array<string, string> $input
     */
    private function reject(string $path, string $message, array $input): never
    {
        Session::flash('danger', $message);
        Session::putOld($input);
        $this->redirect($path);
    }

    private function fail(string $message, string $id): never
    {
        Session::flash('danger', $message);
        $this->redirect($id === '' ? '/rab' : '/rab/' . $id);
    }
}
