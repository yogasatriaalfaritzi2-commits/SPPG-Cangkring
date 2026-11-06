<?php
/**
 * View Uji Transparan Aturan Menu
 */
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= url('/aturan-menu') ?>" class="text-decoration-none">← Kembali ke Aturan Menu</a></p>
        <h1 class="page-title"><?= e($pageTitle ?? 'Hasil Uji Evaluasi Menu') ?></h1>
    </div>
</div>

<section class="app-card p-4">
    <div class="table-responsive">
        <table class="table app-table align-middle mb-0">
            <thead>
                <tr>
                    <th>Aturan</th>
                    <th>Ambang Batas</th>
                    <th>Nilai Menu</th>
                    <th>Hasil Uji</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($findings)): ?>
                    <tr>
                        <td colspan="4" class="text-center py-4 text-muted">Semua aturan lulus.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($findings as $f): ?>
                        <tr>
                            <td><strong><?= e($f['rule_name'] ?? '-') ?></strong></td>
                            <td><?= (float)($f['threshold'] ?? 0) ?></td>
                            <td class="fw-bold"><?= (float)($f['actual'] ?? 0) ?></td>
                            <td>
                                <span class="badge <?= ($f['passed'] ?? false) ? 'bg-success' : 'bg-danger' ?>">
                                    <?= ($f['passed'] ?? false) ? 'Lulus' : 'Tidak Memenuhi' ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
