<?php
$actor = auth_user();
$actorId = $actor !== null ? (int) $actor['id'] : 0;
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Administrasi</p>
        <h1 class="page-title">Manajemen Pengguna</h1>
        <p class="page-subtitle">
            Menambah akun, menyesuaikan peran, serta mengaktifkan atau menonaktifkan akses.
        </p>
    </div>
    <?php if (can('users.create')): ?>
        <div class="page-actions">
            <a class="btn btn-primary" href="<?= url('/pengguna/tambah') ?>">
                <i class="bi bi-person-plus me-1" aria-hidden="true"></i>Tambah pengguna
            </a>
        </div>
    <?php endif; ?>
</div>

<section class="app-card">
    <div class="toolbar">
        <form class="toolbar-form" method="get" action="<?= url('/pengguna') ?>">
            <div class="toolbar-field">
                <label class="form-label" for="q">Cari</label>
                <input class="form-control form-control-sm" type="search" id="q" name="q"
                       value="<?= e($filters['q']) ?>" maxlength="60" placeholder="Nama, nama pengguna, atau surel">
            </div>
            <div class="toolbar-field">
                <label class="form-label" for="role">Peran</label>
                <select class="form-select form-select-sm" id="role" name="role">
                    <option value="">Semua peran</option>
                    <?php foreach ($roles as $role): ?>
                        <option value="<?= e((string) $role['id']) ?>" <?= (int) $filters['role'] === (int) $role['id'] ? 'selected' : '' ?>>
                            <?= e($role['role_name']) ?> (<?= e((string) $role['user_count']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="toolbar-field">
                <label class="form-label" for="status">Status</label>
                <select class="form-select form-select-sm" id="status" name="status">
                    <option value="">Semua status</option>
                    <option value="active" <?= $filters['status'] === 'active' ? 'selected' : '' ?>>Aktif</option>
                    <option value="inactive" <?= $filters['status'] === 'inactive' ? 'selected' : '' ?>>Nonaktif</option>
                </select>
            </div>
            <div class="toolbar-actions">
                <button class="btn btn-sm btn-primary" type="submit">
                    <i class="bi bi-search me-1" aria-hidden="true"></i>Tampilkan
                </button>
                <?php if ($filters['q'] !== '' || $filters['role'] > 0 || $filters['status'] !== ''): ?>
                    <a class="btn btn-sm btn-link" href="<?= url('/pengguna') ?>">Bersihkan</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table app-table align-middle mb-0">
            <thead>
                <tr>
                    <th scope="col">Pengguna</th>
                    <th scope="col">Kontak</th>
                    <th scope="col">Peran</th>
                    <th scope="col">Status</th>
                    <th scope="col">Login terakhir</th>
                    <th scope="col" class="text-end">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($users === []): ?>
                    <tr>
                        <td colspan="6" class="table-empty">
                            <div class="empty-state">
                                <i class="bi bi-inbox" aria-hidden="true"></i>
                                <p class="empty-title">Tidak ada pengguna yang cocok</p>
                                <p class="empty-text">Ubah kata kunci, peran, atau status pada filter di atas.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($users as $user): ?>
                    <?php $isSelf = (int) $user['id'] === $actorId; ?>
                    <tr>
                        <td>
                            <div class="cell-user">
                                <span class="avatar avatar-sm"><?= e(initials((string) $user['full_name'])) ?></span>
                                <span class="cell-user-text">
                                    <span class="cell-strong"><?= e($user['full_name']) ?><?= $isSelf ? ' <span class="text-muted small">(anda)</span>' : '' ?></span>
                                    <span class="cell-sub"><?= e($user['username']) ?></span>
                                </span>
                            </div>
                        </td>
                        <td>
                            <span class="cell-strong d-block"><?= e($user['email']) ?></span>
                            <span class="cell-sub"><?= e($user['phone'] !== null && $user['phone'] !== '' ? (string) $user['phone'] : 'Telepon belum diisi') ?></span>
                        </td>
                        <td>
                            <span class="cell-strong d-block"><?= e($user['role_name']) ?></span>
                            <?php if ((int) $user['role_active'] !== 1): ?>
                                <span class="pill pill-warning">Peran nonaktif</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="pill <?= $user['status'] === \App\Models\User::STATUS_ACTIVE ? 'pill-success' : 'pill-muted' ?>">
                                <?= e(status_label($user['status'])) ?>
                            </span>
                        </td>
                        <td class="text-nowrap">
                            <?php if (!empty($user['last_login'])): ?>
                                <span class="cell-strong d-block"><?= e(tanggal_id((string) $user['last_login'], true)) ?></span>
                                <span class="cell-sub"><?= e($user['last_login_ip'] !== null && $user['last_login_ip'] !== '' ? (string) $user['last_login_ip'] : 'IP tidak tercatat') ?></span>
                            <?php else: ?>
                                <span class="cell-sub">Belum pernah masuk</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="cell-actions">
                                <?php if (can('users.update')): ?>
                                    <a class="btn btn-sm btn-outline-secondary" href="<?= url('/pengguna/' . $user['id']) ?>">Sunting</a>
                                <?php endif; ?>

                                <?php if (can('users.update') && !$isSelf): ?>
                                    <form method="post" action="<?= url('/pengguna/' . $user['id'] . '/status') ?>"
                                          data-confirm
                                          data-confirm-title="Ubah status akun"
                                          data-confirm-text="<?= e($user['status'] === \App\Models\User::STATUS_ACTIVE
                                              ? 'Nonaktifkan akun "' . $user['username'] . '"? Akun tidak dapat masuk lagi.'
                                              : 'Aktifkan kembali akun "' . $user['username'] . '"?') ?>"
                                          data-confirm-label="<?= $user['status'] === \App\Models\User::STATUS_ACTIVE ? 'Ya, nonaktifkan' : 'Ya, aktifkan' ?>"
                                          data-confirm-tone="warning">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="status" value="<?= $user['status'] === \App\Models\User::STATUS_ACTIVE ? 'inactive' : 'active' ?>">
                                        <button class="btn btn-sm btn-outline-secondary" type="submit">
                                            <?= $user['status'] === \App\Models\User::STATUS_ACTIVE ? 'Nonaktifkan' : 'Aktifkan' ?>
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <?php if (can('users.delete') && !$isSelf): ?>
                                    <form method="post" action="<?= url('/pengguna/' . $user['id'] . '/hapus') ?>"
                                          data-confirm
                                          data-confirm-title="Hapus akun"
                                          data-confirm-text="<?= e('Hapus permanen akun "' . $user['username'] . '"? Log aktivitas akun tetap tersimpan tanpa nama pengguna.') ?>"
                                          data-confirm-label="Ya, hapus"
                                          data-confirm-tone="danger">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm btn-outline-danger" type="submit">Hapus</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php
    $basePath = '/pengguna';
    $query = array_filter([
        'q' => $filters['q'],
        'role' => $filters['role'] > 0 ? $filters['role'] : '',
        'status' => $filters['status'],
    ], static fn (mixed $value): bool => $value !== '' && $value !== 0);
    require BASE_PATH . '/app/Views/partials/pagination.php';
    ?>
</section>
