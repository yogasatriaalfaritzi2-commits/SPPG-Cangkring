<?php
/**
 * View Index Jadwal Menu Mingguan MBG
 */
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Operasional MBG</p>
        <h1 class="page-title"><?= e($pageTitle) ?></h1>
        <p class="page-subtitle">
            Pekan: <strong><?= e(tanggal_id($start)) ?></strong> s.d. <strong><?= e(tanggal_id($end)) ?></strong> · 
            Total Porsi: <strong><?= number_format((int)($summary['total_portions'] ?? 0), 0, ',', '.') ?> porsi</strong>
        </p>
    </div>
    <div class="page-actions d-flex gap-2">
        <a class="btn btn-outline-secondary" href="<?= url('/jadwal-menu/kebutuhan-bahan?dari=' . $start . '&sampai=' . $end) ?>">
            <i class="bi bi-basket me-1"></i>Kebutuhan Bahan
        </a>
        <?php if ($canManage): ?>
            <a class="btn btn-primary" href="<?= url('/jadwal-menu/tambah') ?>">
                <i class="bi bi-calendar-plus me-1"></i>Susun Jadwal
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Navigasi Pekan -->
<section class="app-card period-bar mb-4">
    <div class="toolbar p-3">
        <form class="toolbar-form" method="get" action="<?= url('/jadwal-menu') ?>">
            <div class="toolbar-field">
                <label class="form-label" for="mulai">Mulai Tanggal (Senin)</label>
                <input class="form-control form-control-sm" type="date" id="mulai" name="mulai" value="<?= e($start) ?>">
            </div>
            <div class="toolbar-actions">
                <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-calendar3 me-1"></i>Lihat Pekan</button>
                <a class="btn btn-sm btn-link" href="<?= url('/jadwal-menu') ?>">Pekan Ini</a>
            </div>
        </form>
    </div>
</section>

<!-- Kalender Grid Mingguan -->
<div class="row g-3">
    <?php foreach ($grid as $gDate => $dayData): ?>
        <div class="col-12 col-md-6 col-xl-4">
            <section class="app-card h-100 d-flex flex-column">
                <div class="card-header p-3 border-bottom d-flex justify-content-between align-items-center bg-light">
                    <div>
                        <h2 class="h6 fw-bold mb-0"><?= e(tanggal_id($gDate)) ?></h2>
                    </div>
                    <?php if ($canManage): ?>
                        <a class="btn btn-sm btn-link p-0 text-decoration-none" href="<?= url('/jadwal-menu/tambah?tanggal=' . $gDate) ?>">
                            <i class="bi bi-plus-circle me-1"></i>Tambah
                        </a>
                    <?php endif; ?>
                </div>
                <div class="p-3 flex-grow-1">
                    <?php if (empty($dayData['rows'])): ?>
                        <p class="text-muted small text-center my-4">Tidak ada jadwal pelayanan.</p>
                    <?php else: ?>
                        <?php foreach ($dayData['rows'] as $sRow): ?>
                            <div class="border rounded p-2 mb-2 bg-white shadow-sm">
                                <div class="d-flex justify-content-between align-items-start mb-1">
                                    <span class="badge bg-light text-dark border small"><?= e(slot_label((string)$sRow['meal_slot'])) ?></span>
                                    <span class="pill <?= status_badge_class((string)$sRow['schedule_status']) ?>"><?= e(status_label((string)$sRow['schedule_status'])) ?></span>
                                </div>
                                <a href="<?= url('/jadwal-menu/' . $sRow['id']) ?>" class="fw-bold text-decoration-none d-block">
                                    <?= e($sRow['menu_name']) ?>
                                </a>
                                <div class="small text-muted d-flex justify-content-between mt-1">
                                    <span><?= number_format((int)$sRow['total_portions'], 0, ',', '.') ?> porsi</span>
                                    <span>Rp <?= number_format((float)($sRow['total_estimated_cost'] ?? 0), 0, ',', '.') ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    <?php endforeach; ?>
</div>
