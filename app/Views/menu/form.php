<?php
/**
 * View Form Susun / Ubah Menu MBG
 */
$isEdit = ($mode === 'edit' || $mode === 'update');
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= url('/menu') ?>" class="text-decoration-none">← Kembali ke Daftar Menu</a></p>
        <h1 class="page-title"><?= e($pageTitle) ?></h1>
    </div>
</div>

<form method="post" action="<?= $isEdit ? url('/menu/' . $menu['id'] . '/ubah') : url('/menu/tambah') ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="row g-4">
        <div class="col-12 col-lg-7">
            <section class="app-card p-4 mb-4">
                <h2 class="h6 fw-bold mb-3 border-bottom pb-2">Informasi Menu</h2>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="menu_code">Kode Menu *</label>
                        <input class="form-control" type="text" id="menu_code" name="menu_code" 
                               value="<?= e($form['menu_code'] ?? '') ?>" required <?= $isEdit ? 'readonly' : '' ?>>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label" for="menu_name">Nama Menu *</label>
                        <input class="form-control" type="text" id="menu_name" name="menu_name" 
                               value="<?= e($form['menu_name'] ?? '') ?>" placeholder="Misal: Nasi Ayam Suwir Sayur Sop" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="category_id">Kategori Menu *</label>
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
                        <label class="form-label" for="portion_count">Dasar Porsi Acuan</label>
                        <input class="form-control" type="number" id="portion_count" name="portion_count" value="1" readonly>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="description">Deskripsi & Cara Penyajian</label>
                        <textarea class="form-control" id="description" name="description" rows="2"><?= e($form['description'] ?? '') ?></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="photo">Foto Contoh Sajian (Opsional)</label>
                        <input class="form-control" type="file" id="photo" name="photo" accept="image/*">
                    </div>
                </div>
            </section>
        </div>

        <div class="col-12 col-lg-5">
            <section class="app-card p-4 mb-4">
                <h2 class="h6 fw-bold mb-3 border-bottom pb-2">Komponen Bahan Pangan (Resep)</h2>
                <div id="ingredient-rows">
                    <?php if (empty($items)): ?>
                        <div class="row g-2 mb-2 item-row">
                            <div class="col-7">
                                <select class="form-select form-select-sm" name="items[0][ingredient_id]" required>
                                    <option value="">-- Pilih Bahan --</option>
                                    <?php foreach ($ingredients as $ing): ?>
                                        <option value="<?= e($ing['id']) ?>"><?= e($ing['ingredient_name']) ?> (<?= e($ing['base_unit']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-5">
                                <input class="form-control form-control-sm" type="number" step="0.1" name="items[0][quantity]" placeholder="Takaran" required>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($items as $idx => $it): ?>
                            <div class="row g-2 mb-2 item-row">
                                <div class="col-7">
                                    <select class="form-select form-select-sm" name="items[<?= $idx ?>][ingredient_id]" required>
                                        <option value="">-- Pilih Bahan --</option>
                                        <?php foreach ($ingredients as $ing): ?>
                                            <option value="<?= e($ing['id']) ?>" <?= (string)($it['ingredient_id'] ?? '') === (string)$ing['id'] ? 'selected' : '' ?>>
                                                <?= e($ing['ingredient_name']) ?> (<?= e($ing['base_unit']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-5">
                                    <input class="form-control form-control-sm" type="number" step="0.1" name="items[<?= $idx ?>][quantity]" 
                                           value="<?= e($it['quantity'] ?? '') ?>" placeholder="Takaran" required>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="mt-4 pt-2 border-top d-flex justify-content-between">
                    <a class="btn btn-outline-secondary" href="<?= url('/menu') ?>">Batal</a>
                    <button class="btn btn-primary" type="submit">
                        <i class="bi bi-save me-1"></i><?= $isEdit ? 'Simpan Revisi Menu' : 'Simpan Draf Menu' ?>
                    </button>
                </div>
            </section>
        </div>
    </div>
</form>
