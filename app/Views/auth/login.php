<?php
$old = $old ?? [];
?>
<div class="auth-card">
    <div class="auth-card-head">
        <img class="auth-logo-mobile d-lg-none" src="<?= asset('images/logo-sppg.svg') ?>" alt="Logo SPPG Cangkring" width="52" height="52">
        <p class="eyebrow">Akun pengguna</p>
        <h1 class="auth-title">Masuk ke akun Anda</h1>
        <p class="auth-subtitle">Gunakan akun yang diberikan oleh administrator <?= e(app_name()) ?>.</p>
    </div>

    <?php require BASE_PATH . '/app/Views/partials/flash.php'; ?>

    <form class="app-form auth-form" method="post" action="<?= url('/login') ?>">
        <?= csrf_field() ?>

        <div class="form-group">
            <label class="form-label" for="login">Nama pengguna atau surel</label>
            <input
                class="form-control"
                type="text"
                id="login"
                name="login"
                value="<?= e($old['login'] ?? '') ?>"
                autocomplete="username"
                required
                autofocus
            >
        </div>

        <div class="form-group">
            <label class="form-label" for="password">Kata sandi</label>
            <div class="password-field">
                <input
                    class="form-control"
                    type="password"
                    id="password"
                    name="password"
                    autocomplete="current-password"
                    required
                >
                <button
                    class="password-toggle"
                    type="button"
                    data-password-toggle="password"
                    aria-label="Tampilkan kata sandi"
                    aria-pressed="false"
                >
                    <i class="bi bi-eye" aria-hidden="true"></i>
                </button>
            </div>
        </div>

        <button class="btn btn-primary w-100" type="submit">Masuk</button>
    </form>

    <p class="auth-help">Mengalami kendala akses? Hubungi administrator SPPG Cangkring.</p>
    <p class="auth-card-foot">Sistem Informasi MBG · Versi <?= e(\App\Core\App::config('app.version', '0.1.0')) ?></p>
</div>
