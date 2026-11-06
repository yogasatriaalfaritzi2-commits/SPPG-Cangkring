<?php
/**
 * View Form Catat / Ubah Jumlah Penerima Manfaat
 */
$isEdit = isset($row);
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= url('/penerima-manfaat') ?>" class="text-decoration-none">← Kembali ke Rekap Penerima</a></p>
        <h1 class="page-title"><?= e($pageTitle) ?></h1>
        <p class="page-subtitle">Setiap perubahan data jumlah siswa/penerima harus disertai alasan pencatatan yang jelas.</p>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-12 col-md-8 col-lg-6">
        <section class="app-card p-4">
            <form method="post" action="<?= $isEdit ? url('/penerima-manfaat/' . (int)$row['id']) : url('/penerima-manfaat/catat') ?>">
                <?= csrf_field() ?>
                
                <div class="mb-3">
                    <label class="form-label" for="school_id">Sekolah Mitra *</label>
                    <select class="form-select" id="school_id" name="school_id" required <?= $isEdit ? 'disabled' : '' ?>>
                        <option value="">Pilih Sekolah</option>
                        <?php foreach ($schools as $sch): ?>
                            <option value="<?= e($sch['id']) ?>" <?= (string)($form['school_id'] ?? '') === (string)$sch['id'] ? 'selected' : '' ?>>
                                <?= e($sch['school_name']) ?> (<?= e($sch['school_code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($isEdit): ?>
                        <input type="hidden" name="school_id" value="<?= e($form['school_id']) ?>">
                    <?php endif; ?>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="category_id">Kategori Penerima *</label>
                    <select class="form-select" id="category_id" name="category_id" required>
                        <option value="">Pilih Kategori</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= e($cat['id']) ?>" <?= (string)($form['category_id'] ?? '') === (string)$cat['id'] ? 'selected' : '' ?>>
                                <?= e($cat['category_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="class_group">Kelompok / Kelas *</label>
                    <input class="form-control" type="text" id="class_group" name="class_group" 
                           value="<?= e($form['class_group'] ?? '') ?>" placeholder="Misal: Kelas 1, Balita, Guru" required>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label" for="beneficiary_count">Jumlah Siswa / Jiwa *</label>
                        <input class="form-control" type="number" id="beneficiary_count" name="beneficiary_count" 
                               value="<?= e($form['beneficiary_count'] ?? '') ?>" min="0" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="effective_date">Tanggal Berlaku *</label>
                        <input class="form-control" type="date" id="effective_date" name="effective_date" 
                               value="<?= e($form['effective_date'] ?? date('Y-m-d')) ?>" required>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="reason">Alasan Perubahan / Pencatatan *</label>
                    <input class="form-control" type="text" id="reason" name="reason" 
                           value="<?= e($form['reason'] ?? '') ?>" placeholder="Misal: Tahun ajaran baru, mutasi siswa masuk" required>
                </div>

                <div class="mb-4">
                    <label class="form-label" for="notes">Catatan Tambahan</label>
                    <textarea class="form-control" id="notes" name="notes" rows="2"><?= e($form['notes'] ?? '') ?></textarea>
                </div>

                <div class="d-flex justify-content-between">
                    <a class="btn btn-outline-secondary" href="<?= url('/penerima-manfaat') ?>">Batal</a>
                    <button class="btn btn-primary" type="submit">
                        <i class="bi bi-save me-1"></i>Simpan Data Penerima
                    </button>
                </div>
            </form>
        </section>
    </div>
</div>
