<?php
$sortLink = static function (string $key) use ($filters): string {
    $direction = $filters['sort'] === $key && $filters['dir'] === 'asc' ? 'desc' : 'asc';

    return query_url('/transaksi', [
        'q' => $filters['search'],
        'status' => $filters['status'],
        'jenis' => $filters['type'],
        'pusat' => $filters['cost_center'],
        'proyek' => $filters['project'],
        'kategori' => $filters['category'],
        'dari' => $filters['from'],
        'sampai' => $filters['to'],
        'sort' => $key,
        'dir' => $direction,
    ]);
};
$sortIcon = static function (string $key) use ($filters): string {
    if ($filters['sort'] !== $key) {
        return 'bi-arrow-down-up text-muted';
    }

    return $filters['dir'] === 'asc' ? 'bi-sort-down' : 'bi-sort-up';
};
$hasFilter = $filters['search'] !== '' || $filters['status'] !== '' || $filters['type'] !== ''
    || $filters['cost_center'] !== '' || $filters['project'] !== '' || $filters['category'] !== ''
    || $filters['from'] !== '' || $filters['to'] !== '';
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Keuangan</p>
        <h1 class="page-title">Transaksi Keuangan</h1>
        <p class="page-subtitle">
            Pengeluaran, pemasukan sumber dana, pengembalian, dan koreksi dicatat di sini.
            Realisasi proyek hanya menghitung transaksi berstatus disetujui, dan biaya pembangunan
            dilaporkan terpisah dari biaya operasional MBG.
        </p>
    </div>
    <div class="page-actions">
        <?php if (can('transactions.export')): ?>
            <a class="btn btn-outline-secondary"
               href="<?= query_url('/transaksi/ekspor', [
                   'status' => $filters['status'],
                   'jenis' => $filters['type'],
                   'pusat' => $filters['cost_center'],
                   'proyek' => $filters['project'],
                   'kategori' => $filters['category'],
                   'dari' => $filters['from'],
                   'sampai' => $filters['to'],
               ]) ?>">
                <i class="bi bi-download me-1" aria-hidden="true"></i>Ekspor CSV
            </a>
        <?php endif; ?>
        <?php if (can('transactions.create')): ?>
            <a class="btn btn-primary" href="<?= url('/transaksi/tambah') ?>">
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Catat transaksi
            </a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <section class="stat-card">
            <p class="stat-label">Transaksi tampil</p>
            <p class="stat-value"><?= e((string) $result['total']) ?></p>
            <p class="stat-meta">Halaman <?= e((string) $result['page']) ?> dari <?= e((string) $result['pages']) ?></p>
        </section>
    </div>
    <div class="col-6 col-lg-3">
        <section class="stat-card">
            <p class="stat-label">Menunggu persetujuan</p>
            <p class="stat-value"><?= e((string) $totals['pending']) ?></p>
            <p class="stat-meta">
                <?= $totals['pending'] > 0 && can('transactions.approve')
                    ? 'Perlu keputusan pemeriksa'
                    : 'Menunggu pemeriksa berizin' ?>
            </p>
        </section>
    </div>
    <div class="col-6 col-lg-3">
        <section class="stat-card">
            <p class="stat-label">Sudah disetujui</p>
            <p class="stat-value"><?= e((string) $totals['approved']) ?></p>
            <p class="stat-meta">Yang dihitung sebagai realisasi</p>
        </section>
    </div>
    <div class="col-6 col-lg-3">
        <section class="stat-card">
            <p class="stat-label">Realisasi tercatat</p>
            <p class="stat-value h5 mb-1"><?= e(rupiah($overview['realization'])) ?></p>
            <p class="stat-meta">
                <span class="pill pill-<?= e(budget_indicator_tone($overview['indicator'])) ?>">
                    <?= e(budget_indicator_label($overview['indicator'])) ?>
                </span>
            </p>
        </section>
    </div>
</div>

<div class="toolbar">
    <form class="toolbar-form" method="get" action="<?= url('/transaksi') ?>">
        <div class="toolbar-field toolbar-field-wide">
            <label class="form-label" for="q">Cari</label>
            <input class="form-control form-control-sm" type="search" id="q" name="q"
                   value="<?= e($filters['search']) ?>" maxlength="60"
                   placeholder="Nomor, uraian, penerima, atau nomor referensi">
        </div>
        <div class="toolbar-field">
            <label class="form-label" for="status">Status</label>
            <select class="form-select form-select-sm" id="status" name="status">
                <option value="">Semua status</option>
                <?php foreach ($statuses as $status): ?>
                    <option value="<?= e($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>>
                        <?= e(transaction_status_label($status)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="toolbar-field">
            <label class="form-label" for="jenis">Jenis</label>
            <select class="form-select form-select-sm" id="jenis" name="jenis">
                <option value="">Semua jenis</option>
                <?php foreach ($types as $type): ?>
                    <option value="<?= e($type) ?>" <?= $filters['type'] === $type ? 'selected' : '' ?>>
                        <?= e(transaction_type_label($type)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="toolbar-field">
            <label class="form-label" for="pusat">Pusat biaya</label>
            <select class="form-select form-select-sm" id="pusat" name="pusat">
                <option value="">Pembangunan dan operasional</option>
                <?php foreach (\App\Models\FinancialTransaction::COST_CENTERS as $center): ?>
                    <option value="<?= e($center) ?>" <?= $filters['cost_center'] === $center ? 'selected' : '' ?>>
                        <?= e(cost_center_label($center)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="toolbar-field">
            <label class="form-label" for="proyek">Proyek</label>
            <select class="form-select form-select-sm" id="proyek" name="proyek">
                <option value="">Semua proyek</option>
                <?php foreach ($projects as $project): ?>
                    <option value="<?= e((string) $project['id']) ?>"
                        <?= $filters['project'] === (string) $project['id'] ? 'selected' : '' ?>>
                        <?= e((string) $project['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="toolbar-field">
            <label class="form-label" for="kategori">Kategori</label>
            <select class="form-select form-select-sm" id="kategori" name="kategori">
                <option value="">Semua kategori</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= e((string) $category['id']) ?>"
                        <?= $filters['category'] === (string) $category['id'] ? 'selected' : '' ?>>
                        <?= e((string) $category['category_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="toolbar-field">
            <label class="form-label" for="dari">Dari tanggal</label>
            <input class="form-control form-control-sm" type="date" id="dari" name="dari"
                   value="<?= e($filters['from']) ?>">
        </div>
        <div class="toolbar-field">
            <label class="form-label" for="sampai">Sampai tanggal</label>
            <input class="form-control form-control-sm" type="date" id="sampai" name="sampai"
                   value="<?= e($filters['to']) ?>">
        </div>
        <div class="toolbar-actions">
            <button class="btn btn-sm btn-primary" type="submit">Terapkan</button>
            <?php if ($hasFilter): ?>
                <a class="btn btn-sm btn-link" href="<?= url('/transaksi') ?>">Bersihkan</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<section class="app-card">
    <header class="card-head">
        <div>
            <h2 class="card-title">Daftar transaksi</h2>
            <p class="card-subtitle">
                Nominal, tanggal, dan status dibaca dari tabel financial_transactions.
            </p>
        </div>
        <span class="card-count"><?= count($transactions) ?> baris</span>
    </header>

    <div class="table-responsive">
        <table class="table app-table align-middle mb-0">
            <thead>
                <tr>
                    <th scope="col">
                        <a class="sort-link" href="<?= e($sortLink('code')) ?>">
                            Nomor <i class="bi <?= e($sortIcon('code')) ?>" aria-hidden="true"></i>
                        </a>
                    </th>
                    <th scope="col">
                        <a class="sort-link" href="<?= e($sortLink('date')) ?>">
                            Tanggal <i class="bi <?= e($sortIcon('date')) ?>" aria-hidden="true"></i>
                        </a>
                    </th>
                    <th scope="col">Uraian</th>
                    <th scope="col">
                        <a class="sort-link" href="<?= e($sortLink('type')) ?>">
                            Jenis <i class="bi <?= e($sortIcon('type')) ?>" aria-hidden="true"></i>
                        </a>
                    </th>
                    <th scope="col">
                        <a class="sort-link" href="<?= e($sortLink('project')) ?>">
                            Proyek <i class="bi <?= e($sortIcon('project')) ?>" aria-hidden="true"></i>
                        </a>
                    </th>
                    <th scope="col">
                        <a class="sort-link" href="<?= e($sortLink('amount')) ?>">
                            Nominal <i class="bi <?= e($sortIcon('amount')) ?>" aria-hidden="true"></i>
                        </a>
                    </th>
                    <th scope="col">
                        <a class="sort-link" href="<?= e($sortLink('status')) ?>">
                            Status <i class="bi <?= e($sortIcon('status')) ?>" aria-hidden="true"></i>
                        </a>
                    </th>
                    <th scope="col" class="text-end">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($transactions === []): ?>
                    <tr>
                        <td colspan="8" class="table-empty">
                            <div class="empty-state">
                                <i class="bi bi-cash-stack" aria-hidden="true"></i>
                                <p class="empty-title"><?= $hasFilter ? 'Tidak ada transaksi yang cocok' : 'Belum ada transaksi' ?></p>
                                <p class="empty-text">
                                    <?= can('transactions.create')
                                        ? 'Catat pengeluaran atau pemasukan pertama lewat tombol "Catat transaksi".'
                                        : 'Belum ada transaksi keuangan yang tercatat pada penyaringan ini.' ?>
                                </p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($transactions as $transaction): ?>
                    <tr>
                        <td class="cell-strong">
                            <a href="<?= url('/transaksi/' . $transaction['id']) ?>">
                                <?= e((string) $transaction['transaction_code']) ?>
                            </a>
                            <span class="cell-sub">
                                <?= (int) $transaction['attachment_count'] > 0
                                    ? $transaction['attachment_count'] . ' bukti' : 'Tanpa bukti' ?>
                            </span>
                        </td>
                        <td class="text-nowrap"><?= e(tanggal_id((string) $transaction['transaction_date'])) ?></td>
                        <td>
                            <span class="cell-strong"><?= e((string) $transaction['description']) ?></span>
                            <span class="cell-sub">
                                <?= e((string) transaction_type_label((string) $transaction['transaction_type'])) ?>
                                &middot; <?= e((string) cost_center_label((string) $transaction['cost_center'])) ?>
                                &middot; <?= e($transaction['category_name'] !== null && $transaction['category_name'] !== ''
                                    ? (string) $transaction['category_name'] : 'Tanpa kategori') ?>
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
                        <td>
                            <span class="cell-strong">
                                <a href="<?= url('/proyek/' . $transaction['project_id']) ?>">
                                    <?= e((string) $transaction['project_name']) ?>
                                </a>
                            </span>
                            <span class="cell-sub">
                                <?= e((string) $transaction['project_code']) ?>
                                <?php if (($transaction['rab_code'] ?? '') !== '' && $transaction['rab_code'] !== null): ?>
                                    &middot; <?= e((string) $transaction['rab_code']) ?>
                                <?php endif; ?>
                            </span>
                        </td>
                        <td class="text-end cell-strong"><?= e(rupiah($transaction['amount'])) ?></td>
                        <td>
                            <span class="pill pill-<?= e(transaction_status_tone($transaction['status'])) ?>">
                                <?= e(transaction_status_label((string) $transaction['status'])) ?>
                            </span>
                        </td>
                        <td class="text-end cell-actions">
                            <a class="btn btn-sm btn-outline-secondary" href="<?= url('/transaksi/' . $transaction['id']) ?>">
                                Rincian
                            </a>
                            <?php if (can('transactions.update')
                                && in_array((string) $transaction['status'], \App\Models\FinancialTransaction::EDITABLE_STATUSES, true)): ?>
                                <a class="btn btn-sm btn-outline-secondary"
                                   href="<?= url('/transaksi/' . $transaction['id'] . '/ubah') ?>">Sunting</a>
                            <?php endif; ?>
                            <?php if (can('transactions.export')): ?>
                                <a class="btn btn-sm btn-outline-secondary"
                                   href="<?= url('/transaksi/' . $transaction['id'] . '/cetak') ?>" target="_blank"
                                   rel="noopener">Cetak</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php
    $basePath = '/transaksi';
    $query = array_filter([
        'q' => $filters['search'],
        'status' => $filters['status'],
        'jenis' => $filters['type'],
        'pusat' => $filters['cost_center'],
        'proyek' => $filters['project'],
        'kategori' => $filters['category'],
        'dari' => $filters['from'],
        'sampai' => $filters['to'],
        'sort' => $filters['sort'],
        'dir' => $filters['dir'],
    ], static fn (string $value): bool => $value !== '');
    require BASE_PATH . '/app/Views/partials/pagination.php';
    ?>
</section>
