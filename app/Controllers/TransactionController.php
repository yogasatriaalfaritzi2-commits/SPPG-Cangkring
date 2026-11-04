<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Controller;
use App\Core\Csv;
use App\Core\Database;
use App\Core\Exceptions\NotFoundHttpException;
use App\Core\FileUpload;
use App\Core\Request;
use App\Core\Session;
use App\Models\ActivityLog;
use App\Models\BudgetCategory;
use App\Models\BudgetItem;
use App\Models\BudgetPlan;
use App\Models\BudgetSummary;
use App\Models\FinancialTransaction;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectDocument;

/**
 * Pencatatan transaksi keuangan: pengeluaran, pemasukan, pengembalian, dan
 * penyesuaian sebagai koreksi atas transaksi yang sudah disetujui.
 *
 * Nilai nominal selalu dinormalkan di server, transaksi ganda ditolak, dan
 * transaksi yang sudah disetujui tidak dapat dihapus melainkan dibatalkan
 * beserta alasannya.
 */
final class TransactionController extends Controller
{
    private const PER_PAGE = 15;

    public function index(Request $request): void
    {
        $filters = $this->filters($request);
        $result = FinancialTransaction::paginate($filters, max(1, $request->integer('page', 1)), self::PER_PAGE);

        $this->view('transaksi/index', [
            'title' => 'Transaksi Keuangan',
            'pageTitle' => 'Transaksi Pembangunan',
            'filters' => $filters,
            'result' => $result,
            'transactions' => $result['rows'],
            'statuses' => FinancialTransaction::STATUSES,
            'types' => FinancialTransaction::TYPES,
            'methods' => FinancialTransaction::METHODS,
            'categories' => BudgetCategory::activeList(),
            'projects' => Project::optionList(true),
            'totals' => BudgetSummary::transactionStatusCounts($filters['cost_center']),
            'overview' => BudgetSummary::globalOverview($filters['cost_center']),
        ]);
    }

    public function show(Request $request, string $id): void
    {
        $transaction = $this->findTransaction($id);
        $transactionId = (int) $transaction['id'];

        $this->view('transaksi/show', [
            'title' => (string) $transaction['transaction_code'],
            'pageTitle' => 'Detail Transaksi',
            'transaction' => $transaction,
            'approvals' => FinancialTransaction::approvals($transactionId),
            'attachments' => FinancialTransaction::attachments($transactionId),
            'reversals' => FinancialTransaction::correctionsOf($transactionId),
            'summary' => BudgetSummary::projectOverview(
                (int) $transaction['project_id'],
                (string) $transaction['cost_center']
            ),
            'segregation' => BudgetSummary::segregationEnabled(),
            'maxUploadMb' => (int) App::config('app.uploads.max_mb', 10),
        ]);
    }

    public function create(Request $request): void
    {
        $projectId = max(0, $request->integer('proyek'));
        $old = $this->pullOld();

        $form = FinancialTransaction::emptyRecord($projectId);
        foreach ($old as $key => $value) {
            if (array_key_exists($key, $form)) {
                $form[$key] = (string) $value;
            }
        }

        if ((string) $form['transaction_code'] === '') {
            $form['transaction_code'] = FinancialTransaction::nextCode();
        }

        $this->view('transaksi/form', [
            'title' => 'Catat Transaksi',
            'pageTitle' => 'Catat Transaksi Keuangan',
            'mode' => 'create',
            'form' => $form,
            'transaction' => null,
            'projects' => Project::optionList(),
            'categories' => BudgetCategory::activeList(),
            'plans' => $projectId > 0 ? BudgetPlan::forProject($projectId) : [],
            'types' => FinancialTransaction::TYPES,
            'methods' => FinancialTransaction::METHODS,
            'centers' => FinancialTransaction::COST_CENTERS,
            'correctable' => $projectId > 0 ? FinancialTransaction::correctable($projectId) : [],
            'maxUploadMb' => (int) App::config('app.uploads.max_mb', 10),
            'pageScripts' => ['js/finance.js'],
        ]);
    }

    public function store(Request $request): void
    {
        $actor = $this->actor();
        $input = $this->readForm($request);
        [$data, $errors] = $this->validate($input, null);

        $upload = null;
        if ($errors === []) {
            $upload = $this->storeReceipt($request, $errors);
        }

        if ($errors !== []) {
            $this->reject('/transaksi/tambah', $errors[0], $input);
        }

        /** @var array<string, mixed> $data */
        $data['recorded_by'] = (int) $actor['id'];

        [$transactionId, $code] = Database::transaction(function () use ($data, $actor, $upload): array {
            [$id, $createdCode] = FinancialTransaction::create($data);

            if ($upload !== null) {
                $this->saveReceipt($upload, (int) $data['project_id'], $id, (int) $actor['id'], (string) $createdCode);
            }

            return [$id, $createdCode];
        });

        ActivityLog::record(
            (int) $actor['id'],
            'transactions.create',
            'transactions',
            'Mencatat transaksi ' . transaction_type_label((string) $data['transaction_type']) . ' "' . $code
            . '" senilai ' . rupiah((float) $data['amount']) . ' pada proyek ' . $data['project_name'] . '.',
            $request->ip(),
            $request->userAgent()
        );

        Session::clearOld();
        Session::flash('success', 'Transaksi ' . $code . ' tersimpan sebagai draft.');
        $this->redirect('/transaksi/' . $transactionId);
    }

    public function edit(Request $request, string $id): void
    {
        $transaction = $this->findEditable($id);
        $projectId = (int) $transaction['project_id'];
        $old = $this->pullOld();

        $form = [];
        foreach (FinancialTransaction::emptyRecord($projectId) as $key => $unused) {
            $form[$key] = (string) ($old[$key] ?? ($transaction[$key] ?? ''));
        }

        $this->view('transaksi/form', [
            'title' => 'Sunting Transaksi',
            'pageTitle' => 'Sunting Transaksi Keuangan',
            'mode' => 'edit',
            'form' => $form,
            'transaction' => $transaction,
            'projects' => Project::optionList(true),
            'categories' => BudgetCategory::activeList(),
            'plans' => BudgetPlan::forProject($projectId),
            'types' => FinancialTransaction::TYPES,
            'methods' => FinancialTransaction::METHODS,
            'centers' => FinancialTransaction::COST_CENTERS,
            'correctable' => FinancialTransaction::correctable($projectId),
            'maxUploadMb' => (int) App::config('app.uploads.max_mb', 10),
            'pageScripts' => ['js/finance.js'],
        ]);
    }

    public function update(Request $request, string $id): void
    {
        $actor = $this->actor();
        $transaction = $this->findEditable($id);
        $input = $this->readForm($request);

        [$data, $errors] = $this->validate($input, (int) $transaction['id']);

        $upload = null;
        if ($errors === []) {
            $upload = $this->storeReceipt($request, $errors);
        }

        if ($errors !== []) {
            $this->reject('/transaksi/' . $transaction['id'] . '/ubah', $errors[0], $input);
        }

        // Pembaruan membatasi diri pada status draft/ditolak melalui kondisi WHERE,
        // sehingga perubahan bersamaan tidak menimpa transaksi yang sudah disetujui.
        $affected = Database::transaction(function () use ($transaction, $data, $actor, $upload): int {
            $changed = FinancialTransaction::update((int) $transaction['id'], $data);

            if ($changed > 0 && $upload !== null) {
                $this->saveReceipt($upload, (int) $data['project_id'], (int) $transaction['id'], (int) $actor['id'], (string) $transaction['transaction_code']);
            }

            return $changed;
        });

        if ($affected === 0) {
            $this->fail('Transaksi ini sudah disetujui atau statusnya baru saja berubah, sehingga tidak dapat disunting.', (string) $transaction['id']);
        }

        ActivityLog::record(
            (int) $actor['id'],
            'transactions.update',
            'transactions',
            'Memperbarui transaksi "' . $transaction['transaction_code'] . '" menjadi '
            . rupiah((float) $data['amount']) . ' (' . transaction_type_label((string) $data['transaction_type']) . ').',
            $request->ip(),
            $request->userAgent()
        );

        Session::clearOld();
        Session::flash('success', 'Transaksi ' . $transaction['transaction_code'] . ' diperbarui.');
        $this->redirect('/transaksi/' . $transaction['id']);
    }

    /**
     * Hanya draft dan transaksi yang ditolak yang boleh dihapus. Transaksi yang
     * sudah disetujui dilindungi oleh kondisi WHERE pada model.
     */
    public function destroy(Request $request, string $id): void
    {
        $actor = $this->actor();
        $transaction = $this->findTransaction($id);

        if (!in_array((string) $transaction['status'], FinancialTransaction::EDITABLE_STATUSES, true)) {
            $this->fail(
                'Transaksi berstatus ' . transaction_status_label((string) $transaction['status'])
                . ' tidak dapat dihapus. Gunakan pembatalan agar jejak audit tetap tersimpan.',
                (string) $transaction['id']
            );
        }

        Database::transaction(function () use ($transaction): void {
            foreach (FinancialTransaction::attachments((int) $transaction['id']) as $attachment) {
                FileUpload::remove((string) $attachment['stored_name']);
            }

            FinancialTransaction::delete((int) $transaction['id']);
        });

        ActivityLog::record(
            (int) $actor['id'],
            'transactions.delete',
            'transactions',
            'Menghapus transaksi draft "' . $transaction['transaction_code'] . '" senilai '
            . rupiah((float) $transaction['amount']) . '.',
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', 'Transaksi ' . $transaction['transaction_code'] . ' dihapus.');
        $this->redirect('/transaksi');
    }

    public function submit(Request $request, string $id): void
    {
        $actor = $this->actor();
        $transaction = $this->findTransaction($id);

        if (!in_array((string) $transaction['status'], FinancialTransaction::EDITABLE_STATUSES, true)) {
            $this->fail('Hanya transaksi draft atau ditolak yang dapat diajukan.', (string) $transaction['id']);
        }

        if ((int) $transaction['is_project_archived'] === 1) {
            $this->fail('Proyek sudah diarsipkan, transaksi tidak dapat diajukan.', (string) $transaction['id']);
        }

        $affected = FinancialTransaction::changeStatus(
            (int) $transaction['id'],
            (string) $transaction['status'],
            FinancialTransaction::STATUS_PENDING,
            'submit',
            null,
            (int) $actor['id']
        );

        if ($affected === 0) {
            $this->fail('Status transaksi baru saja diubah pengguna lain. Muat ulang halaman lalu ajukan kembali.', (string) $transaction['id']);
        }

        $fresh = FinancialTransaction::findWithNames((int) $transaction['id']);
        if ($fresh !== null) {
            Notification::transactionSubmitted($fresh, (int) $actor['id']);
        }

        ActivityLog::record(
            (int) $actor['id'],
            'transactions.submit',
            'transactions',
            'Mengajukan transaksi "' . $transaction['transaction_code'] . '" untuk persetujuan.',
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', 'Transaksi ' . $transaction['transaction_code'] . ' diajukan untuk persetujuan.');
        $this->redirect('/transaksi/' . $transaction['id']);
    }

    public function decide(Request $request, string $id): void
    {
        $actor = $this->actor();
        $transaction = $this->findTransaction($id);

        $action = $request->string('action');
        if (!in_array($action, ['approve', 'reject'], true)) {
            $this->fail('Tindakan persetujuan tidak dikenali.', (string) $transaction['id']);
        }

        if ((string) $transaction['status'] !== FinancialTransaction::STATUS_PENDING) {
            $this->fail('Transaksi ini tidak sedang menunggu persetujuan.', (string) $transaction['id']);
        }

        $note = trim($request->string('note'));
        if ($action === 'reject' && $note === '') {
            $this->fail('Penolakan transaksi wajib disertai catatan alasan.', (string) $transaction['id']);
        }

        if ($action === 'approve' && BudgetSummary::segregationEnabled()
            && (int) $transaction['submitted_by'] === (int) $actor['id']) {
            $this->fail('Kebijakan pemisahan tugas berlaku: transaksi yang Anda catat sendiri tidak dapat Anda setujui.', (string) $transaction['id']);
        }

        $toStatus = $action === 'approve' ? FinancialTransaction::STATUS_APPROVED : FinancialTransaction::STATUS_REJECTED;

        $affected = Database::transaction(function () use ($transaction, $action, $toStatus, $note, $actor): int {
            // Penguncian baris proyek membuat pemeriksaan anggaran dan perubahan
            // status berjalan berurutan bila dua persetujuan terjadi bersamaan.
            Project::lockForUpdate((int) $transaction['project_id']);

            return FinancialTransaction::changeStatus(
                (int) $transaction['id'],
                FinancialTransaction::STATUS_PENDING,
                $toStatus,
                $action,
                $note === '' ? null : $note,
                (int) $actor['id']
            );
        });

        if ($affected === 0) {
            $this->fail('Status transaksi baru saja diubah pengguna lain. Muat ulang halaman untuk melihat kondisi terbaru.', (string) $transaction['id']);
        }

        $fresh = FinancialTransaction::findWithNames((int) $transaction['id']);
        if ($fresh !== null) {
            Notification::transactionDecided($fresh, $toStatus, (int) $actor['id']);
        }

        ActivityLog::record(
            (int) $actor['id'],
            'transactions.' . $action,
            'transactions',
            ($action === 'approve' ? 'Menyetujui' : 'Menolak') . ' transaksi "'
            . $transaction['transaction_code'] . '" senilai ' . rupiah((float) $transaction['amount'])
            . '.' . ($note !== '' ? ' Catatan: ' . mb_substr($note, 0, 140) : '.'),
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', $action === 'approve'
            ? 'Transaksi disetujui dan realisasi proyek dihitung ulang.'
            : 'Transaksi ditolak. Perbaiki datanya lalu ajukan kembali.');

        // Setelah persetujuan, indikator anggaran proyek dinilai ulang agar peringatan
        // mendekati batas atau terlampaui ikut tercatat.
        if ($action === 'approve' && $fresh !== null) {
            Notification::budgetThreshold(BudgetSummary::projectOverview(
                (int) $fresh['project_id'],
                (string) $fresh['cost_center']
            ));
        }

        $this->redirect('/transaksi/' . $transaction['id']);
    }

    /**
     * Pembatalan transaksi yang sudah disetujui. Barisnya tetap ada, lengkap dengan
     * alasan dan pelakunya, sehingga jejak audit tidak pernah hilang.
     */
    public function cancel(Request $request, string $id): void
    {
        $actor = $this->actor();
        $transaction = $this->findTransaction($id);

        if ((string) $transaction['status'] !== FinancialTransaction::STATUS_APPROVED) {
            $this->fail('Hanya transaksi yang sudah disetujui yang dapat dibatalkan.', (string) $transaction['id']);
        }

        $reason = trim($request->string('reason'));
        if (mb_strlen($reason) < 10) {
            $this->fail('Alasan pembatalan wajib diisi minimal 10 karakter.', (string) $transaction['id']);
        }

        $affected = Database::transaction(function () use ($transaction, $reason, $actor): int {
            Project::lockForUpdate((int) $transaction['project_id']);

            return FinancialTransaction::changeStatus(
                (int) $transaction['id'],
                FinancialTransaction::STATUS_APPROVED,
                FinancialTransaction::STATUS_CANCELLED,
                'cancel',
                $reason,
                (int) $actor['id']
            );
        });

        if ($affected === 0) {
            $this->fail('Transaksi ini baru saja diubah pengguna lain. Muat ulang halaman untuk melanjutkan.', (string) $transaction['id']);
        }

        $fresh = FinancialTransaction::findWithNames((int) $transaction['id']);
        if ($fresh !== null) {
            Notification::transactionCancelled($fresh, (int) $actor['id']);
        }

        ActivityLog::record(
            (int) $actor['id'],
            'transactions.cancel',
            'transactions',
            'Membatalkan transaksi "' . $transaction['transaction_code'] . '" senilai '
            . rupiah((float) $transaction['amount']) . '. Alasan: ' . mb_substr($reason, 0, 180),
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', 'Transaksi dibatalkan dan realisasi proyek dihitung ulang.');
        $this->redirect('/transaksi/' . $transaction['id']);
    }

    public function storeAttachment(Request $request, string $id): void
    {
        $actor = $this->actor();
        $transaction = $this->findTransaction($id);
        $path = '/transaksi/' . $transaction['id'] . '#bukti';

        $errors = [];
        $upload = null;
        $file = $request->file('berkas');

        if ($file === null) {
            $errors[] = 'Berkas bukti wajib dipilih.';
        } else {
            $attempt = FileUpload::store($file);
            if ($attempt['ok']) {
                $upload = $attempt;
            } else {
                $errors[] = $attempt['error'];
            }
        }

        if ($errors !== []) {
            Session::flash('danger', $errors[0]);
            $this->redirect($path);
        }

        /** @var array{stored: string, mime: string, size: int, original: string} $upload */
        $this->saveReceipt($upload, (int) $transaction['project_id'], (int) $transaction['id'], (int) $actor['id'], (string) $transaction['transaction_code']);

        ActivityLog::record(
            (int) $actor['id'],
            'transactions.attachment',
            'transactions',
            'Mengunggah bukti "' . $upload['original'] . '" untuk transaksi ' . $transaction['transaction_code'] . '.',
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', 'Bukti transaksi berhasil diunggah.');
        $this->redirect($path);
    }

    public function destroyAttachment(Request $request, string $documentId): void
    {
        $actor = $this->actor();
        $document = $this->findReceipt($documentId);

        ProjectDocument::delete((int) $document['id']);
        FileUpload::remove((string) $document['stored_name']);

        ActivityLog::record(
            (int) $actor['id'],
            'transactions.attachment.delete',
            'transactions',
            'Menghapus bukti "' . $document['title'] . '" dari transaksi ' . $document['transaction_code'] . '.',
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', 'Bukti transaksi dihapus.');
        $this->redirect('/transaksi/' . $document['financial_transaction_id'] . '#bukti');
    }

    /** Bukti tersimpan di luar folder public dan hanya dapat dibuka pengguna berizin. */
    public function attachment(Request $request, string $documentId): void
    {
        $document = $this->findReceipt($documentId);
        $inline = $request->string('mode') !== 'unduh';

        FileUpload::respond(
            (string) $document['stored_name'],
            (string) $document['mime_type'],
            (string) $document['original_name'],
            $inline
        );
    }

    /**
     * Cetak bukti transaksi. Halaman berdiri sendiri tanpa kerangka aplikasi agar
     * hasil cetakan hanya berisi bukti tersebut.
     */
    public function print(Request $request, string $id): void
    {
        $transaction = $this->findTransaction($id);

        ActivityLog::record(
            (int) $this->actor()['id'],
            'transactions.print',
            'transactions',
            'Mencetak bukti transaksi ' . $transaction['transaction_code'] . '.',
            $request->ip(),
            $request->userAgent()
        );

        $this->view('transaksi/receipt', [
            'title' => 'Bukti ' . (string) $transaction['transaction_code'],
            'pageTitle' => 'Bukti Transaksi',
            'transaction' => $transaction,
            'attachments' => FinancialTransaction::attachments((int) $transaction['id']),
            'institution' => institution_name(),
        ], '');
    }

    public function export(Request $request): void
    {
        $actor = $this->actor();
        $filters = $this->filters($request);
        $rows = FinancialTransaction::forExport($filters, 2000);

        ActivityLog::record(
            (int) $actor['id'],
            'transactions.export',
            'transactions',
            'Mengekspor ' . count($rows) . ' transaksi keuangan.',
            $request->ip(),
            $request->userAgent()
        );

        $data = [];
        foreach ($rows as $row) {
            $data[] = [
                $row['transaction_code'],
                $row['transaction_date'],
                $row['project_code'],
                $row['project_name'],
                transaction_type_label($row['transaction_type']),
                cost_center_label($row['cost_center']),
                $row['category_name'] ?? '',
                $row['description'],
                $row['amount'],
                payment_method_label($row['payment_method']),
                $row['recipient'],
                $row['reference_number'] ?? '',
                transaction_status_label($row['status']),
                $row['recorder_name'] ?? '',
                $row['approver_name'] ?? '',
            ];
        }

        Csv::send(
            'transaksi-' . date('Y-m-d'),
            [
                'Nomor', 'Tanggal', 'Kode Proyek', 'Proyek', 'Jenis', 'Pusat Biaya', 'Kategori', 'Uraian',
                'Nominal', 'Metode', 'Penerima/Sumber', 'Referensi', 'Status', 'Pencatat', 'Penyetuju',
            ],
            $data
        );
    }

    /**
     * @param array<string, string> $input
     * @return array{0: array<string, mixed>|null, 1: array<int, string>}
     */
    private function validate(array $input, ?int $exceptId): array
    {
        $errors = [];

        $code = mb_strtoupper(preg_replace('/\s+/', '', trim($input['transaction_code'])) ?? '', 'UTF-8');
        $projectId = trim($input['project_id']);
        $type = trim($input['transaction_type']);
        $center = trim($input['cost_center']);
        $date = trim($input['transaction_date']);
        $description = preg_replace('/\s+/', ' ', trim($input['description'])) ?? '';
        $amount = amount_normalize(trim($input['amount']));
        $method = trim($input['payment_method']);
        $recipient = trim($input['recipient']);
        $reference = trim($input['reference_number']);
        $categoryId = trim($input['category_id']);
        $planId = trim($input['budget_plan_id']);
        $itemId = trim($input['budget_item_id']);
        $reversalId = trim($input['reversal_of']);

        $project = null;
        if ($projectId === '' || !ctype_digit($projectId) || (int) $projectId <= 0) {
            $errors[] = 'Proyek wajib dipilih.';
        } else {
            $project = Project::findWithNames((int) $projectId);
            if ($project === null) {
                $errors[] = 'Proyek yang dipilih tidak ditemukan.';
            } else {
                $projectName = (string) $project['name'];
            }
        }

        if ($code !== '' && !preg_match('/^TRX-\d{4}-\d{1,4}$/', $code)) {
            $errors[] = 'Nomor transaksi harus bermotif TRX-TAHUN-NOMOR, contoh TRX-2026-014.';
        } elseif ($code !== '' && FinancialTransaction::codeExists($code)) {
            $errors[] = 'Nomor transaksi sudah dipakai. Kosongkan kolomnya agar nomor dibuat otomatis.';
        }

        if (!in_array($type, FinancialTransaction::TYPES, true)) {
            $errors[] = 'Jenis transaksi tidak dikenali.';
        }

        if (!in_array($center, FinancialTransaction::COST_CENTERS, true)) {
            $errors[] = 'Pusat biaya harus pembangunan atau operasional MBG.';
        }

        if (!date_is_valid($date)) {
            $errors[] = 'Tanggal transaksi wajib diisi dengan format yang benar (contoh 2026-11-01).';
        }

        if (mb_strlen($description) < 3 || mb_strlen($description) > 255) {
            $errors[] = 'Uraian transaksi harus terdiri dari 3 sampai 255 karakter.';
        }

        if ($amount === null) {
            $errors[] = 'Nominal harus angka, contoh 1500000 atau 1.500.000,50.';
        } elseif ((float) $amount === 0.0) {
            $errors[] = 'Nominal transaksi tidak boleh nol.';
        } elseif ((float) $amount < 0 && $type !== FinancialTransaction::TYPE_ADJUSTMENT) {
            $errors[] = 'Nominal negatif hanya berlaku untuk penyesuaian sebagai koreksi.';
        } elseif (abs((float) $amount) > 99999999999999.0) {
            $errors[] = 'Nominal terlalu besar untuk disimpan.';
        }

        if (!in_array($method, FinancialTransaction::METHODS, true)) {
            $errors[] = 'Metode pembayaran tidak dikenali.';
        }

        if (mb_strlen($recipient) < 3 || mb_strlen($recipient) > 150) {
            $errors[] = 'Penerima atau sumber dana harus terdiri dari 3 sampai 150 karakter.';
        }

        if (mb_strlen($reference) > 80) {
            $errors[] = 'Nomor referensi maksimal 80 karakter.';
        } elseif ($reference !== '' && $project !== null && FinancialTransaction::referenceExists($reference, (int) $project['id'], $exceptId)) {
            $errors[] = 'Nomor referensi sudah dipakai transaksi lain pada proyek yang sama.';
        }

        $category = null;
        if ($categoryId !== '') {
            if (!ctype_digit($categoryId) || (int) $categoryId <= 0) {
                $errors[] = 'Kategori pekerjaan tidak valid.';
            } else {
                $category = BudgetCategory::find((int) $categoryId);
                if ($category === null) {
                    $errors[] = 'Kategori pekerjaan tidak ditemukan.';
                } elseif ((int) $category['is_active'] !== 1) {
                    $errors[] = 'Kategori pekerjaan yang dipilih sedang tidak aktif.';
                }
            }
        }

        if ($category === null && in_array($type, [FinancialTransaction::TYPE_EXPENSE, FinancialTransaction::TYPE_REFUND], true)) {
            $errors[] = 'Kategori pekerjaan wajib dipilih agar realisasi dapat dibandingkan dengan RAB.';
        }

        $plan = null;
        if ($planId !== '') {
            if (!ctype_digit($planId)) {
                $errors[] = 'RAB acuan tidak valid.';
            } else {
                $plan = BudgetPlan::findWithNames((int) $planId);
                if ($plan === null) {
                    $errors[] = 'RAB acuan tidak ditemukan.';
                } elseif ($project !== null && (int) $plan['project_id'] !== (int) $project['id']) {
                    $errors[] = 'RAB acuan harus berasal dari proyek yang sama.';
                } elseif ((string) $plan['status'] !== BudgetPlan::STATUS_APPROVED) {
                    $errors[] = 'Transaksi hanya dapat mengacu pada RAB yang sudah disetujui.';
                }
            }
        }

        $item = null;
        if ($itemId !== '') {
            if (!ctype_digit($itemId)) {
                $errors[] = 'Item RAB acuan tidak valid.';
            } else {
                $item = BudgetItem::findWithCategory((int) $itemId);
                if ($item === null) {
                    $errors[] = 'Item RAB acuan tidak ditemukan.';
                } else {
                    if ($plan !== null && (int) $item['budget_plan_id'] !== (int) $plan['id']) {
                        $errors[] = 'Item RAB harus bagian dari RAB acuan yang dipilih.';
                    }
                    if ($category !== null && (int) $item['category_id'] !== (int) $category['id']) {
                        $errors[] = 'Kategori transaksi berbeda dari kategori item RAB yang dipilih.';
                    }
                }
            }
        }

        $reversal = null;
        if ($reversalId !== '') {
            if (!ctype_digit($reversalId)) {
                $errors[] = 'Transaksi yang akan dikoreksi tidak valid.';
            } else {
                $reversal = FinancialTransaction::findWithNames((int) $reversalId);
                if ($reversal === null) {
                    $errors[] = 'Transaksi yang akan dikoreksi tidak ditemukan.';
                } elseif ($project !== null && (int) $reversal['project_id'] !== (int) $project['id']) {
                    $errors[] = 'Koreksi harus memakai transaksi dari proyek yang sama.';
                } elseif ((string) $reversal['status'] !== FinancialTransaction::STATUS_APPROVED) {
                    $errors[] = 'Koreksi hanya dapat mengacu pada transaksi yang sudah disetujui.';
                } elseif ($type !== FinancialTransaction::TYPE_ADJUSTMENT) {
                    $errors[] = 'Transaksi koreksi harus berjenis penyesuaian.';
                }
            }
        }

        if ($errors === [] && $project !== null && $amount !== null && $exceptId === null) {
            if (FinancialTransaction::isDuplicate(
                (int) $project['id'],
                $date,
                $type,
                (float) $amount,
                $description,
                $reference === '' ? null : $reference
            )) {
                $errors[] = 'Transaksi serupa sudah tercatat pada tanggal, proyek, dan nominal yang sama. Periksa kembali agar tidak ganda.';
            }
        }

        if ($project !== null && (int) $project['is_archived'] === 1 && $exceptId === null) {
            $errors[] = 'Proyek sudah diarsipkan, transaksi baru tidak dapat dicatat.';
        }

        if ($errors !== []) {
            return [null, $errors];
        }

        /** @var array<string, mixed> $project */
        return [[
            'transaction_code' => $code,
            'project_id' => (int) $projectId,
            'project_name' => (string) $project['name'],
            'budget_plan_id' => $plan === null ? null : (int) $plan['id'],
            'budget_item_id' => $item === null ? null : (int) $item['id'],
            'category_id' => $category === null ? null : (int) $category['id'],
            'cost_center' => $center,
            'transaction_type' => $type,
            'transaction_date' => $date,
            'description' => $description,
            'amount' => round((float) $amount, 2),
            'payment_method' => $method,
            'recipient' => $recipient,
            'reference_number' => $reference === '' ? null : $reference,
            'reversal_of' => $reversal === null ? null : (int) $reversal['id'],
        ], []];
    }

    /**
     * Simpan berkas bukti; pesan galat dititipkan lewat array $errors pemanggil.
     *
     * @param array<int, string> $errors
     * @return array{stored: string, mime: string, size: int, original: string}|null
     */
    private function storeReceipt(Request $request, array &$errors): ?array
    {
        $file = $request->file('bukti');
        if ($file === null) {
            return null;
        }

        $attempt = FileUpload::store($file);
        if (!$attempt['ok']) {
            $errors[] = 'Bukti transaksi: ' . $attempt['error'];

            return null;
        }

        unset($attempt['ok'], $attempt['error']);

        /** @var array{stored: string, mime: string, size: int, original: string} $attempt */
        return $attempt;
    }

    /**
     * @param array{stored: string, mime: string, size: int, original: string} $upload
     */
    private function saveReceipt(array $upload, int $projectId, int $transactionId, int $actorId, string $code): void
    {
        ProjectDocument::create([
            'project_id' => $projectId,
            'financial_transaction_id' => $transactionId,
            'category' => ProjectDocument::TRANSACTION_CATEGORY,
            'title' => 'Bukti ' . $code,
            'original_name' => $upload['original'],
            'stored_name' => $upload['stored'],
            'mime_type' => $upload['mime'],
            'file_size' => $upload['size'],
            'description' => null,
            'uploaded_by' => $actorId,
        ]);
    }

    /** @return array<string, string> */
    private function readForm(Request $request): array
    {
        return [
            'transaction_code' => (string) $request->input('transaction_code', ''),
            'project_id' => (string) $request->input('project_id', ''),
            'transaction_type' => (string) $request->input('transaction_type', ''),
            'cost_center' => (string) $request->input('cost_center', ''),
            'transaction_date' => (string) $request->input('transaction_date', ''),
            'description' => (string) $request->input('description', ''),
            'amount' => (string) $request->input('amount', ''),
            'payment_method' => (string) $request->input('payment_method', ''),
            'recipient' => (string) $request->input('recipient', ''),
            'reference_number' => (string) $request->input('reference_number', ''),
            'category_id' => (string) $request->input('category_id', ''),
            'budget_plan_id' => (string) $request->input('budget_plan_id', ''),
            'budget_item_id' => (string) $request->input('budget_item_id', ''),
            'reversal_of' => (string) $request->input('reversal_of', ''),
        ];
    }

    /** @return array<string, string> */
    private function filters(Request $request): array
    {
        $search = preg_replace('/\s+/', ' ', trim($request->string('q'))) ?? '';
        $status = $request->string('status');
        $type = $request->string('jenis');
        $center = $request->string('pusat');
        $project = $request->string('proyek');
        $category = $request->string('kategori');
        $from = $request->string('dari');
        $to = $request->string('sampai');
        $sort = $request->string('sort');
        $dir = strtolower($request->string('dir'));

        return [
            'search' => mb_substr($search, 0, 60),
            'status' => in_array($status, FinancialTransaction::STATUSES, true) ? $status : '',
            'type' => in_array($type, FinancialTransaction::TYPES, true) ? $type : '',
            'cost_center' => in_array($center, FinancialTransaction::COST_CENTERS, true) ? $center : '',
            'project' => ctype_digit($project) ? $project : '',
            'category' => ctype_digit($category) ? $category : '',
            'from' => date_is_valid($from) ? $from : '',
            'to' => date_is_valid($to) ? $to : '',
            'sort' => array_key_exists($sort, FinancialTransaction::SORTABLE) ? $sort : 'date',
            'dir' => in_array($dir, ['asc', 'desc'], true) ? $dir : 'desc',
        ];
    }

    /** @return array<string, mixed> */
    private function findTransaction(string $id): array
    {
        if (!ctype_digit($id)) {
            throw new NotFoundHttpException();
        }

        $transaction = FinancialTransaction::findWithNames((int) $id);
        if ($transaction === null) {
            throw new NotFoundHttpException();
        }

        return $transaction;
    }

    /** @return array<string, mixed> */
    private function findEditable(string $id): array
    {
        $transaction = $this->findTransaction($id);

        if (!in_array((string) $transaction['status'], FinancialTransaction::EDITABLE_STATUSES, true)) {
            $this->fail(
                'Transaksi berstatus ' . transaction_status_label((string) $transaction['status'])
                . ' tidak dapat disunting. Buat penyesuaian sebagai koreksi yang tercatat.',
                (string) $transaction['id']
            );
        }

        return $transaction;
    }

    /** @return array<string, mixed> */
    private function findReceipt(string $id): array
    {
        if (!ctype_digit($id)) {
            throw new NotFoundHttpException();
        }

        $document = ProjectDocument::findWithTransaction((int) $id);
        if ($document === null) {
            throw new NotFoundHttpException();
        }

        return $document;
    }

    /** @return array<string, mixed> */
    private function pullOld(): array
    {
        $old = Session::pull('_old', []);

        return is_array($old) ? $old : [];
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
        $this->redirect($id === '' ? '/transaksi' : '/transaksi/' . $id);
    }
}
