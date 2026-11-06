<div class="page-head">
    <div>
        <p class="eyebrow">Administrasi</p>
        <h1 class="page-title">Pengaturan dasar</h1>
        <p class="page-subtitle">Identitas aplikasi dan preferensi yang dipakai di seluruh halaman.</p>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-8">
        <section class="app-card">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Identitas aplikasi</h2>
                    <p class="card-subtitle">Perubahan berlaku setelah tombol simpan ditekan.</p>
                </div>
            </header>

            <form class="app-form" method="post" action="<?= url('/pengaturan') ?>">
                <?= csrf_field() ?>

                <div class="form-group">
                    <label class="form-label" for="app_name">Nama aplikasi</label>
                    <input class="form-control" type="text" id="app_name" name="app_name" value="<?= e($values['app_name']) ?>" maxlength="60" required>
                    <div class="form-text">Dipakai pada judul halaman dan halaman masuk.</div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="institution_name">Nama instansi</label>
                    <input class="form-control" type="text" id="institution_name" name="institution_name" value="<?= e($values['institution_name']) ?>" maxlength="120" required>
                    <div class="form-text">Tampil pada footer dan navigasi bagian atas.</div>
                </div>

                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <div class="form-group mb-0">
                            <label class="form-label" for="timezone">Zona waktu</label>
                            <select class="form-select" id="timezone" name="timezone" required>
                                <?php foreach ($timezones as $zone => $label): ?>
                                    <option value="<?= e($zone) ?>" <?= $values['timezone'] === $zone ? 'selected' : '' ?>>
                                        <?= e($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-12 col-md-6">
                        <div class="form-group mb-0">
                            <label class="form-label" for="support_email">Surel dukungan (opsional)</label>
                            <input class="form-control" type="email" id="support_email" name="support_email" value="<?= e($values['support_email']) ?>" maxlength="160" placeholder="contoh@sppg.go.id">
                            <div class="form-text">Ditampilkan pada halaman galat sebagai kontak bantuan.</div>
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <button class="btn btn-primary" type="submit">Simpan pengaturan</button>
                    <a class="btn btn-link" href="<?= url(home_path()) ?>">Batal</a>
                </div>
            </form>
        </section>

        <section class="app-card mt-3">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Pengaturan Operasional MBG & Kuota Porsi</h2>
                    <p class="card-subtitle">Konfigurasi kapasitas layanan harian, mode pencatatan penerima manfaat, dan toleransi bahan baku SPPG Cangkring.</p>
                </div>
            </header>

            <form class="app-form" method="post" action="<?= url('/pengaturan/mbg') ?>">
                <?= csrf_field() ?>

                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <div class="form-group mb-0">
                            <label class="form-label" for="mbg_daily_portion_target">Target Kuota Porsi Harian (porsi / hari) *</label>
                            <input class="form-control" type="number" id="mbg_daily_portion_target"
                                   name="mbg_daily_portion_target" value="<?= e($values['mbg_daily_portion_target'] ?? '3000') ?>"
                                   min="1" max="1000000" step="10" required>
                            <div class="form-text">
                                Kapasitas standar produksi makan bergizi gratis harian dapur SPPG Cangkring.
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6">
                        <div class="form-group mb-0">
                            <label class="form-label" for="mbg_ingredient_tolerance_percent">Toleransi Selisih Belanja Bahan (%) *</label>
                            <input class="form-control" type="number" id="mbg_ingredient_tolerance_percent"
                                   name="mbg_ingredient_tolerance_percent" value="<?= e($values['mbg_ingredient_tolerance_percent'] ?? '5') ?>"
                                   min="0" max="50" step="1" required>
                            <div class="form-text">
                                Batas persentase cadangan susut / kelebihan kuantitas saat rekap kebutuhan bahan belanja.
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-group mt-3">
                    <label class="form-label" for="mbg_beneficiary_mode">Mode Pencatatan Penerima Manfaat *</label>
                    <select class="form-select" id="mbg_beneficiary_mode" name="mbg_beneficiary_mode" required>
                        <option value="rekap" <?= ($values['mbg_beneficiary_mode'] ?? 'rekap') === 'rekap' ? 'selected' : '' ?>>
                            Mode Rekap Agregat (Baku) — Pencatatan jumlah total per sekolah, kategori, dan kelompok kelas
                        </option>
                        <option value="individu" <?= ($values['mbg_beneficiary_mode'] ?? 'rekap') === 'individu' ? 'selected' : '' ?>>
                            Mode Rinci Individu — Mengaktifkan pencatatan per nama siswa/individu di halaman terpisah
                        </option>
                    </select>
                    <div class="form-text">
                        Menyesuaikan tampilan dan mekanisme rekapitulasi data siswa penerima manfaat di seluruh sistem.
                    </div>
                </div>

                <div class="form-actions">
                    <button class="btn btn-primary" type="submit">Simpan Pengaturan Operasional MBG</button>
                </div>
            </form>
        </section>
    </div>

    <div class="col-12 col-xl-4">
        <aside class="app-card">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Informasi sistem</h2>
                    <p class="card-subtitle">Ringkasan konfigurasi yang sedang aktif.</p>
                </div>
            </header>
            <dl class="info-list">
                <div>
                    <dt>Versi aplikasi</dt>
                    <dd><?= e(\App\Core\App::config('app.version', '0.1.0')) ?></dd>
                </div>
                <div>
                    <dt>Lingkungan</dt>
                    <dd><?= e(environment_label()) ?></dd>
                </div>
                <div>
                    <dt>Zona waktu aktif</dt>
                    <dd><?= e(date_default_timezone_get()) ?></dd>
                </div>
                <div>
                    <dt>Terakhir diperbarui</dt>
                    <dd>
                        <?php if ($lastUpdate !== null): ?>
                            <?= e(tanggal_id((string) $lastUpdate['updated_at'], true)) ?>
                            <?php if (!empty($lastUpdate['user_name'])): ?>
                                <span class="d-block text-muted small">oleh <?= e($lastUpdate['user_name']) ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            Belum tercatat
                        <?php endif; ?>
                    </dd>
                </div>
            </dl>
            <p class="muted-note">Zona waktu memengaruhi tampilan tanggal dan waktu di seluruh halaman.</p>
        </aside>
    </div>
</div>
