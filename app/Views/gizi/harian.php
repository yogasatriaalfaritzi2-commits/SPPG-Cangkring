<?php
/**
 * View Evaluasi Gizi Harian
 */
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= url('/analisis-gizi') ?>" class="text-decoration-none">← Kembali ke Analisis Gizi</a></p>
        <h1 class="page-title"><?= e($pageTitle) ?></h1>
        <p class="page-subtitle">Evaluasi kumulatif asupan gizi harian per siswa dari jadwal distribusi makan.</p>
    </div>
</div>

<section class="app-card mb-4">
    <div class="table-responsive">
        <table class="table app-table align-middle mb-0">
            <thead>
                <tr>
                    <th>Tanggal</th>
                    <th>Menu Terjadwal</th>
                    <th class="text-end">Total Energi</th>
                    <th class="text-end">Total Protein</th>
                    <th>Status Target Harian</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="5" class="text-center py-5 text-muted">Belum ada data evaluasi harian pada periode ini.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?= e(tanggal_id((string)$r['date'])) ?></td>
                            <td>
                                <?php foreach ($r['menus'] as $m): ?>
                                    <span class="badge bg-light text-dark border me-1"><?= e($m['menu_name']) ?></span>
                                <?php endforeach; ?>
                            </td>
                            <td class="text-end fw-bold text-primary"><?= number_format((float)($r['values']['energy'] ?? 0), 1, ',', '.') ?> kkal</td>
                            <td class="text-end"><?= number_format((float)($r['values']['protein'] ?? 0), 1, ',', '.') ?> g</td>
                            <td><span class="pill pill-success">Tercapai</span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
