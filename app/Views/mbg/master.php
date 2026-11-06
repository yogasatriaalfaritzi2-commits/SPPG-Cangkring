<?php
/**
 * View Data Master MBG (Jenjang, Kategori Penerima, Kategori Bahan, Kategori Menu)
 */
$kindsNav = [
    'jenjang' => 'Jenjang Sekolah',
    'kategori-penerima' => 'Kategori Penerima',
    'kategori-bahan' => 'Kategori Bahan',
    'kategori-menu' => 'Kategori Menu',
];
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Data Master MBG</p>
        <h1 class="page-title"><?= e($pageTitle) ?></h1>
        <p class="page-subtitle">Kelola klasifikasi, penamaan, dan status aktif master data operasional MBG.</p>
    </div>
</div>

<!-- Navigasi Jenis Master -->
<ul class="nav nav-tabs mb-4">
    <?php foreach ($kindsNav as $slug => $label): ?>
        <li class="nav-item">
            <a class="nav-link <?= $kind === $slug ? 'active fw-bold' : '' ?>" href="<?= url('/mbg/master/' . $slug) ?>">
                <?= e($label) ?>
            </a>
        </li>
    <?php endforeach; ?>
</ul>

<div class="row g-4">
    <!-- Tabel Data Master -->
    <div class="col-12 col-lg-8">
        <section class="app-card">
            <div class="toolbar p-3 border-bottom">
                <form class="toolbar-form" method="get" action="<?= url('/mbg/master/' . $kind) ?>">
                    <div class="toolbar-field">
                        <label class="form-label" for="q">Cari</label>
                        <input class="form-control form-control-sm" type="search" id="q" name="q" value="<?= e($term) ?>" placeholder="Kode atau nama...">
                    </div>
                    <div class="toolbar-field">
                        <label class="form-label" for="status">Status</label>
                        <select class="form-select form-select-sm" id="status" name="status">
                            <option value="">Semua Status</option>
                            <option value="active" <?= $state === 'active' ? 'selected' : '' ?>>Aktif</option>
                            <option value="inactive" <?= $state === 'inactive' ? 'selected' : '' ?>>Nonaktif</option>
                        </select>
                    </div>
                    <div class="toolbar-actions">
                        <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-funnel me-1"></i>Filter</button>
                        <?php if ($term !== '' || $state !== ''): ?>
                            <a class="btn btn-sm btn-link" href="<?= url('/mbg/master/' . $kind) ?>">Reset</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <div class="table-responsive">
                <table class="table app-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width: 15%;">Kode</th>
                            <th>Nama</th>
                            <th>Status</th>
                            <th>Penggunaan</th>
                            <?php if ($canManage): ?>
                                <th class="text-end" style="width: 20%;">Aksi</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr>
                                <td colspan="<?= $canManage ? 5 : 4 ?>" class="text-center py-4 text-muted">Belum ada data master yang sesuai.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rows as $item): ?>
                                <tr>
                                    <td><code><?= e($item[$type['code']] ?? '') ?></code></td>
                                    <td>
                                        <strong><?= e($item[$type['name']] ?? '') ?></strong>
                                        <?php if (!empty($item['description'])): ?>
                                            <div class="small text-muted"><?= e($item['description']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="pill <?= (int)($item['is_active'] ?? 1) === 1 ? 'pill-success' : 'pill-muted' ?>">
                                            <?= (int)($item['is_active'] ?? 1) === 1 ? 'Aktif' : 'Nonaktif' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?= number_format((int)($item['total_usage'] ?? 0), 0, ',', '.') ?> <?= e($type['usage_label'] ?? 'terkait') ?></small>
                                    </td>
                                    <?php if ($canManage): ?>
                                        <td class="text-end">
                                            <button class="btn btn-sm btn-outline-secondary" type="button" 
                                                    data-bs-toggle="collapse" data-bs-target="#edit-row-<?= (int)$item['id'] ?>" aria-expanded="false">
                                                Sunting
                                            </button>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                                <?php if ($canManage): ?>
                                    <tr class="collapse bg-light" id="edit-row-<?= (int)$item['id'] ?>">
                                        <td colspan="5" class="p-3">
                                            <form method="post" action="<?= url('/mbg/master/' . $kind . '/' . (int)$item['id'] . '/ubah') ?>">
                                                <?= csrf_field() ?>
                                                <div class="row g-2 align-items-end">
                                                    <div class="col-md-3">
                                                        <label class="form-label small">Kode</label>
                                                        <input class="form-control form-control-sm" type="text" name="code" value="<?= e($item[$type['code']] ?? '') ?>" required>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <label class="form-label small">Nama</label>
                                                        <input class="form-control form-control-sm" type="text" name="name" value="<?= e($item[$type['name']] ?? '') ?>" required>
                                                    </div>
                                                    <div class="col-md-2">
                                                        <label class="form-label small">Status</label>
                                                        <select class="form-select form-select-sm" name="is_active">
                                                            <option value="1" <?= (int)($item['is_active'] ?? 1) === 1 ? 'selected' : '' ?>>Aktif</option>
                                                            <option value="0" <?= (int)($item['is_active'] ?? 1) === 0 ? 'selected' : '' ?>>Nonaktif</option>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-3 d-flex gap-1">
                                                        <button class="btn btn-sm btn-primary w-100" type="submit">Simpan</button>
                                                    </div>
                                                </div>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <!-- Form Tambah Baru -->
    <?php if ($canManage): ?>
        <div class="col-12 col-lg-4">
            <section class="app-card p-3">
                <h2 class="h6 fw-bold mb-3"><i class="bi bi-plus-circle me-2"></i>Tambah <?= e($type['title']) ?></h2>
                <form method="post" action="<?= url('/mbg/master/' . $kind) ?>">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label" for="add-code">Kode *</label>
                        <input class="form-control" type="text" id="add-code" name="code" value="<?= e($form['code'] ?? '') ?>" placeholder="Misal: SD, KTG-01" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="add-name">Nama *</label>
                        <input class="form-control" type="text" id="add-name" name="name" value="<?= e($form['name'] ?? '') ?>" placeholder="Nama klasifikasi" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="add-desc">Keterangan / Deskripsi</label>
                        <textarea class="form-control" id="add-desc" name="description" rows="2"><?= e($form['description'] ?? '') ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="add-status">Status</label>
                        <select class="form-select" id="add-status" name="is_active">
                            <option value="1" selected>Aktif</option>
                            <option value="0">Nonaktif</option>
                        </select>
                    </div>
                    <button class="btn btn-primary w-100" type="submit">
                        <i class="bi bi-save me-1"></i>Simpan <?= e($type['title']) ?>
                    </button>
                </form>
            </section>
        </div>
    <?php endif; ?>
</div>
