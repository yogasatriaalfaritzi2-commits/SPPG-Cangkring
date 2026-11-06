<?php
/**
 * View Detail Sekolah Penerima Manfaat
 */
$schoolId = (int)$school['id'];
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= url('/sekolah') ?>" class="text-decoration-none">← Kembali ke Daftar Sekolah</a></p>
        <h1 class="page-title"><?= e($school['school_name']) ?></h1>
        <p class="page-subtitle">
            Kode: <code><?= e($school['school_code']) ?></code> · 
            Jenjang: <strong><?= e($school['level_name'] ?? '-') ?></strong> · 
            NPSN: <?= e($school['npsn'] ?? 'Belum ada') ?>
        </p>
    </div>
    <div class="page-actions d-flex gap-2">
        <?php if (can('schools.update')): ?>
            <a class="btn btn-outline-secondary" href="<?= url('/sekolah/' . $schoolId . '/ubah') ?>">
                <i class="bi bi-pencil me-1"></i>Sunting Profil
            </a>
        <?php endif; ?>
        <?php if (can('beneficiaries.manage')): ?>
            <a class="btn btn-primary" href="<?= url('/penerima-manfaat/catat?school=' . $schoolId) ?>">
                <i class="bi bi-people-fill me-1"></i>Kelola Penerima
            </a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-12 col-md-4">
        <div class="app-card p-3">
            <span class="text-muted small">TOTAL PENERIMA SAAT INI</span>
            <h2 class="h3 fw-bold mb-0 text-primary"><?= number_format((int)$totalToday, 0, ',', '.') ?> Jiwa</h2>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="app-card p-3">
            <span class="text-muted small">STATUS LAYANAN</span>
            <div><span class="pill <?= status_badge_class((string)$school['service_status']) ?> fs-6"><?= e(status_label((string)$school['service_status'])) ?></span></div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="app-card p-3">
            <span class="text-muted small">STATUS KERJASAMA</span>
            <h2 class="h5 fw-bold mb-0"><?= e(ucfirst((string)($school['partnership_status'] ?? '-'))) ?></h2>
            <small class="text-muted">
                <?= !empty($school['partnership_end']) ? 's.d. ' . e(tanggal_id((string)$school['partnership_end'])) : 'Jangka panjang' ?>
            </small>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-12 col-lg-7">
        <!-- Rincian Jumlah Penerima Manfaat -->
        <section class="app-card mb-4">
            <div class="card-header p-3 border-bottom d-flex justify-content-between align-items-center">
                <h2 class="h6 fw-bold mb-0"><i class="bi bi-person-lines-fill me-2"></i>Rekap Jumlah Penerima Manfaat</h2>
            </div>
            <div class="table-responsive">
                <table class="table app-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Kelompok / Kelas</th>
                            <th>Kategori</th>
                            <th class="text-end">Jumlah Siswa</th>
                            <th>Berlaku Sejak</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($beneficiaryRows)): ?>
                            <tr>
                                <td colspan="4" class="text-center py-4 text-muted">Belum ada pencatatan jumlah penerima untuk sekolah ini.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($beneficiaryRows as $row): ?>
                                <tr>
                                    <td><strong><?= e($row['class_group']) ?></strong></td>
                                    <td><span class="badge bg-light text-dark border"><?= e($row['category_name']) ?></span></td>
                                    <td class="text-end fw-bold"><?= number_format((int)$row['total_beneficiaries'], 0, ',', '.') ?></td>
                                    <td><small class="text-muted"><?= e(tanggal_id((string)$row['effective_date'])) ?></small></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Jadwal Hari Pelayanan -->
        <section class="app-card mb-4">
            <div class="card-header p-3 border-bottom">
                <h2 class="h6 fw-bold mb-0"><i class="bi bi-calendar-check me-2"></i>Jadwal Hari Layanan Makan</h2>
            </div>
            <div class="p-3">
                <div class="row g-2">
                    <?php
                    $allDays = [
                        'senin' => 'Senin',
                        'selasa' => 'Selasa',
                        'rabu' => 'Rabu',
                        'kamis' => 'Kamis',
                        'jumat' => 'Jumat',
                        'sabtu' => 'Sabtu',
                    ];
                    $activeDays = [];
                    foreach ($serviceDays as $sd) {
                        $activeDays[$sd['day_of_week']] = $sd;
                    }
                    ?>
                    <?php foreach ($allDays as $dKey => $dLabel): ?>
                        <div class="col-6 col-sm-4">
                            <div class="p-2 border rounded text-center <?= isset($activeDays[$dKey]) ? 'bg-light border-primary' : 'text-muted' ?>">
                                <div class="fw-bold"><?= $dLabel ?></div>
                                <small><?= isset($activeDays[$dKey]) ? 'Dilayani' : 'Libur' ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    </div>

    <div class="col-12 col-lg-5">
        <!-- Informasi Alamat & Kontak -->
        <section class="app-card p-3 mb-4">
            <h2 class="h6 fw-bold mb-3 border-bottom pb-2"><i class="bi bi-geo-alt me-2"></i>Alamat & Kontak</h2>
            <dl class="row mb-0 small">
                <dt class="col-sm-4 text-muted">Alamat</dt>
                <dd class="col-sm-8"><?= nl2br(e($school['address'] ?? '-')) ?></dd>

                <dt class="col-sm-4 text-muted">Kecamatan</dt>
                <dd class="col-sm-8"><?= e($school['district'] ?? '-') ?></dd>

                <dt class="col-sm-4 text-muted">Kabupaten</dt>
                <dd class="col-sm-8"><?= e($school['regency'] ?? '-') ?></dd>

                <dt class="col-sm-4 text-muted">Kontak PIC</dt>
                <dd class="col-sm-8"><?= e($school['person_in_charge'] ?? '-') ?></dd>

                <dt class="col-sm-4 text-muted">Telepon</dt>
                <dd class="col-sm-8"><?= e($school['phone'] ?? '-') ?></dd>

                <dt class="col-sm-4 text-muted">Email</dt>
                <dd class="col-sm-8"><?= e($school['email'] ?? '-') ?></dd>
            </dl>
        </section>

        <!-- Riwayat Perubahan Data -->
        <section class="app-card p-3">
            <h2 class="h6 fw-bold mb-3 border-bottom pb-2"><i class="bi bi-clock-history me-2"></i>Riwayat Perubahan Penerima</h2>
            <?php if (empty($histories)): ?>
                <p class="small text-muted mb-0">Belum ada riwayat mutasi siswa/penerima.</p>
            <?php else: ?>
                <div class="timeline small">
                    <?php foreach (array_slice($histories, 0, 5) as $h): ?>
                        <div class="mb-2 pb-2 border-bottom">
                            <div class="d-flex justify-content-between">
                                <strong><?= e($h['class_group']) ?> (<?= e($h['category_name']) ?>)</strong>
                                <span class="text-muted"><?= e(tanggal_id((string)$h['created_at'])) ?></span>
                            </div>
                            <div class="text-muted"><?= e($h['reason'] ?? 'Penyesuaian data') ?>: <?= (int)$h['previous_count'] ?> → <strong><?= (int)$h['new_count'] ?></strong></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>
