<?php
/**
 * View Rekap Kebutuhan Bahan Pangan
 */
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= url('/jadwal-menu') ?>" class="text-decoration-none">← Kembali ke Kalender Jadwal</a></p>
        <h1 class="page-title"><?= e($pageTitle ?? 'Rekap Kebutuhan Bahan Pangan MBG') ?></h1>
        <p class="page-subtitle">Akumulasi seluruh bahan makanan yang diperlukan untuk produksi masakan sesuai jadwal terencana.</p>
    </div>
    <div class="page-actions">
        <?php if (can('nutrition.export')): ?>
            <a class="btn btn-outline-secondary" href="<?= query_url('/jadwal-menu/kebutuhan-bahan/ekspor', $filters ?? []) ?>">
                <i class="bi bi-download me-1"></i>Ekspor CSV
            </a>
        <?php endif; ?>
    </div>
</div>

<section class="app-card">
    <div class="table-responsive">
        <table class="table app-table align-middle mb-0">
            <thead>
                <tr>
                    <th>Bahan Pangan</th>
                    <th>Kategori</th>
                    <th class="text-end">Total Kebutuhan (Satuan Dasar)</th>
                    <th class="text-end">Kebutuhan (Satuan Beli)</th>
                    <th class="text-end">Estimasi Total Biaya</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($plan['items'])): ?>
                    <tr>
                        <td colspan="5" class="text-center py-5 text-muted">
                            <i class="bi bi-basket fs-2 d-block mb-2"></i>
                            Belum ada jadwal berstatus rencana untuk menghitung kebutuhan bahan.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($plan['items'] as $item): ?>
                        <tr>
                            <td><strong><?= e($item['ingredient_name']) ?></strong></td>
                            <td><?= e($item['category_name'] ?? '-') ?></td>
                            <td class="text-end"><?= number_format((float)$item['total_quantity'], 1, ',', '.') ?> <?= e($item['base_unit'] ?? 'g') ?></td>
                            <td class="text-end fw-bold text-primary">
                                <?= number_format((float)($item['purchase_quantity'] ?? $item['total_quantity']), 1, ',', '.') ?> <?= e($item['purchase_unit'] ?? $item['base_unit']) ?>
                            </td>
                            <td class="text-end">Rp <?= number_format((float)($item['estimated_cost'] ?? 0), 0, ',', '.') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
