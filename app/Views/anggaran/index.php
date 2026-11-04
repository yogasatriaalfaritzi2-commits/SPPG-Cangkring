<?php
/**
 * Rekap anggaran dibandingkan realisasi.
 * Semua angka datang dari BudgetSummary, kelas yang sama dipakai halaman RAB,
 * transaksi, dan dashboard, sehingga tidak ada dua angka yang berbeda untuk satu proyek.
 */
$bar = static function (?float $percent): string {
    if ($percent === null) {
        return '0';
    }

    return (string) min(100.0, max(0.0, $percent));
};
// Penyerapan tidak dapat dihitung bila nilai acuan nol, jadi tampilkan tanda hubung, bukan "0,0%".
$pct = static fn (?float $percent): string => $percent === null ? '—' : percent_id($percent);
$centerLabel = $center === '' ? 'semua pusat biaya' : strtolower(cost_center_label($center));
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Keuangan</p>
        <h1 class="page-title">Anggaran dan Realisasi</h1>
        <p class="page-subtitle">
            Anggaran acuan dibandingkan dengan realisasi pengeluaran yang sudah disetujui.
            Nilai anggaran awal proyek hanya berubah lewat usulan perubahan yang disetujui,
            lengkap dengan riwayat dan alasannya.
        </p>
    </div>
    <div class="page-actions">
        <?php if (can('budgets.export')): ?>
            <a class="btn btn-outline-secondary"
               href="<?= query_url('/anggaran/ekspor', ['pusat' => $center, 'lingkup' => 'proyek']) ?>">
                <i class="bi bi-download me-1" aria-hidden="true"></i>Ekspor per proyek
            </a>
            <a class="btn btn-outline-secondary"
               href="<?= query_url('/anggaran/ekspor', ['pusat' => $center, 'lingkup' => 'kategori']) ?>">
                <i class="bi bi-download me-1" aria-hidden="true"></i>Ekspor per kategori
            </a>
        <?php endif; ?>
        <?php if (can('budgets.view')): ?>
            <a class="btn btn-primary" href="<?= url('/rab') ?>">Kelola RAB</a>
        <?php endif; ?>
    </div>
</div>

<div class="toolbar">
    <form class="toolbar-form" method="get" action="<?= url('/anggaran') ?>">
        <div class="toolbar-field">
            <label class="form-label" for="pusat">Pusat biaya</label>
            <select class="form-select form-select-sm" id="pusat" name="pusat">
                <?php foreach (\App\Models\FinancialTransaction::COST_CENTERS as $option): ?>
                    <option value="<?= e($option) ?>" <?= $center === $option ? 'selected' : '' ?>>
                        <?= e(cost_center_label($option)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="toolbar-actions">
            <button class="btn btn-sm btn-primary" type="submit">Terapkan</button>
        </div>
    </form>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <section class="stat-card">
            <p class="stat-label">Anggaran acuan</p>
            <p class="stat-value h5 mb-1"><?= e(rupiah($totals['baseline'])) ?></p>
            <p class="stat-meta"><?= e(budget_baseline_label($totals['baseline_source'])) ?></p>
        </section>
    </div>
    <div class="col-6 col-lg-3">
        <section class="stat-card">
            <p class="stat-label">Realisasi</p>
            <p class="stat-value h5 mb-1"><?= e(rupiah($totals['realization'])) ?></p>
            <p class="stat-meta">
                <?= e((string) $totals['approved_transaction_count']) ?> transaksi disetujui
            </p>
        </section>
    </div>
    <div class="col-6 col-lg-3">
        <section class="stat-card">
            <p class="stat-label">Sisa anggaran</p>
            <p class="stat-value h5 mb-1"><?= e(rupiah($totals['remaining'])) ?></p>
            <p class="stat-meta">
                <span class="pill pill-<?= e(budget_indicator_tone($totals['indicator'])) ?>">
                    <?= e(budget_indicator_label($totals['indicator'])) ?>
                </span>
            </p>
        </section>
    </div>
    <div class="col-6 col-lg-3">
        <section class="stat-card">
            <p class="stat-label">Penyerapan</p>
            <p class="stat-value h5 mb-1"><?= e($pct($totals['percent'])) ?></p>
            <p class="stat-meta">
                Ambang peringatan <?= e((string) $warningPercent) ?>%
                &middot; <?= e((string) $totals['pending_transaction_count']) ?> transaksi menunggu
            </p>
        </section>
    </div>
</div>

<?php if ($pendingCount > 0): ?>
    <div class="notice-box">
        <p class="mb-0">
            <i class="bi bi-arrow-left-right me-1" aria-hidden="true"></i>
            <?= e((string) $pendingCount) ?> usulan perubahan anggaran menunggu keputusan
            pemeriksa berizin <code>budgets.approve</code>.
        </p>
    </div>
<?php endif; ?>

<section class="app-card mb-3">
    <header class="card-head">
        <div>
            <h2 class="card-title">Rekap anggaran per proyek</h2>
            <p class="card-subtitle">
                Pusat biaya <?= e($centerLabel) ?>. Proyek arsip tidak dihitung.
            </p>
        </div>
        <span class="card-count"><?= count($rows) ?> proyek</span>
    </header>

    <div class="table-responsive">
        <table class="table app-table align-middle mb-0">
            <thead>
                <tr>
                    <th scope="col">Proyek</th>
                    <th scope="col" class="text-end">Anggaran awal</th>
                    <th scope="col" class="text-end">RAB disetujui</th>
                    <th scope="col" class="text-end">Acuan</th>
                    <th scope="col" class="text-end">Realisasi</th>
                    <th scope="col" class="text-end">Sisa</th>
                    <th scope="col">Penyerapan</th>
                    <th scope="col">Antrean</th>
                    <th scope="col" class="text-end">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr>
                        <td colspan="9" class="table-empty">
                            <div class="empty-state">
                                <i class="bi bi-wallet2" aria-hidden="true"></i>
                                <p class="empty-title">Belum ada proyek aktif</p>
                                <p class="empty-text">
                                    Buat proyek pembangunan lebih dahulu, lalu susun RAB-nya agar anggaran muncul di sini.
                                </p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <?php $percent = $row['percent'] === null ? null : (float) $row['percent']; ?>
                    <tr>
                        <td>
                            <span class="cell-strong">
                                <a href="<?= url('/anggaran/' . $row['project_id']) ?>">
                                    <?= e((string) $row['project_name']) ?>
                                </a>
                            </span>
                            <span class="cell-sub">
                                <?= e((string) $row['project_code']) ?>
                                &middot; <?= e(project_status_label($row['project_status'])) ?>
                            </span>
                        </td>
                        <td class="text-end text-nowrap"><?= e(rupiah($row['initial_budget'])) ?></td>
                        <td class="text-end text-nowrap"><?= e(rupiah($row['approved_budget'])) ?></td>
                        <td class="text-end text-nowrap">
                            <?= e(rupiah($row['baseline'])) ?>
                            <span class="cell-sub d-block">
                                <?= $row['baseline_source'] === \App\Models\BudgetSummary::SOURCE_APPROVED_RAB
                                    ? 'RAB' : 'Anggaran awal' ?>
                            </span>
                        </td>
                        <td class="text-end text-nowrap"><?= e(rupiah($row['realization'])) ?></td>
                        <td class="text-end text-nowrap">
                            <?= e(rupiah($row['remaining'])) ?>
                            <?php if ((float) $row['remaining'] < 0): ?>
                                <span class="cell-sub d-block">Melebihi acuan</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="progress-cell">
                                <div class="progress app-progress" role="progressbar"
                                     aria-label="Penyerapan anggaran proyek" aria-valuemin="0" aria-valuemax="100"
                                     aria-valuenow="<?= e($bar($percent)) ?>">
                                    <div class="progress-bar" style="width: <?= e($bar($percent)) ?>%"></div>
                                </div>
                                <span class="progress-value"><?= e($pct($percent)) ?></span>
                            </div>
                            <span class="pill pill-<?= e(budget_indicator_tone($row['indicator'])) ?>">
                                <?= e(budget_indicator_label($row['indicator'])) ?>
                            </span>
                        </td>
                        <td>
                            <span class="cell-sub d-block">
                                <?= e((string) $row['pending_rab']) ?> RAB &middot;
                                <?= e((string) $row['pending_transactions']) ?> transaksi
                            </span>
                        </td>
                        <td class="text-end cell-actions">
                            <a class="btn btn-sm btn-outline-secondary"
                               href="<?= url('/anggaran/' . $row['project_id']) ?>">Rincian</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<div class="row g-3">
    <section class="col-12 col-xl-7 app-card">
        <header class="card-head">
            <div>
                <h2 class="card-title">Rekap per kategori pekerjaan</h2>
                <p class="card-subtitle">
                    Rencana dari RAB yang disetujui dibandingkan belanja transaksi pada kategori yang sama.
                </p>
            </div>
            <span class="card-count"><?= count($categories) ?> kategori</span>
        </header>

        <div class="table-responsive">
            <table class="table app-table align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col">Kategori</th>
                        <th scope="col" class="text-end">Rencana RAB</th>
                        <th scope="col" class="text-end">Realisasi</th>
                        <th scope="col" class="text-end">Sisa</th>
                        <th scope="col">Penyerapan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($categories === []): ?>
                        <tr>
                            <td colspan="5" class="table-empty">
                                <div class="empty-state">
                                    <i class="bi bi-tags" aria-hidden="true"></i>
                                    <p class="empty-title">Belum ada angka yang dapat dibandingkan</p>
                                    <p class="empty-text">
                                        Kategori muncul setelah RAB disetujui atau transaksi dicatat pada kategori tersebut.
                                    </p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($categories as $category): ?>
                        <?php $percent = $category['percent'] === null ? null : (float) $category['percent']; ?>
                        <tr>
                            <td>
                                <span class="cell-strong"><?= e((string) $category['category_name']) ?></span>
                                <span class="cell-sub">
                                    <?= e((string) $category['category_code']) ?>
                                    &middot; <?= e((string) $category['item_count']) ?> item RAB
                                    &middot; <?= e((string) $category['transaction_count']) ?> transaksi
                                </span>
                            </td>
                            <td class="text-end text-nowrap"><?= e(rupiah($category['planned'])) ?></td>
                            <td class="text-end text-nowrap"><?= e(rupiah($category['realization'])) ?></td>
                            <td class="text-end text-nowrap"><?= e(rupiah($category['remaining'])) ?></td>
                            <td>
                                <div class="progress-cell">
                                    <div class="progress app-progress" role="progressbar"
                                         aria-label="Penyerapan kategori" aria-valuemin="0" aria-valuemax="100"
                                         aria-valuenow="<?= e($bar($percent)) ?>">
                                        <div class="progress-bar" style="width: <?= e($bar($percent)) ?>%"></div>
                                    </div>
                                    <span class="progress-value"><?= e($pct($percent)) ?></span>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <div class="col-12 col-xl-5">
        <section class="app-card mb-3">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Pembangunan dan operasional MBG</h2>
                    <p class="card-subtitle">
                        Dihitung dari seluruh transaksi disetujui, tanpa penyaringan pusat biaya.
                    </p>
                </div>
            </header>
            <div class="table-responsive">
                <table class="table app-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Pusat biaya</th>
                            <th scope="col" class="text-end">Realisasi</th>
                            <th scope="col" class="text-end">Pemasukan</th>
                            <th scope="col" class="text-end">Transaksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($costCenters as $centerRow): ?>
                            <tr>
                                <td class="cell-strong"><?= e(cost_center_label($centerRow['cost_center'])) ?></td>
                                <td class="text-end text-nowrap"><?= e(rupiah($centerRow['realization'])) ?></td>
                                <td class="text-end text-nowrap"><?= e(rupiah($centerRow['income'])) ?></td>
                                <td class="text-end"><?= e((string) $centerRow['approved_count']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="app-card">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Usulan perubahan anggaran</h2>
                    <p class="card-subtitle">
                        Usulan yang menunggu keputusan, urut dari yang paling lama diajukan.
                    </p>
                </div>
                <span class="card-count"><?= count($pendingRevisions) ?> usulan</span>
            </header>
            <?php if ($pendingRevisions === []): ?>
                <div class="empty-state empty-state-block">
                    <i class="bi bi-check2-circle" aria-hidden="true"></i>
                    <p class="empty-title">Tidak ada usulan yang menunggu</p>
                    <p class="empty-text">
                        Anggaran setiap proyek sedang sesuai nilai yang berlaku.
                    </p>
                </div>
            <?php else: ?>
                <div class="timeline-list">
                    <?php foreach ($pendingRevisions as $revision): ?>
                        <article class="timeline-item">
                            <div class="timeline-head">
                                <span class="timeline-date"><?= e(tanggal_id((string) $revision['requested_at'])) ?></span>
                                <span class="pill pill-info">Menunggu keputusan</span>
                            </div>
                            <p class="timeline-title">
                                <a href="<?= url('/anggaran/perubahan/' . $revision['id']) ?>">
                                    <?= e((string) $revision['revision_code']) ?>
                                </a>
                                &middot; <?= e((string) $revision['project_name']) ?>
                            </p>
                            <p class="timeline-text">
                                <?= e(rupiah($revision['previous_amount'])) ?>
                                &rarr; <?= e(rupiah($revision['proposed_amount'])) ?>
                            </p>
                            <p class="timeline-foot">
                                Diajukan oleh <?= e($revision['requester_name'] ?? '—') ?>
                            </p>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>

<section class="app-card mt-3">
    <header class="card-head">
        <div>
            <h2 class="card-title">Cara membaca halaman ini</h2>
        </div>
    </header>
    <ul class="rule-list">
        <li>Anggaran acuan = total RAB yang sudah disetujui; bila belum ada, anggaran awal proyek yang dipakai.</li>
        <li>Sisa = anggaran acuan &minus; realisasi. Realisasi hanya menghitung transaksi berstatus disetujui.</li>
        <li>
            Penyerapan = realisasi &divide; anggaran acuan &times; 100%. Nilai acuan nol membuat persentase
            tidak dapat dihitung, sehingga ditampilkan sebagai tanda hubung panjang.
        </li>
        <li>Indikator berubah menjadi mendekati batas pada <?= e((string) $warningPercent) ?>% dan terlampaui di atas 100%. Batas itu dapat diubah di Pengaturan.</li>
        <li>Anggaran awal proyek tidak berubah sampai usulan perubahan disetujui; setiap keputusan tersimpan beserta alasannya.</li>
    </ul>
</section>
