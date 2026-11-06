<?php
/** @var string $content */
/** @var array<int, string> $pageScripts */
$documentTitle = ($title ?? 'Dashboard') . ' · ' . app_name();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Sistem Informasi Manajemen Pembangunan dan Operasional MBG SPPG Cangkring.">
    <title><?= e($documentTitle) ?></title>
    <link rel="icon" type="image/png" href="<?= asset('images/favicon.png') ?>">
    <link rel="stylesheet" href="<?= asset('vendor/bootstrap/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('css/app.css') ?>">
</head>
<body class="app-body">
<a class="skip-link" href="#main-content">Lewati ke konten utama</a>

<div class="app-shell">
    <?php require BASE_PATH . '/app/Views/partials/sidebar.php'; ?>

    <div class="app-main">
        <?php require BASE_PATH . '/app/Views/partials/navbar.php'; ?>

        <main class="app-content" id="main-content">
            <?php require BASE_PATH . '/app/Views/partials/flash.php'; ?>
            <?= $content ?>
        </main>

        <?php require BASE_PATH . '/app/Views/partials/footer.php'; ?>
    </div>
</div>

<div class="sidebar-backdrop" data-sidebar-close aria-hidden="true"></div>

<?php require BASE_PATH . '/app/Views/partials/confirm_modal.php'; ?>
<?php require BASE_PATH . '/app/Views/partials/command_palette.php'; ?>

<script src="<?= asset('vendor/bootstrap/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= asset('js/app.js') ?>"></script>
<?php foreach ($pageScripts ?? [] as $pageScript): ?>
    <script src="<?= asset($pageScript) ?>"></script>
<?php endforeach; ?>
</body>
</html>
