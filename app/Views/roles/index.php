<?php
$selectedId = $selected !== null ? (int) $selected['id'] : 0;
$isSuperRole = $selected !== null && (string) $selected['role_slug'] === \App\Models\Role::SUPER_ADMIN_SLUG;

$grantedMap = [];
foreach ($granted as $permissionId) {
    $grantedMap[(int) $permissionId] = true;
}

$permissionCountByRole = [];
foreach ($matrix as $roleId => $grantedPermissions) {
    $permissionCountByRole[(int) $roleId] = count($grantedPermissions);
}
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Administrasi</p>
        <h1 class="page-title">Peran &amp; Hak Akses</h1>
        <p class="page-subtitle">
            <?= e((string) count($roles)) ?> peran dan <?= e((string) count($permissions)) ?> izin terdaftar. Izin dibaca ulang
            pada setiap permintaan, sehingga perubahan langsung berlaku tanpa mengubah kode aplikasi.
        </p>
    </div>
</div>

<div class="notice-box" role="note">
    <i class="bi bi-shield-lock" aria-hidden="true"></i>
    <p>Peran Super Admin selalu memegang seluruh izin, dan izin <code>permissions.manage</code> tidak dapat diberikan ke peran lain. Aturan ini dijaga di sisi server.</p>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-5">
        <section class="app-card mb-3">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Daftar peran</h2>
                    <p class="card-subtitle">Pilih peran untuk menyesuaikan izinnya.</p>
                </div>
            </header>
            <div class="table-responsive">
                <table class="table app-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Peran</th>
                            <th scope="col">Pengguna</th>
                            <th scope="col">Izin</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="text-end">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($roles as $role): ?>
                            <?php $roleId = (int) $role['id']; ?>
                            <tr class="<?= $roleId === $selectedId ? 'row-selected' : '' ?>">
                                <td>
                                    <span class="cell-strong d-block"><?= e($role['role_name']) ?></span>
                                    <span class="cell-sub"><?= e($role['role_slug']) ?></span>
                                </td>
                                <td><?= e((string) $role['user_count']) ?> akun</td>
                                <td><?= e((string) ($permissionCountByRole[$roleId] ?? 0)) ?></td>
                                <td>
                                    <span class="pill <?= (int) $role['is_active'] === 1 ? 'pill-success' : 'pill-muted' ?>">
                                        <?= e(status_label($role['is_active'])) ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-secondary" href="<?= e(query_url('/hak-akses', ['peran' => $roleId])) ?>">Atur izin</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="app-card">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Tambah peran</h2>
                    <p class="card-subtitle">Peran baru belum memiliki izin sampai Anda mencentangnya.</p>
                </div>
            </header>
            <form class="app-form" method="post" action="<?= url('/hak-akses/peran') ?>">
                <?= csrf_field() ?>

                <div class="form-group">
                    <label class="form-label" for="role_name">Nama peran *</label>
                    <input class="form-control" type="text" id="role_name" name="role_name"
                           value="<?= e((string) ($old['role_name'] ?? '')) ?>" maxlength="80" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="role_slug">Slug (opsional)</label>
                    <input class="form-control" type="text" id="role_slug" name="role_slug"
                           value="<?= e((string) ($old['role_slug'] ?? '')) ?>" maxlength="40" placeholder="dikisi otomatis dari nama">
                    <div class="form-text">Huruf kecil, angka, dan garis bawah. 3-40 karakter.</div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="role_description">Deskripsi</label>
                    <textarea class="form-control" id="role_description" name="description" rows="2" maxlength="255"
                              placeholder="Tanggung jawab peran ini"><?= e((string) ($old['description'] ?? '')) ?></textarea>
                </div>

                <div class="form-actions">
                    <button class="btn btn-primary" type="submit">Tambah peran</button>
                </div>
            </form>
        </section>
    </div>

    <div class="col-12 col-xl-7">
        <?php if ($selected === null): ?>
            <section class="app-card h-100">
                <div class="empty-state empty-state-block">
                    <i class="bi bi-shield-check" aria-hidden="true"></i>
                    <p class="empty-title">Belum ada peran dipilih</p>
                    <p class="empty-text">Klik tombol <strong>Atur izin</strong> pada daftar peran untuk mengubah identitas dan hak aksesnya.</p>
                </div>
            </section>
        <?php else: ?>
            <section class="app-card mb-3">
                <header class="card-head">
                    <div>
                        <h2 class="card-title"><?= e($selected['role_name']) ?></h2>
                        <p class="card-subtitle">
                            Slug <code><?= e($selected['role_slug']) ?></code> ·
                            <?= e((string) \App\Models\Role::countUsers($selectedId)) ?> pengguna ·
                            dibuat <?= e(tanggal_id((string) $selected['created_at'])) ?>
                        </p>
                    </div>
                </header>

                <form class="app-form" method="post" action="<?= url('/hak-akses/peran/' . $selectedId) ?>">
                    <?= csrf_field() ?>

                    <div class="row g-3">
                        <div class="col-12 col-md-7">
                            <div class="form-group mb-0">
                                <label class="form-label" for="edit_role_name">Nama peran *</label>
                                <input class="form-control" type="text" id="edit_role_name" name="edit_role_name"
                                       value="<?= e((string) ($old['edit_role_name'] ?? $selected['role_name'])) ?>" maxlength="80" required>
                            </div>
                        </div>
                        <div class="col-12 col-md-5">
                            <div class="form-group mb-0">
                                <span class="form-label d-block">Status peran</span>
                                <?php if ($isSuperRole): ?>
                                    <p class="form-text mb-0">Peran Super Admin selalu aktif.</p>
                                <?php else: ?>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="edit_is_active" name="edit_is_active" value="1"
                                               <?= (int) $selected['is_active'] === 1 ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="edit_is_active">Peran dapat digunakan</label>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-group mb-0">
                                <label class="form-label" for="edit_description">Deskripsi</label>
                                <textarea class="form-control" id="edit_description" name="edit_description" rows="2" maxlength="255"><?= e((string) ($old['edit_description'] ?? $selected['description'])) ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button class="btn btn-primary" type="submit">Simpan identitas peran</button>
                    </div>
                </form>
            </section>

            <section class="app-card">
                <header class="card-head">
                    <div>
                        <h2 class="card-title">Izin peran</h2>
                        <p class="card-subtitle">
                            <?= $isSuperRole
                                ? 'Seluruh izin sistem melekat pada peran ini dan tidak dapat dikurangi.'
                                : 'Centang izin yang boleh digunakan, lalu simpan.' ?>
                        </p>
                    </div>
                    <span class="card-count"><?= $isSuperRole ? count($permissions) : count($grantedMap) ?> / <?= count($permissions) ?> izin</span>
                </header>

                <form class="app-form" method="post" action="<?= url('/hak-akses/peran/' . $selectedId . '/izin') ?>">
                    <?= csrf_field() ?>

                    <?php foreach ($grouped as $moduleName => $modulePermissions): ?>
                        <div class="permission-group">
                            <p class="permission-group-title">
                                <?= e(module_label((string) $moduleName)) ?>
                                <span class="text-muted small">· <?= e($moduleName) ?></span>
                            </p>
                            <div class="permission-grid">
                                <?php foreach ($modulePermissions as $permission): ?>
                                    <?php
                                    $permissionId = (int) $permission['id'];
                                    $isLocked = $isSuperRole || ($managePermissionId !== null && $permissionId === $managePermissionId);
                                    $isChecked = $isSuperRole || isset($grantedMap[$permissionId]);
                                    ?>
                                    <div class="permission-item">
                                        <input class="form-check-input" type="checkbox"
                                               id="perm_<?= e((string) $permissionId) ?>"
                                               name="permissions[]" value="<?= e((string) $permissionId) ?>"
                                            <?= $isChecked ? 'checked' : '' ?>
                                            <?= $isLocked ? 'disabled' : '' ?>>
                                        <label for="perm_<?= e((string) $permissionId) ?>">
                                            <span class="permission-name"><?= e($permission['permission_name']) ?></span>
                                            <code class="permission-slug"><?= e($permission['permission_slug']) ?></code>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <p class="muted-note">
                        Izin <code>permissions.manage</code> sengaja dikunci karena hanya boleh melekat pada peran Super Admin.
                    </p>

                    <div class="form-actions">
                        <button class="btn btn-primary" type="submit">
                            <?= $isSuperRole ? 'Pulihkan seluruh izin' : 'Simpan hak akses' ?>
                        </button>
                        <a class="btn btn-link" href="<?= url('/hak-akses') ?>">Batal</a>
                    </div>
                </form>
            </section>
        <?php endif; ?>
    </div>
</div>

<section class="app-card mt-3">
    <header class="card-head">
        <div>
            <h2 class="card-title">Matriks izin</h2>
            <p class="card-subtitle">Gambaran gabungan seluruh peran terhadap setiap izin sistem.</p>
        </div>
    </header>
    <div class="table-responsive">
        <table class="table app-table matrix-table mb-0">
            <thead>
                <tr>
                    <th scope="col">Izin</th>
                    <?php foreach ($roles as $role): ?>
                        <th scope="col" class="text-center matrix-col" title="<?= e($role['role_slug']) ?>">
                            <?= e($role['role_name']) ?>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($grouped as $moduleName => $modulePermissions): ?>
                    <tr class="matrix-module">
                        <th scope="rowgroup" colspan="<?= e((string) (count($roles) + 1)) ?>">
                            <?= e(module_label((string) $moduleName)) ?> <span class="text-muted small">(<?= e($moduleName) ?>)</span>
                        </th>
                    </tr>
                    <?php foreach ($modulePermissions as $permission): ?>
                        <?php $permissionId = (int) $permission['id']; ?>
                        <tr>
                            <th scope="row">
                                <code class="permission-slug"><?= e($permission['permission_slug']) ?></code>
                            </th>
                            <?php foreach ($roles as $role): ?>
                                <?php $has = isset($matrix[(int) $role['id']][$permissionId]); ?>
                                <td class="text-center">
                                    <?php if ($has): ?>
                                        <i class="bi bi-check-lg matrix-yes" aria-hidden="true"></i>
                                        <span class="visually-hidden">Dimiliki</span>
                                    <?php else: ?>
                                        <i class="bi bi-dash matrix-no" aria-hidden="true"></i>
                                        <span class="visually-hidden">Tidak dimiliki</span>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
