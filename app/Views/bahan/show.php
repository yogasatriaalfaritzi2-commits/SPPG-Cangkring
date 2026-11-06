<?php
/**
 * View Detail Bahan Makanan & Kandungan Gizi
 */
$ingId = (int)$ingredient['id'];
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= url('/bahan') ?>" class="text-decoration-none">← Kembali ke Katalog Bahan</a></p>
        <h1 class="page-title"><?= e($ingredient['ingredient_name']) ?></h1>
        <p class="page-subtitle">
            Kode: <code><?= e($ingredient['ingredient_code']) ?></code> · 
            Kategori: <strong><?= e($ingredient['category_name'] ?? '-') ?></strong> · 
            Status Gizi: <span class="badge bg-light text-dark border"><?= e(ucfirst((string)$nutritionStatus)) ?></span>
        </p>
    </div>
    <div class="page-actions d-flex gap-2">
        <?php if ($canManage): ?>
            <a class="btn btn-outline-secondary" href="<?= url('/bahan/' . $ingId . '/ubah') ?>">
                <i class="bi bi-pencil me-1"></i>Sunting Bahan
            </a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-md-3">
        <div class="app-card p-3">
            <span class="text-muted small">SATUAN DASAR</span>
            <h2 class="h4 fw-bold mb-0 text-primary"><?= e($ingredient['base_unit']) ?></h2>
            <small class="text-muted">Digunakan dalam resep menu</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="app-card p-3">
            <span class="text-muted small">SATUAN BELI</span>
            <h2 class="h4 fw-bold mb-0"><?= e($ingredient['purchase_unit'] ?? $ingredient['base_unit']) ?></h2>
            <small class="text-muted">Faktor konversi: <?= (float)($ingredient['conversion_factor'] ?? 1) ?></small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="app-card p-3">
            <span class="text-muted small">HARGA ACUAN</span>
            <h2 class="h4 fw-bold mb-0">Rp <?= number_format((float)($ingredient['reference_price'] ?? 0), 0, ',', '.') ?></h2>
            <small class="text-muted">Per <?= e($ingredient['purchase_unit'] ?? $ingredient['base_unit']) ?></small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="app-card p-3">
            <span class="text-muted small">PEMAKAIAN MENU</span>
            <h2 class="h4 fw-bold mb-0"><?= (int)$menuUsage ?> Menu</h2>
            <small class="text-muted">Tercatat dalam resep aktif</small>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Tabel Kandungan Gizi -->
    <div class="col-12 col-lg-8">
        <section class="app-card mb-4">
            <div class="card-header p-3 border-bottom d-flex justify-content-between align-items-center">
                <h2 class="h6 fw-bold mb-0"><i class="bi bi-pie-chart-fill me-2"></i>Komposisi Nilai Gizi per 100 <?= e($ingredient['base_unit']) ?></h2>
            </div>
            <div class="table-responsive">
                <table class="table app-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Zat Gizi</th>
                            <th class="text-end">Jumlah</th>
                            <th>Satuan</th>
                            <th>Sumber Rujukan</th>
                            <?php if ($canManage): ?>
                                <th class="text-end">Aksi</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($nutrients)): ?>
                            <tr>
                                <td colspan="<?= $canManage ? 5 : 4 ?>" class="text-center py-4 text-muted">
                                    Belum ada data kandungan zat gizi untuk bahan ini.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($nutrients as $nut): ?>
                                <tr>
                                    <td><strong><?= e(nutrient_label((string)$nut['nutrient_code'])) ?></strong></td>
                                    <td class="text-end fw-bold text-primary"><?= (float)$nut['nutrient_value'] ?></td>
                                    <td><?= e($nut['nutrient_unit']) ?></td>
                                    <td>
                                        <small class="badge bg-light text-dark border">
                                            <?= e($nut['reference_code'] ?? ($nut['is_estimate'] ? 'Estimasi' : 'Rujukan')) ?>
                                        </small>
                                    </td>
                                    <?php if ($canManage): ?>
                                        <td class="text-end">
                                            <form method="post" action="<?= url('/bahan/gizi/' . (int)$nut['id'] . '/hapus') ?>" class="d-inline"
                                                  onsubmit="return confirm('Hapus zat gizi ini?');">
                                                <?= csrf_field() ?>
                                                <button class="btn btn-sm btn-outline-danger" type="submit">Hapus</button>
                                            </form>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <!-- Form Tambah Kandungan Gizi -->
    <?php if ($canManage): ?>
        <div class="col-12 col-lg-4">
            <section class="app-card p-3">
                <h2 class="h6 fw-bold mb-3"><i class="bi bi-plus-circle me-2"></i>Input Angka Gizi</h2>
                <form method="post" action="<?= url('/bahan/' . $ingId . '/gizi') ?>">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label" for="nutrient_code">Zat Gizi *</label>
                        <select class="form-select" id="nutrient_code" name="nutrient_code" required>
                            <option value="">Pilih Zat Gizi</option>
                            <?php foreach ($coreCodes as $cCode): ?>
                                <option value="<?= e($cCode) ?>"><?= e(nutrient_label($cCode)) ?> (<?= e($cCode) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-7">
                            <label class="form-label" for="nutrient_value">Nilai per 100 <?= e($ingredient['base_unit']) ?> *</label>
                            <input class="form-control" type="number" step="0.001" id="nutrient_value" name="nutrient_value" required>
                        </div>
                        <div class="col-5">
                            <label class="form-label" for="nutrient_unit">Satuan *</label>
                            <input class="form-control" type="text" id="nutrient_unit" name="nutrient_unit" value="g" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="reference_id">Sumber Rujukan *</label>
                        <select class="form-select" id="reference_id" name="reference_id">
                            <option value="">-- Pilih Referensi Resmi --</option>
                            <?php foreach ($references as $ref): ?>
                                <option value="<?= e($ref['id']) ?>"><?= e($ref['reference_name']) ?> (<?= e($ref['reference_code']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3 form-check">
                        <input class="form-check-input" type="checkbox" id="is_estimate" name="is_estimate" value="1">
                        <label class="form-check-label small" for="is_estimate">Nilai ini adalah perkiraan / estimasi ahli</label>
                    </div>
                    <button class="btn btn-primary w-100" type="submit">
                        <i class="bi bi-save me-1"></i>Simpan Angka Gizi
                    </button>
                </form>
            </section>
        </div>
    <?php endif; ?>
</div>
