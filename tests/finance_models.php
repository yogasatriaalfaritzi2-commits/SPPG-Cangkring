<?php
declare(strict_types=1);

/**
 * Pengujian model sisi server modul RAB, anggaran, transaksi, dan jadwal.
 *
 * Seluruh skenario berjalan di dalam satu transaksi database yang dibatalkan pada
 * akhir pelaksanaan, jadi suite ini dapat dijalankan berulang tanpa meninggalkan
 * jejak data dan tanpa memerlukan server web.
 *
 * Jalankan: php tests/finance_models.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Skrip ini hanya boleh dijalankan dari CLI.\n");
    exit(1);
}

require dirname(__DIR__) . '/bootstrap/app.php';

use App\Core\App;
use App\Core\Database;
use App\Models\BudgetCategory;
use App\Models\BudgetItem;
use App\Models\BudgetPlan;
use App\Models\BudgetRevision;
use App\Models\BudgetSummary;
use App\Models\FinancialTransaction;
use App\Models\Project;
use App\Models\ProjectSchedule;
use App\Models\Setting;

App::loadSettings();

$passed = 0;
$failed = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;

    if ($ok) {
        $passed++;
        echo "  [LULUS] {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
        return;
    }

    $failed++;
    echo "  [GAGAL] {$name}" . ($detail !== '' ? " -> {$detail}" : '') . "\n";
}

function section(string $title): void
{
    echo "\n== {$title} ==\n";
}

function same(float $expected, float $actual, string $label): void
{
    check($label, abs($expected - $actual) < 0.005, 'diharapkan ' . $expected . ', nyata ' . $actual);
}

$filtersOf = static fn (array $keys): array => array_fill_keys($keys, '');

$pdo = Database::connection();
$pdo->beginTransaction();

// Agregat nasional dibaca tanpa saringan proyek, jadi nilainya bergantung pada data yang
// sudah ada di basis data nyata. Nilai awal diambil sebelum fixture dibuat, lalu setiap
// uji agregat dibandingkan terhadap selisihnya agar suite tetap sah pada basis data berisi.
$statusAwal = BudgetSummary::transactionStatusCounts();
$pusatAwal = BudgetSummary::byCostCenter();
$nasionalAwal = BudgetSummary::globalOverview(FinancialTransaction::CENTER_CONSTRUCTION);
$awal = [
    'construction' => (float) ($pusatAwal[FinancialTransaction::CENTER_CONSTRUCTION]['realization'] ?? 0),
    'operations' => (float) ($pusatAwal[FinancialTransaction::CENTER_OPERATIONS]['realization'] ?? 0),
    'nasional' => (float) ($nasionalAwal['realization'] ?? 0),
    'approved' => (int) ($statusAwal[FinancialTransaction::STATUS_APPROVED] ?? 0),
    'pending' => (int) ($statusAwal[FinancialTransaction::STATUS_PENDING] ?? 0),
];

try {
    $actorId = (int) (Database::query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetch()['id'] ?? 0);
    check('Terdapat pengguna sebagai pencatat', $actorId > 0, 'user id ' . $actorId);

    $projectId = Project::create([
        'project_code' => 'UJI-MODEL-2026',
        'name' => 'Proyek Uji Model Keuangan',
        'location' => 'Cangkring',
        'pic_name' => 'Petugas Uji',
        'start_date' => '2026-11-01',
        'target_date' => '2026-12-31',
        'budget_amount' => 2500000000.0,
        'status' => 'running',
        'created_by' => $actorId,
        'updated_by' => $actorId,
    ]);
    check('Proyek uji terbentuk', $projectId > 0);

    section('Normalisasi angka di sisi server');

    check('Format ribuan Indonesia dibaca', amount_normalize('1.500.000,50') === '1500000.50', (string) amount_normalize('1.500.000,50'));
    check('Format polos dibaca', amount_normalize('500000') === '500000');
    check('Nilai negatif dibaca', amount_normalize('-25.000') === '-25000', (string) amount_normalize('-25.000'));
    check('Teks bukan angka ditolak', amount_normalize('abc') === null);
    check('Pengelompokan ribuan tidak rapi ditolak', amount_normalize('1.5000') === null);
    check('Volume tiga desimal dibaca', volume_normalize('2,250') === '2.250', (string) volume_normalize('2,250'));
    check('Volume lebih dari tiga desimal ditolak', volume_normalize('2,2504') === null);

    section('Item dan total RAB');

    $categories = BudgetCategory::activeList();
    check('Kategori pekerjaan tersedia', count($categories) >= 12, (string) count($categories));

    $catA = (int) $categories[0]['id'];
    $codeA = (string) $categories[0]['category_code'];
    $catB = (int) $categories[1]['id'];
    $codeB = (string) $categories[1]['category_code'];

    [$planId, $planCode] = BudgetPlan::create([
        'project_id' => $projectId,
        'name' => 'RAB Uji Tahap I',
        'period_start' => '2026-11-01',
        'period_end' => '2026-12-31',
        'created_by' => $actorId,
        'updated_by' => $actorId,
    ]);
    check('Nomor RAB otomatis', preg_match('/^RAB-\d{4}-\d{3}$/', $planCode) === 1, $planCode);

    $itemA = BudgetItem::create([
        'budget_plan_id' => $planId,
        'category_id' => $catA,
        'item_code' => BudgetItem::nextItemCode($planId, $codeA),
        'item_name' => 'Galian tanah pondasi',
        'volume' => volume_normalize('10'),
        'unit' => 'm3',
        'unit_price' => amount_normalize('150.000'),
        'created_by' => $actorId,
        'updated_by' => $actorId,
    ]);
    $itemB = BudgetItem::create([
        'budget_plan_id' => $planId,
        'category_id' => $catB,
        'item_code' => BudgetItem::nextItemCode($planId, $codeB),
        'item_name' => 'Beton footplaat',
        'volume' => volume_normalize('2,5'),
        'unit' => 'm3',
        'unit_price' => amount_normalize('1.250.000,50'),
        'created_by' => $actorId,
        'updated_by' => $actorId,
    ]);
    $itemC = BudgetItem::create([
        'budget_plan_id' => $planId,
        'category_id' => $catB,
        'item_code' => BudgetItem::nextItemCode($planId, $codeB),
        'item_name' => 'Lantai kerja',
        'volume' => volume_normalize('1'),
        'unit' => 'lot',
        'unit_price' => amount_normalize('500.000'),
        'created_by' => $actorId,
        'updated_by' => $actorId,
    ]);

    same(1500000.0, BudgetItem::computeTotal('10', '150000'), 'Total item = volume x harga satuan');
    same(3125001.25, BudgetItem::computeTotal('2.5', '1250000.50'), 'Perkalian mempertahankan dua desimal');

    $totalExpected = 1500000.0 + 3125001.25 + 500000.0;
    same($totalExpected, BudgetPlan::recalculateTotal($planId), 'Total RAB = jumlah seluruh item');
    same($totalExpected, BudgetPlan::storedTotal($planId), 'Total RAB tersimpan di basis data');
    check('Kecocokan total terdeteksi', BudgetPlan::totalIsCurrent($planId));

    BudgetItem::update(
        $itemA,
        [
            'budget_plan_id' => $planId,
            'category_id' => $catA,
            'item_code' => BudgetItem::nextItemCode($planId, $codeA),
            'item_name' => 'Galian tanah pondasi (revisi volume)',
            'volume' => volume_normalize('12'),
            'unit' => 'm3',
            'unit_price' => amount_normalize('150.000'),
            'updated_by' => $actorId,
        ]
    );
    $totalAfterEdit = 1800000.0 + 3125001.25 + 500000.0;
    same($totalAfterEdit, BudgetPlan::recalculateTotal($planId), 'Total RAB ikut berubah saat item diubah');
    check('Semua item termuat', count(BudgetItem::forPlan($planId, '', 0)) === 3);
    check('Filter kategori item bekerja', count(BudgetItem::forPlan($planId, '', $catB)) === 2);
    check('Pencarian item bekerja', count(BudgetItem::forPlan($planId, 'Beton', 0)) === 1);
    check('Rekap kategori per RAB', count(BudgetItem::categoryTotals($planId)) === 2);

    $itemDuplicate = BudgetItem::duplicate($itemC, $actorId);
    check('Item dapat diduplikasi', $itemDuplicate !== null);
    check('Hasil duplikasi memakai kode baru', $itemDuplicate !== null && $itemDuplicate !== $itemC);
    same($totalAfterEdit + 500000.0, BudgetPlan::recalculateTotal($planId), 'Total bertambah setelah duplikasi');
    BudgetItem::delete((int) $itemDuplicate);
    same($totalAfterEdit, BudgetPlan::recalculateTotal($planId), 'Total berkurang setelah item dihapus');

    section('Alur persetujuan RAB');

    $draft = BudgetPlan::findWithNames($planId);
    check('RAB baru berstatus draft', (string) $draft['status'] === BudgetPlan::STATUS_DRAFT);
    check('Draft termasuk status yang boleh diedit', in_array($draft['status'], BudgetPlan::EDITABLE_STATUSES, true));

    check('Pengajuan RAB mengubah satu baris', BudgetPlan::changeStatus(
        $planId,
        BudgetPlan::STATUS_DRAFT,
        BudgetPlan::STATUS_PENDING,
        'submit',
        null,
        $actorId
    ) === 1);

    check(
        'Pengajuan ganda ditolak kunci optimistis',
        BudgetPlan::changeStatus(
            $planId,
            BudgetPlan::STATUS_DRAFT,
            BudgetPlan::STATUS_PENDING,
            'submit',
            null,
            $actorId
        ) === 0
    );

    $pending = BudgetPlan::findWithNames($planId);
    check('Pengaju tercatat', (int) $pending['submitted_by'] === $actorId);
    check('Waktu pengajuan tercatat', (string) $pending['submitted_at'] !== '');

    check('Persetujuan RAB berhasil', BudgetPlan::changeStatus(
        $planId,
        BudgetPlan::STATUS_PENDING,
        BudgetPlan::STATUS_APPROVED,
        'approve',
        'Disetujui dalam uji model',
        $actorId
    ) === 1);

    $approvedPlan = BudgetPlan::findWithNames($planId);
    check('Status akhir disetujui', (string) $approvedPlan['status'] === BudgetPlan::STATUS_APPROVED);
    check('Pemeriksa tercatat', (int) $approvedPlan['reviewed_by'] === $actorId);
    check('Catatan persetujuan tersimpan', str_contains((string) $approvedPlan['decision_note'], 'uji model'));
    check(
        'RAB disetujui terkunci dari perubahan langsung',
        !in_array($approvedPlan['status'], BudgetPlan::EDITABLE_STATUSES, true)
    );
    check('Jejak persetujuan tercatat dua aksi', count(BudgetPlan::approvals($planId)) >= 2);
    check('RAB disetujui masuk perhitungan anggaran', abs(BudgetPlan::approvedTotalForProject($projectId) - $totalAfterEdit) < 0.005);

    [$revisedPlanId, $revisedCode] = BudgetPlan::copyAsRevision(
        $planId,
        ['id' => $actorId],
        'RAB Uji Revisi Tahap I',
        'Penyesuaian volume pondasi'
    );
    $revisedPlan = BudgetPlan::findWithNames($revisedPlanId);
    check('RAB turunan dibuat', $revisedPlan !== null);
    check('Versi RAB turunan bertambah', (int) $revisedPlan['version'] === 2, (string) $revisedPlan['version']);
    check('RAB turunan berstatus draft', (string) $revisedPlan['status'] === BudgetPlan::STATUS_DRAFT);
    check('RAB turunan menunjuk induk', (int) $revisedPlan['revision_of'] === $planId);
    check('Seluruh item ikut tersalin', BudgetItem::countForPlan($revisedPlanId) === BudgetItem::countForPlan($planId));
    same($totalAfterEdit, BudgetPlan::storedTotal($revisedPlanId), 'Total RAB turunan sama dengan induk');
    check('Induk tetap disetujui sampai turunan disetujui', (string) BudgetPlan::findWithNames($planId)['status'] === BudgetPlan::STATUS_APPROVED);
    check('Riwayat versi membaca turunan', count(BudgetPlan::revisionsOf($planId)) === 1);

    section('Transaksi keuangan dan realisasi');

    [$trxOne, $trxOneCode] = FinancialTransaction::create([
        'project_id' => $projectId,
        'budget_plan_id' => $planId,
        'category_id' => $catA,
        'cost_center' => FinancialTransaction::CENTER_CONSTRUCTION,
        'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
        'transaction_date' => '2026-11-05',
        'description' => 'Pembayaran galian pondasi',
        'amount' => 1000000.0,
        'payment_method' => 'transfer',
        'recipient' => 'CV Uji Bangun',
        'reference_number' => 'SPM-UJI-001',
        'recorded_by' => $actorId,
    ]);
    [$trxDraft, $trxDraftCode] = FinancialTransaction::create([
        'project_id' => $projectId,
        'category_id' => $catB,
        'cost_center' => FinancialTransaction::CENTER_CONSTRUCTION,
        'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
        'transaction_date' => '2026-11-06',
        'description' => 'Pembayaran beton masih draft',
        'amount' => 500000.0,
        'payment_method' => 'cash',
        'recipient' => 'Toko Bahan Uji',
        'recorded_by' => $actorId,
    ]);

    check('Nomor transaksi otomatis', preg_match('/^TRX-\d{4}-\d{3}$/', $trxOneCode) === 1, $trxOneCode);
    check('Transaksi baru berstatus draft', (string) FinancialTransaction::findWithNames($trxOne)['status'] === FinancialTransaction::STATUS_DRAFT);
    same(0.0, FinancialTransaction::realization($projectId), 'Transaksi draft belum menjadi realisasi');

    FinancialTransaction::changeStatus($trxOne, FinancialTransaction::STATUS_DRAFT, FinancialTransaction::STATUS_PENDING, 'submit', null, $actorId);
    check(
        'Persetujuan mengubah satu baris',
        FinancialTransaction::changeStatus($trxOne, FinancialTransaction::STATUS_PENDING, FinancialTransaction::STATUS_APPROVED, 'approve', 'Sesuai SPAB', $actorId) === 1
    );
    same(1000000.0, FinancialTransaction::realization($projectId), 'Realisasi hanya dari transaksi disetujui');

    check(
        'Transaksi disetujui tidak dapat diubah nilainya',
        FinancialTransaction::update($trxOne, [
            'project_id' => $projectId,
            'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
            'transaction_date' => '2026-11-05',
            'description' => 'Dicoba diubah',
            'amount' => 1.0,
            'payment_method' => 'cash',
            'recipient' => 'X',
        ]) === 0
    );
    check(
        'Transaksi disetujui tidak dapat dihapus permanen',
        FinancialTransaction::delete($trxOne) === 0
        && FinancialTransaction::findWithNames($trxOne) !== null
    );

    [$trxRefund] = FinancialTransaction::create([
        'project_id' => $projectId,
        'category_id' => $catA,
        'cost_center' => FinancialTransaction::CENTER_CONSTRUCTION,
        'transaction_type' => FinancialTransaction::TYPE_REFUND,
        'transaction_date' => '2026-11-07',
        'description' => 'Pengembalian kelebihan bayar',
        'amount' => 250000.0,
        'payment_method' => 'transfer',
        'recipient' => 'Bendahara Pengeluaran',
        'reference_number' => 'SPM-UJI-002',
        'recorded_by' => $actorId,
    ]);
    FinancialTransaction::changeStatus($trxRefund, FinancialTransaction::STATUS_DRAFT, FinancialTransaction::STATUS_PENDING, 'submit', null, $actorId);
    FinancialTransaction::changeStatus($trxRefund, FinancialTransaction::STATUS_PENDING, FinancialTransaction::STATUS_APPROVED, 'approve', null, $actorId);
    same(750000.0, FinancialTransaction::realization($projectId), 'Pengembalian dana mengurangi realisasi');

    [$trxAdjust] = FinancialTransaction::create([
        'project_id' => $projectId,
        'category_id' => $catA,
        'cost_center' => FinancialTransaction::CENTER_CONSTRUCTION,
        'transaction_type' => FinancialTransaction::TYPE_ADJUSTMENT,
        'transaction_date' => '2026-11-08',
        'description' => 'Koreksi kurang bayar upah tukang',
        'amount' => 50000.0,
        'payment_method' => 'cash',
        'recipient' => 'Kelompok Kerja',
        'reference_number' => 'SPM-UJI-003',
        'recorded_by' => $actorId,
    ]);
    FinancialTransaction::changeStatus($trxAdjust, FinancialTransaction::STATUS_DRAFT, FinancialTransaction::STATUS_PENDING, 'submit', null, $actorId);
    FinancialTransaction::changeStatus($trxAdjust, FinancialTransaction::STATUS_PENDING, FinancialTransaction::STATUS_APPROVED, 'approve', null, $actorId);
    same(800000.0, FinancialTransaction::realization($projectId), 'Penyesuaian positif menambah realisasi');

    [$trxIncome] = FinancialTransaction::create([
        'project_id' => $projectId,
        'cost_center' => FinancialTransaction::CENTER_CONSTRUCTION,
        'transaction_type' => FinancialTransaction::TYPE_INCOME,
        'transaction_date' => '2026-11-08',
        'description' => 'Penerimaan dana pembangunan',
        'amount' => 2000000.0,
        'payment_method' => 'transfer',
        'recipient' => 'Bendahara Pembangunan',
        'reference_number' => 'SPM-UJI-004',
        'recorded_by' => $actorId,
    ]);
    FinancialTransaction::changeStatus($trxIncome, FinancialTransaction::STATUS_DRAFT, FinancialTransaction::STATUS_PENDING, 'submit', null, $actorId);
    FinancialTransaction::changeStatus($trxIncome, FinancialTransaction::STATUS_PENDING, FinancialTransaction::STATUS_APPROVED, 'approve', null, $actorId);
    same(800000.0, FinancialTransaction::realization($projectId), 'Pemasukan tidak dihitung sebagai realisasi');
    same(2000000.0, FinancialTransaction::income($projectId), 'Sumber dana tercatat sebagai pemasukan');

    section('Pemisahan anggaran pembangunan dan operasional MBG');

    [$trxOps] = FinancialTransaction::create([
        'project_id' => $projectId,
        'cost_center' => FinancialTransaction::CENTER_OPERATIONS,
        'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
        'transaction_date' => '2026-11-09',
        'description' => 'Belanja bahan makanan dapur MBG',
        'amount' => 300000.0,
        'payment_method' => 'cash',
        'recipient' => 'Penyedia Bahan Pangan',
        'reference_number' => 'SPM-UJI-005',
        'recorded_by' => $actorId,
    ]);
    FinancialTransaction::changeStatus($trxOps, FinancialTransaction::STATUS_DRAFT, FinancialTransaction::STATUS_PENDING, 'submit', null, $actorId);
    FinancialTransaction::changeStatus($trxOps, FinancialTransaction::STATUS_PENDING, FinancialTransaction::STATUS_APPROVED, 'approve', null, $actorId);

    same(800000.0, FinancialTransaction::realization($projectId, FinancialTransaction::CENTER_CONSTRUCTION), 'Realisasi pembangunan tidak tercampur operasional');
    same(300000.0, FinancialTransaction::realization($projectId, FinancialTransaction::CENTER_OPERATIONS), 'Realisasi operasional MBG berdiri sendiri');

    $perCenter = BudgetSummary::byCostCenter();
    same($awal['construction'] + 800000.0, (float) $perCenter['construction']['realization'], 'Rekap per cost center: pembangunan');
    same($awal['operations'] + 300000.0, (float) $perCenter['operations']['realization'], 'Rekap per cost center: operasional');

    section('Pembatalan beralasan dan pencegahan duplikasi');

    check(
        'Pembatalan memerlukan alasan dicatat',
        FinancialTransaction::changeStatus(
            $trxDraft,
            FinancialTransaction::STATUS_DRAFT,
            FinancialTransaction::STATUS_CANCELLED,
            'cancel',
            'Salah input nominal',
            $actorId
        ) === 1
    );
    $cancelled = FinancialTransaction::findWithNames($trxDraft);
    check('Transaksi batal tetap tersimpan', $cancelled !== null);
    check('Alasan pembatalan tersimpan', str_contains((string) $cancelled['cancel_reason'], 'Salah input'));
    same(800000.0, FinancialTransaction::realization($projectId), 'Transaksi batal tidak masuk realisasi');

    [$trxDuplicate] = FinancialTransaction::create([
        'project_id' => $projectId,
        'cost_center' => FinancialTransaction::CENTER_CONSTRUCTION,
        'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
        'transaction_date' => '2026-11-05',
        'description' => 'Pembayaran galian pondasi',
        'amount' => 1000000.0,
        'payment_method' => 'transfer',
        'recipient' => 'CV Uji Bangun',
        'recorded_by' => $actorId,
    ]);

    check(
        'Transaksi ganda pada proyek, tanggal, jenis, dan nominal terdeteksi',
        FinancialTransaction::isDuplicate($projectId, '2026-11-05', FinancialTransaction::TYPE_EXPENSE, 1000000.0, 'Pembayaran galian pondasi', null, null)
    );
    check(
        'Transaksi itu sendiri tidak dianggap ganda saat diedit',
        !FinancialTransaction::isDuplicate($projectId, '2026-11-05', FinancialTransaction::TYPE_EXPENSE, 1000000.0, 'Pembayaran galian pondasi', null, $trxDuplicate)
    );
    check(
        'Transaksi berbeda tidak dianggap ganda',
        !FinancialTransaction::isDuplicate($projectId, '2026-11-10', FinancialTransaction::TYPE_EXPENSE, 250000.0, 'Pembayaran lain', null, null)
    );
    FinancialTransaction::delete($trxDuplicate);

    $duplicateReference = false;
    try {
        FinancialTransaction::create([
            'project_id' => $projectId,
            'cost_center' => FinancialTransaction::CENTER_CONSTRUCTION,
            'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
            'transaction_date' => '2026-11-10',
            'description' => 'Nomor referensi kembar',
            'amount' => 10000.0,
            'payment_method' => 'cash',
            'recipient' => 'X',
            'reference_number' => 'SPM-UJI-001',
            'recorded_by' => $actorId,
        ]);
    } catch (PDOException $exception) {
        $duplicateReference = $exception->getCode() === '23000';
    }
    check('Nomor referensi wajib unik per proyek', $duplicateReference);

    $zeroAmount = false;
    try {
        FinancialTransaction::create([
            'project_id' => $projectId,
            'cost_center' => FinancialTransaction::CENTER_CONSTRUCTION,
            'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
            'transaction_date' => '2026-11-10',
            'description' => 'Nominal nol',
            'amount' => 0.0,
            'payment_method' => 'cash',
            'recipient' => 'X',
            'recorded_by' => $actorId,
        ]);
    } catch (PDOException $exception) {
        $zeroAmount = true;
    }
    check('Nominal pengeluaran harus lebih dari nol', $zeroAmount);

    section('Anggaran, sisa, dan penyerapan');

    $overview = BudgetSummary::projectOverview($projectId);
    same($totalAfterEdit, (float) $overview['approved_budget'], 'Anggaran disetujui berasal dari RAB approved');
    same(800000.0, (float) $overview['realization'], 'Realisasi pada ringkasan proyek');
    same($totalAfterEdit - 800000.0, (float) $overview['remaining'], 'Sisa = anggaran disetujui - realisasi');
    $expectedPercent = round(800000.0 / $totalAfterEdit * 100, 1);
    check('Persentase penyerapan dihitung server', abs((float) $overview['percent'] - $expectedPercent) < 0.05, (string) $overview['percent']);
    check('Acuan anggaran memakai RAB disetujui', $overview['baseline_source'] === BudgetSummary::SOURCE_APPROVED_RAB);
    check('Indikator aman di bawah ambang', $overview['indicator'] === BudgetSummary::INDICATOR_SAFE, (string) $overview['indicator']);

    check('Pembagian nol menghasilkan null', BudgetSummary::percent(0.0, 1000.0) === null);
    check('Indikator tanpa acuan bernilai unknown', BudgetSummary::indicator(0.0, 0.0) === BudgetSummary::INDICATOR_UNKNOWN);

    $originalThreshold = (string) App::setting('budget_warning_percent', '85');

    Setting::put('budget_warning_percent', '10', $actorId);
    App::refreshSettings();

    $tuned = BudgetSummary::projectOverview($projectId);
    check(
        'Ambang peringatan dapat dikonfigurasi',
        $tuned['indicator'] === BudgetSummary::INDICATOR_WARNING && (int) $tuned['warning_percent'] === 10,
        'persen ' . $tuned['percent']
    );

    Setting::put('budget_warning_percent', $originalThreshold, $actorId);
    App::refreshSettings();

    check(
        'Ambang asli dipulihkan dan indikator kembali aman',
        BudgetSummary::projectOverview($projectId)['indicator'] === BudgetSummary::INDICATOR_SAFE
    );

    [$trxOver] = FinancialTransaction::create([
        'project_id' => $projectId,
        'cost_center' => FinancialTransaction::CENTER_CONSTRUCTION,
        'transaction_type' => FinancialTransaction::TYPE_EXPENSE,
        'transaction_date' => '2026-11-12',
        'description' => 'Pembayaran melampaui RAB',
        'amount' => 5000000.0,
        'payment_method' => 'transfer',
        'recipient' => 'CV Uji Bangun',
        'reference_number' => 'SPM-UJI-006',
        'recorded_by' => $actorId,
    ]);
    FinancialTransaction::changeStatus($trxOver, FinancialTransaction::STATUS_DRAFT, FinancialTransaction::STATUS_PENDING, 'submit', null, $actorId);
    FinancialTransaction::changeStatus($trxOver, FinancialTransaction::STATUS_PENDING, FinancialTransaction::STATUS_APPROVED, 'approve', null, $actorId);

    $overrun = BudgetSummary::projectOverview($projectId);
    check('Indikator terlampaui saat realisasi melewati anggaran', $overrun['indicator'] === BudgetSummary::INDICATOR_EXCEEDED, (string) $overrun['indicator']);
    check('Sisa anggaran bernilai negatif saat terlampaui', (float) $overrun['remaining'] < 0.0, (string) $overrun['remaining']);

    $emptyProjectId = Project::create([
        'project_code' => 'UJI-KOSONG-2026',
        'name' => 'Proyek Uji Tanpa Anggaran',
        'location' => 'Cangkring',
        'pic_name' => 'Petugas Uji',
        'start_date' => '2026-11-01',
        'target_date' => '2026-12-31',
        'budget_amount' => 0.0,
        'status' => 'planning',
        'created_by' => $actorId,
        'updated_by' => $actorId,
    ]);
    $emptyOverview = BudgetSummary::projectOverview($emptyProjectId);
    check('Proyek tanpa anggaran tidak membagi dengan nol', $emptyOverview['percent'] === null);
    check('Acuan kosong ditandai', $emptyOverview['baseline_source'] === BudgetSummary::SOURCE_NONE);

    section('Rekap kategori dan agregasi lintas proyek');

    $byCategory = BudgetSummary::perCategory($projectId);
    check('Rekap kategori menghasilkan baris', $byCategory !== []);
    $categoryIds = array_map(static fn (array $row): int => (int) $row['category_id'], $byCategory);
    check('Kategori RAB muncul pada rekap', in_array($catA, $categoryIds, true) && in_array($catB, $categoryIds, true));
    $spentAll = array_sum(array_map(static fn (array $row): float => (float) $row['realization'], $byCategory));
    same(5800000.0, $spentAll, 'Jumlah realisasi per kategori sama dengan total realisasi');

    $global = BudgetSummary::globalOverview(FinancialTransaction::CENTER_CONSTRUCTION);
    same($awal['nasional'] + 5800000.0, (float) $global['realization'], 'Realisasi nasional cost center pembangunan');
    check('Jumlah proyek aktif dihitung', (int) $global['project_count'] >= 2, (string) $global['project_count']);

    $statuses = BudgetSummary::transactionStatusCounts();
    check('Rekap status transaksi lengkap', count($statuses) === count(FinancialTransaction::STATUSES));
    check('Transaksi menunggu tercatat', $statuses[FinancialTransaction::STATUS_PENDING] === $awal['pending'], (string) $statuses[FinancialTransaction::STATUS_PENDING]);
    check('Transaksi disetujui tercatat', $statuses[FinancialTransaction::STATUS_APPROVED] === $awal['approved'] + 6, (string) $statuses[FinancialTransaction::STATUS_APPROVED]);

    $page = FinancialTransaction::paginate(
        $filtersOf(['search', 'status', 'type', 'cost_center', 'project', 'category', 'from', 'to', 'sort', 'dir']),
        1,
        15
    );
    check('Paginasi transaksi berfungsi', $page['total'] >= 6 && count($page['rows']) <= 15, 'total ' . $page['total']);

    $filtered = FinancialTransaction::paginate([
        'search' => '',
        'status' => FinancialTransaction::STATUS_APPROVED,
        'type' => FinancialTransaction::TYPE_EXPENSE,
        'cost_center' => FinancialTransaction::CENTER_CONSTRUCTION,
        'project' => (string) $projectId,
        'category' => '',
        'from' => '2026-11-01',
        'to' => '2026-11-30',
        'sort' => 'date',
        'dir' => 'asc',
    ], 1, 15);
    check('Filter transaksi menyaring hasil', $filtered['total'] === 2, 'total ' . $filtered['total']);
    check('Ekspor transaksi tersedia', count(FinancialTransaction::forExport([
        'search' => '',
        'status' => '',
        'type' => '',
        'cost_center' => '',
        'project' => (string) $projectId,
        'category' => '',
        'from' => '',
        'to' => '',
        'sort' => 'date',
        'dir' => 'asc',
    ])) >= 6);

    // Disaring ke proyek fixture agar jumlahnya tidak bergantung pada RAB proyek lain
    // yang sudah ada di basis data.
    $planPage = BudgetPlan::paginate(
        array_merge($filtersOf(['search', 'status', 'project', 'category', 'year', 'sort', 'dir']), [
            'project' => (string) $projectId,
        ]),
        1,
        10
    );
    check('Paginasi RAB berfungsi', $planPage['total'] === 2, 'total ' . $planPage['total']);

    section('Perubahan anggaran melalui revisi bersertifikat');

    $beforeBudget = (float) Project::findWithNames($projectId)['budget_amount'];
    [$revisionId, $revisionCode] = BudgetRevision::create([
        'project_id' => $projectId,
        'previous_amount' => $beforeBudget,
        'proposed_amount' => 3000000000.0,
        'reason' => 'Penambahan pekerjaan area dapur',
        'requested_by' => $actorId,
    ]);
    check('Kode perubahan anggaran otomatis', preg_match('/^RVB-\d{4}-\d{3}$/', $revisionCode) === 1, $revisionCode);
    check('Anggaran awal belum berubah saat pengajuan', abs((float) Project::findWithNames($projectId)['budget_amount'] - $beforeBudget) < 0.005);
    check('Perubahan anggaran berstatus menunggu', count(BudgetRevision::pending()) >= 1);

    check('Persetujuan perubahan mengubah satu baris', BudgetRevision::decide($revisionId, BudgetRevision::STATUS_APPROVED, 'Disetujui', $actorId) === 1);
    check('Anggaran proyek berubah setelah disetujui', abs((float) Project::findWithNames($projectId)['budget_amount'] - 3000000000.0) < 0.005);
    check('Perubahan yang sudah diputus tidak dapat diputus ulang', BudgetRevision::decide($revisionId, BudgetRevision::STATUS_REJECTED, 'Dicoba ulang', $actorId) === 0);
    check('Riwayat perubahan anggaran tercatat', count(BudgetRevision::historyForProject($projectId)) === 1);

    section('Jadwal pembangunan');

    $scheduleOne = ProjectSchedule::create([
        'project_id' => $projectId,
        'name' => 'Pekerjaan struktur pondasi',
        'category_id' => $catA,
        'start_date' => '2026-11-01',
        'end_date' => '2026-11-10',
        'owner_name' => 'Mandor Uji',
        'priority' => 'high',
        'status' => ProjectSchedule::STATUS_IN_PROGRESS,
        'progress' => 40,
        'created_by' => $actorId,
        'updated_by' => $actorId,
    ]);
    $scheduleTwo = ProjectSchedule::create([
        'project_id' => $projectId,
        'name' => 'Pekerjaan atap dan plafon',
        'category_id' => $catB,
        'start_date' => '2026-11-11',
        'end_date' => '2026-11-25',
        'owner_name' => 'Mandor Uji',
        'priority' => 'medium',
        'status' => ProjectSchedule::STATUS_NOT_STARTED,
        'progress' => 0,
        'created_by' => $actorId,
        'updated_by' => $actorId,
    ]);

    $first = ProjectSchedule::findWithNames($scheduleOne);
    check('Kode kegiatan otomatis per proyek', preg_match('/^KGT-\d{3}$/', (string) $first['activity_code']) === 1, (string) $first['activity_code']);
    check('Durasi dihitung dari rentang tanggal', (int) $first['duration_days'] === 10, (string) $first['duration_days']);
    check('Kode kegiatan berikutnya berbeda', (string) ProjectSchedule::findWithNames($scheduleTwo)['activity_code'] !== (string) $first['activity_code']);
    check('Nama proyek ikut terbaca', (string) $first['project_name'] === 'Proyek Uji Model Keuangan');

    $changed = ProjectSchedule::update($scheduleOne, [
        'project_id' => $projectId,
        'name' => 'Pekerjaan struktur pondasi',
        'category_id' => $catA,
        'start_date' => '2026-11-01',
        'end_date' => '2026-11-15',
        'owner_name' => 'Mandor Uji',
        'priority' => 'urgent',
        'status' => ProjectSchedule::STATUS_DELAYED,
        'progress' => 55,
        'notes' => 'Menunggu pasokan material',
        'updated_by' => $actorId,
    ], 'Mundur karena material terlambat', $actorId);

    check('Perubahan jadwal melaporkan bidang yang berubah', in_array('end_date', $changed, true) && in_array('progress', $changed, true), implode(',', $changed));
    $history = ProjectSchedule::history($scheduleOne);
    check('Riwayat jadwal tersimpan', count($history) >= 3, (string) count($history));
    check('Alasan perubahan tercatat di riwayat', str_contains((string) $history[0]['reason'], 'material terlambat'));
    check('Nilai lama dan baru tercatat', (string) $history[0]['old_value'] !== '' || (string) $history[0]['new_value'] !== '');

    $afterUpdate = ProjectSchedule::findWithNames($scheduleOne);
    check('Durasi diperbarui mengikuti tanggal', (int) $afterUpdate['duration_days'] === 15, (string) $afterUpdate['duration_days']);

    check(
        'Ketergantungan tidak boleh mengarah ke diri sendiri',
        ProjectSchedule::createsCycle($scheduleOne, $scheduleOne, ProjectSchedule::dependencyEdges($projectId))
    );

    ProjectSchedule::addDependency($scheduleTwo, $scheduleOne, 'FS', 0, $actorId);
    $edges = ProjectSchedule::dependencyEdges($projectId);
    check('Ketergantungan tersimpan', count($edges) === 1);
    check(
        'Ketergantungan melingkar ditolak',
        ProjectSchedule::createsCycle($scheduleOne, $scheduleTwo, $edges)
    );

    check(
        'Validasi tanggal FS yang melanggar diberi pesan',
        ProjectSchedule::dependencyDateError('2026-11-05', '2026-11-20', '2026-11-01', '2026-11-15', 'FS', 0) !== null
    );
    check(
        'Validasi tanggal FS yang sah lolos',
        ProjectSchedule::dependencyDateError('2026-11-16', '2026-11-30', '2026-11-01', '2026-11-15', 'FS', 0) === null
    );
    check('Validasi tanggal SS mengenal aturan mulai bersama', ProjectSchedule::dependencyDateError('2026-11-01', '2026-11-30', '2026-11-05', '2026-11-15', 'SS', 0) !== null);

    $calendar = ProjectSchedule::calendarEvents('2026-11-01', '2026-11-30', $projectId);
    check('Kalender mengembalikan kegiatan', count($calendar) === 2, (string) count($calendar));
    check('Gantt mengembalikan kegiatan beserta progres', count(ProjectSchedule::ganttRows($projectId)) === 2);
    check('Jadwal terdekat terbaca', count(ProjectSchedule::upcoming(30, 5, $projectId)) >= 1);
    $summary = ProjectSchedule::summary($projectId);
    check('Ringkasan jadwal menghitung status', $summary['total'] === 2 && $summary['delayed'] === 1, json_encode($summary));
    check('Progres rata-rata dihitung', abs($summary['avg_progress'] - 27.5) < 0.05, (string) $summary['avg_progress']);
    check('Target vs realisasi tersedia', count(ProjectSchedule::targetVersusActual($projectId)) === 2);

    // Sama seperti RAB, daftar kegiatan dibatasi ke proyek fixture agar tidak ikut
    // menghitung kegiatan proyek lain yang sudah ada.
    $saringanJadwal = array_merge(
        $filtersOf(['search', 'status', 'priority', 'project', 'category', 'from', 'to', 'late', 'sort', 'dir']),
        ['project' => (string) $projectId]
    );
    $schedulePage = ProjectSchedule::paginate($saringanJadwal, 1, 15);
    check('Paginasi jadwal berfungsi', $schedulePage['total'] === 2, 'total ' . $schedulePage['total']);

    check('Jadwal dapat dihapus lunak', ProjectSchedule::softDelete($scheduleTwo, $actorId) === 1);
    check(
        'Jadwal yang dihapus lunak hilang dari daftar',
        ProjectSchedule::paginate($saringanJadwal, 1, 15)['total'] === 1
    );
    $rawRow = Database::query(
        'SELECT is_deleted, deleted_by, deleted_at FROM project_schedules WHERE id = :id',
        ['id' => $scheduleTwo]
    )->fetch();
    check('Baris jadwal tetap tersimpan sebagai jejak', $rawRow !== null && (int) $rawRow['is_deleted'] === 1);
    check('Penghapus lunak tercatat', (int) $rawRow['deleted_by'] === $actorId && (string) $rawRow['deleted_at'] !== '');
    check('Jadwal lunak tidak muncul di daftar pilihan', count(ProjectSchedule::options($projectId)) === 1);
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

echo "\nRingkasan: {$passed} lulus, {$failed} gagal (" . ($passed + $failed) . " uji)\n";
exit($failed === 0 ? 0 : 1);
