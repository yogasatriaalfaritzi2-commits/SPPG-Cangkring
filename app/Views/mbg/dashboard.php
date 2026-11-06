<?php
/**
 * View Dashboard Operasional MBG
 */
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Operasional MBG</p>
        <h1 class="page-title"><?= e($pageTitle ?? 'Ringkasan Operasional MBG') ?></h1>
        <p class="page-subtitle">
            Periode pekan: <?= e(tanggal_id($weekFrom)) ?> s.d. <?= e(tanggal_id($weekTo)) ?> · Pemantauan distribusi porsi, gizi, dan kebutuhan bahan pangan.
        </p>
    </div>
    <div class="page-actions">
        <?php if ($canViewSchedules && can('menu_schedules.manage')): ?>
            <a class="btn btn-primary" href="<?= url('/jadwal-menu/tambah') ?>">
                <i class="bi bi-calendar-plus me-1" aria-hidden="true"></i>Buat Jadwal Baru
            </a>
        <?php endif; ?>
    </div>
</div>

<section class="app-card period-bar mb-3">
    <div class="toolbar">
        <form class="toolbar-form" method="get" action="<?= url('/mbg') ?>">
            <div class="toolbar-field">
                <label class="form-label" for="dari">Pilih Pekan (Mulai Senin)</label>
                <input class="form-control form-control-sm" type="date" id="dari" name="dari" value="<?= e($weekFrom) ?>">
            </div>
            <div class="toolbar-actions">
                <button class="btn btn-sm btn-primary" type="submit">
                    <i class="bi bi-filter me-1" aria-hidden="true"></i>Tampilkan Pekan
                </button>
                <a class="btn btn-sm btn-link" href="<?= url('/mbg') ?>">Pekan Ini</a>
            </div>
        </form>
    </div>
</section>

<!-- Ringkasan Kartu Metrik Utama -->
<div class="row g-3 mb-4">
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="app-card p-3 h-100">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="text-muted small fw-semibold">TOTAL PENERIMA</span>
                <span class="badge bg-primary-subtle text-primary"><i class="bi bi-people"></i></span>
            </div>
            <h2 class="h3 mb-1 fw-bold"><?= number_format((int)($beneficiaries ?? 0), 0, ',', '.') ?></h2>
            <p class="text-muted small mb-0">Jiwa / siswa terdaftar aktif</p>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="app-card p-3 h-100">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="text-muted small fw-semibold">PORSI PEKAN INI</span>
                <span class="badge bg-success-subtle text-success"><i class="bi bi-box-seam"></i></span>
            </div>
            <h2 class="h3 mb-1 fw-bold"><?= number_format((int)($week['total_portions'] ?? 0), 0, ',', '.') ?></h2>
            <p class="text-muted small mb-0">Total alokasi porsi terencana</p>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="app-card p-3 h-100">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="text-muted small fw-semibold">SEKOLAH DILAYANI</span>
                <span class="badge bg-info-subtle text-info"><i class="bi bi-mortarboard"></i></span>
            </div>
            <h2 class="h3 mb-1 fw-bold"><?= number_format((int)($schools['aktif'] ?? 0), 0, ',', '.') ?></h2>
            <p class="text-muted small mb-0">Sekolah mitra status aktif</p>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="app-card p-3 h-100">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="text-muted small fw-semibold">KATALOG MENU</span>
                <span class="badge bg-warning-subtle text-warning"><i class="bi bi-egg-fried"></i></span>
            </div>
            <h2 class="h3 mb-1 fw-bold"><?= number_format((int)($menus['total'] ?? 0), 0, ',', '.') ?></h2>
            <p class="text-muted small mb-0"><?= (int)($menus['approved'] ?? 0) ?> menu telah disetujui</p>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <!-- Jadwal Mendatang & Pelayanan -->
    <div class="col-12 col-lg-8">
        <section class="app-card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center p-3 border-bottom">
                <h2 class="h6 mb-0 fw-bold"><i class="bi bi-calendar-event me-2"></i>Jadwal Menu Terdekat</h2>
                <a class="btn btn-sm btn-outline-primary" href="<?= url('/jadwal-menu') ?>">Lihat Semua</a>
            </div>
            <div class="table-responsive">
                <table class="table app-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Tanggal</th>
                            <th>Menu</th>
                            <th>Slot</th>
                            <th>Total Porsi</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($upcoming)): ?>
                            <tr>
                                <td colspan="5" class="text-center py-4 text-muted">Belum ada jadwal menu terdekat.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($upcoming as $item): ?>
                                <tr>
                                    <td><?= e(tanggal_id((string)$item['schedule_date'])) ?></td>
                                    <td>
                                        <strong><?= e($item['menu_name']) ?></strong>
                                        <div class="small text-muted"><?= e($item['menu_code']) ?></div>
                                    </td>
                                    <td><span class="badge bg-light text-dark border"><?= e(slot_label((string)$item['service_slot'])) ?></span></td>
                                    <td><?= number_format((int)$item['total_portions'], 0, ',', '.') ?> porsi</td>
                                    <td><span class="pill <?= status_badge_class((string)$item['schedule_status']) ?>"><?= e(status_label((string)$item['schedule_status'])) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Kebutuhan Bahan Pangan Horizon Depan -->
        <section class="app-card">
            <div class="card-header d-flex justify-content-between align-items-center p-3 border-bottom">
                <div>
                    <h2 class="h6 mb-0 fw-bold"><i class="bi bi-basket me-2"></i>Rencana Kebutuhan Bahan Pangan (7 Hari ke Depan)</h2>
                    <small class="text-muted"><?= e(tanggal_id($planFrom)) ?> s.d. <?= e(tanggal_id($planTo)) ?></small>
                </div>
                <?php if ($canViewSchedules): ?>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= url('/jadwal-menu/kebutuhan-bahan') ?>">Detail Pengadaan</a>
                <?php endif; ?>
            </div>
            <div class="table-responsive">
                <table class="table app-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Bahan</th>
                            <th>Kategori</th>
                            <th>Total Kebutuhan</th>
                            <th>Estimasi Biaya</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($plan['items'])): ?>
                            <tr>
                                <td colspan="4" class="text-center py-4 text-muted">Belum ada kebutuhan bahan untuk jadwal berstatus rencana.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach (array_slice($plan['items'], 0, 6) as $item): ?>
                                <tr>
                                    <td><strong><?= e($item['ingredient_name']) ?></strong></td>
                                    <td><?= e($item['category_name'] ?? '-') ?></td>
                                    <td><?= number_format((float)$item['total_quantity'], 1, ',', '.') ?> <?= e($item['unit']) ?></td>
                                    <td>Rp <?= number_format((float)($item['estimated_cost'] ?? 0), 0, ',', '.') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <!-- Panel Informasi Samping / Peringatan -->
    <div class="col-12 col-lg-4">
        <!-- Peringatan Kerjasama Sekolah -->
        <?php if (!empty($alerts)): ?>
            <div class="alert alert-warning mb-3">
                <h6 class="alert-heading fw-bold mb-1"><i class="bi bi-exclamation-triangle-fill me-1"></i> Perhatian Kerjasama</h6>
                <p class="small mb-2">Terdapat sekolah mitra yang masa kerjasamanya mendekati batas waktu:</p>
                <ul class="small mb-0 ps-3">
                    <?php foreach ($alerts as $alt): ?>
                        <li><?= e($alt['school_name']) ?> (berakhir <?= e(tanggal_id((string)$alt['partnership_end'])) ?>)</li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <!-- Distribusi Status Menu -->
        <section class="app-card mb-3 p-3">
            <h2 class="h6 fw-bold mb-3"><i class="bi bi-pie-chart me-2"></i>Status Katalog Menu</h2>
            <div class="mb-3" style="max-height: 200px; position: relative;">
                <canvas class="chart-canvas" data-chart="menuStatusChart"
                        data-chart-type="doughnut"
                        data-chart-labels='["Disetujui", "Terjadwal", "Menunggu", "Draf"]'
                        data-chart-values='[<?= (int)($menus['approved'] ?? 0) ?>, <?= (int)($menus['scheduled'] ?? 0) ?>, <?= (int)($menus['pending'] ?? 0) ?>, <?= (int)($menus['draft'] ?? 0) ?>]'
                        aria-label="Status Katalog Menu" role="img"></canvas>
            </div>
            <div class="d-flex justify-content-between mb-2 pb-1 border-bottom">
                <span>Disetujui (Siap Masak)</span>
                <span class="badge bg-success"><?= (int)($menus['approved'] ?? 0) ?></span>
            </div>
            <div class="d-flex justify-content-between mb-2 pb-1 border-bottom">
                <span>Terjadwal Aktif</span>
                <span class="badge bg-info"><?= (int)($menus['scheduled'] ?? 0) ?></span>
            </div>
            <div class="d-flex justify-content-between mb-2 pb-1 border-bottom">
                <span>Menunggu Persetujuan</span>
                <span class="badge bg-warning text-dark"><?= (int)($menus['pending'] ?? 0) ?></span>
            </div>
            <div class="d-flex justify-content-between">
                <span>Draf Perancangan</span>
                <span class="badge bg-secondary"><?= (int)($menus['draft'] ?? 0) ?></span>
            </div>
        </section>

        <!-- Rekap Sekolah per Jenjang -->
        <section class="app-card p-3">
            <h2 class="h6 fw-bold mb-3"><i class="bi bi-building me-2"></i>Distribusi Jenjang Sekolah</h2>
            <?php if (empty($byLevel)): ?>
                <p class="small text-muted mb-0">Belum ada sekolah terdata.</p>
            <?php else: ?>
                <?php foreach ($byLevel as $lvl): ?>
                    <div class="d-flex justify-content-between mb-2 pb-1 border-bottom">
                        <span><?= e($lvl['level_name'] ?? 'Lainnya') ?></span>
                        <span class="fw-bold"><?= (int)($lvl['total_schools'] ?? 0) ?> sekolah</span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </div>
</div>
