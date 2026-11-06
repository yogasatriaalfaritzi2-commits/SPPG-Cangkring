<?php
/**
 * View Penerima Manfaat Individu (Khusus / Alergi / Kebutuhan Khusus)
 */
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= url('/penerima-manfaat') ?>" class="text-decoration-none">← Kembali ke Rekap Penerima</a></p>
        <h1 class="page-title"><?= e($pageTitle ?? 'Data Individu Penerima Manfaat') ?></h1>
        <p class="page-subtitle">Pencatatan data individu bagi siswa atau penerima dengan kebutuhan khusus / pantangan alergi (Data Sensitif Terlindungi).</p>
    </div>
</div>

<section class="app-card p-4">
    <div class="alert alert-info small mb-4">
        <i class="bi bi-shield-check me-1"></i> Data NIK/KK tidak disimpan dalam sistem demi privasi dan keamanan data anak didik. Hanya nama dan catatan diet/alergi yang dikelola.
    </div>

    <div class="table-responsive">
        <table class="table app-table align-middle mb-0">
            <thead>
                <tr>
                    <th>Sekolah</th>
                    <th>Nama Penerima</th>
                    <th>Kelas</th>
                    <th>Catatan Alergi / Diet Khusus</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($individuals)): ?>
                    <tr>
                        <td colspan="5" class="text-center py-4 text-muted">Belum ada catatan data individu khusus.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($individuals as $ind): ?>
                        <tr>
                            <td><strong><?= e($ind['school_name']) ?></strong></td>
                            <td><?= e($ind['full_name']) ?></td>
                            <td><?= e($ind['class_group']) ?></td>
                            <td><span class="text-danger fw-semibold"><?= e($ind['dietary_notes'] ?? '-') ?></span></td>
                            <td><span class="pill pill-success">Aktif</span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
