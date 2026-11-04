<div class="page-head">
    <div>
        <p class="eyebrow">Akun</p>
        <h1 class="page-title">Profil saya</h1>
        <p class="page-subtitle">Informasi akun dan pengelolaan kata sandi.</p>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-lg-4">
        <section class="app-card profile-card">
            <span class="avatar avatar-lg"><?= e(initials((string) $user['full_name'])) ?></span>
            <h2 class="profile-name"><?= e($user['full_name']) ?></h2>
            <p class="profile-role"><?= e(role_nama($user)) ?></p>
            <dl class="profile-meta">
                <div>
                    <dt>Status akun</dt>
                    <dd><?= e(status_label($user['status'])) ?></dd>
                </div>
                <div>
                    <dt>Bergabung</dt>
                    <dd><?= e(tanggal_id((string) $user['created_at'])) ?></dd>
                </div>
            </dl>
        </section>
    </div>

    <div class="col-12 col-lg-8">
        <section class="app-card">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Informasi akun</h2>
                    <p class="card-subtitle">Data akun yang tersimpan pada sistem.</p>
                </div>
            </header>
            <dl class="detail-list">
                <div class="detail-row">
                    <dt>Nama lengkap</dt>
                    <dd><?= e($user['full_name']) ?></dd>
                </div>
                <div class="detail-row">
                    <dt>Nama pengguna</dt>
                    <dd><?= e($user['username']) ?></dd>
                </div>
                <div class="detail-row">
                    <dt>Surel</dt>
                    <dd><?= e($user['email']) ?></dd>
                </div>
                <div class="detail-row">
                    <dt>Telepon</dt>
                    <dd><?= e($user['phone'] !== null && $user['phone'] !== '' ? (string) $user['phone'] : 'Belum diisi') ?></dd>
                </div>
                <div class="detail-row">
                    <dt>Peran</dt>
                    <dd>
                        <?= e(role_nama($user)) ?>
                        <span class="text-muted"> · <?= e(status_label($user['role_active'])) ?></span>
                    </dd>
                </div>
                <div class="detail-row">
                    <dt>Izin aktif</dt>
                    <dd><?= e((string) $permissionCount) ?> izin aktif dari <?= e((string) $permissionTotal) ?> izin sistem</dd>
                </div>
                <div class="detail-row">
                    <dt>Login terakhir</dt>
                    <dd>
                        <?php if (!empty($user['last_login'])): ?>
                            <?= e(tanggal_id((string) $user['last_login'], true)) ?>
                            <?php if (!empty($user['last_login_ip'])): ?>
                                <span class="text-muted"> · IP <?= e($user['last_login_ip']) ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            Belum pernah tercatat
                        <?php endif; ?>
                    </dd>
                </div>
                <div class="detail-row">
                    <dt>Terakhir diperbarui</dt>
                    <dd><?= e(tanggal_id((string) $user['updated_at'], true)) ?></dd>
                </div>
            </dl>
        </section>

        <section class="app-card mt-3">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Ubah kata sandi</h2>
                    <p class="card-subtitle">Minimal 8 karakter, memuat huruf dan angka.</p>
                </div>
            </header>

            <form class="app-form" method="post" action="<?= url('/profil/kata-sandi') ?>">
                <?= csrf_field() ?>

                <div class="form-group">
                    <label class="form-label" for="current_password">Kata sandi saat ini</label>
                    <div class="password-field">
                        <input class="form-control" type="password" id="current_password" name="current_password" autocomplete="current-password" required>
                        <button class="password-toggle" type="button" data-password-toggle="current_password" aria-label="Tampilkan kata sandi" aria-pressed="false">
                            <i class="bi bi-eye" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <div class="form-group mb-0">
                            <label class="form-label" for="new_password">Kata sandi baru</label>
                            <div class="password-field">
                                <input class="form-control" type="password" id="new_password" name="new_password" autocomplete="new-password" minlength="8" maxlength="72" required>
                                <button class="password-toggle" type="button" data-password-toggle="new_password" aria-label="Tampilkan kata sandi" aria-pressed="false">
                                    <i class="bi bi-eye" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6">
                        <div class="form-group mb-0">
                            <label class="form-label" for="new_password_confirmation">Ulangi kata sandi baru</label>
                            <div class="password-field">
                                <input class="form-control" type="password" id="new_password_confirmation" name="new_password_confirmation" autocomplete="new-password" minlength="8" maxlength="72" required>
                                <button class="password-toggle" type="button" data-password-toggle="new_password_confirmation" aria-label="Tampilkan kata sandi" aria-pressed="false">
                                    <i class="bi bi-eye" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <button class="btn btn-primary" type="submit">Simpan kata sandi</button>
                </div>
            </form>
        </section>
    </div>
</div>
