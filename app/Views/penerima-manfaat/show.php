<?php
/**
 * View Detail Satu Baris Penerima Manfaat
 */
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= url('/penerima-manfaat') ?>" class="text-decoration-none">← Kembali ke Rekap Penerima</a></p>
        <h1 class="page-title"><?= e($pageTitle) ?></h1>
        <p class="page-subtitle"><?= e($row['school_name']) ?> · <?= e($row['class_group']) ?> (<?= e($row['category_name']) ?>)</p>
    </div>
    <div class="page-actions">
        <?php if ($canManage): ?>
            <a class="btn btn-outline-secondary" href="<?= url('/penerima-manfaat/' . (int)$row['id'] . '/ubah') ?>">
                <i class="bi bi-pencil me-1"></i>Ubah Jumlah
            </a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-md-4">
        <div class="app-card p-3">
            <span class="text-muted small">JUMLAH SAAT INI</span>
            <h2 class="h3 fw-bold mb-0 text-primary"><?= number_format((int)$row['beneficiary_count'], 0, ',', '.') ?> Jiwa</h2>
            <small class="text-muted">Berlaku sejak <?= e(tanggal_id((string)$row['effective_date'])) ?></small>
        </div>
    </div>
    <div class="col-md-4">
        <div class="app-card p-3">
            <span class="text-muted small">TOTAL SEKOLAH</span>
            <h2 class="h3 fw-bold mb-0"><?= number_format((int)$schoolTotal, 0, ',', '.') ?> Jiwa</h2>
            <small class="text-muted">Semua kelompok di sekolah ini</small>
        </div>
    </div>
    <div class="col-md-4">
        <div class="app-card p-3">
            <span class="text-muted small">STATUS KELOMPOK</span>
            <div><span class="pill <?= (int)$row['is_active'] === 1 ? 'pill-success' : 'pill-muted' ?> fs-6"><?= (int)$row['is_active'] === 1 ? 'Aktif Menerima' : 'Nonaktif' ?></span></div>
        </div>
    </div>
</div>

<section class="app-card">
    <div class="card-header p-3 border-bottom">
        <h2 class="h6 fw-bold mb-0"><i class="bi bi-clock-history me-2"></i>Riwayat Perubahan Baris Ini</h2>
    </div>
    <div class="table-responsive">
        <table class="table app-table align-middle mb-0">
            <thead>
                <tr>
                    <th>Waktu Catat</th>
                    <th>Sebelumnya</th>
                    <th>Jumlah Baru</th>
                    <th>Alasan</th>
                    <th>Petugas</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($histories)): ?>
                    <tr>
                        <td colspan="5" class="text-center py-4 text-muted">Belum ada riwayat mutasi untuk baris ini.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($histories as $h): ?>
                        <tr>
                            <td><?= e(tanggal_id((string)$h['created_at'], true)) ?></td>
                            <td><?= (int)$h['previous_count'] ?></td>
                            <td><strong><?= (int)$h['new_count'] ?></strong></td>
                            <td><?= e($h['reason']) ?></td>
                            <td><?= e($h['user_name'] ?? 'Sistem') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
