<?php
/** @var string $content */
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Masuk ke Sistem Informasi Manajemen Pembangunan dan Operasional MBG SPPG Cangkring.">
    <title><?= e(($title ?? 'Masuk') . ' · ' . app_name()) ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= asset('images/favicon.svg') ?>">
    <link rel="stylesheet" href="<?= asset('vendor/bootstrap/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('css/app.css') ?>">
</head>
<body class="auth-body">
<main class="auth-shell">
    <section class="auth-brand" aria-label="Tentang aplikasi">
        <div class="auth-brand-head">
            <img src="<?= asset('images/logo-sppg-lockup-inverted.svg') ?>" alt="Logo SPPG Cangkring" width="163" height="48">
            <span class="auth-brand-sub">Sistem Informasi MBG</span>
        </div>

        <div class="auth-brand-body">
            <p class="auth-eyebrow">Satuan Pelayanan Pemenuhan Gizi</p>
            <h2>Pengelolaan pembangunan dan operasional MBG dalam satu sistem.</h2>
            <p>Fondasi sistem informasi untuk mendukung pencatatan dan pelaporan SPPG Cangkring secara tertib.</p>
            <ul class="auth-points">
                <li><i class="bi bi-check2-circle" aria-hidden="true"></i><span>Akses pengguna dengan peran terkelola</span></li>
                <li><i class="bi bi-check2-circle" aria-hidden="true"></i><span>Pengaturan aplikasi terpusat</span></li>
                <li><i class="bi bi-check2-circle" aria-hidden="true"></i><span>Jejak aktivitas sistem</span></li>
            </ul>
        </div>

        <div class="auth-brand-foot">
            <span>SPPG Cangkring</span>
            <span><?= date('Y') ?></span>
        </div>
    </section>

    <section class="auth-panel">
        <?= $content ?>
    </section>
</main>

<script src="<?= asset('vendor/bootstrap/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= asset('js/app.js') ?>"></script>
</body>
</html>
