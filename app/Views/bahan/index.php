<?php
/**
 * View Index Bahan Makanan
 */
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Operasional MBG</p>
        <h1 class="page-title"><?= e($pageTitle) ?></h1>
        <p class="page-subtitle">Katalog bahan makanan, konversi satuan, estimasi harga beli, dan kandungan nilai gizi.</p>
    </div>
    <div class="page-actions d-flex gap-2">
        <a class="btn btn-outline-secondary" href="<?= url('/referensi-gizi') ?>">
            <i class="bi bi-journal-bookmark me-1"></i>Referensi Gizi
        </a>
        <?php if (can('nutrition.export')): ?>
            <a class="btn btn-outline-secondary" href="<?= url('/bahan/ekspor') ?>">
                <i class="bi bi-download me-1"></i>Ekspor CSV
            </a>
        <?php endif; ?>
        <?php if ($canManage): ?>
            <a class="btn btn-primary" href="<?= url('/bahan/tambah') ?>">
                <i class="bi bi-plus-lg me-1"></i>Tambah Bahan
            </a>
        <?php endif; ?>
    </div>
</div>

<section class="app-card">
    <div class="toolbar p-3 border-bottom">
        <form class="toolbar-form" method="get" action="<?= url('/bahan') ?>">
            <div class="toolbar-field">
                <label class="form-label" for="q">Cari Bahan</label>
                <input class="form-control form-control-sm" type="search" id="q" name="q" value="<?= e($filters['q']) ?>" placeholder="Nama atau kode bahan...">
            </div>
            <div class="toolbar-field">
                <label class="form-label" for="category">Kategori</label>
                <select class="form-select form-select-sm" id="category" name="category">
                    <option value="">Semua Kategori</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= e($cat['id']) ?>" <?= (string)$filters['category'] === (string)$cat['id'] ? 'selected' : '' ?>>
                            <?= e($cat['category_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="toolbar-actions">
                <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-funnel me-1"></i>Filter</button>
                <a class="btn btn-sm btn-link" href="<?= url('/bahan') ?>">Reset</a>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table app-table align-middle mb-0">
            <thead>
                <tr>
                    <th>Kode</th>
                    <th>Nama Bahan Pangan</th>
                    <th>Kategori</th>
                    <th>Satuan Dasar</th>
                    <th>Satuan Beli</th>
                    <th>Harga Acuan</th>
                    <th class="text-end">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($ingredients)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">
                            <i class="bi bi-egg-fried fs-2 d-block mb-2"></i>
                            Belum ada katalog bahan pangan.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($ingredients as $ing): ?>
                        <tr>
                            <td><code><?= e($ing['ingredient_code']) ?></code></td>
                            <td>
                                <a href="<?= url('/bahan/' . $ing['id']) ?>" class="fw-bold text-decoration-none">
                                    <?= e($ing['ingredient_name']) ?>
                                </a>
                            </td>
                            <td><span class="badge bg-light text-dark border"><?= e($ing['category_name'] ?? '-') ?></span></td>
                            <td><?= e($ing['base_unit']) ?></td>
                            <td>
                                <?= e($ing['purchase_unit'] ?? $ing['base_unit']) ?>
                                <?php if (!empty($ing['conversion_factor']) && (float)$ing['conversion_factor'] != 1): ?>
                                    <div class="small text-muted">1 <?= e($ing['purchase_unit']) ?> = <?= (float)$ing['conversion_factor'] ?> <?= e($ing['base_unit']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($ing['reference_price'])): ?>
                                    Rp <?= number_format((float)$ing['reference_price'], 0, ',', '.') ?>
                                <?php else: ?>
                                    <span class="text-muted small">Belum diisi</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-primary" href="<?= url('/bahan/' . $ing['id']) ?>">
                                    Detail & Gizi
                                </a>
                                <?php if ($canManage): ?>
                                    <a class="btn btn-sm btn-outline-secondary" href="<?= url('/bahan/' . $ing['id'] . '/ubah') ?>">
                                        Sunting
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php
    $basePath = $basePath ?? '/bahan';
    $query = array_filter($filters, static fn ($v): bool => $v !== '');
    require BASE_PATH . '/app/Views/partials/pagination.php';
    ?>
</section>
