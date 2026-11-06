<?php
/**
 * View Index Menu MBG
 */
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Operasional MBG</p>
        <h1 class="page-title"><?= e($pageTitle) ?></h1>
        <p class="page-subtitle">Perancangan susunan menu makan bergizi, alur persetujuan ahli gizi, dan kalkulasi nilai nutrisi.</p>
    </div>
    <div class="page-actions d-flex gap-2">
        <a class="btn btn-outline-secondary" href="<?= url('/aturan-menu') ?>">
            <i class="bi bi-list-check me-1"></i>Aturan Menu
        </a>
        <a class="btn btn-outline-secondary" href="<?= url('/menu/rekomendasi') ?>">
            <i class="bi bi-stars me-1"></i>Rekomendasi Menu
        </a>
        <?php if ($canCreate): ?>
            <a class="btn btn-primary" href="<?= url('/menu/tambah') ?>">
                <i class="bi bi-plus-lg me-1"></i>Susun Menu Baru
            </a>
        <?php endif; ?>
    </div>
</div>

<section class="app-card">
    <div class="toolbar p-3 border-bottom">
        <form class="toolbar-form" method="get" action="<?= url('/menu') ?>">
            <div class="toolbar-field">
                <label class="form-label" for="q">Cari Menu</label>
                <input class="form-control form-control-sm" type="search" id="q" name="q" value="<?= e($filters['q'] ?? $filters['search'] ?? '') ?>" placeholder="Nama atau kode menu...">
            </div>
            <div class="toolbar-field">
                <label class="form-label" for="status">Status Persetujuan</label>
                <select class="form-select form-select-sm" id="status" name="status">
                    <option value="">Semua Status</option>
                    <?php foreach ($statuses as $st): ?>
                        <option value="<?= e($st) ?>" <?= ($filters['status'] ?? '') === $st ? 'selected' : '' ?>><?= e(menu_status_label($st)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="toolbar-actions">
                <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-funnel me-1"></i>Filter</button>
                <a class="btn btn-sm btn-link" href="<?= url('/menu') ?>">Reset</a>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table app-table align-middle mb-0">
            <thead>
                <tr>
                    <th>Kode</th>
                    <th>Nama Menu</th>
                    <th>Kategori</th>
                    <th>Energi (Kal)</th>
                    <th>Protein (g)</th>
                    <th>Biaya / Porsi</th>
                    <th>Status</th>
                    <th class="text-end">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($menus)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-5 text-muted">
                            <i class="bi bi-card-checklist fs-2 d-block mb-2"></i>
                            Belum ada perencanaan menu.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($menus as $m): ?>
                        <tr>
                            <td><code><?= e($m['menu_code']) ?></code></td>
                            <td>
                                <a href="<?= url('/menu/' . $m['id']) ?>" class="fw-bold text-decoration-none">
                                    <?= e($m['menu_name']) ?>
                                </a>
                                <?php if (!empty($m['version']) && (int)$m['version'] > 1): ?>
                                    <span class="badge bg-secondary-subtle text-secondary ms-1">v<?= (int)$m['version'] ?></span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge bg-light text-dark border"><?= e($m['category_name'] ?? '-') ?></span></td>
                            <td><?= number_format((float)($m['calculated_energy'] ?? 0), 0, ',', '.') ?> kkal</td>
                            <td><?= number_format((float)($m['calculated_protein'] ?? 0), 1, ',', '.') ?> g</td>
                            <td>Rp <?= number_format((float)($m['cost_per_portion'] ?? 0), 0, ',', '.') ?></td>
                            <td>
                                <span class="pill <?= status_badge_class((string)$m['menu_status']) ?>">
                                    <?= e(menu_status_label((string)$m['menu_status'])) ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-primary" href="<?= url('/menu/' . $m['id']) ?>">
                                    Detail
                                </a>
                                <?php if ($m['menu_status'] === 'draft' && (can('menus.update') || can('menus.manage'))): ?>
                                    <a class="btn btn-sm btn-outline-secondary" href="<?= url('/menu/' . $m['id'] . '/ubah') ?>">
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
    $basePath = $basePath ?? '/menu';
    $query = array_filter($filters, static fn ($v): bool => $v !== '');
    require BASE_PATH . '/app/Views/partials/pagination.php';
    ?>
</section>
