<?php
/**
 * View Analisis Gizi Menu MBG
 */
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Operasional MBG</p>
        <h1 class="page-title"><?= e($pageTitle) ?></h1>
        <p class="page-subtitle">Evaluasi pemenuhan target nutrisi menu MBG terhadap standar kelompok sasaran peserta didik.</p>
    </div>
    <div class="page-actions d-flex gap-2">
        <a class="btn btn-outline-secondary" href="<?= url('/analisis-gizi/harian') ?>">
            <i class="bi bi-calendar3 me-1"></i>Evaluasi Harian
        </a>
        <a class="btn btn-outline-secondary" href="<?= url('/target-gizi') ?>">
            <i class="bi bi-bullseye me-1"></i>Konfigurasi Target
        </a>
        <?php if (can('nutrition.export')): ?>
            <a class="btn btn-outline-secondary" href="<?= query_url('/analisis-gizi/ekspor', $filters) ?>">
                <i class="bi bi-download me-1"></i>Ekspor CSV
            </a>
        <?php endif; ?>
    </div>
</div>

<section class="app-card">
    <div class="toolbar p-3 border-bottom">
        <form class="toolbar-form" method="get" action="<?= url('/analisis-gizi') ?>">
            <div class="toolbar-field">
                <label class="form-label" for="kelompok">Kelompok Sasaran</label>
                <select class="form-select form-select-sm" id="kelompok" name="kelompok">
                    <?php foreach ($groups as $g): ?>
                        <option value="<?= e($g['id']) ?>" <?= (int)$groupId === (int)$g['id'] ? 'selected' : '' ?>>
                            <?= e($g['group_name']) ?> (<?= e($g['group_code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="toolbar-actions">
                <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-funnel me-1"></i>Terapkan</button>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table app-table align-middle mb-0">
            <thead>
                <tr>
                    <th>Menu</th>
                    <th>Kategori</th>
                    <th class="text-end">Energi (kkal)</th>
                    <th class="text-end">Protein (g)</th>
                    <th class="text-end">Lemak (g)</th>
                    <th class="text-end">Karbohidrat (g)</th>
                    <th class="text-end">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($result['rows'])): ?>
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">Belum ada menu untuk dievaluasi.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($result['rows'] as $m): ?>
                        <?php $comp = Menu::compute((int)$m['id']); ?>
                        <tr>
                            <td>
                                <a href="<?= url('/menu/' . $m['id']) ?>" class="fw-bold text-decoration-none">
                                    <?= e($m['menu_name']) ?>
                                </a>
                                <div class="small text-muted"><?= e($m['menu_code']) ?></div>
                            </td>
                            <td><span class="badge bg-light text-dark border"><?= e($m['category_name'] ?? '-') ?></span></td>
                            <td class="text-end fw-bold text-primary"><?= number_format((float)($comp['energy'] ?? 0), 1, ',', '.') ?></td>
                            <td class="text-end"><?= number_format((float)($comp['protein'] ?? 0), 1, ',', '.') ?></td>
                            <td class="text-end"><?= number_format((float)($comp['fat'] ?? 0), 1, ',', '.') ?></td>
                            <td class="text-end"><?= number_format((float)($comp['carbohydrate'] ?? 0), 1, ',', '.') ?></td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-primary" href="<?= url('/analisis-gizi/' . $m['id']) ?>">
                                    Rapor Gizi
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
