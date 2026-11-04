<?php
/**
 * Rincian anggaran satu proyek: acuan, realisasi, kategori, RAB, transaksi,
 * beserta riwayat perubahan anggaran dan status proyek.
 */
$result = $transactions;
$rowsTx = $transactions['rows'];
$projectId = (int) $project['id'];
$bar = static function (?float $percent): string {
    if ($percent === null) {
        return '0';
    }

    return (string) min(100.0, max(0.0, $percent));
};
$pct = static fn (?float $percent): string => $percent === null ? '—' : percent_id($percent);
// Pusat biaya selalu terisi, jadi tidak dihitung sebagai penyaringan tambahan.
$hasTxFilter = $txFilters['search'] !== '' || $txFilters['status'] !== '' || $txFilters['type'] !== ''
    || $txFilters['category'] !== '' || $txFilters['from'] !== '' || $txFilters['to'] !== '';
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Keuangan</p>
        <h1 class="page-title">Anggaran proyek <?= e((string) $project['name']) ?></h1>
        <p class="page-subtitle">
            Nilai dihitung ulang setiap halaman dibuka, langsung dari tabel RAB dan transaksi,
            sehingga realisasi tidak pernah tertinggal dari pencatatan terbaru.
        </p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline-secondary" href="<?= url('/anggaran') ?>">Daftar anggaran</a>
        <?php if (can('budgets.view')): ?>
            <a class="btn btn-outline-secondary" href="<?= query_url('/rab', ['proyek' => (string) $projectId]) ?>">
                RAB proyek ini
            </a>
        <?php endif; ?>
        <a class="btn btn-primary" href="#ajukan">Ajukan perubahan anggaran</a>
    </div>
</div>

<div class="toolbar">
    <form class="toolbar-form" method="get" action="<?= url('/anggaran/' . $projectId) ?>">
        <?php foreach (['q' => $txFilters['search'], 'status' => $txFilters['status'],
                        'jenis' => $txFilters['type'], 'kategori' => $txFilters['category'],
                        'dari' => $txFilters['from'], 'sampai' => $txFilters['to']] as $name => $value): ?>
            <?php if ($value !== ''): ?>
                <input type="hidden" name="<?= e($name) ?>" value="<?= e($value) ?>">
            <?php endif; ?>
        <?php endforeach; ?>
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
        <p class="muted-note mb-0 ms-auto">
            Penyaringan pusat biaya berlaku untuk realisasi, kategori, dan daftar transaksi di bawah.
        </p>
    </form>
</div>

<?php if ((int) $project['is_archived'] === 1): ?>
    <div class="notice-box">
        <p class="mb-0">
            <i class="bi bi-archive me-1" aria-hidden="true"></i>
            Proyek ini sudah diarsipkan. Angka masih dapat dibaca, namun transaksi baru tidak dapat diajukan.
        </p>
    </div>
<?php elseif ($overview['baseline_source'] === \App\Models\BudgetSummary::SOURCE_PROJECT_BUDGET): ?>
    <div class="notice-box">
        <p class="mb-0">
            <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
            Belum ada RAB yang disetujui, jadi anggaran awal proyek dipakai sebagai acuan sementara.
        </p>
    </div>
<?php elseif ($overview['baseline_source'] === \App\Models\BudgetSummary::SOURCE_NONE): ?>
    <div class="notice-box">
        <p class="mb-0">
            <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
            Proyek ini belum memiliki anggaran awal maupun RAB disetujui, sehingga penyerapan belum dapat dihitung.
        </p>
    </div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <section class="stat-card">
            <p class="stat-label">Anggaran awal proyek</p>
            <p class="stat-value h5 mb-1"><?= e(rupiah($overview['initial_budget'])) ?></p>
            <p class="stat-meta">
                <?php if (count($revisions) > 0): ?>
                    <?= e((string) count($revisions)) ?> kali perubahan diajukan
                <?php else: ?>
                    Belum pernah diubah
                <?php endif; ?>
            </p>
        </section>
    </div>
    <div class="col-6 col-lg-3">
        <section class="stat-card">
            <p class="stat-label">Anggaran acuan</p>
            <p class="stat-value h5 mb-1"><?= e(rupiah($overview['baseline'])) ?></p>
            <p class="stat-meta"><?= e(budget_baseline_label($overview['baseline_source'])) ?></p>
        </section>
    </div>
    <div class="col-6 col-lg-3">
        <section class="stat-card">
            <p class="stat-label">Realisasi &amp; sisa</p>
            <p class="stat-value h5 mb-1"><?= e(rupiah($overview['realization'])) ?></p>
            <p class="stat-meta">
                Sisa <?= e(rupiah($overview['remaining'])) ?>
                <?php if ((float) $overview['remaining'] < 0): ?>
                    &middot; defisit
                <?php endif; ?>
            </p>
        </section>
    </div>
    <div class="col-6 col-lg-3">
        <section class="stat-card">
            <p class="stat-label">Penyerapan</p>
            <p class="stat-value h5 mb-1"><?= e($pct($overview['percent'])) ?></p>
            <p class="stat-meta">
                <span class="pill pill-<?= e(budget_indicator_tone($overview['indicator'])) ?>">
                    <?= e(budget_indicator_label($overview['indicator'])) ?>
                </span>
            </p>
        </section>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-8">
        <section class="app-card mb-3">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Anggaran per kategori pekerjaan</h2>
                    <p class="card-subtitle">
                        Rencana RAB disetujui dibandingkan belanja transaksi pada kategori yang sama.
                    </p>
                </div>
                <span class="card-count"><?= count($categories) ?> kategori</span>
            </header>
            <div class="table-responsive">
                <table class="table app-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Kategori</th>
                            <th scope="col" class="text-end">Rencana</th>
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
                                        <p class="empty-title">Belum ada pembanding</p>
                                        <p class="empty-text">
                                            Kategori muncul setelah RAB proyek ini disetujui atau transaksinya dicatat.
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
                                        &middot; <?= e((string) $category['item_count']) ?> item
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

        <section class="app-card mb-3">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Transaksi proyek</h2>
                    <p class="card-subtitle">
                        Hanya transaksi disetujui yang menambah realisasi; transaksi draft dan menunggu
                        ditampilkan agar jumlah yang akan masuk terlihat.
                    </p>
                </div>
                <span class="card-count"><?= e((string) $result['total']) ?> transaksi</span>
            </header>

            <div class="toolbar">
                <form class="toolbar-form" method="get" action="<?= url('/anggaran/' . $projectId) ?>">
                    <input type="hidden" name="pusat" value="<?= e($center) ?>">
                    <div class="toolbar-field toolbar-field-wide">
                        <label class="form-label" for="q">Cari</label>
                        <input class="form-control form-control-sm" type="search" id="q" name="q"
                               value="<?= e($txFilters['search']) ?>" maxlength="60"
                               placeholder="Nomor, uraian, atau penerima">
                    </div>
                    <div class="toolbar-field">
                        <label class="form-label" for="status">Status</label>
                        <select class="form-select form-select-sm" id="status" name="status">
                            <option value="">Semua status</option>
                            <?php foreach (\App\Models\FinancialTransaction::STATUSES as $status): ?>
                                <option value="<?= e($status) ?>" <?= $txFilters['status'] === $status ? 'selected' : '' ?>>
                                    <?= e(transaction_status_label($status)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="toolbar-field">
                        <label class="form-label" for="jenis">Jenis</label>
                        <select class="form-select form-select-sm" id="jenis" name="jenis">
                            <option value="">Semua jenis</option>
                            <?php foreach (\App\Models\FinancialTransaction::TYPES as $type): ?>
                                <option value="<?= e($type) ?>" <?= $txFilters['type'] === $type ? 'selected' : '' ?>>
                                    <?= e(transaction_type_label($type)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="toolbar-field">
                        <label class="form-label" for="kategori">Kategori</label>
                        <select class="form-select form-select-sm" id="kategori" name="kategori">
                            <option value="">Semua kategori</option>
                            <?php foreach ($filterCategories as $category): ?>
                                <option value="<?= e((string) $category['id']) ?>"
                                    <?= $txFilters['category'] === (string) $category['id'] ? 'selected' : '' ?>>
                                    <?= e((string) $category['category_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="toolbar-field">
                        <label class="form-label" for="dari">Dari</label>
                        <input class="form-control form-control-sm" type="date" id="dari" name="dari"
                               value="<?= e($txFilters['from']) ?>">
                    </div>
                    <div class="toolbar-field">
                        <label class="form-label" for="sampai">Sampai</label>
                        <input class="form-control form-control-sm" type="date" id="sampai" name="sampai"
                               value="<?= e($txFilters['to']) ?>">
                    </div>
                    <div class="toolbar-actions">
                        <button class="btn btn-sm btn-primary" type="submit">Terapkan</button>
                        <?php if ($hasTxFilter): ?>
                            <a class="btn btn-sm btn-link"
                               href="<?= query_url('/anggaran/' . $projectId, ['pusat' => $center]) ?>">Bersihkan</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <div class="table-responsive">
                <table class="table app-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Nomor</th>
                            <th scope="col">Tanggal</th>
                            <th scope="col">Uraian</th>
                            <th scope="col">Jenis</th>
                            <th scope="col" class="text-end">Nominal</th>
                            <th scope="col">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($rowsTx === []): ?>
                            <tr>
                                <td colspan="6" class="table-empty">
                                    <div class="empty-state">
                                        <i class="bi bi-cash-stack" aria-hidden="true"></i>
                                        <p class="empty-title">
                                            <?= $hasTxFilter ? 'Tidak ada transaksi yang cocok' : 'Belum ada transaksi' ?>
                                        </p>
                                        <p class="empty-text">
                                            <?= can('transactions.create')
                                                ? 'Catat pengeluaran pertama proyek ini pada halaman transaksi.'
                                                : 'Belum ada transaksi keuangan tercatat untuk penyaringan ini.' ?>
                                        </p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($rowsTx as $transaction): ?>
                            <tr>
                                <td class="cell-strong">
                                    <a href="<?= url('/transaksi/' . $transaction['id']) ?>">
                                        <?= e((string) $transaction['transaction_code']) ?>
                                    </a>
                                </td>
                                <td class="text-nowrap"><?= e(tanggal_id((string) $transaction['transaction_date'])) ?></td>
                                <td>
                                    <span class="cell-strong"><?= e((string) $transaction['description']) ?></span>
                                    <span class="cell-sub">
                                        <?= e($transaction['category_name'] !== null && $transaction['category_name'] !== ''
                                            ? (string) $transaction['category_name'] : 'Tanpa kategori') ?>
                                        &middot; <?= e((string) cost_center_label((string) $transaction['cost_center'])) ?>
                                        <?php if (($transaction['recipient'] ?? '') !== '' && $transaction['recipient'] !== null): ?>
                                            &middot; <?= e((string) $transaction['recipient']) ?>
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="pill pill-<?= e(transaction_type_tone($transaction['transaction_type'])) ?>">
                                        <?= e(transaction_type_label((string) $transaction['transaction_type'])) ?>
                                    </span>
                                </td>
                                <td class="text-end text-nowrap cell-strong"><?= e(rupiah($transaction['amount'])) ?></td>
                                <td>
                                    <span class="pill pill-<?= e(transaction_status_tone($transaction['status'])) ?>">
                                        <?= e(transaction_status_label((string) $transaction['status'])) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php
            $basePath = '/anggaran/' . $projectId;
            $query = [
                'pusat' => $txFilters['cost_center'],
                'q' => $txFilters['search'],
                'status' => $txFilters['status'],
                'jenis' => $txFilters['type'],
                'kategori' => $txFilters['category'],
                'dari' => $txFilters['from'],
                'sampai' => $txFilters['to'],
                'sort' => $txFilters['sort'],
                'dir' => $txFilters['dir'],
            ];
            $query = array_filter($query, static fn (string $value): bool => $value !== '');
            require BASE_PATH . '/app/Views/partials/pagination.php';
            ?>
        </section>

        <section class="app-card">
            <header class="card-head">
                <div>
                    <h2 class="card-title">RAB pada proyek ini</h2>
                    <p class="card-subtitle">
                        RAB disetujui menjadi dasar anggaran; versi revisi ditampilkan beserta statusnya.
                    </p>
                </div>
                <span class="card-count"><?= count($plans) ?> RAB</span>
            </header>
            <div class="table-responsive">
                <table class="table app-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Kode RAB</th>
                            <th scope="col">Periode</th>
                            <th scope="col" class="text-end">Item</th>
                            <th scope="col" class="text-end">Total</th>
                            <th scope="col">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($plans === []): ?>
                            <tr>
                                <td colspan="5" class="table-empty">
                                    <div class="empty-state">
                                        <i class="bi bi-file-earmark-text" aria-hidden="true"></i>
                                        <p class="empty-title">Belum ada RAB</p>
                                        <p class="empty-text">
                                            <?= can('budgets.create')
                                                ? 'Susun RAB agar anggaran proyek memiliki dasar yang dapat dipertanggungjawabkan.'
                                                : 'Rencana anggaran biaya belum disusun untuk proyek ini.' ?>
                                        </p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($plans as $plan): ?>
                            <tr>
                                <td class="cell-strong">
                                    <a href="<?= url('/rab/' . $plan['id']) ?>">
                                        <?= e((string) $plan['rab_code']) ?>
                                    </a>
                                    <span class="cell-sub">
                                        <?= e((string) $plan['name']) ?> &middot; versi <?= e((string) $plan['version']) ?>
                                    </span>
                                </td>
                                <td class="text-nowrap">
                                    <?= e(tanggal_id((string) $plan['period_start'])) ?>
                                    &ndash; <?= e(tanggal_id((string) $plan['period_end'])) ?>
                                </td>
                                <td class="text-end"><?= e((string) $plan['item_count']) ?></td>
                                <td class="text-end text-nowrap"><?= e(rupiah($plan['total_amount'])) ?></td>
                                <td>
                                    <span class="pill pill-<?= e(budget_status_tone($plan['status'])) ?>">
                                        <?= e(budget_status_label((string) $plan['status'])) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <div class="col-12 col-xl-4">
        <section class="app-card mb-3">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Data proyek</h2>
                </div>
            </header>
            <dl class="info-list">
                <div>
                    <dt>Kode</dt>
                    <dd><?= e((string) $project['project_code']) ?></dd>
                </div>
                <div>
                    <dt>Status</dt>
                    <dd>
                        <span class="pill pill-<?= e(project_status_tone($project['status'])) ?>">
                            <?= e(project_status_label((string) $project['status'])) ?>
                        </span>
                    </dd>
                </div>
                <div>
                    <dt>Penanggung jawab</dt>
                    <dd class="text-start"><?= e($project['pic_user_name'] ?? $project['pic_name'] ?? '—') ?></dd>
                </div>
                <div>
                    <dt>Kontraktor</dt>
                    <dd class="text-start"><?= e($project['contractor'] !== null && $project['contractor'] !== ''
                        ? (string) $project['contractor'] : '—') ?></dd>
                </div>
                <div>
                    <dt>Mulai &amp; target</dt>
                    <dd class="text-start">
                        <?= e(tanggal_id((string) $project['start_date'])) ?>
                        &ndash; <?= e(tanggal_id((string) $project['target_date'])) ?>
                    </dd>
                </div>
                <div>
                    <dt>Progres fisik</dt>
                    <dd><?= e((string) $project['progress']) ?>%</dd>
                </div>
                <div>
                    <dt>RAB disetujui</dt>
                    <dd><?= e((string) $overview['approved_plan_count']) ?> dokumen</dd>
                </div>
                <div>
                    <dt>Total RAB semua status</dt>
                    <dd class="text-start"><?= e(rupiah($overview['planned_all_status'])) ?></dd>
                </div>
                <div>
                    <dt>Pemasukan sumber dana</dt>
                    <dd class="text-start"><?= e(rupiah($overview['income'])) ?></dd>
                </div>
            </dl>
            <p class="muted-note">
                <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                Progres fisik <?= e((string) $project['progress']) ?>% dan penyerapan anggaran <?= e($pct($overview['percent'])) ?>
                dilaporkan berdampingan agar keduanya dapat dibandingkan.
            </p>
        </section>

        <?php if (can('budgets.revision')): ?>
            <section class="app-card mb-3" id="ajukan">
                <header class="card-head">
                    <div>
                        <h2 class="card-title">Ajukan perubahan anggaran</h2>
                        <p class="card-subtitle">
                            Nilai lama diambil dari database, bukan dari formulir, dan perubahan baru berlaku
                            setelah pemeriksa menyetujuinya.
                        </p>
                    </div>
                </header>
                <form class="app-form" method="post" action="<?= url('/anggaran/' . $projectId . '/perubahan') ?>">
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label class="form-label" for="proposed_amount">Usulan anggaran baru</label>
                        <input class="form-control" type="text" id="proposed_amount" name="proposed_amount"
                               required maxlength="30" inputmode="numeric"
                               value="<?= e(number_format((float) $project['budget_amount'], 0, ',', '.')) ?>">
                        <small class="form-text text-muted">
                            Nilai berlaku sekarang <?= e(rupiah($project['budget_amount'])) ?>.
                            Boleh ditulis 1.250.000.000 atau 1250000000.
                        </small>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="reason">Alasan perubahan</label>
                        <textarea class="form-control" id="reason" name="reason" rows="4"
                                  required minlength="10" maxlength="500"
                                  placeholder="Contoh: penambahan pekerjaan area dapur sesuai hasil review lapangan."></textarea>
                        <small class="form-text text-muted">Wajib diisi, minimal 10 karakter.</small>
                    </div>
                    <div class="form-actions">
                        <button class="btn btn-primary" type="submit">Kirim usulan</button>
                    </div>
                </form>
            </section>
        <?php else: ?>
            <section class="app-card mb-3" id="ajukan">
                <header class="card-head">
                    <div>
                        <h2 class="card-title">Ajukan perubahan anggaran</h2>
                    </div>
                </header>
                <p class="muted-note">
                    Hanya pengguna dengan hak <code>budgets.revision</code> yang dapat mengajukan perubahan anggaran.
                </p>
            </section>
        <?php endif; ?>

        <section class="app-card mb-3">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Riwayat perubahan anggaran</h2>
                    <p class="card-subtitle">Setiap usulan beserta keputusan dan alasannya.</p>
                </div>
                <span class="card-count"><?= count($revisions) ?> catatan</span>
            </header>
            <?php if ($revisions === []): ?>
                <div class="empty-state empty-state-block">
                    <i class="bi bi-clock-history" aria-hidden="true"></i>
                    <p class="empty-title">Anggaran belum pernah diubah</p>
                    <p class="empty-text">
                        Nilai <?= e(rupiah($overview['initial_budget'])) ?> masih merupakan nilai yang berlaku sejak proyek dibuat.
                    </p>
                </div>
            <?php else: ?>
                <div class="timeline-list">
                    <?php foreach ($revisions as $revision): ?>
                        <article class="timeline-item">
                            <div class="timeline-head">
                                <span class="timeline-date"><?= e(tanggal_id((string) $revision['requested_at'])) ?></span>
                                <span class="pill pill-<?= e(budget_status_tone($revision['status'])) ?>">
                                    <?= e(budget_status_label((string) $revision['status'])) ?>
                                </span>
                            </div>
                            <p class="timeline-title">
                                <a href="<?= url('/anggaran/perubahan/' . $revision['id']) ?>">
                                    <?= e((string) $revision['revision_code']) ?>
                                </a>
                            </p>
                            <p class="timeline-text">
                                <?= e(rupiah($revision['previous_amount'])) ?>
                                &rarr; <?= e(rupiah($revision['proposed_amount'])) ?>
                            </p>
                            <p class="timeline-text">
                                <?= e((string) $revision['reason']) ?>
                            </p>
                            <p class="timeline-foot">
                                Diajukan oleh <?= e($revision['requester_name'] ?? '—') ?>
                                <?php if (($revision['decider_name'] ?? null) !== null): ?>
                                    &middot; Diputus oleh <?= e((string) $revision['decider_name']) ?>
                                    <?= e(tanggal_id((string) $revision['decided_at'])) ?>
                                <?php endif; ?>
                            </p>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="app-card">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Riwayat status proyek</h2>
                </div>
            </header>
            <?php if ($history === []): ?>
                <div class="empty-state empty-state-block">
                    <i class="bi bi-diary" aria-hidden="true"></i>
                    <p class="empty-title">Belum ada riwayat status</p>
                    <p class="empty-text">Riwayat terbentuk saat proyek dibuat atau statusnya diubah.</p>
                </div>
            <?php else: ?>
                <div class="timeline-list">
                    <?php foreach ($history as $entry): ?>
                        <article class="timeline-item">
                            <div class="timeline-head">
                                <span class="timeline-date"><?= e(tanggal_id((string) $entry['changed_at'], true)) ?></span>
                                <span class="timeline-delta">
                                    <?= e($entry['from_status'] !== null && $entry['from_status'] !== ''
                                        ? project_status_label($entry['from_status']) : 'Awal') ?>
                                    &rarr; <?= e(project_status_label($entry['to_status'])) ?>
                                </span>
                            </div>
                            <p class="timeline-foot">
                                <?= e($entry['changed_by_name'] ?? 'Sistem') ?>
                                <?php if (($entry['reason'] ?? '') !== '' && $entry['reason'] !== null): ?>
                                    &middot; <?= e((string) $entry['reason']) ?>
                                <?php endif; ?>
                            </p>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>
