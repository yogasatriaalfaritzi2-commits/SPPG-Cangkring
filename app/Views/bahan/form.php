<?php
/**
 * View Form Tambah/Ubah Bahan Makanan
 */
$isEdit = ($mode === 'update' || $mode === 'edit');
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= url('/bahan') ?>" class="text-decoration-none">← Kembali ke Katalog Bahan</a></p>
        <h1 class="page-title"><?= e($pageTitle) ?></h1>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-12 col-lg-8">
        <section class="app-card p-4">
            <form method="post" action="<?= $isEdit ? url('/bahan/' . $ingredient['id'] . '/ubah') : url('/bahan/tambah') ?>">
                <?= csrf_field() ?>
                
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label" for="ingredient_code">Kode Bahan *</label>
                        <input class="form-control" type="text" id="ingredient_code" name="ingredient_code" 
                               value="<?= e($form['ingredient_code'] ?? '') ?>" required <?= $isEdit ? 'readonly' : '' ?>>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label" for="ingredient_name">Nama Bahan *</label>
                        <input class="form-control" type="text" id="ingredient_name" name="ingredient_name" 
                               value="<?= e($form['ingredient_name'] ?? '') ?>" placeholder="Misal: Beras Putih, Daging Ayam" required>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label" for="category_id">Kategori Bahan *</label>
                        <select class="form-select" id="category_id" name="category_id" required>
                            <option value="">Pilih Kategori</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= e($cat['id']) ?>" <?= (string)($form['category_id'] ?? '') === (string)$cat['id'] ? 'selected' : '' ?>>
                                    <?= e($cat['category_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="base_unit">Satuan Dasar Resep *</label>
                        <select class="form-select" id="base_unit" name="base_unit" required>
                            <?php foreach ($units as $uKey => $uLabel): ?>
                                <option value="<?= e($uKey) ?>" <?= ($form['base_unit'] ?? 'gram') === $uKey ? 'selected' : '' ?>>
                                    <?= e($uLabel) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Satuan yang dipakai dalam penakaran resep (biasanya gram atau mililiter).</div>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label" for="purchase_unit">Satuan Beli / Kemasan</label>
                        <select class="form-select" id="purchase_unit" name="purchase_unit">
                            <?php foreach ($units as $uKey => $uLabel): ?>
                                <option value="<?= e($uKey) ?>" <?= ($form['purchase_unit'] ?? 'kilogram') === $uKey ? 'selected' : '' ?>>
                                    <?= e($uLabel) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="conversion_factor">Faktor Konversi</label>
                        <input class="form-control" type="number" step="0.001" id="conversion_factor" name="conversion_factor" 
                               value="<?= e($form['conversion_factor'] ?? '1000') ?>">
                        <div class="form-text">1 Satuan Beli = ... Satuan Dasar</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="reference_price">Harga Acuan (Rp)</label>
                        <input class="form-control" type="number" id="reference_price" name="reference_price" 
                               value="<?= e($form['reference_price'] ?? '') ?>" placeholder="Harga per satuan beli">
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label class="form-label" for="edible_portion_factor">Bagian Dapat Dimakan (BDD)</label>
                        <input class="form-control" type="number" step="0.01" min="0" max="1" id="edible_portion_factor" name="edible_portion_factor" 
                               value="<?= e($form['edible_portion_factor'] ?? '1') ?>">
                        <div class="form-text">Nilai 0 s.d 1. Misalnya 0.8 untuk ikan tanpa tulang. Bawaan 1.0.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="notes">Catatan Bahan</label>
                        <textarea class="form-control" id="notes" name="notes" rows="2"><?= e($form['notes'] ?? '') ?></textarea>
                    </div>
                </div>

                <div class="d-flex justify-content-between">
                    <a class="btn btn-outline-secondary" href="<?= url('/bahan') ?>">Batal</a>
                    <button class="btn btn-primary" type="submit">
                        <i class="bi bi-save me-1"></i><?= $isEdit ? 'Simpan Perubahan' : 'Tambahkan Bahan' ?>
                    </button>
                </div>
            </form>
        </section>
    </div>
</div>
