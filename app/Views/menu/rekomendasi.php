<?php
/**
 * View Rekomendasi Menu MBG
 */
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= url('/menu') ?>" class="text-decoration-none">← Kembali ke Daftar Menu</a></p>
        <h1 class="page-title"><?= e($pageTitle ?? 'Rekomendasi Menu MBG') ?></h1>
        <p class="page-subtitle">Saran kombinasi menu harian yang seimbang gizi, variatif, dan sesuai anggaran acuan.</p>
    </div>
</div>

<div class="row g-4">
    <?php if (empty($suggestions)): ?>
        <div class="col-12">
            <section class="app-card p-5 text-center text-muted">
                <i class="bi bi-stars fs-1 d-block mb-3"></i>
                <h5>Belum ada rekomendasi menu yang dihitung</h5>
                <p>Silakan buat menu terverifikasi lebih banyak agar sistem rekomendasi variasi menu bekerja optimal.</p>
            </section>
        </div>
    <?php else: ?>
        <?php foreach ($suggestions as $s): ?>
            <div class="col-12 col-md-4">
                <div class="app-card p-3 h-100">
                    <h2 class="h6 fw-bold mb-2"><?= e($s['menu_name'] ?? 'Menu Rekomendasi') ?></h2>
                    <p class="small text-muted mb-3"><?= e($s['reason'] ?? 'Kombinasi bahan kaya protein dan mikronutrien.') ?></p>
                    <div class="d-flex justify-content-between align-items-center mt-auto border-top pt-2">
                        <span class="fw-bold text-primary">Rp <?= number_format((float)($s['cost'] ?? 0), 0, ',', '.') ?></span>
                        <a href="<?= url('/menu/' . ($s['id'] ?? '')) ?>" class="btn btn-sm btn-outline-primary">Lihat Menu</a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
