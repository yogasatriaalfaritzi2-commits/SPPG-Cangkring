<?php
/**
 * View Konfigurasi Target Gizi Kelompok Sasaran
 */
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= url('/analisis-gizi') ?>" class="text-decoration-none">← Kembali ke Analisis Gizi</a></p>
        <h1 class="page-title"><?= e($pageTitle) ?></h1>
        <p class="page-subtitle">Standar batas minimal & maksimal kecukupan energi dan zat gizi berdasarkan kelompok usia.</p>
    </div>
</div>

<div class="row g-4 mb-4">
    <!-- Daftar Kelompok Sasaran -->
    <div class="col-12 col-lg-4">
        <section class="app-card p-3 mb-4">
            <h2 class="h6 fw-bold mb-3"><i class="bi bi-people me-2"></i>Kelompok Sasaran</h2>
            <div class="list-group list-group-flush">
                <?php foreach ($groups as $g): ?>
                    <a href="<?= url('/target-gizi?kelompok=' . $g['id']) ?>" 
                       class="list-group-item list-group-item-action d-flex justify-content-between align-items-center <?= (int)$groupId === (int)$g['id'] ? 'active' : '' ?>">
                        <div>
                            <strong><?= e($g['group_name']) ?></strong>
                            <div class="small <?= (int)$groupId === (int)$g['id'] ? 'text-white-50' : 'text-muted' ?>">Usia: <?= e($g['age_min'] ?? '0') ?> - <?= e($g['age_max'] ?? '18') ?> th</div>
                        </div>
                        <span class="badge bg-light text-dark"><?= e($g['group_code']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    </div>

    <!-- Nilai Target Zat Gizi -->
    <div class="col-12 col-lg-8">
        <section class="app-card p-4">
            <h2 class="h6 fw-bold mb-3 border-bottom pb-2">Nilai Standar Gizi: <?= e($group['group_name'] ?? 'Pilih Kelompok') ?></h2>
            <div class="table-responsive">
                <table class="table app-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Parameter Zat Gizi</th>
                            <th class="text-end">Batas Min</th>
                            <th class="text-end">Batas Max</th>
                            <th>Satuan</th>
                            <th>Rujukan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($targets)): ?>
                            <tr>
                                <td colspan="5" class="text-center py-4 text-muted">Belum ada target zat gizi yang diatur untuk kelompok ini.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($targets as $t): ?>
                                <tr>
                                    <td><strong><?= e(nutrient_label((string)$t['nutrient_code'])) ?></strong></td>
                                    <td class="text-end fw-bold"><?= (float)$t['target_min'] ?></td>
                                    <td class="text-end fw-bold"><?= (float)$t['target_max'] ?></td>
                                    <td><?= e($t['nutrient_unit']) ?></td>
                                    <td><small class="badge bg-light text-dark border"><?= e($t['reference_code'] ?? 'AKG') ?></small></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
