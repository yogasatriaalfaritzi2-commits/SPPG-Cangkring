<?php
/**
 * View Rapor Detail Analisis Gizi Menu
 */
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= url('/analisis-gizi') ?>" class="text-decoration-none">← Kembali ke Analisis Gizi</a></p>
        <h1 class="page-title"><?= e($pageTitle ?? 'Rapor Analisis Gizi Menu') ?></h1>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-12 col-lg-8">
        <section class="app-card p-4">
            <h2 class="h6 fw-bold mb-3 border-bottom pb-2">Komparasi Pemenuhan Gizi per Porsi</h2>
            <div class="table-responsive">
                <table class="table app-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Parameter Zat Gizi</th>
                            <th class="text-end">Kandungan Menu</th>
                            <th class="text-end">Standar Target</th>
                            <th>Status Pemenuhan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($evaluation['nutrients'] ?? [])): ?>
                            <tr>
                                <td colspan="4" class="text-center py-4 text-muted">Belum ada target yang dikonfigurasi untuk kelompok sasaran ini.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($evaluation['nutrients'] as $nut): ?>
                                <tr>
                                    <td><strong><?= e(nutrient_label((string)$nut['nutrient_code'])) ?></strong></td>
                                    <td class="text-end fw-bold"><?= (float)($nut['actual_value'] ?? 0) ?> <?= e($nut['unit'] ?? '') ?></td>
                                    <td class="text-end"><?= (float)($nut['target_min'] ?? 0) ?> - <?= (float)($nut['target_max'] ?? 0) ?> <?= e($nut['unit'] ?? '') ?></td>
                                    <td>
                                        <span class="badge <?= ($nut['status'] ?? '') === 'sesuai' ? 'bg-success' : 'bg-warning text-dark' ?>">
                                            <?= e(ucfirst((string)($nut['status'] ?? 'Dievaluasi'))) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
