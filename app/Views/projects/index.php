<?php
$canBudget = can('budgets.view');
$sortLink = static function (string $key) use ($filters): string {
    $direction = $filters['sort'] === $key && $filters['dir'] === 'asc' ? 'desc' : 'asc';

    return query_url('/proyek', [
        'q' => $filters['search'],
        'status' => $filters['status'],
        'from' => $filters['from'],
        'to' => $filters['to'],
        'archived' => $filters['archived'],
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
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Pembangunan</p>
        <h1 class="page-title">Manajemen Proyek Pembangunan</h1>
    </div>
    <?php if (can('projects.create')): ?>
        <div class="page-actions">
            <a class="btn btn-primary" href="<?= url('/proyek/tambah') ?>">
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Tambah proyek
            </a>
        </div>
    <?php endif; ?>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <section class="stat-card">
            <p class="stat-label">Proyek aktif</p>
            <p class="stat-value"><?= e((string) $totals['total']) ?></p>
            <p class="stat-meta">Tanpa proyek terarsip</p>
        </section>
    </div>
    <div class="col-6 col-lg-3">
        <section class="stat-card">
            <p class="stat-label">Sedang berjalan</p>
            <p class="stat-value"><?= e((string) $totals['running']) ?></p>
            <p class="stat-meta"><?= e((string) $totals['overdue']) ?> proyek melewati target</p>
        </section>
    </div>
    <div class="col-6 col-lg-3">
        <section class="stat-card">
            <p class="stat-label">Selesai</p>
            <p class="stat-value"><?= e((string) $totals['completed']) ?></p>
            <p class="stat-meta"><?= e((string) $totals['cancelled']) ?> proyek dibatalkan</p>
        </section>
    </div>
    <div class="col-6 col-lg-3">
        <section class="stat-card">
            <p class="stat-label">Anggaran tercatat</p>
            <p class="stat-value"><?= $canBudget ? e(rupiah($totals['budget_total'])) : 'Dibatasi izin' ?></p>
            <p class="stat-meta"><?= $canBudget ? 'Total anggaran awal proyek aktif' : 'Peran Anda tidak memiliki izin budgets.view' ?></p>
        </section>
    </div>
</div>

<section class="app-card">
    <div class="toolbar">
        <form class="toolbar-form" method="get" action="<?= url('/proyek') ?>">
            <div class="toolbar-field toolbar-field-wide">
                <label class="form-label" for="q">Cari</label>
                <input class="form-control form-control-sm" type="search" id="q" name="q"
                       value="<?= e($filters['search']) ?>" maxlength="60"
                       placeholder="Kode, nama, lokasi, penanggung jawab, atau kontraktor">
            </div>
            <div class="toolbar-field">
                <label class="form-label" for="status">Status</label>
                <select class="form-select form-select-sm" id="status" name="status">
                    <option value="">Semua status</option>
                    <?php foreach ($statuses as $status): ?>
                        <option value="<?= e($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>>
                            <?= e(project_status_label($status)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="toolbar-field">
                <label class="form-label" for="from">Target mulai</label>
                <input class="form-control form-control-sm" type="date" id="from" name="from"
                       value="<?= e($filters['from']) ?>">
            </div>
            <div class="toolbar-field">
                <label class="form-label" for="to">Target akhir</label>
                <input class="form-control form-control-sm" type="date" id="to" name="to"
                       value="<?= e($filters['to']) ?>">
            </div>
            <div class="toolbar-field">
                <label class="form-label" for="archived">Arsip</label>
                <select class="form-select form-select-sm" id="archived" name="archived">
                    <option value="">Selain arsip</option>
                    <option value="1" <?= $filters['archived'] === '1' ? 'selected' : '' ?>>Hanya terarsip</option>
                    <option value="all" <?= $filters['archived'] === 'all' ? 'selected' : '' ?>>Semua proyek</option>
                </select>
            </div>
            <div class="toolbar-actions">
                <button class="btn btn-sm btn-primary" type="submit">
                    <i class="bi bi-search me-1" aria-hidden="true"></i>Tampilkan
                </button>
                <?php if ($filters['search'] !== '' || $filters['status'] !== '' || $filters['from'] !== ''
                    || $filters['to'] !== '' || $filters['archived'] !== ''): ?>
                    <a class="btn btn-sm btn-link" href="<?= url('/proyek') ?>">Bersihkan</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table app-table align-middle mb-0">
            <thead>
                <tr>
                    <th scope="col">
                        <a class="sort-link" href="<?= e($sortLink('code')) ?>">Kode
                            <i class="bi <?= e($sortIcon('code')) ?>" aria-hidden="true"></i></a>
                    </th>
                    <th scope="col">
                        <a class="sort-link" href="<?= e($sortLink('name')) ?>">Nama proyek
                            <i class="bi <?= e($sortIcon('name')) ?>" aria-hidden="true"></i></a>
                    </th>
                    <th scope="col">Lokasi</th>
                    <th scope="col">
                        <a class="sort-link" href="<?= e($sortLink('pic')) ?>">Penanggung jawab
                            <i class="bi <?= e($sortIcon('pic')) ?>" aria-hidden="true"></i></a>
                    </th>
                    <th scope="col">
                        <a class="sort-link" href="<?= e($sortLink('start')) ?>">Mulai
                            <i class="bi <?= e($sortIcon('start')) ?>" aria-hidden="true"></i></a>
                    </th>
                    <th scope="col">
                        <a class="sort-link" href="<?= e($sortLink('target')) ?>">Target selesai
                            <i class="bi <?= e($sortIcon('target')) ?>" aria-hidden="true"></i></a>
                    </th>
                    <?php if ($canBudget): ?>
                        <th scope="col" class="text-end">
                            <a class="sort-link" href="<?= e($sortLink('budget')) ?>">Anggaran
                                <i class="bi <?= e($sortIcon('budget')) ?>" aria-hidden="true"></i></a>
                        </th>
                    <?php endif; ?>
                    <th scope="col" style="min-width: 150px;">
                        <a class="sort-link" href="<?= e($sortLink('progress')) ?>">Progres
                            <i class="bi <?= e($sortIcon('progress')) ?>" aria-hidden="true"></i></a>
                    </th>
                    <th scope="col">
                        <a class="sort-link" href="<?= e($sortLink('status')) ?>">Status
                            <i class="bi <?= e($sortIcon('status')) ?>" aria-hidden="true"></i></a>
                    </th>
                    <th scope="col" class="text-end">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($projects === []): ?>
                    <tr>
                        <td colspan="<?= $canBudget ? 10 : 9 ?>" class="table-empty">
                            <div class="empty-state">
                                <i class="bi bi-inbox" aria-hidden="true"></i>
                                <p class="empty-title">Belum ada proyek</p>
                                <p class="empty-text">
                                    Tambah proyek pertama melalui tombol "Tambah proyek", atau ubah filter
                                    pencarian, status, periode, dan arsip di atas.
                                </p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($projects as $project): ?>
                    <?php
                    $progress = (int) $project['progress'];
                    $isArchived = (int) $project['is_archived'] === 1;
                    $isOverdue = (int) ($project['is_overdue'] ?? 0) === 1;
                    ?>
                    <tr>
                        <td class="text-nowrap">
                            <span class="cell-strong d-block"><?= e($project['project_code']) ?></span>
                            <?php if ($isArchived): ?>
                                <span class="pill pill-muted">Terarsip</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a class="cell-strong d-block" href="<?= url('/proyek/' . $project['id']) ?>">
                                <?= e($project['name']) ?>
                            </a>
                            <span class="cell-sub"><?= e($project['contractor'] !== null && $project['contractor'] !== ''
                                ? (string) $project['contractor']
                                : 'Kontraktor belum diisi') ?></span>
                        </td>
                        <td><?= e($project['location']) ?></td>
                        <td>
                            <span class="cell-strong d-block"><?= e($project['pic_name']) ?></span>
                            <span class="cell-sub"><?= e($project['pic_user_name'] !== null && $project['pic_user_name'] !== ''
                                ? 'Akun: ' . (string) $project['pic_user_name']
                                : 'Tidak tertaut akun') ?></span>
                        </td>
                        <td class="text-nowrap"><?= e(tanggal_id((string) $project['start_date'])) ?></td>
                        <td class="text-nowrap">
                            <?= e(tanggal_id((string) $project['target_date'])) ?>
                            <?php if ($isOverdue): ?>
                                <span class="cell-sub d-block text-warning">Lewat target</span>
                            <?php endif; ?>
                        </td>
                        <?php if ($canBudget): ?>
                            <td class="text-end text-nowrap"><?= e(rupiah($project['budget_amount'])) ?></td>
                        <?php endif; ?>
                        <td>
                            <div class="progress-cell">
                                <div class="progress app-progress" role="progressbar"
                                     aria-label="Progres proyek" aria-valuemin="0" aria-valuemax="100"
                                     aria-valuenow="<?= e((string) $progress) ?>">
                                    <div class="progress-bar" style="width: <?= e((string) $progress) ?>%"></div>
                                </div>
                                <span class="progress-value"><?= e((string) $progress) ?>%</span>
                            </div>
                        </td>
                        <td>
                            <span class="pill pill-<?= e(project_status_tone($project['status'])) ?>">
                                <?= e(project_status_label($project['status'])) ?>
                            </span>
                        </td>
                        <td>
                            <div class="cell-actions">
                                <a class="btn btn-sm btn-outline-secondary" href="<?= url('/proyek/' . $project['id']) ?>">Detail</a>

                                <?php if (can('projects.update') && !$isArchived): ?>
                                    <a class="btn btn-sm btn-outline-secondary" href="<?= url('/proyek/' . $project['id'] . '/ubah') ?>">Sunting</a>
                                <?php endif; ?>

                                <?php if (can('projects.archive')): ?>
                                    <form method="post" action="<?= url('/proyek/' . $project['id'] . '/arsip') ?>"
                                          data-confirm
                                          data-confirm-title="<?= $isArchived ? 'Pulihkan arsip' : 'Arsipkan proyek' ?>"
                                          data-confirm-text="<?= e($isArchived
                                              ? 'Pulihkan proyek "' . $project['project_code'] . '" agar kembali aktif pada daftar dan dashboard?'
                                              : 'Arsipkan proyek "' . $project['project_code'] . '"? Proyek tetap tersimpan beserta progres, dokumen, dan timnya.') ?>"
                                          data-confirm-label="<?= $isArchived ? 'Ya, pulihkan' : 'Ya, arsipkan' ?>"
                                          data-confirm-tone="warning">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="archive" value="<?= $isArchived ? '0' : '1' ?>">
                                        <button class="btn btn-sm btn-outline-secondary" type="submit">
                                            <?= $isArchived ? 'Pulihkan' : 'Arsipkan' ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php
    $basePath = '/proyek';
    $query = array_filter([
        'q' => $filters['search'],
        'status' => $filters['status'],
        'from' => $filters['from'],
        'to' => $filters['to'],
        'archived' => $filters['archived'],
        'sort' => $filters['sort'] === 'target' ? '' : $filters['sort'],
        'dir' => $filters['dir'],
    ], static fn (mixed $value): bool => $value !== '');
    require BASE_PATH . '/app/Views/partials/pagination.php';
    ?>
</section>
