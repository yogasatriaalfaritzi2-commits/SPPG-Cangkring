<?php
$planId = (int) $plan['id'];
$status = (string) $plan['status'];
$projectId = (int) $plan['project_id'];
$isArchived = (int) $plan['is_project_archived'] === 1;
$actor = auth_user();
$actorId = (int) ($actor['id'] ?? 0);
$isMine = $actorId > 0 && (int) ($plan['submitted_by'] ?? 0) === $actorId;
$total = (float) $plan['total_amount'];
$canUpdate = can('budgets.update');
$canApprove = can('budgets.approve');
$canRevise = can('budgets.revision');
$editable = in_array($status, \App\Models\BudgetPlan::EDITABLE_STATUSES, true);
$approvalAction = [
    'submit' => 'Diajukan',
    'approve' => 'Disetujui',
    'reject' => 'Ditolak',
    'revise' => 'Direvisi',
];
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Keuangan &middot; RAB</p>
        <h1 class="page-title"><?= e((string) $plan['rab_code']) ?></h1>
        <p class="page-subtitle">
            <?= e((string) $plan['name']) ?> &middot;
            <a href="<?= url('/proyek/' . $projectId) ?>"><?= e((string) $plan['project_name']) ?></a>
            &middot; periode <?= e(tanggal_id((string) $plan['period_start'])) ?> &ndash;
            <?= e(tanggal_id((string) $plan['period_end'])) ?>
        </p>
    </div>
    <div class="page-actions">
        <?php if (can('budgets.export')): ?>
            <a class="btn btn-outline-secondary" href="<?= url('/rab/' . $planId . '/ekspor') ?>">
                <i class="bi bi-download me-1" aria-hidden="true"></i>Ekspor CSV
            </a>
        <?php endif; ?>
        <?php if ($canUpdate && $editable && !$isArchived): ?>
            <a class="btn btn-primary" href="<?= url('/rab/' . $planId . '/ubah') ?>">
                <i class="bi bi-pencil me-1" aria-hidden="true"></i>Sunting data RAB
            </a>
        <?php endif; ?>
        <a class="btn btn-outline-secondary" href="<?= url('/rab') ?>">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Kembali
        </a>
    </div>
</div>

<?php if ($isArchived): ?>
    <div class="notice-box" role="note">
        <i class="bi bi-archive" aria-hidden="true"></i>
        <p>Proyek ini sudah diarsipkan. Rincian tetap dapat dibaca, namun item dan status RAB dikunci oleh server.</p>
    </div>
<?php endif; ?>

<?php if (!$totalIsCurrent): ?>
    <div class="notice-box" role="alert">
        <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
        <p>
            Total tersimpan tidak sama dengan jumlah itemnya. Simpan satu perubahan item atau
            ajukan RAB ini agar server menghitung ulang totalnya.
        </p>
    </div>
<?php endif; ?>

<?php if (!$canEditItems && $status === \App\Models\BudgetPlan::STATUS_APPROVED): ?>
    <div class="notice-box" role="note">
        <i class="bi bi-shield-lock" aria-hidden="true"></i>
        <p>
            RAB yang sudah disetujui tidak dapat diedit langsung. Gunakan <strong>Buat revisi</strong>;
            RAB ini tetap menjadi dasar anggaran sampai versi revisinya disetujui.
        </p>
    </div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <section class="stat-card">
            <p class="stat-label">Total RAB</p>
            <p class="stat-value h5 mb-1"><?= e(rupiah($total)) ?></p>
            <p class="stat-meta">Jumlah <?= e((string) $itemCount) ?> item &times; dihitung server</p>
        </section>
    </div>
    <div class="col-6 col-lg-3">
        <section class="stat-card">
            <p class="stat-label">Status</p>
            <p class="stat-value h5 mb-1">
                <span class="pill pill-<?= e(budget_status_tone($status)) ?>">
                    <?= e(budget_status_label($status)) ?>
                </span>
            </p>
            <p class="stat-meta">Versi <?= e((string) $plan['version']) ?>
                <?php if (!empty($plan['revision_of_code'])): ?>
                    &middot; revisi dari <?= e((string) $plan['revision_of_code']) ?>
                <?php endif; ?>
            </p>
        </section>
    </div>
    <div class="col-6 col-lg-3">
        <section class="stat-card">
            <p class="stat-label">Realisasi proyek</p>
            <p class="stat-value h5 mb-1"><?= e(rupiah($summary['realization'])) ?></p>
            <p class="stat-meta">Hanya transaksi pembangunan berstatus disetujui</p>
        </section>
    </div>
    <div class="col-6 col-lg-3">
        <section class="stat-card">
            <p class="stat-label">Sisa anggaran</p>
            <p class="stat-value h5 mb-1"><?= e(rupiah($summary['remaining'])) ?></p>
            <p class="stat-meta">
                <span class="pill pill-<?= e(budget_indicator_tone($summary['indicator'])) ?>">
                    <?= e(budget_indicator_label($summary['indicator'])) ?>
                </span>
                <?= $summary['percent'] === null ? '' : ' · penyerapan ' . e(percent_id($summary['percent'])) ?>
            </p>
        </section>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-8">
        <section class="app-card">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Rincian item pekerjaan</h2>
                    <p class="card-subtitle">
                        Total item selalu volume &times; harga satuan dan dihitung ulang di server.
                    </p>
                </div>
                <span class="card-count"><?= count($items) ?> baris</span>
            </header>

            <form class="toolbar-form" method="get" action="<?= url('/rab/' . $planId) ?>">
                <div class="toolbar-field toolbar-field-wide">
                    <label class="form-label" for="q">Cari item</label>
                    <input class="form-control form-control-sm" type="search" id="q" name="q"
                           value="<?= e($search) ?>" maxlength="60"
                           placeholder="Kode, uraian, atau nama kategori">
                </div>
                <div class="toolbar-field">
                    <label class="form-label" for="kategori">Kategori</label>
                    <select class="form-select form-select-sm" id="kategori" name="kategori">
                        <option value="">Semua kategori</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= e((string) $category['id']) ?>"
                                <?= (string) $categoryFilter === (string) $category['id'] ? 'selected' : '' ?>>
                                <?= e((string) $category['category_code']) ?> &mdash; <?= e((string) $category['category_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="toolbar-actions">
                    <button class="btn btn-sm btn-primary" type="submit">Terapkan</button>
                    <?php if ($search !== '' || $categoryFilter !== ''): ?>
                        <a class="btn btn-sm btn-link" href="<?= url('/rab/' . $planId) ?>">Bersihkan</a>
                    <?php endif; ?>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table app-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Kode</th>
                            <th scope="col">Uraian pekerjaan</th>
                            <th scope="col" class="text-end">Volume</th>
                            <th scope="col">Satuan</th>
                            <th scope="col" class="text-end">Harga satuan</th>
                            <th scope="col" class="text-end">Total</th>
                            <th scope="col" class="text-end">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($items === []): ?>
                            <tr>
                                <td colspan="7" class="table-empty">
                                    <div class="empty-state">
                                        <i class="bi bi-list-task" aria-hidden="true"></i>
                                        <p class="empty-title"><?= $search !== '' || $categoryFilter !== '' ? 'Item tidak ditemukan' : 'Rincian masih kosong' ?></p>
                                        <p class="empty-text">
                                            <?= $canEditItems && $canUpdate
                                                ? 'Tambahkan item lewat formulir di bawah ini, atau impor berkas hasil ekspor.'
                                                : 'RAB ini belum memiliki item pekerjaan.' ?>
                                        </p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($items as $item): ?>
                            <tr id="item-<?= e((string) $item['id']) ?>">
                                <td class="cell-strong"><?= e((string) $item['item_code']) ?></td>
                                <td>
                                    <span class="cell-strong"><?= e((string) $item['item_name']) ?></span>
                                    <span class="cell-sub">
                                        <?= e((string) $item['category_code']) ?> &middot; <?= e((string) $item['category_name']) ?>
                                        <?php if (($item['notes'] ?? '') !== '' && $item['notes'] !== null): ?>
                                            <br><?= e((string) $item['notes']) ?>
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td class="text-end"><?= e(volume_display((string) $item['volume'])) ?></td>
                                <td><?= e((string) $item['unit']) ?></td>
                                <td class="text-end"><?= e(rupiah($item['unit_price'])) ?></td>
                                <td class="text-end cell-strong"><?= e(rupiah($item['total_price'])) ?></td>
                                <td class="text-end cell-actions">
                                    <?php if ($canEditItems && $canUpdate): ?>
                                        <a class="btn btn-sm btn-outline-secondary"
                                           href="<?= query_url('/rab/' . $planId, ['sunting' => (string) $item['id']]) ?>">
                                            Sunting
                                        </a>
                                        <form method="post" action="<?= url('/item/' . $item['id'] . '/duplikat') ?>"
                                              class="d-inline">
                                            <?= csrf_field() ?>
                                            <button class="btn btn-sm btn-outline-secondary" type="submit">Salin</button>
                                        </form>
                                        <form method="post" action="<?= url('/item/' . $item['id'] . '/hapus') ?>"
                                              class="d-inline">
                                            <?= csrf_field() ?>
                                            <button class="btn btn-sm btn-outline-danger" type="submit"
                                                    data-confirm
                                                    data-confirm-title="Hapus item RAB"
                                                    data-confirm-text="<?= e('Hapus "' . $item['item_code'] . ' — ' . $item['item_name'] . '"? Total RAB dihitung ulang oleh server.') ?>"
                                                    data-confirm-label="Ya, hapus"
                                                    data-confirm-tone="danger">
                                                Hapus
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="cell-sub">Terkunci</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <?php if ($items !== []): ?>
                        <tfoot>
                            <tr>
                                <th colspan="5" scope="row">Jumlah item yang tampil</th>
                                <td class="text-end cell-strong">
                                    <?= e(rupiah(array_sum(array_map(
                                        static fn (array $row): float => (float) $row['total_price'],
                                        $items
                                    )))) ?>
                                </td>
                                <td></td>
                            </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </section>

        <?php if ($categoryTotals !== []): ?>
            <section class="app-card">
                <header class="card-head">
                    <div>
                        <h2 class="card-title">Rekap per kategori</h2>
                        <p class="card-subtitle">Dasar anggaran per kategori pekerjaan.</p>
                    </div>
                </header>
                <div class="table-responsive">
                    <table class="table app-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">Kategori</th>
                                <th scope="col" class="text-end">Item</th>
                                <th scope="col" class="text-end">Subtotal</th>
                                <th scope="col">Porsi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($categoryTotals as $row): ?>
                                <?php
                                $subtotal = (float) $row['subtotal'];
                                $share = $total > 0.0 ? round($subtotal / $total * 100, 1) : 0.0;
                                ?>
                                <tr>
                                    <td>
                                        <span class="cell-strong"><?= e((string) $row['category_code']) ?></span>
                                        <span class="cell-sub"><?= e((string) $row['category_name']) ?></span>
                                    </td>
                                    <td class="text-end"><?= e((string) $row['item_count']) ?></td>
                                    <td class="text-end cell-strong"><?= e(rupiah($subtotal)) ?></td>
                                    <td class="progress-cell">
                                        <div class="progress app-progress" role="progressbar"
                                             aria-label="Porsi kategori <?= e((string) $row['category_name']) ?>"
                                             aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= e((string) $share) ?>">
                                            <div class="progress-bar" style="width: <?= e((string) $share) ?>%"></div>
                                        </div>
                                        <span class="cell-sub"><?= $total > 0.0 ? e(percent_id($share)) : '—' ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($canEditItems && $canUpdate): ?>
            <?php
            $isEditing = $editing !== null;
            $itemForm = $isEditing ? $editing : \App\Models\BudgetItem::emptyRecord();
            $itemAction = $isEditing
                ? url('/item/' . $editing['id'] . '/ubah')
                : url('/rab/' . $planId . '/item');
            ?>
            <section class="app-card" id="form-item">
                <header class="card-head">
                    <div>
                        <h2 class="card-title"><?= $isEditing ? 'Sunting item' : 'Tambah item pekerjaan' ?></h2>
                        <p class="card-subtitle">
                            <?= $isEditing
                                ? 'Item ' . e((string) $editing['item_code']) . ' — total dihitung ulang setelah disimpan.'
                                : 'Kode item boleh dikosongkan; sistem membuat nomor berikutnya sesuai kategori.' ?>
                        </p>
                    </div>
                </header>
                <form class="app-form" method="post" action="<?= e($itemAction) ?>" data-live-total>
                    <?= csrf_field() ?>
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <div class="form-group mb-0">
                                <label class="form-label" for="category_id">Kategori pekerjaan *</label>
                                <select class="form-select" id="category_id" name="category_id" required>
                                    <option value="">Pilih kategori</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?= e((string) $category['id']) ?>"
                                            <?= (string) $itemForm['category_id'] === (string) $category['id'] ? ' selected' : '' ?>>
                                            <?= e((string) $category['category_code']) ?> &mdash; <?= e((string) $category['category_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="col-12 col-md-6">
                            <div class="form-group mb-0">
                                <label class="form-label" for="item_code">Kode item</label>
                                <input class="form-control" type="text" id="item_code" name="item_code"
                                       value="<?= e((string) $itemForm['item_code']) ?>" maxlength="30"
                                       pattern="[A-Za-z0-9][A-Za-z0-9._\-]{1,29}">
                                <div class="form-text">Contoh PREP-01. Unik dalam satu RAB.</div>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="form-group mb-0">
                                <label class="form-label" for="item_name">Uraian pekerjaan *</label>
                                <input class="form-control" type="text" id="item_name" name="item_name"
                                       value="<?= e((string) $itemForm['item_name']) ?>" maxlength="180" required>
                            </div>
                        </div>

                        <div class="col-12 col-md-3">
                            <div class="form-group mb-0">
                                <label class="form-label" for="volume">Volume *</label>
                                <input class="form-control" type="text" id="volume" name="volume" inputmode="decimal"
                                       value="<?= e((string) $itemForm['volume']) ?>" data-live-volume required>
                            </div>
                        </div>

                        <div class="col-12 col-md-3">
                            <div class="form-group mb-0">
                                <label class="form-label" for="unit">Satuan *</label>
                                <input class="form-control" type="text" id="unit" name="unit"
                                       value="<?= e((string) $itemForm['unit']) ?>" maxlength="30"
                                       placeholder="m2, m3, unit, lot" required>
                            </div>
                        </div>

                        <div class="col-12 col-md-3">
                            <div class="form-group mb-0">
                                <label class="form-label" for="unit_price">Harga satuan *</label>
                                <input class="form-control" type="text" id="unit_price" name="unit_price"
                                       inputmode="numeric" value="<?= e((string) $itemForm['unit_price']) ?>"
                                       data-live-price required>
                            </div>
                        </div>

                        <div class="col-12 col-md-3">
                            <div class="form-group mb-0">
                                <label class="form-label" for="live_total">Perkiraan total</label>
                                <output class="form-control text-end" id="live_total" for="volume unit_price">
                                    <?= e(rupiah((float) $itemForm['volume'] * (float) $itemForm['unit_price'])) ?>
                                </output>
                                <div class="form-text">Hanya bantuan tampilan; nilai resmi dihitung server.</div>
                            </div>
                        </div>

                        <div class="col-12 col-md-9">
                            <div class="form-group mb-0">
                                <label class="form-label" for="notes">Keterangan</label>
                                <input class="form-control" type="text" id="notes" name="notes"
                                       value="<?= e((string) ($itemForm['notes'] ?? '')) ?>" maxlength="255">
                            </div>
                        </div>

                        <div class="col-12 col-md-3">
                            <div class="form-group mb-0">
                                <label class="form-label" for="sort_order">Urutan tampil</label>
                                <input class="form-control" type="text" id="sort_order" name="sort_order"
                                       inputmode="numeric" value="<?= e((string) $itemForm['sort_order']) ?>">
                            </div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button class="btn btn-primary" type="submit">
                            <?= $isEditing ? 'Simpan perubahan item' : 'Tambah item' ?>
                        </button>
                        <?php if ($isEditing): ?>
                            <a class="btn btn-outline-secondary" href="<?= url('/rab/' . $planId) ?>#item-<?= e((string) $editing['id']) ?>">
                                Batal
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </section>
        <?php endif; ?>

        <?php if ($canEditItems && can('budgets.create')): ?>
            <section class="app-card">
                <header class="card-head">
                    <div>
                        <h2 class="card-title">Impor item dari CSV</h2>
                        <p class="card-subtitle">
                            Gunakan berkas hasil ekspor. Kolom total diabaikan karena server yang menghitung ulang.
                        </p>
                    </div>
                </header>
                <form class="app-form" method="post" enctype="multipart/form-data"
                      action="<?= url('/rab/' . $planId . '/impor') ?>">
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label class="form-label" for="berkas">Berkas CSV *</label>
                        <input class="form-control" type="file" id="berkas" name="berkas" accept=".csv,.txt" required>
                        <div class="form-text">Maksimal 2 MB. Baris judul boleh ada atau tidak.</div>
                    </div>
                    <div class="form-actions">
                        <button class="btn btn-primary" type="submit">Impor item</button>
                    </div>
                </form>
            </section>
        <?php endif; ?>
    </div>

    <div class="col-12 col-xl-4">
        <section class="app-card">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Data RAB</h2>
                    <p class="card-subtitle">Diisi dari kolom tabel budget_plans.</p>
                </div>
            </header>
            <dl class="info-list">
                <div>
                    <dt>Nomor RAB</dt>
                    <dd><?= e((string) $plan['rab_code']) ?></dd>
                </div>
                <div>
                    <dt>Proyek</dt>
                    <dd>
                        <a href="<?= url('/proyek/' . $projectId) ?>"><?= e((string) $plan['project_name']) ?></a>
                        <br><span class="cell-sub"><?= e((string) $plan['project_code']) ?></span>
                    </dd>
                </div>
                <div>
                    <dt>Periode anggaran</dt>
                    <dd>
                        <?= e(tanggal_id((string) $plan['period_start'])) ?> &ndash;
                        <?= e(tanggal_id((string) $plan['period_end'])) ?>
                    </dd>
                </div>
                <div>
                    <dt>Anggaran awal proyek</dt>
                    <dd><?= e(rupiah($plan['project_budget'] ?? 0)) ?></dd>
                </div>
                <div>
                    <dt>Dibuat oleh</dt>
                    <dd>
                        <?= e($plan['creator_name'] !== null && $plan['creator_name'] !== ''
                            ? (string) $plan['creator_name'] : 'Pengguna telah dihapus') ?>
                        <br><span class="cell-sub"><?= e(tanggal_id((string) $plan['created_at'], true)) ?></span>
                    </dd>
                </div>
                <div>
                    <dt>Terakhir diubah</dt>
                    <dd><?= e(tanggal_id((string) $plan['updated_at'], true)) ?></dd>
                </div>
                <div>
                    <dt>Catatan</dt>
                    <dd class="text-start">
                        <?= e($plan['notes'] !== null && $plan['notes'] !== '' ? (string) $plan['notes'] : '—') ?>
                    </dd>
                </div>
            </dl>
        </section>

        <section class="app-card">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Alur persetujuan</h2>
                    <p class="card-subtitle">
                        <?= $segregation
                            ? 'Pemisahan tugas aktif: pengaju tidak dapat menyetujui RAB sendiri.'
                            : 'Pemisahan tugas dinonaktifkan pada pengaturan.' ?>
                    </p>
                </div>
            </header>

            <dl class="info-list mb-3">
                <div>
                    <dt>Diajukan oleh</dt>
                    <dd>
                        <?= e($plan['submitter_name'] !== null && $plan['submitter_name'] !== ''
                            ? (string) $plan['submitter_name'] : 'Belum diajukan') ?>
                        <?php if ($plan['submitted_at'] !== null): ?>
                            <br><span class="cell-sub"><?= e(tanggal_id((string) $plan['submitted_at'], true)) ?></span>
                        <?php endif; ?>
                    </dd>
                </div>
                <div>
                    <dt>Diputus oleh</dt>
                    <dd>
                        <?= e($plan['reviewer_name'] !== null && $plan['reviewer_name'] !== ''
                            ? (string) $plan['reviewer_name'] : 'Belum diputus') ?>
                        <?php if ($plan['reviewed_at'] !== null): ?>
                            <br><span class="cell-sub"><?= e(tanggal_id((string) $plan['reviewed_at'], true)) ?></span>
                        <?php endif; ?>
                    </dd>
                </div>
                <div>
                    <dt>Catatan keputusan</dt>
                    <dd class="text-start">
                        <?= e($plan['decision_note'] !== null && $plan['decision_note'] !== ''
                            ? (string) $plan['decision_note'] : '—') ?>
                    </dd>
                </div>
            </dl>

            <?php if (($status === \App\Models\BudgetPlan::STATUS_DRAFT || $status === \App\Models\BudgetPlan::STATUS_REJECTED)
                && $canRevise && !$isArchived): ?>
                <form class="app-form" method="post" action="<?= url('/rab/' . $planId . '/ajukan') ?>">
                    <?= csrf_field() ?>
                    <div class="form-actions mt-0">
                        <button class="btn btn-primary w-100" type="submit" data-confirm
                                data-confirm-title="Ajukan RAB"
                                data-confirm-text="RAB akan dikunci dari perubahan dan dikirim ke pemeriksa. Server menghitung ulang totalnya saat diajukan."
                                data-confirm-label="Ya, ajukan"
                                data-confirm-tone="warning"
                                <?= $itemCount === 0 ? 'disabled' : '' ?>>
                            <i class="bi bi-send me-1" aria-hidden="true"></i>
                            <?= $itemCount === 0 ? 'Tambah item dahulu' : 'Ajukan untuk persetujuan' ?>
                        </button>
                    </div>
                    <?php if ($itemCount === 0): ?>
                        <p class="muted-note">RAB tanpa item tidak dapat diajukan.</p>
                    <?php endif; ?>
                </form>
            <?php endif; ?>

            <?php if ($status === \App\Models\BudgetPlan::STATUS_PENDING && $canApprove): ?>
                <form class="app-form" method="post" action="<?= url('/rab/' . $planId . '/keputusan') ?>">
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label class="form-label" for="note">Catatan keputusan</label>
                        <textarea class="form-control" id="note" name="note" rows="3" maxlength="500"
                                  placeholder="Wajib diisi bila RAB ditolak."></textarea>
                    </div>
                    <?php if ($segregation && $isMine): ?>
                        <p class="muted-note">
                            Anda pengaju RAB ini. Kebijakan pemisahan tugas menolak keputusan Anda di sisi server.
                        </p>
                    <?php endif; ?>
                    <div class="form-actions mt-0">
                        <button class="btn btn-success" type="submit" name="action" value="approve"
                                <?= $segregation && $isMine ? 'disabled' : '' ?> data-confirm
                                data-confirm-title="Setujui RAB"
                                data-confirm-text="<?= e('Nilai ' . rupiah($total) . ' akan menjadi dasar anggaran disetujui proyek ' . $plan['project_name'] . '.') ?>"
                                data-confirm-label="Ya, setujui"
                                data-confirm-tone="warning">
                            Setujui
                        </button>
                        <button class="btn btn-danger" type="submit" name="action" value="reject">
                            Tolak
                        </button>
                    </div>
                </form>
            <?php elseif ($status === \App\Models\BudgetPlan::STATUS_PENDING): ?>
                <p class="muted-note">Menunggu keputusan pemeriksa yang memiliki izin budgets.approve.</p>
            <?php endif; ?>

            <?php if (in_array($status, [\App\Models\BudgetPlan::STATUS_APPROVED, \App\Models\BudgetPlan::STATUS_REVISED], true)
                && $canRevise && !$isArchived): ?>
                <form class="app-form" method="post" action="<?= url('/rab/' . $planId . '/revisi') ?>">
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label class="form-label" for="revise_name">Nama RAB hasil revisi</label>
                        <input class="form-control" type="text" id="revise_name" name="name" maxlength="150"
                               value="<?= e((string) $plan['name']) ?> (revisi)">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="revise_note">Alasan revisi</label>
                        <textarea class="form-control" id="revise_note" name="note" rows="3" maxlength="1000"
                                  placeholder="Contoh: penyesuaian harga material dan penambahan pekerjaan sanitasi."></textarea>
                    </div>
                    <div class="form-actions mt-0">
                        <button class="btn btn-primary" type="submit" data-confirm
                                data-confirm-title="Buat revisi RAB"
                                data-confirm-text="Salinan RAB beserta itemnya dibuat sebagai draft baru. RAB ini tetap menjadi dasar anggaran sampai revisi disetujui."
                                data-confirm-label="Ya, buat revisi"
                                data-confirm-tone="warning">
                            <i class="bi bi-files me-1" aria-hidden="true"></i>Buat revisi
                        </button>
                    </div>
                </form>
            <?php endif; ?>

            <?php if ($canUpdate && $editable && !$isArchived && $itemCount === 0): ?>
                <form class="app-form" method="post" action="<?= url('/rab/' . $planId . '/hapus') ?>">
                    <?= csrf_field() ?>
                    <div class="form-actions mt-0">
                        <button class="btn btn-outline-danger" type="submit" data-confirm
                                data-confirm-title="Hapus RAB"
                                data-confirm-text="<?= e('Hapus RAB ' . $plan['rab_code'] . ' yang masih kosong? Riwayat persetujuannya ikut terhapus.') ?>"
                                data-confirm-label="Ya, hapus"
                                data-confirm-tone="danger">
                            Hapus RAB kosong
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </section>

        <section class="app-card">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Riwayat alur</h2>
                    <p class="card-subtitle">Setiap pengajuan, persetujuan, penolakan, dan revisi.</p>
                </div>
                <span class="card-count"><?= count($approvals) ?></span>
            </header>
            <?php if ($approvals === []): ?>
                <p class="muted-note">Belum ada tindakan persetujuan pada RAB ini.</p>
            <?php else: ?>
                <div class="timeline-list">
                    <?php foreach ($approvals as $log): ?>
                        <article class="timeline-item">
                            <div class="timeline-head">
                                <span class="timeline-date"><?= e(tanggal_id((string) $log['created_at'], true)) ?></span>
                                <span class="timeline-delta">
                                    <?= e($log['from_status'] !== '' && $log['from_status'] !== null
                                        ? budget_status_label((string) $log['from_status']) : 'Awal') ?>
                                    &rarr; <?= e(budget_status_label((string) $log['to_status'])) ?>
                                </span>
                            </div>
                            <p class="timeline-title">
                                <?= e($approvalAction[(string) $log['action']] ?? (string) $log['action']) ?>
                                oleh <?= e($log['actor_name'] !== null && $log['actor_name'] !== ''
                                    ? (string) $log['actor_name'] : 'Pengguna telah dihapus') ?>
                            </p>
                            <?php if (($log['note'] ?? null) !== null && $log['note'] !== ''): ?>
                                <p class="timeline-text"><?= e((string) $log['note']) ?></p>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <?php if ($revisions !== []): ?>
            <section class="app-card">
                <header class="card-head">
                    <div>
                        <h2 class="card-title">Versi turunan</h2>
                        <p class="card-subtitle">Revisi yang dibuat dari RAB ini.</p>
                    </div>
                    <span class="card-count"><?= count($revisions) ?></span>
                </header>
                <div class="table-responsive">
                    <table class="table app-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">Nomor</th>
                                <th scope="col" class="text-end">Total</th>
                                <th scope="col">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($revisions as $revision): ?>
                                <tr>
                                    <td>
                                        <a class="cell-strong" href="<?= url('/rab/' . $revision['id']) ?>">
                                            <?= e((string) $revision['rab_code']) ?>
                                        </a>
                                        <span class="cell-sub">
                                            v<?= e((string) $revision['version']) ?> &middot;
                                            <?= e($revision['creator_name'] !== null && $revision['creator_name'] !== ''
                                                ? (string) $revision['creator_name'] : 'Pengguna telah dihapus') ?>
                                        </span>
                                    </td>
                                    <td class="text-end"><?= e(rupiah($revision['total_amount'])) ?></td>
                                    <td>
                                        <span class="pill pill-<?= e(budget_status_tone($revision['status'])) ?>">
                                            <?= e(budget_status_label($revision['status'])) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

        <section class="app-card">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Dasar anggaran proyek</h2>
                    <p class="card-subtitle">
                        Realisasi hanya berasal dari transaksi berstatus disetujui dan dipisahkan
                        dari biaya operasional MBG.
                    </p>
                </div>
            </header>
            <dl class="info-list">
                <div>
                    <dt>Sumber dasar anggaran</dt>
                    <dd class="text-start"><?= e(budget_baseline_label($summary['baseline_source'])) ?></dd>
                </div>
                <div>
                    <dt>Anggaran disetujui</dt>
                    <dd><?= e(rupiah($summary['approved_budget'])) ?></dd>
                </div>
                <div>
                    <dt>Realisasi</dt>
                    <dd><?= e(rupiah($summary['realization'])) ?></dd>
                </div>
                <div>
                    <dt>Pemasukan sumber dana</dt>
                    <dd><?= e(rupiah($summary['income'])) ?></dd>
                </div>
                <div>
                    <dt>Sisa</dt>
                    <dd><?= e(rupiah($summary['remaining'])) ?></dd>
                </div>
                <div>
                    <dt>Penyerapan</dt>
                    <dd>
                        <?= $summary['percent'] === null ? '—' : e(percent_id($summary['percent'])) ?>
                        <span class="pill pill-<?= e(budget_indicator_tone($summary['indicator'])) ?>">
                            <?= e(budget_indicator_label($summary['indicator'])) ?>
                        </span>
                    </dd>
                </div>
                <div>
                    <dt>Batas peringatan</dt>
                    <dd>
                        <?= e((string) $summary['warning_percent']) ?> %
                        <br><span class="cell-sub">Dapat diubah di halaman Pengaturan</span>
                    </dd>
                </div>
            </dl>
            <p class="muted-note">
                <a href="<?= url('/anggaran/' . $projectId) ?>">Lihat rekap anggaran proyek ini</a>
                beserta riwayat perubahan anggarannya.
            </p>
        </section>
    </div>
</div>
