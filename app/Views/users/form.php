<?php
$isEdit = $mode === 'edit';
$userId = $isEdit ? (int) $target['id'] : 0;
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Administrasi</p>
        <h1 class="page-title"><?= $isEdit ? 'Sunting pengguna' : 'Tambah pengguna' ?></h1>
        <p class="page-subtitle">
            <?= $isEdit
                ? 'Perubahan disimpan setelah tombol simpan ditekan. Setiap perubahan tercatat pada log aktivitas.'
                : 'Isi data akun baru. Nama pengguna dan surel tidak boleh sama dengan akun yang sudah ada.' ?>
        </p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline-secondary" href="<?= url('/pengguna') ?>">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Kembali ke daftar
        </a>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-lg-8">
        <section class="app-card">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Data akun</h2>
                    <p class="card-subtitle">Kolom bertanda bintang wajib diisi.</p>
                </div>
            </header>

            <form class="app-form" method="post" action="<?= $isEdit ? url('/pengguna/' . $userId) : url('/pengguna/tambah') ?>">
                <?= csrf_field() ?>

                <div class="row g-3">
                    <div class="col-12">
                        <div class="form-group mb-0">
                            <label class="form-label" for="full_name">Nama lengkap *</label>
                            <input class="form-control" type="text" id="full_name" name="full_name"
                                   value="<?= e($form['full_name']) ?>" maxlength="120" required>
                        </div>
                    </div>

                    <div class="col-12 col-md-6">
                        <div class="form-group mb-0">
                            <label class="form-label" for="username">Nama pengguna *</label>
                            <input class="form-control" type="text" id="username" name="username"
                                   value="<?= e($form['username']) ?>" maxlength="40" required
                                   pattern="[a-zA-Z0-9][a-zA-Z0-9._\-]{2,39}" autocomplete="off">
                            <div class="form-text">Huruf kecil, angka, titik, garis bawah, atau tanda hubung. 3-40 karakter.</div>
                        </div>
                    </div>

                    <div class="col-12 col-md-6">
                        <div class="form-group mb-0">
                            <label class="form-label" for="email">Surel *</label>
                            <input class="form-control" type="email" id="email" name="email"
                                   value="<?= e($form['email']) ?>" maxlength="160" required>
                        </div>
                    </div>

                    <div class="col-12 col-md-6">
                        <div class="form-group mb-0">
                            <label class="form-label" for="phone">Nomor telepon</label>
                            <input class="form-control" type="tel" id="phone" name="phone"
                                   value="<?= e($form['phone']) ?>" maxlength="30" placeholder="081234567890">
                            <div class="form-text">Opsional. Contoh 081234567890 atau +6281234567890.</div>
                        </div>
                    </div>

                    <div class="col-12 col-md-6">
                        <div class="form-group mb-0">
                            <label class="form-label" for="role_id">Peran *</label>
                            <select class="form-select" id="role_id" name="role_id" required>
                                <option value="">Pilih peran</option>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?= e((string) $role['id']) ?>" <?= (int) $form['role_id'] === (int) $role['id'] ? 'selected' : '' ?>>
                                        <?= e($role['role_name']) ?>
                                        <?= (int) ($role['is_active'] ?? 1) === 1 ? '' : ' (nonaktif)' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <?php if ($isEdit): ?>
                        <div class="col-12 col-md-6">
                            <div class="form-group mb-0">
                                <label class="form-label" for="status">Status akun *</label>
                                <select class="form-select" id="status" name="status" <?= $isSelf ? 'disabled' : '' ?> <?= $isSelf ? '' : 'required' ?>>
                                    <option value="active" <?= $form['status'] === \App\Models\User::STATUS_ACTIVE ? 'selected' : '' ?>>Aktif</option>
                                    <option value="inactive" <?= $form['status'] === \App\Models\User::STATUS_INACTIVE ? 'selected' : '' ?>>Nonaktif</option>
                                </select>
                                <?php if ($isSelf): ?>
                                    <input type="hidden" name="status" value="<?= e($form['status']) ?>">
                                    <div class="form-text">Status akun sendiri tidak dapat diubah dari halaman ini.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="col-12 col-md-6">
                            <div class="form-group mb-0">
                                <label class="form-label" for="status">Status akun *</label>
                                <select class="form-select" id="status" name="status" required>
                                    <option value="active" <?= $form['status'] === \App\Models\User::STATUS_ACTIVE ? 'selected' : '' ?>>Aktif</option>
                                    <option value="inactive" <?= $form['status'] === \App\Models\User::STATUS_INACTIVE ? 'selected' : '' ?>>Nonaktif</option>
                                </select>
                            </div>
                        </div>

                        <div class="col-12 col-md-6">
                            <div class="form-group mb-0">
                                <label class="form-label" for="password">Kata sandi *</label>
                                <div class="password-field">
                                    <input class="form-control" type="password" id="password" name="password"
                                           autocomplete="new-password" minlength="8" maxlength="72" required>
                                    <button class="password-toggle" type="button" data-password-toggle="password"
                                            aria-label="Tampilkan kata sandi" aria-pressed="false">
                                        <i class="bi bi-eye" aria-hidden="true"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-md-6">
                            <div class="form-group mb-0">
                                <label class="form-label" for="password_confirmation">Ulangi kata sandi *</label>
                                <div class="password-field">
                                    <input class="form-control" type="password" id="password_confirmation" name="password_confirmation"
                                           autocomplete="new-password" minlength="8" maxlength="72" required>
                                    <button class="password-toggle" type="button" data-password-toggle="password_confirmation"
                                            aria-label="Tampilkan kata sandi" aria-pressed="false">
                                        <i class="bi bi-eye" aria-hidden="true"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="form-actions">
                    <button class="btn btn-primary" type="submit">
                        <?= $isEdit ? 'Simpan perubahan' : 'Simpan pengguna baru' ?>
                    </button>
                    <a class="btn btn-link" href="<?= url('/pengguna') ?>">Batal</a>
                </div>
            </form>
        </section>
    </div>

    <div class="col-12 col-lg-4">
        <?php if ($isEdit): ?>
            <aside class="app-card mb-3">
                <header class="card-head">
                    <div>
                        <h2 class="card-title">Ringkasan akun</h2>
                        <p class="card-subtitle">Kondisi akun saat ini.</p>
                    </div>
                </header>
                <dl class="info-list">
                    <div>
                        <dt>Status</dt>
                        <dd>
                            <span class="pill <?= $target['status'] === \App\Models\User::STATUS_ACTIVE ? 'pill-success' : 'pill-muted' ?>">
                                <?= e(status_label($target['status'])) ?>
                            </span>
                        </dd>
                    </div>
                    <div>
                        <dt>Peran</dt>
                        <dd>
                            <?= e($target['role_name']) ?>
                            <span class="d-block text-muted small"><?= e(status_label($target['role_active'])) ?> · <?= e((string) $permissionCount) ?> izin aktif</span>
                        </dd>
                    </div>
                    <div>
                        <dt>Dibuat</dt>
                        <dd><?= e(tanggal_id((string) $target['created_at'], true)) ?></dd>
                    </div>
                    <div>
                        <dt>Login terakhir</dt>
                        <dd>
                            <?= !empty($target['last_login']) ? e(tanggal_id((string) $target['last_login'], true)) : 'Belum pernah masuk' ?>
                        </dd>
                    </div>
                    <div>
                        <dt>Catatan aktivitas</dt>
                        <dd><?= e((string) $activityCount) ?> entri</dd>
                    </div>
                </dl>
            </aside>

            <?php if (can('users.update')): ?>
                <section class="app-card mb-3">
                    <header class="card-head">
                        <div>
                            <h2 class="card-title">Ganti kata sandi</h2>
                            <p class="card-subtitle">Kata sandi lama tidak ditampilkan dan tidak dikirim ke log.</p>
                        </div>
                    </header>
                    <form class="app-form" method="post" action="<?= url('/pengguna/' . $userId . '/kata-sandi') ?>"
                          data-confirm
                          data-confirm-title="Ganti kata sandi"
                          data-confirm-text="<?= e('Kata sandi akun "' . $target['username'] . '" akan diganti. Pengguna harus masuk ulang dengan kata sandi baru.') ?>"
                          data-confirm-label="Ya, ganti"
                          data-confirm-tone="warning">
                        <?= csrf_field() ?>

                        <div class="form-group">
                            <label class="form-label" for="new_password">Kata sandi baru</label>
                            <div class="password-field">
                                <input class="form-control" type="password" id="new_password" name="password"
                                       autocomplete="new-password" minlength="8" maxlength="72" required>
                                <button class="password-toggle" type="button" data-password-toggle="new_password"
                                        aria-label="Tampilkan kata sandi" aria-pressed="false">
                                    <i class="bi bi-eye" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="new_password_confirmation">Ulangi kata sandi baru</label>
                            <div class="password-field">
                                <input class="form-control" type="password" id="new_password_confirmation" name="password_confirmation"
                                       autocomplete="new-password" minlength="8" maxlength="72" required>
                                <button class="password-toggle" type="button" data-password-toggle="new_password_confirmation"
                                        aria-label="Tampilkan kata sandi" aria-pressed="false">
                                    <i class="bi bi-eye" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button class="btn btn-primary" type="submit">Ganti kata sandi</button>
                        </div>
                    </form>
                </section>
            <?php endif; ?>

            <?php if (can('users.delete') && !$isSelf): ?>
                <section class="app-card card-danger">
                    <header class="card-head">
                        <div>
                            <h2 class="card-title">Hapus akun</h2>
                            <p class="card-subtitle">Tindakan ini tidak dapat dibatalkan.</p>
                        </div>
                    </header>
                    <p class="muted-note">
                        Pertimbangkan menonaktifkan akun agar riwayat tetap terhubung ke nama pengguna.
                        Super Admin aktif terakhir tidak dapat dihapus.
                    </p>
                    <form class="app-form" method="post" action="<?= url('/pengguna/' . $userId . '/hapus') ?>"
                          data-confirm
                          data-confirm-title="Hapus akun"
                          data-confirm-text="<?= e('Hapus permanen akun "' . $target['username'] . '"?') ?>"
                          data-confirm-label="Ya, hapus"
                          data-confirm-tone="danger">
                        <?= csrf_field() ?>
                        <div class="form-actions">
                            <button class="btn btn-outline-danger" type="submit">Hapus akun</button>
                        </div>
                    </form>
                </section>
            <?php endif; ?>
        <?php else: ?>
            <aside class="app-card">
                <header class="card-head">
                    <div>
                        <h2 class="card-title">Kebijakan akun</h2>
                        <p class="card-subtitle">Aturan yang berlaku saat akun dibuat.</p>
                    </div>
                </header>
                <ul class="rule-list">
                    <li><i class="bi bi-shield-lock" aria-hidden="true"></i>Kata sandi minimal 8 karakter, memuat huruf dan angka, tanpa spasi.</li>
                    <li><i class="bi bi-person-check" aria-hidden="true"></i>Nama pengguna dan surel harus unik di seluruh sistem.</li>
                    <li><i class="bi bi-slash-circle" aria-hidden="true"></i>Akun nonaktif ditolak saat masuk tanpa memberi tahu penyebabnya.</li>
                    <li><i class="bi bi-journal-text" aria-hidden="true"></i>Setiap penambahan dan perubahan akun tercatat pada log aktivitas.</li>
                </ul>
            </aside>
        <?php endif; ?>
    </div>
</div>
