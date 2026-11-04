<?php
/** @var string $content */
$actionHref = $actionHref ?? url('/login');
$actionLabel = $actionLabel ?? 'Kembali ke halaman masuk';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= e(($title ?? 'Terjadi kesalahan') . ' · ' . app_name()) ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= asset('images/favicon.svg') ?>">
    <link rel="stylesheet" href="<?= asset('vendor/bootstrap/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('css/app.css') ?>">
</head>
<body class="error-body">
<main class="error-shell">
    <a class="error-brand" href="<?= url('/login') ?>">
        <img src="<?= asset('images/logo-sppg-lockup.svg') ?>" alt="Logo SPPG Cangkring" width="139" height="41">
    </a>

    <section class="error-card">
        <p class="error-code"><?= e($code ?? '') ?></p>
        <h1><?= e($title ?? 'Terjadi kesalahan') ?></h1>
        <p class="error-message"><?= e($message ?? '') ?></p>

        <?php if (!empty($errorId)): ?>
            <p class="error-ref">Referensi kejadian: <span><?= e($errorId) ?></span></p>
        <?php endif; ?>

        <?php if (!empty($exception)): ?>
            <details class="error-debug">
                <summary>Detail teknis (mode debug)</summary>
                <pre><?= e(
                    $exception::class . ': ' . $exception->getMessage() . "\n"
                    . $exception->getFile() . ':' . $exception->getLine() . "\n\n"
                    . $exception->getTraceAsString()
                ) ?></pre>
            </details>
        <?php endif; ?>

        <?php if (support_email() !== ''): ?>
            <p class="error-ref">Bantuan: <a href="mailto:<?= e(support_email()) ?>"><?= e(support_email()) ?></a></p>
        <?php endif; ?>

        <a class="btn btn-primary" href="<?= e($actionHref) ?>"><?= e($actionLabel) ?></a>
    </section>

    <p class="error-foot">&copy; <?= date('Y') ?> <?= e(institution_name()) ?> · Sistem Informasi MBG</p>
</main>
</body>
</html>
