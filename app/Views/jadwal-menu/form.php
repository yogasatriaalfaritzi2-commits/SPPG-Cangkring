<?php
/**
 * View Form Susun / Ubah Jadwal Menu
 */
$isEdit = ($mode === 'update');
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= url('/jadwal-menu') ?>" class="text-decoration-none">← Kembali ke Kalender Jadwal</a></p>
        <h1 class="page-title"><?= e($pageTitle) ?></h1>
    </div>
</div>

<form method="post" action="<?= $isEdit ? url('/jadwal-menu/' . $form['id'] . '/ubah') : url('/jadwal-menu/tambah') ?>">
    <?= csrf_field() ?>
    <div class="row g-4">
        <div class="col-12 col-lg-5">
            <section class="app-card p-4 mb-4">
                <h2 class="h6 fw-bold mb-3 border-bottom pb-2">Pengaturan Waktu & Menu</h2>
                <div class="mb-3">
                    <label class="form-label" for="service_date">Tanggal Pelayanan *</label>
                    <input class="form-control" type="date" id="service_date" name="service_date" value="<?= e($form['service_date']) ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="meal_slot">Slot Makan *</label>
                    <select class="form-select" id="meal_slot" name="meal_slot" required>
                        <?php foreach ($slots as $sKey => $sVal): ?>
                            <option value="<?= e($sKey) ?>" <?= $form['meal_slot'] === $sKey ? 'selected' : '' ?>><?= e(slot_label($sKey)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="menu_id">Pilihan Menu MBG (Sudah Disetujui) *</label>
                    <select class="form-select" id="menu_id" name="menu_id" required>
                        <option value="">-- Pilih Menu Terverifikasi --</option>
                        <?php foreach ($menus as $m): ?>
                            <option value="<?= e($m['id']) ?>" <?= (string)$form['menu_id'] === (string)$m['id'] ? 'selected' : '' ?>>
                                <?= e($m['menu_name']) ?> (<?= e($m['menu_code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="notes">Catatan Distribusi</label>
                    <textarea class="form-control" id="notes" name="notes" rows="2"><?= e($form['notes'] ?? '') ?></textarea>
                </div>
            </section>
        </div>

        <div class="col-12 col-lg-7">
            <section class="app-card p-4 mb-4">
                <h2 class="h6 fw-bold mb-3 border-bottom pb-2">Alokasi Porsi Sekolah</h2>
                <div class="table-responsive">
                    <table class="table app-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Sekolah Penerima</th>
                                <th style="width: 35%;">Alokasi Porsi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($schools as $idx => $sch): ?>
                                <?php
                                $allocated = 0;
                                foreach ($allocations as $al) {
                                    if ((string)$al['school_id'] === (string)$sch['id']) {
                                        $allocated = (int)$al['portions'];
                                        break;
                                    }
                                }
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= e($sch['school_name']) ?></strong>
                                        <input type="hidden" name="allocations[<?= $idx ?>][school_id]" value="<?= e($sch['id']) ?>">
                                    </td>
                                    <td>
                                        <input class="form-control form-control-sm" type="number" min="0" 
                                               name="allocations[<?= $idx ?>][portions]" value="<?= $allocated ?>" placeholder="Porsi">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 pt-2 border-top d-flex justify-content-between">
                    <a class="btn btn-outline-secondary" href="<?= url('/jadwal-menu') ?>">Batal</a>
                    <button class="btn btn-primary" type="submit">
                        <i class="bi bi-save me-1"></i>Simpan Jadwal
                    </button>
                </div>
            </section>
        </div>
    </div>
</form>
