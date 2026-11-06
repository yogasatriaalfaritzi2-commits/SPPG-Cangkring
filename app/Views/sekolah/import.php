<?php
/**
 * View Impor Data Sekolah
 */
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= url('/sekolah') ?>" class="text-decoration-none">← Kembali ke Daftar Sekolah</a></p>
        <h1 class="page-title"><?= e($pageTitle ?? 'Impor Data Sekolah') ?></h1>
        <p class="page-subtitle">Unggah berkas CSV untuk mendaftarkan atau memperbarui data sekolah penerima manfaat.</p>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-12 col-md-8 col-lg-6">
        <section class="app-card p-4">
            <form method="post" action="<?= url('/sekolah/impor') ?>" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label fw-bold" for="csv_file">Pilih Berkas CSV</label>
                    <input class="form-control" type="file" id="csv_file" name="csv_file" accept=".csv" required>
                    <div class="form-text">Format berkas harus berupa CSV dengan pemisah koma (,).</div>
                </div>

                <div class="alert alert-info small mb-4">
                    <h6 class="alert-heading fw-bold">Format Kolom CSV:</h6>
                    <code>school_code, school_name, npsn, level_code, district, regency, person_in_charge, phone</code>
                </div>

                <div class="d-flex justify-content-between">
                    <a class="btn btn-outline-secondary" href="<?= url('/sekolah') ?>">Batal</a>
                    <button class="btn btn-primary" type="submit">
                        <i class="bi bi-upload me-1"></i>Mulai Impor
                    </button>
                </div>
            </form>
        </section>
    </div>
</div>
