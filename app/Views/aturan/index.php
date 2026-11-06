<?php
/**
 * View Index Aturan Rekomendasi Menu MBG
 */
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Operasional MBG</p>
        <h1 class="page-title"><?= e($pageTitle) ?></h1>
        <p class="page-subtitle">Aturan logika dan validasi gizi menu harian serta variasi resep mingguan.</p>
    </div>
</div>

<div class="row g-4">
    <div class="col-12 col-lg-8">
        <section class="app-card">
            <div class="table-responsive">
                <table class="table app-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Kode & Nama Aturan</th>
                            <th>Lingkup</th>
                            <th>Batas / Ambang</th>
                            <th>Tingkat</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rules)): ?>
                            <tr>
                                <td colspan="5" class="text-center py-4 text-muted">Belum ada aturan validasi menu.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rules as $r): ?>
                                <tr>
                                    <td>
                                        <strong><?= e($r['rule_name']) ?></strong>
                                        <div class="small text-muted"><code><?= e($r['rule_code']) ?></code> · <?= e($r['rule_type']) ?></div>
                                    </td>
                                    <td><span class="badge bg-light text-dark border"><?= e($scopes[$r['applies_to']] ?? $r['applies_to']) ?></span></td>
                                    <td class="fw-bold"><?= (float)$r['threshold_value'] ?></td>
                                    <td>
                                        <?php $sev = $r['rule_severity'] ?? $r['severity'] ?? 'peringatan'; ?>
                                        <span class="badge <?= $sev === 'harus' ? 'bg-danger' : ($sev === 'info' ? 'bg-info' : 'bg-warning text-dark') ?>">
                                            <?= e(ucfirst((string)$sev)) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="pill <?= (int)($r['is_active'] ?? 1) === 1 ? 'pill-success' : 'pill-muted' ?>">
                                            <?= (int)($r['is_active'] ?? 1) === 1 ? 'Aktif' : 'Nonaktif' ?>
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

    <!-- Panel Informasi Aturan -->
    <div class="col-12 col-lg-4">
        <section class="app-card p-3 mb-4">
            <h2 class="h6 fw-bold mb-3"><i class="bi bi-info-circle me-2"></i>Tentang Aturan Menu</h2>
            <p class="small text-muted mb-2">
                Aturan menu dievaluasi secara otomatis setiap kali menu MBG dirancang atau diajukan untuk diverifikasi ahli gizi.
            </p>
            <div class="p-2 border rounded bg-light small mb-2">
                <strong>Aturan Menu Tunggal:</strong> <?= count($activeMenu) ?> aturan aktif
            </div>
            <div class="p-2 border rounded bg-light small">
                <strong>Aturan Rencana Mingguan:</strong> <?= count($activeWeek) ?> aturan aktif
            </div>
        </section>
    </div>
</div>
