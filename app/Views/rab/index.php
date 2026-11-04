<?php
$sortLink = static function (string $key) use ($filters): string {
    $direction = $filters['sort'] === $key && $filters['dir'] === 'asc' ? 'desc' : 'asc';

    return query_url('/rab', [
        'q' => $filters['search'],
        'status' => $filters['status'],
        'proyek' => $filters['project'],
        'kategori' => $filters['category'],
        'tahun' => $filters['year'],
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
$hasFilter = $filters['search'] !== '' || $filters['status'] !== '' || $filters['project'] !== ''
    || $filters['category'] !== '' || $filters['year'] !== '';
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Keuangan</p>
        <h1 class="page-title">Rencana Anggaran Biaya</h1>
        <p class="page-subtitle">
            Setiap RAB beserta itemnya dibaca dari database. Total dihitung ulang di server
            dari volume dikali harga satuan, bukan dari nilai yang dikirim browser.
        </p>
    </div>
    <?php if (can('budgets.create')): ?>
        <div class="page-actions">
            <a class="btn btn-primary" href="<?= url('/rab/tambah') ?>">
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Buat RAB
            </a>
        </div>
    <?php endif; ?>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <section class="stat-card">
            <p class="stat-label">Total RAB tercatat</p>
            <p class="stat-value"><?= e((string) $result['total']) ?></p>
            <p class="stat-meta">Seluruh versi RAB beserta draftnya</p>
        </section>
    </div>
    <div class="col-6 col-lg-3">
        <section class="stat-card">
            <p class="stat-label">Menunggu persetujuan</p>
            <p class="stat-value"><?= e((string) $pendingCount) ?></p>
            <p class="stat-meta">
                <?= $pendingCount > 0 && can('budgets.approve')
                    ? 'Perlu keputusan pemeriksa'
                    : 'Tidak ada pengajuan tertahan' ?>
            </p>
        </section>
    </div>
    <div class="col-6 col-lg-3">
        <section class="stat-card">
            <p class="stat-label">Kategori pekerjaan</p>
            <p class="stat-value"><?= e((string) count($categories)) ?></p>
            <p class="stat-meta">
                <a href="<?= url('/kategori') ?>">Kelola kategori</a>
            </p>
        </section>
    </div>
    <div class="col-6 col-lg-3">
        <section class="stat-card">
            <p class="stat-label">Tahun anggaran</p>
            <p class="stat-value"><?= e((string) ($filters['year'] !== '' ? $filters['year'] : date('Y'))) ?></p>
            <p class="stat-meta"><?= e((string) count($years)) ?> tahun memiliki RAB</p>
        </section>
    </div>
</div>

<section class="app-card">
    <div class="toolbar">
        <form class="toolbar-form" method="get" action="<?= url('/rab') ?>">
            <div class="toolbar-field toolbar-field-wide">
                <label class="form-label" for="q">Cari</label>
                <input class="form-control form-control-sm" type="search" id="q" name="q"
                       value="<?= e($filters['search']) ?>" maxlength="60"
                       placeholder="Nomor RAB, nama, atau proyek">
            </div>
            <div class="toolbar-field">
                <label class="form-label" for="status">Status</label>
                <select class="form-select form-select-sm" id="status" name="status">
                    <option value="">Semua status</option>
                    <?php foreach ($statuses as $status): ?>
                        <option value="<?= e($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>>
                            <?= e(budget_status_label($status)) ?>
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
                            <?= e($project['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="toolbar-field">
                <label class="form-label" for="kategori">Kategori item</label>
                <select class="form-select form-select-sm" id="kategori" name="kategori">
                    <option value="">Semua kategori</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= e((string) $category['id']) ?>"
                                <?= $filters['category'] === (string) $category['id'] ? 'selected' : '' ?>>
                            <?= e($category['category_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="toolbar-field">
                <label class="form-label" for="tahun">Tahun</label>
                <select class="form-select form-select-sm" id="tahun" name="tahun">
                    <option value="">Semua tahun</option>
                    <?php foreach ($years as $year): ?>
                        <option value="<?= e((string) $year) ?>" <?= $filters['year'] === (string) $year ? 'selected' : '' ?>>
                            <?= e((string) $year) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="toolbar-actions">
                <button class="btn btn-sm btn-primary" type="submit">
                    <i class="bi bi-search me-1" aria-hidden="true"></i>Tampilkan
                </button>
                <?php if ($hasFilter): ?>
                    <a class="btn btn-sm btn-link" href="<?= url('/rab') ?>">Bersihkan</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table app-table align-middle mb-0">
            <thead>
                <tr>
                    <th scope="col">
                        <a class="sort-link" href="<?= e($sortLink('code')) ?>">Nomor RAB
                            <i class="bi <?= e($sortIcon('code')) ?>" aria-hidden="true"></i></a>
                    </th>
                    <th scope="col">
                        <a class="sort-link" href="<?= e($sortLink('name')) ?>">Nama RAB
                            <i class="bi <?= e($sortIcon('name')) ?>" aria-hidden="true"></i></a>
                    </th>
                    <th scope="col">
                        <a class="sort-link" href="<?= e($sortLink('project')) ?>">Proyek
                            <i class="bi <?= e($sortIcon('project')) ?>" aria-hidden="true"></i></a>
                    </th>
                    <th scope="col">
                        <a class="sort-link" href="<?= e($sortLink('period')) ?>">Periode
                            <i class="bi <?= e($sortIcon('period')) ?>" aria-hidden="true"></i></a>
                    </th>
                    <th scope="col" class="text-end">Item</th>
                    <th scope="col" class="text-end">
                        <a class="sort-link" href="<?= e($sortLink('total')) ?>">Total RAB
                            <i class="bi <?= e($sortIcon('total')) ?>" aria-hidden="true"></i></a>
                    </th>
                    <th scope="col">
                        <a class="sort-link" href="<?= e($sortLink('status')) ?>">Status
                            <i class="bi <?= e($sortIcon('status')) ?>" aria-hidden="true"></i></a>
                    </th>
                    <th scope="col" class="text-end">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($plans === []): ?>
                    <tr>
                        <td colspan="8" class="table-empty">
                            <div class="empty-state">
                                <i class="bi bi-file-earmark-text" aria-hidden="true"></i>
                                <p class="empty-title">Belum ada RAB</p>
                                <p class="empty-text">
                                    Buat RAB melalui tombol "Buat RAB", lalu isi itemnya pada halaman rincian.
                                    Kategori pekerjaan dapat disiapkan lebih dahulu di menu Kategori RAB.
                                </p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($plans as $plan): ?>
                    <tr>
                        <td class="text-nowrap">
                            <a class="cell-strong d-block" href="<?= url('/rab/' . $plan['id']) ?>">
                                <?= e($plan['rab_code']) ?>
                            </a>
                            <span class="cell-sub">v<?= e((string) $plan['version']) ?></span>
                        </td>
                        <td>
                            <span class="cell-strong d-block"><?= e($plan['name']) ?></span>
                            <span class="cell-sub">
                                <?= e($plan['creator_name'] !== null && $plan['creator_name'] !== ''
                                    ? 'Dibuat ' . (string) $plan['creator_name']
                                    : 'Pembuat tidak tercatat') ?>
                            </span>
                        </td>
                        <td>
                            <a class="cell-strong d-block" href="<?= url('/proyek/' . $plan['project_id']) ?>">
                                <?= e($plan['project_name']) ?>
                            </a>
                            <span class="cell-sub"><?= e($plan['project_code']) ?></span>
                        </td>
                        <td class="text-nowrap">
                            <?= e(tanggal_id((string) $plan['period_start'])) ?>
                            <span class="cell-sub d-block">s.d. <?= e(tanggal_id((string) $plan['period_end'])) ?></span>
                        </td>
                        <td class="text-end"><?= e((string) $plan['item_count']) ?></td>
                        <td class="text-end text-nowrap"><?= e(rupiah($plan['total_amount'])) ?></td>
                        <td>
                            <span class="pill pill-<?= e(budget_status_tone($plan['status'])) ?>">
                                <?= e(budget_status_label($plan['status'])) ?>
                            </span>
                        </td>
                        <td>
                            <div class="cell-actions">
                                <a class="btn btn-sm btn-outline-secondary" href="<?= url('/rab/' . $plan['id']) ?>">Rincian</a>
                                <?php if (can('budgets.update')
                                    && in_array((string) $plan['status'], \App\Models\BudgetPlan::EDITABLE_STATUSES, true)): ?>
                                    <a class="btn btn-sm btn-outline-secondary"
                                       href="<?= url('/rab/' . $plan['id'] . '/ubah') ?>">Sunting</a>
                                <?php endif; ?>
                                <?php if (can('budgets.export')): ?>
                                    <a class="btn btn-sm btn-outline-secondary"
                                       href="<?= url('/rab/' . $plan['id'] . '/ekspor') ?>">Ekspor</a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php
    $basePath = '/rab';
    $query = array_filter([
        'q' => $filters['search'],
        'status' => $filters['status'],
        'proyek' => $filters['project'],
        'kategori' => $filters['category'],
        'tahun' => $filters['year'],
        'sort' => $filters['sort'] === 'created' ? '' : $filters['sort'],
        'dir' => $filters['dir'],
    ], static fn (mixed $value): bool => $value !== '');
    require BASE_PATH . '/app/Views/partials/pagination.php';
    ?>
</section>
