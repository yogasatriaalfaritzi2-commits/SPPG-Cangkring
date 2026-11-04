<?php
$isEdit = $mode === 'edit';
$projectId = $isEdit ? (int) $project['id'] : 0;
$action = $isEdit ? url('/proyek/' . $projectId . '/ubah') : url('/proyek/tambah');
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Pembangunan</p>
        <h1 class="page-title"><?= $isEdit ? 'Sunting proyek pembangunan' : 'Tambah proyek pembangunan' ?></h1>
        <p class="page-subtitle">
            <?= $isEdit
                ? 'Perubahan tersimpan setelah tombol simpan ditekan dan tercatat pada log aktivitas. Progres tidak diubah dari halaman ini.'
                : 'Isi data proyek. Kode proyek dibuat otomatis dan wajib unik, sedangkan progres awal selalu 0 persen.' ?>
        </p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline-secondary" href="<?= $isEdit ? url('/proyek/' . $projectId) : url('/proyek') ?>">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Kembali
        </a>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-8">
        <form class="app-form" method="post" action="<?= e($action) ?>">
            <?= csrf_field() ?>

            <section class="app-card mb-3">
                <header class="card-head">
                    <div>
                        <h2 class="card-title">Identitas proyek</h2>
                        <p class="card-subtitle">Kolom bertanda bintang wajib diisi.</p>
                    </div>
                </header>

                <div class="row g-3">
                    <div class="col-12 col-md-4">
                        <div class="form-group mb-0">
                            <label class="form-label" for="project_code">Kode proyek *</label>
                            <input class="form-control" type="text" id="project_code" name="project_code"
                                   value="<?= e($form['project_code']) ?>" maxlength="30"
                                   pattern="[A-Za-z][A-Za-z0-9._\-]{2,29}" required>
                            <div class="form-text">
                                <?= $isEdit
                                    ? 'Huruf kapital, angka, titik, garis bawah, atau tanda hubung. Harus unik.'
                                    : 'Dibiarkan kosong berarti sistem memakai kode otomatis berikutnya. Harus unik.' ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-md-8">
                        <div class="form-group mb-0">
                            <label class="form-label" for="name">Nama proyek *</label>
                            <input class="form-control" type="text" id="name" name="name"
                                   value="<?= e($form['name']) ?>" maxlength="150" required>
                        </div>
                    </div>

                    <div class="col-12">
                        <div class="form-group mb-0">
                            <label class="form-label" for="description">Deskripsi proyek</label>
                            <textarea class="form-control" id="description" name="description" rows="4"
                                      maxlength="2000"><?= e($form['description']) ?></textarea>
                        </div>
                    </div>
                </div>
            </section>

            <section class="app-card mb-3">
                <header class="card-head">
                    <div>
                        <h2 class="card-title">Lokasi</h2>
                        <p class="card-subtitle">Koordinat bersifat opsional, format desimal dengan titik.</p>
                    </div>
                </header>

                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <div class="form-group mb-0">
                            <label class="form-label" for="location">Lokasi / nama wilayah *</label>
                            <input class="form-control" type="text" id="location" name="location"
                                   value="<?= e($form['location']) ?>" maxlength="150" required>
                        </div>
                    </div>

                    <div class="col-12 col-md-6">
                        <div class="form-group mb-0">
                            <label class="form-label" for="contractor">Kontraktor / pelaksana</label>
                            <input class="form-control" type="text" id="contractor" name="contractor"
                                   value="<?= e($form['contractor']) ?>" maxlength="150">
                        </div>
                    </div>

                    <div class="col-12">
                        <div class="form-group mb-0">
                            <label class="form-label" for="address">Alamat lengkap</label>
                            <textarea class="form-control" id="address" name="address" rows="3"
                                      maxlength="1000"><?= e($form['address']) ?></textarea>
                        </div>
                    </div>

                    <div class="col-12 col-md-6">
                        <div class="form-group mb-0">
                            <label class="form-label" for="latitude">Lintang (latitude)</label>
                            <input class="form-control" type="text" id="latitude" name="latitude" inputmode="decimal"
                                   value="<?= e((string) $form['latitude']) ?>" placeholder="-7.1234567">
                            <div class="form-text">Rentang -90 sampai 90.</div>
                        </div>
                    </div>

                    <div class="col-12 col-md-6">
                        <div class="form-group mb-0">
                            <label class="form-label" for="longitude">Bujur (longitude)</label>
                            <input class="form-control" type="text" id="longitude" name="longitude" inputmode="decimal"
                                   value="<?= e((string) $form['longitude']) ?>" placeholder="109.7654321">
                            <div class="form-text">Rentang -180 sampai 180.</div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="app-card mb-3">
                <header class="card-head">
                    <div>
                        <h2 class="card-title">Penanggung jawab, jadwal, dan anggaran</h2>
                        <p class="card-subtitle">Target selesai tidak boleh lebih awal dari tanggal mulai.</p>
                    </div>
                </header>

                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <div class="form-group mb-0">
                            <label class="form-label" for="pic_name">Nama penanggung jawab *</label>
                            <input class="form-control" type="text" id="pic_name" name="pic_name"
                                   value="<?= e($form['pic_name']) ?>" maxlength="120" required>
                        </div>
                    </div>

                    <div class="col-12 col-md-6">
                        <div class="form-group mb-0">
                            <label class="form-label" for="pic_user_id">Tautkan ke akun pengguna</label>
                            <select class="form-select" id="pic_user_id" name="pic_user_id">
                                <option value="">Tidak tertaut akun</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?= e((string) $user['id']) ?>"
                                        <?= (int) $form['pic_user_id'] === (int) $user['id'] ? 'selected' : '' ?>>
                                        <?= e($user['full_name']) ?> · <?= e($user['role_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Hanya akun aktif. Halaman tim dapat menetapkan penanggung jawab sekaligus.</div>
                        </div>
                    </div>

                    <div class="col-12 col-md-4">
                        <div class="form-group mb-0">
                            <label class="form-label" for="start_date">Tanggal mulai *</label>
                            <input class="form-control" type="date" id="start_date" name="start_date"
                                   value="<?= e($form['start_date']) ?>" required>
                        </div>
                    </div>

                    <div class="col-12 col-md-4">
                        <div class="form-group mb-0">
                            <label class="form-label" for="target_date">Target selesai *</label>
                            <input class="form-control" type="date" id="target_date" name="target_date"
                                   value="<?= e($form['target_date']) ?>" required>
                        </div>
                    </div>

                    <div class="col-12 col-md-4">
                        <div class="form-group mb-0">
                            <label class="form-label" for="status">Status *</label>
                            <select class="form-select" id="status" name="status" required>
                                <?php foreach ($statuses as $status): ?>
                                    <option value="<?= e($status) ?>" <?= $form['status'] === $status ? 'selected' : '' ?>>
                                        <?= e(project_status_label($status)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="col-12 col-md-6">
                        <div class="form-group mb-0">
                            <label class="form-label" for="budget_amount">Anggaran awal (rupiah) *</label>
                            <input class="form-control" type="number" id="budget_amount" name="budget_amount"
                                   value="<?= e((string) $form['budget_amount']) ?>"
                                   min="0" max="9999999999999" step="1" required>
                            <div class="form-text">Isi angka tanpa pemisah titik atau koma. Nilai 0 bila belum dianggarkan.</div>
                        </div>
                    </div>

                    <div class="col-12">
                        <div class="form-group mb-0">
                            <label class="form-label" for="status_reason">Alasan perubahan status</label>
                            <input class="form-control" type="text" id="status_reason" name="status_reason"
                                   maxlength="255"
                                   placeholder="Wajib diisi bila status diubah menjadi Tertunda atau Dibatalkan.">
                            <div class="form-text">
                                <?= $isEdit
                                    ? 'Status saat ini: ' . e(project_status_label((string) $project['status'])) . '. Riwayat status disimpan beserta nama pengguna dan waktunya.'
                                    : 'Status awal proyek langsung dicatat sebagai riwayat perubahan.' ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-12">
                        <div class="form-group mb-0">
                            <label class="form-label" for="notes">Catatan</label>
                            <textarea class="form-control" id="notes" name="notes" rows="3"
                                      maxlength="2000"><?= e($form['notes']) ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <button class="btn btn-primary" type="submit">
                        <?= $isEdit ? 'Simpan perubahan' : 'Simpan proyek baru' ?>
                    </button>
                    <a class="btn btn-link" href="<?= $isEdit ? url('/proyek/' . $projectId) : url('/proyek') ?>">Batal</a>
                </div>
            </section>
        </form>
    </div>

    <div class="col-12 col-xl-4">
        <aside class="app-card mb-3">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Aturan penyimpanan</h2>
                    <p class="card-subtitle">Diperiksa di server, bukan hanya di formulir.</p>
                </div>
            </header>
            <ul class="rule-list">
                <li><i class="bi bi-hash" aria-hidden="true"></i>Kode proyek unik, 3-30 karakter, diawali huruf kapital.</li>
                <li><i class="bi bi-calendar-check" aria-hidden="true"></i>Target selesai tidak boleh lebih awal dari tanggal mulai.</li>
                <li><i class="bi bi-percent" aria-hidden="true"></i>Progres hanya berubah lewat laporan progres, bukan formulir ini.</li>
                <li><i class="bi bi-journal-text" aria-hidden="true"></i>Perubahan status dan data proyek tercatat pada log aktivitas.</li>
            </ul>
        </aside>

        <?php if ($isEdit): ?>
            <aside class="app-card">
                <header class="card-head">
                    <div>
                        <h2 class="card-title">Kondisi proyek saat ini</h2>
                        <p class="card-subtitle">Data sebelum perubahan disimpan.</p>
                    </div>
                </header>
                <dl class="info-list">
                    <div>
                        <dt>Status</dt>
                        <dd>
                            <span class="pill pill-<?= e(project_status_tone($project['status'])) ?>">
                                <?= e(project_status_label($project['status'])) ?>
                            </span>
                        </dd>
                    </div>
                    <div>
                        <dt>Progres</dt>
                        <dd><?= e((string) (int) $project['progress']) ?>%</dd>
                    </div>
                    <div>
                        <dt>Anggaran</dt>
                        <dd><?= can('budgets.view') ? e(rupiah($project['budget_amount'])) : 'Dibatasi izin' ?></dd>
                    </div>
                    <div>
                        <dt>Dibuat oleh</dt>
                        <dd><?= e($project['creator_name'] !== null && $project['creator_name'] !== ''
                            ? (string) $project['creator_name']
                            : 'Sistem / tidak tercatat') ?></dd>
                    </div>
                    <div>
                        <dt>Arsip</dt>
                        <dd><?= (int) $project['is_archived'] === 1 ? 'Terarsip' : 'Aktif' ?></dd>
                    </div>
                </dl>
            </aside>
        <?php endif; ?>
    </div>
</div>
