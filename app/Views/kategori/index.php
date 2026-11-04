<?php
$isEditing = $editing !== null;
$manage = can('budgets.manage');
$editingId = $isEditing ? (int) $editing['id'] : 0;

/** Nilai awal formulir: input lama > data kategori yang disunting > kosong. */
$form = [
    'category_code' => '',
    'category_name' => '',
    'description' => '',
    'sort_order' => '',
    'is_active' => '1',
];
if ($isEditing) {
    foreach ($form as $key => $unused) {
        $form[$key] = (string) ($editing[$key] ?? ($key === 'is_active' ? (int) $editing['is_active'] : ''));
    }
}
foreach ($old as $key => $value) {
    if (array_key_exists($key, $form)) {
        $form[$key] = (string) $value;
    }
}

$activeCount = 0;
foreach ($categories as $category) {
    if ((int) $category['is_active'] === 1) {
        $activeCount++;
    }
}
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Keuangan &middot; RAB</p>
        <h1 class="page-title">Kategori Pekerjaan RAB</h1>
        <p class="page-subtitle">
            Daftar kategori dipakai pada item RAB, pengelompokan transaksi, dan kegiatan jadwal.
            Kategori yang sudah dipakai tidak dapat dihapus, hanya dinonaktifkan agar riwayat lama tetap terbaca.
        </p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline-secondary" href="<?= url('/rab') ?>">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Kembali ke RAB
        </a>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <section class="stat-card">
            <p class="stat-label">Kategori tampil</p>
            <p class="stat-value"><?= e((string) count($categories)) ?></p>
            <p class="stat-meta">Disaring dari seluruh kategori</p>
        </section>
    </div>
    <div class="col-6 col-lg-3">
        <section class="stat-card">
            <p class="stat-label">Status aktif</p>
            <p class="stat-value"><?= e((string) $activeCount) ?></p>
            <p class="stat-meta">Dapat dipilih pada item dan transaksi</p>
        </section>
    </div>
    <div class="col-6 col-lg-3">
        <section class="stat-card">
            <p class="stat-label">Nonaktif</p>
            <p class="stat-value"><?= e((string) (count($categories) - $activeCount)) ?></p>
            <p class="stat-meta">Riwayat lama tetap terbaca</p>
        </section>
    </div>
    <div class="col-6 col-lg-3">
        <section class="stat-card">
            <p class="stat-label">Bawaan modul</p>
            <p class="stat-value">12</p>
            <p class="stat-meta">Persiapan sampai finishing</p>
        </section>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-8">
        <section class="app-card">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Daftar kategori</h2>
                    <p class="card-subtitle">Diurutkan berdasarkan kolom urutan tampil.</p>
                </div>
                <span class="card-count"><?= count($categories) ?> kategori</span>
            </header>

            <form class="toolbar-form" method="get" action="<?= url('/kategori') ?>">
                <?php if ($isEditing): ?>
                    <input type="hidden" name="sunting" value="<?= e((string) $editingId) ?>">
                <?php endif; ?>
                <div class="toolbar-field toolbar-field-wide">
                    <label class="form-label" for="q">Cari</label>
                    <input class="form-control form-control-sm" type="search" id="q" name="q"
                           value="<?= e($search) ?>" maxlength="60" placeholder="Kode atau nama kategori">
                </div>
                <div class="toolbar-field">
                    <label class="form-label" for="status">Status</label>
                    <select class="form-select form-select-sm" id="status" name="status">
                        <option value="">Semua</option>
                        <option value="active" <?= $state === 'active' ? 'selected' : '' ?>>Aktif saja</option>
                        <option value="inactive" <?= $state === 'inactive' ? 'selected' : '' ?>>Nonaktif saja</option>
                    </select>
                </div>
                <div class="toolbar-actions">
                    <button class="btn btn-sm btn-primary" type="submit">Terapkan</button>
                    <?php if ($search !== '' || $state !== ''): ?>
                        <a class="btn btn-sm btn-link" href="<?= url('/kategori') ?>">Bersihkan</a>
                    <?php endif; ?>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table app-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Kode</th>
                            <th scope="col">Nama kategori</th>
                            <th scope="col" class="text-end">Item RAB</th>
                            <th scope="col" class="text-end">Transaksi</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="text-end">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($categories === []): ?>
                            <tr>
                                <td colspan="6" class="table-empty">
                                    <div class="empty-state">
                                        <i class="bi bi-tags" aria-hidden="true"></i>
                                        <p class="empty-title">Tidak ada kategori yang cocok</p>
                                        <p class="empty-text">Ubah kata kunci atau filter status, atau tambahkan kategori baru.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($categories as $category): ?>
                            <?php $isActive = (int) $category['is_active'] === 1; ?>
                            <tr>
                                <td class="cell-strong"><?= e((string) $category['category_code']) ?></td>
                                <td>
                                    <span class="cell-strong"><?= e((string) $category['category_name']) ?></span>
                                    <span class="cell-sub">
                                        <?= e($category['description'] !== null && $category['description'] !== ''
                                            ? (string) $category['description'] : 'Tanpa deskripsi') ?>
                                        · urutan <?= e((string) $category['sort_order']) ?>
                                    </span>
                                </td>
                                <td class="text-end"><?= e((string) $category['item_count']) ?></td>
                                <td class="text-end"><?= e((string) $category['transaction_count']) ?></td>
                                <td>
                                    <span class="pill pill-<?= $isActive ? 'success' : 'muted' ?>">
                                        <?= $isActive ? 'Aktif' : 'Nonaktif' ?>
                                    </span>
                                </td>
                                <td class="text-end cell-actions">
                                    <?php if ($manage): ?>
                                        <a class="btn btn-sm btn-outline-secondary"
                                           href="<?= query_url('/kategori', ['sunting' => (string) $category['id'], 'q' => $search, 'status' => $state]) ?>">
                                            Sunting
                                        </a>
                                        <form method="post" action="<?= url('/kategori/' . $category['id'] . '/ubah') ?>"
                                              class="d-inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="category_code" value="<?= e((string) $category['category_code']) ?>">
                                            <input type="hidden" name="category_name" value="<?= e((string) $category['category_name']) ?>">
                                            <input type="hidden" name="description" value="<?= e((string) ($category['description'] ?? '')) ?>">
                                            <input type="hidden" name="sort_order" value="<?= e((string) $category['sort_order']) ?>">
                                            <?php if ($isActive): ?>
                                                <button class="btn btn-sm btn-outline-warning" type="submit" data-confirm
                                                        data-confirm-title="Nonaktifkan kategori"
                                                        data-confirm-text="<?= e('Kategori ' . $category['category_code'] . ' tidak lagi muncul pada formulir item dan transaksi. Riwayat lama tetap terbaca.') ?>"
                                                        data-confirm-label="Ya, nonaktifkan"
                                                        data-confirm-tone="warning">
                                                    Nonaktifkan
                                                </button>
                                            <?php else: ?>
                                                <input type="hidden" name="is_active" value="1">
                                                <button class="btn btn-sm btn-outline-success" type="submit">
                                                    Aktifkan
                                                </button>
                                            <?php endif; ?>
                                        </form>
                                        <?php if ((int) $category['item_count'] === 0 && (int) $category['transaction_count'] === 0): ?>
                                            <form method="post" action="<?= url('/kategori/' . $category['id'] . '/hapus') ?>"
                                                  class="d-inline">
                                                <?= csrf_field() ?>
                                                <button class="btn btn-sm btn-outline-danger" type="submit" data-confirm
                                                        data-confirm-title="Hapus kategori"
                                                        data-confirm-text="<?= e('Hapus "' . $category['category_name'] . '" secara permanen?') ?>"
                                                        data-confirm-label="Ya, hapus"
                                                        data-confirm-tone="danger">
                                                    Hapus
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="cell-sub">Hanya baca</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="muted-note">
                Hitungan transaksi hanya menghitung transaksi pembangunan yang disetujui.
                Server tetap memeriksa pemakaian kategori pada item, transaksi, dan kegiatan jadwal sebelum menghapus.
            </p>
        </section>
    </div>

    <div class="col-12 col-xl-4">
        <?php if ($manage): ?>
            <section class="app-card" id="form-kategori">
                <header class="card-head">
                    <div>
                        <h2 class="card-title"><?= $isEditing ? 'Sunting kategori' : 'Tambah kategori' ?></h2>
                        <p class="card-subtitle">
                            <?= $isEditing
                                ? 'Perubahan berlaku pada item RAB, transaksi, dan kegiatan yang memakai kategori ini.'
                                : 'Kode dibuat dari huruf kapital dan angka, contoh: LABOR.' ?>
                        </p>
                    </div>
                </header>
                <form class="app-form" method="post"
                      action="<?= $isEditing ? url('/kategori/' . $editingId . '/ubah') : url('/kategori/tambah') ?>">
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label class="form-label" for="category_code">Kode kategori *</label>
                        <input class="form-control" type="text" id="category_code" name="category_code"
                               value="<?= e($form['category_code']) ?>" maxlength="20"
                               pattern="[A-Za-z][A-Za-z0-9._\-]{1,19}" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="category_name">Nama kategori *</label>
                        <input class="form-control" type="text" id="category_name" name="category_name"
                               value="<?= e($form['category_name']) ?>" maxlength="120" required>
                        <div class="form-text">Harus unik, 3 sampai 120 karakter.</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="description">Deskripsi</label>
                        <textarea class="form-control" id="description" name="description" rows="3"
                                  maxlength="255"><?= e($form['description']) ?></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="sort_order">Urutan tampil</label>
                        <input class="form-control" type="text" id="sort_order" name="sort_order"
                               inputmode="numeric" value="<?= e($form['sort_order']) ?>" placeholder="0">
                        <div class="form-text">Angka 0 sampai 999999. Kategori bawaan memakai kelipatan sepuluh.</div>
                    </div>
                    <div class="form-group">
                        <label class="form-check-label" for="is_active">
                            <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                                   <?= $form['is_active'] === '1' ? 'checked' : '' ?>>
                            Kategori aktif dan dapat dipilih pada formulir
                        </label>
                    </div>
                    <div class="form-actions">
                        <button class="btn btn-primary" type="submit">
                            <?= $isEditing ? 'Simpan perubahan' : 'Tambah kategori' ?>
                        </button>
                        <?php if ($isEditing): ?>
                            <a class="btn btn-outline-secondary" href="<?= url('/kategori') ?>">Batal</a>
                        <?php endif; ?>
                    </div>
                </form>
            </section>
        <?php else: ?>
            <section class="app-card">
                <header class="card-head">
                    <div>
                        <h2 class="card-title">Kelola kategori</h2>
                        <p class="card-subtitle">Perlu izin budgets.manage.</p>
                    </div>
                </header>
                <p class="muted-note">
                    Peran Anda hanya dapat membaca kategori pekerjaan. Minta Super Admin atau Kepala SPPG
                    menambahkan izin budgets.manage untuk dapat menambah, mengubah, atau menonaktifkan kategori.
                </p>
            </section>
        <?php endif; ?>

        <section class="app-card">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Aturan kategori</h2>
                    <p class="card-subtitle">Diperiksa di server pada setiap penyimpanan.</p>
                </div>
            </header>
            <ul class="rule-list">
                <li>Kode kategori unik, diawali huruf, tanpa spasi.</li>
                <li>Nama kategori unik dan panjangnya 3-120 karakter.</li>
                <li>Kategori yang masih dipakai item, transaksi, atau kegiatan tidak dapat dihapus.</li>
                <li>Menonaktifkan kategori tidak mengubah data historis yang sudah memakai kategori itu.</li>
                <li>Item RAB baru hanya boleh memakai kategori berstatus aktif.</li>
            </ul>
        </section>
    </div>
</div>
