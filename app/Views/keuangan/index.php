<?php
/**
 * Dashboard keuangan dan jadwal.
 * Seluruh angka berasal dari BudgetSummary, FinancialTransaction, dan ProjectSchedule
 * pada permintaan ini juga; grafik hanya menggambar ulang nilai yang sudah dihitung server.
 */
$overview = is_array($overview ?? []) ? $overview : [];
$perProject = is_array($perProject ?? []) ? $perProject : [];
$categories = is_array($categories ?? []) ? $categories : [];
$series = is_array($series ?? []) ? $series : [];
$counts = is_array($counts ?? []) ? $counts : [];
$byCenter = is_array($byCenter ?? []) ? $byCenter : [];
$scheduleSummary = is_array($scheduleSummary ?? []) ? $scheduleSummary : [];
$targetActual = (is_array($targetActual ?? []) ? $targetActual : [])
    + ['actual' => 0.0, 'target' => 0.0];
$progressByCategory = is_array($progressByCategory ?? []) ? $progressByCategory : [];
$pendingPlans = is_array($pendingPlans ?? []) ? $pendingPlans : [];
$pendingTransactions = is_array($pendingTransactions ?? []) ? $pendingTransactions : [];
$pendingRevisions = is_array($pendingRevisions ?? []) ? $pendingRevisions : [];
$upcoming = is_array($upcoming ?? []) ? $upcoming : [];
$lateTasks = is_array($lateTasks ?? []) ? $lateTasks : [];
$projects = is_array($projects ?? []) ? $projects : [];
$centers = is_array($centers ?? []) ? $centers : [];
$projectId = (int) ($projectId ?? 0);
$canBudget = can('budgets.view');
$canTransaction = can('transactions.view');
$canSchedule = can('schedules.view');

// Penyerapan tidak dapat dihitung bila nilai acuan nol, jadi tampilkan tanda hubung, bukan "0,0%".
$pct = static fn (?float $percent): string => $percent === null ? '—' : percent_id($percent);
$centerLabel = $center === '' ? 'pembangunan dan operasional digabung' : strtolower(cost_center_label($center));
$scopeLabel = $projectId > 0 ? 'proyek terpilih' : 'semua proyek';
// RAB dan anggaran awal proyek belum dipisah per pusat biaya. Saat halaman disaring ke satu
// pusat biaya, realisasi tetap terbaca tetapi selisihnya tidak dapat dinyatakan sebagai sisa.
$comparable = $center === '';

$projectChart = [
    'labels' => [],
    'baseline' => [],
    'realization' => [],
];
foreach (array_slice($perProject, 0, 8) as $row) {
    $projectChart['labels'][] = (string) $row['project_code'];
    $projectChart['baseline'][] = (float) $row['baseline'];
    $projectChart['realization'][] = (float) $row['realization'];
}

$categoryChart = ['labels' => [], 'planned' => [], 'realization' => []];
foreach (array_slice($categories, 0, 8) as $row) {
    $categoryChart['labels'][] = (string) $row['category_code'];
    $categoryChart['planned'][] = (float) $row['planned'];
    $categoryChart['realization'][] = (float) $row['realization'];
}

$monthChart = ['labels' => [], 'spent' => [], 'income' => []];
foreach ($series as $row) {
    $monthChart['labels'][] = periode_bulan_id((string) $row['period']);
    $monthChart['spent'][] = (float) $row['spent'];
    $monthChart['income'][] = (float) $row['income'];
}

$progressChart = ['labels' => [], 'progress' => []];
foreach ($progressByCategory as $row) {
    $progressChart['labels'][] = (string) $row['category_code'];
    $progressChart['progress'][] = (float) $row['avg_progress'];
}

$paceChart = [
    'labels' => ['Target waktu', 'Progres tercatat'],
    'values' => [(float) $targetActual['target'], (float) $targetActual['actual']],
];
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Keuangan</p>
        <h1 class="page-title">Ringkasan Keuangan dan Jadwal</h1>
        <p class="page-subtitle">
            Angka anggaran, realisasi, dan kemajuan dibaca langsung dari basis data pada saat halaman
            ini dibuka. Pusat biaya <?= e($centerLabel) ?>, lingkungan <?= e($scopeLabel) ?>.
        </p>
    </div>
    <div class="page-actions">
        <?php if (can('budgets.create')): ?>
            <a class="btn btn-outline-secondary" href="<?= url('/rab/tambah') ?>">
                <i class="bi bi-file-earmark-plus me-1" aria-hidden="true"></i>RAB baru
            </a>
        <?php endif; ?>
        <?php if ($canTransaction && can('transactions.create')): ?>
            <a class="btn btn-outline-secondary" href="<?= url('/transaksi/tambah') ?>">
                <i class="bi bi-cash-stack me-1" aria-hidden="true"></i>Catat transaksi
            </a>
        <?php endif; ?>
        <?php if ($canBudget): ?>
            <a class="btn btn-outline-secondary" href="<?= url('/anggaran') ?>">
                <i class="bi bi-wallet2 me-1" aria-hidden="true"></i>Anggaran
            </a>
        <?php endif; ?>
        <?php if ($canSchedule && can('schedules.create')): ?>
            <a class="btn btn-outline-secondary" href="<?= url('/jadwal/tambah') ?>">
                <i class="bi bi-calendar-plus me-1" aria-hidden="true"></i>Kegiatan baru
            </a>
        <?php endif; ?>
    </div>
</div>

<div class="toolbar">
    <form class="toolbar-form" method="get" action="<?= url('/keuangan') ?>">
        <div class="toolbar-field toolbar-field-wide">
            <label class="form-label" for="proyek">Proyek</label>
            <select class="form-select form-select-sm" id="proyek" name="proyek">
                <option value="">Semua proyek</option>
                <?php foreach ($projects as $option): ?>
                    <?php $oid = (int) $option['id']; ?>
                    <option value="<?= e((string) $oid) ?>" <?= $oid === $projectId ? 'selected' : '' ?>>
                        <?= e((string) $option['project_code'] . ' — ' . (string) $option['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="toolbar-field">
            <label class="form-label" for="pusat">Pusat biaya</label>
            <select class="form-select form-select-sm" id="pusat" name="pusat">
                <option value="">Digabung</option>
                <?php foreach ($centers as $key): ?>
                    <option value="<?= e($key) ?>" <?= $center === $key ? 'selected' : '' ?>>
                        <?= e(cost_center_label($key)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="toolbar-field">
            <label class="form-label" for="dari">Tren dari</label>
            <input class="form-control form-control-sm" id="dari" name="dari" type="date" value="<?= e($from) ?>">
        </div>
        <div class="toolbar-field">
            <label class="form-label" for="sampai">Tren sampai</label>
            <input class="form-control form-control-sm" id="sampai" name="sampai" type="date" value="<?= e($to) ?>">
        </div>
        <div class="toolbar-actions">
            <button class="btn btn-sm btn-primary" type="submit">Terapkan</button>
            <?php if ($projectId > 0 || $center !== '' || $from !== date('Y-01-01') || $to !== date('Y-12-31')): ?>
                <a class="btn btn-sm btn-outline-secondary" href="<?= url('/keuangan') ?>">Bersihkan</a>
            <?php endif; ?>
        </div>
    </form>
    <p class="muted-note">
        Rentang tanggal hanya memengaruhi grafik tren bulanan. Kartu anggaran, realisasi, dan jadwal
        selalu menghitung seluruh data yang ada saat ini.
    </p>
</div>

<?php if ($canBudget || $canTransaction): ?>
    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3">
            <section class="stat-card">
                <p class="stat-label">Anggaran disetujui</p>
                <p class="stat-value h5 mb-1"><?= e(rupiah($overview['baseline'] ?? 0)) ?></p>
                <p class="stat-meta">
                    <?= e(budget_baseline_label($overview['baseline_source'] ?? 'none')) ?>
                    &middot; <?= e((string) ($overview['approved_plan_count'] ?? 0)) ?> RAB
                </p>
            </section>
        </div>
        <div class="col-6 col-lg-3">
            <section class="stat-card">
                <p class="stat-label">Realisasi</p>
                <p class="stat-value h5 mb-1"><?= e(rupiah($overview['realization'] ?? 0)) ?></p>
                <p class="stat-meta">
                    <?= e((string) ($overview['approved_transaction_count'] ?? 0)) ?> transaksi disetujui
                </p>
            </section>
        </div>
        <div class="col-6 col-lg-3">
            <section class="stat-card">
                <p class="stat-label">Sisa anggaran</p>
                <p class="stat-value h5 mb-1"><?= e($comparable ? rupiah($overview['remaining'] ?? 0) : '—') ?></p>
                <p class="stat-meta">
                    <?php if ($comparable): ?>
                        <span class="pill pill-<?= e(budget_indicator_tone($overview['indicator'] ?? 'unknown')) ?>">
                            <?= e(budget_indicator_label($overview['indicator'] ?? 'unknown')) ?>
                        </span>
                    <?php else: ?>
                        <span class="pill pill-muted">Tidak sebanding</span>
                    <?php endif; ?>
                </p>
            </section>
        </div>
        <div class="col-6 col-lg-3">
            <section class="stat-card">
                <p class="stat-label">Penyerapan</p>
                <p class="stat-value h5 mb-1"><?= e($comparable ? $pct($overview['percent'] ?? null) : '—') ?></p>
                <p class="stat-meta">
                    Ambang peringatan <?= e((string) $warningPercent) ?>% &middot;
                    <?= e((string) ($counts['pending'] ?? 0)) ?> transaksi menunggu
                </p>
            </section>
        </div>
    </div>
<?php endif; ?>

<?php if (($canBudget || $canTransaction) && !$comparable): ?>
    <div class="notice-box" role="note">
        <p>
            Halaman ini disaring pada satu pusat biaya. Nomor RAB dan anggaran awal proyek belum
            dipisah per pusat biaya, sehingga realisasi <?= e(strtolower(cost_center_label($center))) ?>
            dibandingkan terhadap seluruh anggaran. Kartu sisa dan penyerapan sengaja dikosongkan agar
            angkanya tidak menyesatkan; gunakan tabel Pembangunan dan operasional MBG di bawah untuk
            memisahkan keduanya.
        </p>
    </div>
<?php endif; ?>

<?php if ($canSchedule): ?>
    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3">
            <section class="stat-card">
                <p class="stat-label">Kegiatan berlangsung</p>
                <p class="stat-value"><?= e((string) $scheduleSummary['running']) ?></p>
                <p class="stat-meta">
                    <?= e((string) $scheduleSummary['total']) ?> kegiatan pada <?= e($scopeLabel) ?>
                </p>
            </section>
        </div>
        <div class="col-6 col-lg-3">
            <section class="stat-card">
                <p class="stat-label">Kegiatan terlambat</p>
                <p class="stat-value"><?= e((string) $scheduleSummary['late']) ?></p>
                <p class="stat-meta">
                    <?= e((string) $scheduleSummary['delayed']) ?> berstatus tertunda
                </p>
            </section>
        </div>
        <div class="col-6 col-lg-3">
            <section class="stat-card">
                <p class="stat-label">Jadwal terdekat</p>
                <p class="stat-value"><?= e((string) $scheduleSummary['due_week']) ?></p>
                <p class="stat-meta">
                    Tenggat dalam <?= e((string) $dueDays) ?> hari ke depan
                </p>
            </section>
        </div>
        <div class="col-6 col-lg-3">
            <section class="stat-card">
                <p class="stat-label">Rata-rata progres</p>
                <p class="stat-value"><?= e((string) $scheduleSummary['avg_progress']) ?>%</p>
                <p class="stat-meta">
                    Target waktu <?= e((string) $targetActual['target']) ?>%
                </p>
            </section>
        </div>
    </div>
<?php endif; ?>

<?php if (($canBudget || $canTransaction) && $perProject === [] && $categories === []): ?>
    <div class="notice-box">
        <p class="mb-0">
            Belum ada anggaran maupun realisasi yang dapat dibandingkan. Mulai dengan
            <?php if (can('budgets.create')): ?>
                <a href="<?= url('/rab/tambah') ?>">menyusun RAB</a> atau
                <a href="<?= url('/transaksi/tambah') ?>">mencatat transaksi</a>.
            <?php else: ?>
                menyusun RAB atau mencatat transaksi; keduanya perlu hak akses modul keuangan.
            <?php endif; ?>
        </p>
    </div>
<?php endif; ?>

<div class="row g-3">
    <?php if ($canBudget && $projectChart['labels'] !== []): ?>
        <div class="col-12 col-xl-6">
            <section class="app-card h-100">
                <header class="card-head">
                    <div>
                        <h2 class="card-title">Anggaran dibandingkan realisasi</h2>
                        <p class="card-subtitle">
                            Delapan proyek dengan anggaran acuan terbesar, pusat biaya <?= e($centerLabel) ?>.
                        </p>
                    </div>
                </header>
                <div class="chart-box">
                    <canvas class="chart-canvas" data-chart-type="bar" data-chart-horizontal="1"
                            data-chart-labels="<?= json_attr($projectChart['labels']) ?>"
                            data-chart-values="<?= json_attr($projectChart['realization']) ?>"
                            data-chart-alt-values="<?= json_attr($projectChart['baseline']) ?>"
                            data-chart-label="Realisasi" data-chart-alt-label="Anggaran acuan"
                            data-chart-unit="rupiah"
                            aria-label="Anggaran dibandingkan realisasi per proyek" role="img"></canvas>
                </div>
            </section>
        </div>
    <?php endif; ?>

    <?php if ($canBudget && $categoryChart['labels'] !== []): ?>
        <div class="col-12 col-xl-6">
            <section class="app-card h-100">
                <header class="card-head">
                    <div>
                        <h2 class="card-title">Pengeluaran per kategori pekerjaan</h2>
                        <p class="card-subtitle">
                            Rencana dari RAB yang disetujui dibandingkan belanja yang sudah disetujui.
                        </p>
                    </div>
                </header>
                <div class="chart-box">
                    <canvas class="chart-canvas" data-chart-type="bar" data-chart-horizontal="1"
                            data-chart-labels="<?= json_attr($categoryChart['labels']) ?>"
                            data-chart-values="<?= json_attr($categoryChart['realization']) ?>"
                            data-chart-alt-values="<?= json_attr($categoryChart['planned']) ?>"
                            data-chart-label="Realisasi" data-chart-alt-label="Rencana RAB"
                            data-chart-unit="rupiah"
                            aria-label="Pengeluaran per kategori pekerjaan" role="img"></canvas>
                </div>
            </section>
        </div>
    <?php endif; ?>

    <?php if ($canTransaction && $monthChart['labels'] !== []): ?>
        <div class="col-12 col-xl-6">
            <section class="app-card h-100">
                <header class="card-head">
                    <div>
                        <h2 class="card-title">Tren belanja bulanan</h2>
                        <p class="card-subtitle">
                            <?= e(tanggal_id($from)) ?> &ndash; <?= e(tanggal_id($to)) ?>,
                            hanya transaksi berstatus disetujui.
                        </p>
                    </div>
                </header>
                <div class="chart-box">
                    <canvas class="chart-canvas" data-chart-type="line"
                            data-chart-labels="<?= json_attr($monthChart['labels']) ?>"
                            data-chart-values="<?= json_attr($monthChart['spent']) ?>"
                            data-chart-alt-values="<?= json_attr($monthChart['income']) ?>"
                            data-chart-label="Pengeluaran" data-chart-alt-label="Pemasukan"
                            data-chart-unit="rupiah"
                            aria-label="Tren belanja bulanan" role="img"></canvas>
                </div>
            </section>
        </div>
    <?php endif; ?>

    <?php if ($canSchedule && $progressChart['labels'] !== []): ?>
        <div class="col-12 col-xl-6">
            <section class="app-card h-100">
                <header class="card-head">
                    <div>
                        <h2 class="card-title">Progres kegiatan per kategori</h2>
                        <p class="card-subtitle">
                            Rata-rata persentase progres kegiatan yang tercatat pada tiap kategori.
                        </p>
                    </div>
                </header>
                <div class="chart-box">
                    <canvas class="chart-canvas" data-chart-type="bar" data-chart-horizontal="1"
                            data-chart-labels="<?= json_attr($progressChart['labels']) ?>"
                            data-chart-values="<?= json_attr($progressChart['progress']) ?>"
                            data-chart-label="Progres" data-chart-unit="persen" data-chart-max="100"
                            aria-label="Progres kegiatan per kategori" role="img"></canvas>
                </div>
            </section>
        </div>
    <?php endif; ?>

    <?php if ($canSchedule): ?>
        <div class="col-12 col-xl-6">
            <section class="app-card h-100">
                <header class="card-head">
                    <div>
                        <h2 class="card-title">Target dibandingkan realisasi jadwal</h2>
                        <p class="card-subtitle">
                            Target dihitung dari porsi waktu kegiatan yang sudah berjalan; kegiatan
                            selesai dianggap mencapai 100%.
                        </p>
                    </div>
                </header>
                <div class="chart-box">
                    <canvas class="chart-canvas" data-chart-type="bar"
                            data-chart-labels="<?= json_attr($paceChart['labels']) ?>"
                            data-chart-values="<?= json_attr($paceChart['values']) ?>"
                            data-chart-label="Persentase" data-chart-unit="persen" data-chart-max="100"
                            aria-label="Target dibandingkan realisasi jadwal" role="img"></canvas>
                </div>
                <p class="muted-note">
                    <?php
                    $gap = round((float) $targetActual['actual'] - (float) $targetActual['target'], 1);
                    ?>
                    Selisih progres terhadap target waktu
                    <?= e($gap >= 0 ? '+' : '') ?><?= e((string) $gap) ?> persen.
                    Nilai ini dihitung pada kueri yang sama dengan kartu progres di atas.
                </p>
            </section>
        </div>
    <?php endif; ?>
</div>

<?php if ($canBudget || $canTransaction): ?>
    <div class="row g-3 mt-1">
        <?php if ($canBudget): ?>
            <div class="col-12 col-xl-4">
                <section class="app-card h-100">
                    <header class="card-head">
                        <div>
                            <h2 class="card-title">RAB menunggu persetujuan</h2>
                        </div>
                        <span class="card-count"><?= e((string) count($pendingPlans)) ?></span>
                    </header>
                    <?php if ($pendingPlans === []): ?>
                        <p class="muted-note">Tidak ada RAB berstatus Menunggu Persetujuan.</p>
                    <?php else: ?>
                        <div class="note-list">
                            <?php foreach ($pendingPlans as $plan): ?>
                                <p class="mb-1">
                                    <a href="<?= url('/rab/' . (int) $plan['id']) ?>">
                                        <?= e((string) $plan['rab_code'] . ' — ' . (string) $plan['name']) ?>
                                    </a>
                                </p>
                                <p class="note-text">
                                    <?= e(rupiah($plan['total_amount'])) ?>
                                    &middot; <?= e((string) $plan['project_name']) ?>
                                    &middot; diajukan <?= e((string) ($plan['submitter_name'] ?? '—')) ?>
                                </p>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        <?php endif; ?>

        <?php if ($canTransaction): ?>
            <div class="col-12 col-xl-4">
                <section class="app-card h-100">
                    <header class="card-head">
                        <div>
                            <h2 class="card-title">Transaksi menunggu persetujuan</h2>
                        </div>
                        <span class="card-count"><?= e((string) count($pendingTransactions)) ?></span>
                    </header>
                    <?php if ($pendingTransactions === []): ?>
                        <p class="muted-note">Tidak ada transaksi berstatus Menunggu.</p>
                    <?php else: ?>
                        <div class="note-list">
                            <?php foreach ($pendingTransactions as $tx): ?>
                                <p class="mb-1">
                                    <a href="<?= url('/transaksi/' . (int) $tx['id']) ?>">
                                        <?= e((string) $tx['transaction_code']) ?>
                                    </a>
                                </p>
                                <p class="note-text">
                                    <?= e(rupiah($tx['amount'])) ?>
                                    &middot; <?= e(transaction_type_label($tx['transaction_type'])) ?>
                                    &middot; <?= e(tanggal_id((string) $tx['transaction_date'])) ?>
                                </p>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        <?php endif; ?>

        <?php if ($canBudget): ?>
            <div class="col-12 col-xl-4">
                <section class="app-card h-100">
                    <header class="card-head">
                        <div>
                            <h2 class="card-title">Usulan perubahan anggaran</h2>
                        </div>
                        <span class="card-count"><?= e((string) count($pendingRevisions)) ?></span>
                    </header>
                    <?php if ($pendingRevisions === []): ?>
                        <p class="muted-note">Tidak ada usulan perubahan yang menunggu keputusan.</p>
                    <?php else: ?>
                        <div class="note-list">
                            <?php foreach ($pendingRevisions as $revision): ?>
                                <p class="mb-1">
                                    <a href="<?= url('/anggaran/perubahan/' . (int) $revision['id']) ?>">
                                        <?= e((string) $revision['revision_code']) ?>
                                    </a>
                                </p>
                                <p class="note-text">
                                    <?= e(rupiah($revision['previous_amount'])) ?>
                                    &rarr; <?= e(rupiah($revision['proposed_amount'])) ?>
                                    &middot; <?= e((string) $revision['project_name']) ?>
                                </p>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($canSchedule && ($upcoming !== [] || $lateTasks !== [])): ?>
    <div class="row g-3 mt-1">
        <?php if ($upcoming !== []): ?>
            <div class="col-12 col-xl-6">
                <section class="app-card h-100">
                    <header class="card-head">
                        <div>
                            <h2 class="card-title">Tenggat terdekat</h2>
                            <p class="card-subtitle">
                                Kegiatan yang belum selesai dan jatuh tempo dalam <?= e((string) $dueDays) ?> hari.
                            </p>
                        </div>
                    </header>
                    <div class="table-responsive">
                        <table class="app-table">
                            <thead>
                                <tr>
                                    <th scope="col">Kegiatan</th>
                                    <th scope="col">Selesai</th>
                                    <th scope="col" class="text-end">Hari tersisa</th>
                                    <th scope="col" class="text-end">Progres</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($upcoming as $task): ?>
                                    <tr>
                                        <td>
                                            <a class="cell-strong" href="<?= url('/jadwal/' . (int) $task['id']) ?>">
                                                <?= e((string) $task['activity_code'] . ' — ' . (string) $task['name']) ?>
                                            </a>
                                            <span class="cell-sub d-block">
                                                <?= e((string) $task['project_name']) ?>
                                                &middot; <?= e((string) $task['owner_name']) ?>
                                            </span>
                                        </td>
                                        <td><?= e(tanggal_id((string) $task['end_date'])) ?></td>
                                        <td class="text-end"><?= e((string) (int) $task['days_left']) ?></td>
                                        <td class="text-end"><?= e((string) (int) $task['progress']) ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        <?php endif; ?>

        <?php if ($lateTasks !== []): ?>
            <div class="col-12 col-xl-6">
                <section class="app-card h-100">
                    <header class="card-head">
                        <div>
                            <h2 class="card-title">Kegiatan lewat tenggat</h2>
                            <p class="card-subtitle">
                                Diurutkan dari yang paling lama melewati tanggal selesai.
                            </p>
                        </div>
                    </header>
                    <div class="table-responsive">
                        <table class="app-table">
                            <thead>
                                <tr>
                                    <th scope="col">Kegiatan</th>
                                    <th scope="col">Selesai</th>
                                    <th scope="col" class="text-end">Lewat (hari)</th>
                                    <th scope="col">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($lateTasks as $task): ?>
                                    <tr>
                                        <td>
                                            <a class="cell-strong" href="<?= url('/jadwal/' . (int) $task['id']) ?>">
                                                <?= e((string) $task['activity_code'] . ' — ' . (string) $task['name']) ?>
                                            </a>
                                            <span class="cell-sub d-block">
                                                <?= e((string) $task['project_name']) ?>
                                            </span>
                                        </td>
                                        <td><?= e(tanggal_id((string) $task['end_date'])) ?></td>
                                        <td class="text-end"><?= e((string) (int) $task['days_late']) ?></td>
                                        <td>
                                            <span class="pill pill-<?= e(schedule_status_tone($task['status'])) ?>">
                                                <?= e(schedule_status_label((string) $task['status'])) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($canBudget): ?>
    <div class="row g-3 mt-1">
        <div class="col-12 col-xl-6">
            <section class="app-card h-100">
                <header class="card-head">
                    <div>
                        <h2 class="card-title">Pembangunan dan operasional MBG</h2>
                        <p class="card-subtitle">
                            Kedua pusat biaya selalu dilaporkan terpisah meski halaman ini disaring salah satunya.
                        </p>
                    </div>
                </header>
                <div class="table-responsive">
                    <table class="app-table">
                        <thead>
                            <tr>
                                <th scope="col">Pusat biaya</th>
                                <th scope="col" class="text-end">Transaksi disetujui</th>
                                <th scope="col" class="text-end">Realisasi</th>
                                <th scope="col" class="text-end">Pemasukan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($byCenter as $row): ?>
                                <tr>
                                    <td class="cell-strong"><?= e(cost_center_label($row['cost_center'])) ?></td>
                                    <td class="text-end"><?= e((string) $row['approved_count']) ?></td>
                                    <td class="text-end"><?= e(rupiah($row['realization'])) ?></td>
                                    <td class="text-end"><?= e(rupiah($row['income'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <div class="col-12 col-xl-6">
            <section class="app-card h-100">
                <header class="card-head">
                    <div>
                        <h2 class="card-title">Status transaksi</h2>
                        <p class="card-subtitle">
                            Hitungan transaksi pada proyek aktif
                            <?= $center === '' ? '' : 'dengan pusat biaya ' . e(strtolower(cost_center_label($center))) ?>.
                        </p>
                    </div>
                </header>
                <dl class="info-list">
                    <?php foreach (array_keys($counts) as $status): ?>
                        <div>
                            <dt><?= e(transaction_status_label($status)) ?></dt>
                            <dd><?= e((string) $counts[$status]) ?></dd>
                        </div>
                    <?php endforeach; ?>
                </dl>
            </section>
        </div>
    </div>
<?php endif; ?>

<section class="app-card mt-3">
    <header class="card-head">
        <div>
            <h2 class="card-title">Cara angka pada halaman ini dihitung</h2>
        </div>
    </header>
    <ul class="rule-list">
        <li>Realisasi hanya menghitung transaksi berstatus Disetujui; draft, usulan, dan transaksi yang dibatalkan tidak ikut menjumlah.</li>
        <li>Nilai acuan memakai total RAB yang sudah disetujui; bila belum ada, anggaran awal proyek yang dipakai, dan sumbernya ditulis pada kartu anggaran.</li>
        <li>Sisa = anggaran acuan dikurangi realisasi. Penyerapan = realisasi dibagi anggaran acuan dikali 100%, ditandai mendekati batas pada <?= e((string) $warningPercent) ?>%.</li>
        <li>Grafik tren bulanan mengikuti rentang tanggal pada penyaringan; kartu anggaran dan jadwal tidak mengikuti rentang tersebut.</li>
        <li>Terlambat berarti tanggal selesai kegiatan sudah lewat sementara statusnya belum Selesai atau Dibatalkan.</li>
        <li>Bila halaman disaring satu pusat biaya, sisa dan penyerapan dikosongkan: nomor RAB belum dipisah per pusat biaya sehingga pembandingnya tidak sepadan.</li>
        <li>Semua nilai berasal dari MySQL pada permintaan ini; peramban hanya menggambar grafik dari angka yang sudah dihitung server.</li>
    </ul>
</section>
