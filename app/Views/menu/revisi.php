<?php
/**
 * View Usulan Revisi Menu MBG
 */
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= url('/menu') ?>" class="text-decoration-none">← Kembali ke Daftar Menu</a></p>
        <h1 class="page-title"><?= e($pageTitle ?? 'Daftar Usulan Revisi Menu') ?></h1>
        <p class="page-subtitle">Daftar menu yang sedang dalam proses pengajuan perubahan atau perbaikan resep.</p>
    </div>
</div>

<section class="app-card">
    <div class="table-responsive">
        <table class="table app-table align-middle mb-0">
            <thead>
                <tr>
                    <th>Kode Revisi</th>
                    <th>Menu Asal</th>
                    <th>Versi Usulan</th>
                    <th>Alasan Revisi</th>
                    <th>Pengusul</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($revisions)): ?>
                    <tr>
                        <td colspan="6" class="text-center py-4 text-muted">Tidak ada pengajuan revisi menu yang aktif.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($revisions as $rev): ?>
                        <tr>
                            <td><code><?= e($rev['revision_code']) ?></code></td>
                            <td><strong><?= e($rev['menu_name']) ?></strong></td>
                            <td><span class="badge bg-secondary">v<?= (int)$rev['target_version'] ?></span></td>
                            <td><?= e($rev['revision_reason']) ?></td>
                            <td><?= e($rev['user_name'] ?? 'Petugas') ?></td>
                            <td><span class="pill pill-warning">Menunggu Keputusan</span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
