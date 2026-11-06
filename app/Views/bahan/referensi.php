<?php
/**
 * View Referensi Gizi
 */
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= url('/bahan') ?>" class="text-decoration-none">← Kembali ke Katalog Bahan</a></p>
        <h1 class="page-title"><?= e($pageTitle ?? 'Sumber Referensi Gizi') ?></h1>
        <p class="page-subtitle">Daftar tabel komposisi pangan dan acuan gizi resmi yang digunakan sebagai dasar perhitungan.</p>
    </div>
</div>

<section class="app-card mb-4">
    <div class="table-responsive">
        <table class="table app-table align-middle mb-0">
            <thead>
                <tr>
                    <th>Kode</th>
                    <th>Nama Sumber Referensi</th>
                    <th>Penerbit / Institusi</th>
                    <th>Tahun</th>
                    <th>Status Verifikasi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($references)): ?>
                    <tr>
                        <td colspan="5" class="text-center py-4 text-muted">Belum ada daftar referensi rujukan gizi.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($references as $ref): ?>
                        <tr>
                            <td><code><?= e($ref['reference_code']) ?></code></td>
                            <td><strong><?= e($ref['reference_name']) ?></strong></td>
                            <td><?= e($ref['publisher'] ?? '-') ?></td>
                            <td><?= e($ref['publication_year'] ?? '-') ?></td>
                            <td>
                                <span class="pill <?= (int)($ref['is_verified'] ?? 0) === 1 ? 'pill-success' : 'pill-warning' ?>">
                                    <?= (int)($ref['is_verified'] ?? 0) === 1 ? 'Terverifikasi' : 'Perlu Verifikasi' ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
