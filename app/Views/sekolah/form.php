<?php
/**
 * View Form Tambah/Ubah Sekolah
 */
$isEdit = ($mode === 'edit');
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= url('/sekolah') ?>" class="text-decoration-none">← Kembali ke Daftar Sekolah</a></p>
        <h1 class="page-title"><?= e($pageTitle) ?></h1>
    </div>
</div>

<form method="post" action="<?= $isEdit ? url('/sekolah/' . $school['id'] . '/ubah') : url('/sekolah/tambah') ?>" class="needs-validation">
    <?= csrf_field() ?>
    <div class="row g-4">
        <div class="col-12 col-lg-8">
            <section class="app-card p-4 mb-4">
                <h2 class="h6 fw-bold mb-3 border-bottom pb-2">Identitas Sekolah</h2>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="school_code">Kode Sekolah *</label>
                        <input class="form-control" type="text" id="school_code" name="school_code" 
                               value="<?= e($form['school_code'] ?? '') ?>" required <?= $isEdit ? 'readonly' : '' ?>>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label" for="school_name">Nama Sekolah *</label>
                        <input class="form-control" type="text" id="school_name" name="school_name" 
                               value="<?= e($form['school_name'] ?? '') ?>" placeholder="Misal: SDN 1 Cangkring" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="npsn">NPSN (Nomor Pokok Sekolah Nasional)</label>
                        <input class="form-control" type="text" id="npsn" name="npsn" 
                               value="<?= e($form['npsn'] ?? '') ?>" placeholder="8 digit angka">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="school_level_id">Jenjang Pendidikan *</label>
                        <select class="form-select" id="school_level_id" name="school_level_id" required>
                            <option value="">Pilih Jenjang</option>
                            <?php foreach ($levels as $lvl): ?>
                                <option value="<?= e($lvl['id']) ?>" <?= (string)($form['school_level_id'] ?? '') === (string)$lvl['id'] ? 'selected' : '' ?>>
                                    <?= e($lvl['level_name']) ?> (<?= e($lvl['level_code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </section>

            <section class="app-card p-4 mb-4">
                <h2 class="h6 fw-bold mb-3 border-bottom pb-2">Alamat & Lokasi</h2>
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label" for="address">Alamat Lengkap</label>
                        <textarea class="form-control" id="address" name="address" rows="2"><?= e($form['address'] ?? '') ?></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="district">Kecamatan</label>
                        <input class="form-control" type="text" id="district" name="district" value="<?= e($form['district'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="regency">Kabupaten / Kota</label>
                        <input class="form-control" type="text" id="regency" name="regency" value="<?= e($form['regency'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="province">Provinsi</label>
                        <input class="form-control" type="text" id="province" name="province" value="<?= e($form['province'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="postal_code">Kode Pos</label>
                        <input class="form-control" type="text" id="postal_code" name="postal_code" value="<?= e($form['postal_code'] ?? '') ?>">
                    </div>
                </div>
            </section>

            <section class="app-card p-4 mb-4">
                <h2 class="h6 fw-bold mb-3 border-bottom pb-2">Kontak Penanggung Jawab (PIC)</h2>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="person_in_charge">Nama PIC / Kepala Sekolah</label>
                        <input class="form-control" type="text" id="person_in_charge" name="person_in_charge" value="<?= e($form['person_in_charge'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="phone">Nomor Telepon / WhatsApp</label>
                        <input class="form-control" type="text" id="phone" name="phone" value="<?= e($form['phone'] ?? '') ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="email">Alamat Email</label>
                        <input class="form-control" type="email" id="email" name="email" value="<?= e($form['email'] ?? '') ?>">
                    </div>
                </div>
            </section>
        </div>

        <div class="col-12 col-lg-4">
            <section class="app-card p-4 mb-4">
                <h2 class="h6 fw-bold mb-3 border-bottom pb-2">Status & Kerjasama</h2>
                <div class="mb-3">
                    <label class="form-label" for="school_status">Status Layanan / Sekolah *</label>
                    <select class="form-select" id="school_status" name="school_status" required>
                        <?php foreach ($statuses as $st): ?>
                            <?php $currentStatus = $form['school_status'] ?? $form['service_status'] ?? 'aktif'; ?>
                            <option value="<?= e($st) ?>" <?= $currentStatus === $st ? 'selected' : '' ?>>
                                <?= e(school_status_label($st)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="partnership_status">Status Kerjasama</label>
                    <select class="form-select" id="partnership_status" name="partnership_status">
                        <?php foreach ($partnerships as $ps): ?>
                            <?php $currentPartnership = $form['partnership_status'] ?? 'belum_ada'; ?>
                            <option value="<?= e($ps) ?>" <?= $currentPartnership === $ps ? 'selected' : '' ?>>
                                <?= e(partnership_label($ps)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="partnership_start">Mulai Kerjasama</label>
                    <input class="form-control" type="date" id="partnership_start" name="partnership_start" value="<?= e($form['partnership_start'] ?? '') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="partnership_end">Berakhir Kerjasama</label>
                    <input class="form-control" type="date" id="partnership_end" name="partnership_end" value="<?= e($form['partnership_end'] ?? '') ?>">
                </div>
                <div class="mb-4">
                    <label class="form-label" for="notes">Catatan Khusus</label>
                    <textarea class="form-control" id="notes" name="notes" rows="3"><?= e($form['notes'] ?? '') ?></textarea>
                </div>
                <button class="btn btn-primary w-100" type="submit">
                    <i class="bi bi-save me-1"></i><?= $isEdit ? 'Simpan Perubahan' : 'Daftarkan Sekolah' ?>
                </button>
            </section>
        </div>
    </div>
</form>
