<?php
/**
 * View Detail Jadwal Menu
 */
$schId = (int)$schedule['id'];
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= url('/jadwal-menu') ?>" class="text-decoration-none">← Kembali ke Kalender Jadwal</a></p>
        <h1 class="page-title"><?= e($schedule['menu_name']) ?></h1>
        <p class="page-subtitle">
            Kode: <code><?= e($schedule['schedule_code']) ?></code> · 
            Tanggal: <strong><?= e(tanggal_id((string)$schedule['service_date'])) ?></strong> · 
            Slot: <span class="badge bg-light text-dark border"><?= e(slot_label((string)$schedule['meal_slot'])) ?></span>
        </p>
    </div>
    <div class="page-actions d-flex gap-2">
        <a class="btn btn-outline-primary" href="<?= url('/jadwal-menu/' . $schId . '/surat-jalan') ?>" target="_blank">
            <i class="bi bi-printer me-1"></i>Cetak Surat Jalan
        </a>
        <?php if ($editable && $canManage): ?>
            <a class="btn btn-outline-secondary" href="<?= url('/jadwal-menu/' . $schId . '/ubah') ?>">
                <i class="bi bi-pencil me-1"></i>Sunting
            </a>
            <form method="post" action="<?= url('/jadwal-menu/' . $schId . '/kirim') ?>" class="d-inline">
                <?= csrf_field() ?>
                <button class="btn btn-primary" type="submit">
                    <i class="bi bi-send me-1"></i>Ajukan Persetujuan
                </button>
            </form>
        <?php endif; ?>
        <?php if ($schedule['schedule_status'] === 'pending' && $canDecide): ?>
            <form method="post" action="<?= url('/jadwal-menu/' . $schId . '/keputusan') ?>" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="decision" value="approved">
                <button class="btn btn-success" type="submit">
                    <i class="bi bi-check-lg me-1"></i>Setujui Jadwal
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-md-4">
        <div class="app-card p-3">
            <span class="text-muted small">TOTAL ALOKASI PORSI</span>
            <h2 class="h3 fw-bold mb-0 text-primary"><?= number_format((int)$schedule['total_portions'], 0, ',', '.') ?> Porsi</h2>
            <small class="text-muted">Didistribusikan ke <?= count($allocations) ?> sekolah mitra</small>
        </div>
    </div>
    <div class="col-md-4">
        <div class="app-card p-3">
            <span class="text-muted small">ESTIMASI ANGGARAN</span>
            <h2 class="h3 fw-bold mb-0">Rp <?= number_format((float)($schedule['total_estimated_cost'] ?? 0), 0, ',', '.') ?></h2>
            <small class="text-muted">Total biaya bahan pangan</small>
        </div>
    </div>
    <div class="col-md-4">
        <div class="app-card p-3">
            <span class="text-muted small">STATUS JADWAL</span>
            <div><span class="pill <?= status_badge_class((string)$schedule['schedule_status']) ?> fs-6"><?= e(status_label((string)$schedule['schedule_status'])) ?></span></div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Alokasi Sekolah -->
    <div class="col-12 col-lg-7">
        <section class="app-card mb-4">
            <div class="card-header p-3 border-bottom">
                <h2 class="h6 fw-bold mb-0"><i class="bi bi-mortarboard me-2"></i>Daftar Sekolah Penerima</h2>
            </div>
            <div class="table-responsive">
                <table class="table app-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Sekolah</th>
                            <th>Jenjang</th>
                            <th class="text-end">Jumlah Porsi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allocations as $al): ?>
                            <tr>
                                <td>
                                    <strong><?= e($al['school_name']) ?></strong>
                                    <div class="small text-muted"><?= e($al['school_code']) ?></div>
                                </td>
                                <td><?= e($al['level_name'] ?? '-') ?></td>
                                <td class="text-end fw-bold"><?= number_format((int)$al['portions'], 0, ',', '.') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <!-- Rencana Bahan Pangan Jadwal Ini -->
    <div class="col-12 col-lg-5">
        <section class="app-card mb-4">
            <div class="card-header p-3 border-bottom">
                <h2 class="h6 fw-bold mb-0"><i class="bi bi-basket me-2"></i>Kebutuhan Bahan Pangan</h2>
            </div>
            <div class="table-responsive">
                <table class="table app-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Bahan</th>
                            <th class="text-end">Jumlah</th>
                            <th class="text-end">Biaya</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($plan['items'])): ?>
                            <tr>
                                <td colspan="3" class="text-center py-4 text-muted">Belum ada rincian bahan.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($plan['items'] as $it): ?>
                                <tr>
                                    <td><strong><?= e($it['ingredient_name']) ?></strong></td>
                                    <td class="text-end"><?= number_format((float)$it['total_quantity'], 1, ',', '.') ?> <?= e($it['unit']) ?></td>
                                    <td class="text-end">Rp <?= number_format((float)($it['estimated_cost'] ?? 0), 0, ',', '.') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
