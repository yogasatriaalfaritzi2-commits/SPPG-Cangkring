<?php
/**
 * View Detail Menu MBG
 */
$menuId = (int)$menu['id'];
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= url('/menu') ?>" class="text-decoration-none">← Kembali ke Daftar Menu</a></p>
        <h1 class="page-title"><?= e($menu['menu_name']) ?></h1>
        <p class="page-subtitle">
            Kode: <code><?= e($menu['menu_code']) ?></code> · 
            Kategori: <strong><?= e($menu['category_name'] ?? '-') ?></strong> · 
            Versi: <span class="badge bg-secondary">v<?= (int)($menu['version'] ?? 1) ?></span>
        </p>
    </div>
    <div class="page-actions d-flex gap-2">
        <?php if ($menu['menu_status'] === 'draft' && $canEdit): ?>
            <a class="btn btn-outline-secondary" href="<?= url('/menu/' . $menuId . '/ubah') ?>">
                <i class="bi bi-pencil me-1"></i>Sunting
            </a>
            <form method="post" action="<?= url('/menu/' . $menuId . '/kirim') ?>" class="d-inline">
                <?= csrf_field() ?>
                <button class="btn btn-success" type="submit">
                    <i class="bi bi-send me-1"></i>Ajukan ke Ahli Gizi
                </button>
            </form>
        <?php endif; ?>
        <?php if ($menu['menu_status'] === 'pending' && $canApprove): ?>
            <form method="post" action="<?= url('/menu/' . $menuId . '/keputusan') ?>" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="decision" value="approved">
                <button class="btn btn-success" type="submit">
                    <i class="bi bi-check-lg me-1"></i>Setujui Menu
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-md-3">
        <div class="app-card p-3">
            <span class="text-muted small">TOTAL ENERGI</span>
            <h2 class="h3 fw-bold mb-0 text-primary"><?= number_format((float)($computation['energy'] ?? 0), 0, ',', '.') ?> kkal</h2>
            <small class="text-muted">Per porsi sajian</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="app-card p-3">
            <span class="text-muted small">TOTAL PROTEIN</span>
            <h2 class="h3 fw-bold mb-0 text-success"><?= number_format((float)($computation['protein'] ?? 0), 1, ',', '.') ?> g</h2>
            <small class="text-muted">Per porsi sajian</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="app-card p-3">
            <span class="text-muted small">ESTIMASI BIAYA</span>
            <h2 class="h3 fw-bold mb-0">Rp <?= number_format((float)($computation['cost'] ?? 0), 0, ',', '.') ?></h2>
            <small class="text-muted">Per porsi bahan acuan</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="app-card p-3">
            <span class="text-muted small">STATUS MENU</span>
            <div><span class="pill <?= status_badge_class((string)$menu['menu_status']) ?> fs-6"><?= e(menu_status_label((string)$menu['menu_status'])) ?></span></div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Komposisi Resep Bahan -->
    <div class="col-12 col-lg-7">
        <section class="app-card mb-4">
            <div class="card-header p-3 border-bottom">
                <h2 class="h6 fw-bold mb-0"><i class="bi bi-basket me-2"></i>Komposisi Bahan Makanan (Per Porsi)</h2>
            </div>
            <div class="table-responsive">
                <table class="table app-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Bahan Pangan</th>
                            <th class="text-end">Takaran</th>
                            <th class="text-end">Energi</th>
                            <th class="text-end">Protein</th>
                            <th class="text-end">Est. Biaya</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($lines)): ?>
                            <tr>
                                <td colspan="5" class="text-center py-4 text-muted">Belum ada rincian bahan untuk menu ini.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($lines as $line): ?>
                                <tr>
                                    <td><strong><?= e($line['ingredient_name']) ?></strong></td>
                                    <td class="text-end"><?= (float)$line['quantity'] ?> <?= e($line['base_unit']) ?></td>
                                    <td class="text-end"><?= number_format((float)($line['energy'] ?? 0), 1, ',', '.') ?> kkal</td>
                                    <td class="text-end"><?= number_format((float)($line['protein'] ?? 0), 1, ',', '.') ?> g</td>
                                    <td class="text-end">Rp <?= number_format((float)($line['cost'] ?? 0), 0, ',', '.') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <!-- Hasil Penilaian Aturan Gizi -->
    <div class="col-12 col-lg-5">
        <section class="app-card p-3 mb-4">
            <h2 class="h6 fw-bold mb-3 border-bottom pb-2"><i class="bi bi-shield-check me-2"></i>Evaluasi Standar Aturan Menu</h2>
            <?php if (empty($findings)): ?>
                <div class="alert alert-success small mb-0">
                    <i class="bi bi-check-circle-fill me-1"></i> Semua kriteria aturan menu terpenuhi dengan baik.
                </div>
            <?php else: ?>
                <ul class="list-group list-group-flush small">
                    <?php foreach ($findings as $f): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                            <div>
                                <strong><?= e($f['rule_name'] ?? 'Standar Nutrisi') ?></strong>
                                <div class="text-muted"><?= e($f['message'] ?? '') ?></div>
                            </div>
                            <span class="badge <?= ($f['passed'] ?? false) ? 'bg-success' : 'bg-danger' ?>">
                                <?= ($f['passed'] ?? false) ? 'Sesuai' : 'Perlu Perbaikan' ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>
</div>
