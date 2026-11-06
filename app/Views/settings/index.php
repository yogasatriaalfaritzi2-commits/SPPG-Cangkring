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
                    <h2 class="card-title">Ambang keuangan dan jadwal</h2>
                    <p class="card-subtitle">Nilai ini dibaca ulang setiap permintaan, jadi perubahan berlaku tanpa mengubah kode.</p>
                </div>
            </header>

            <form class="app-form" method="post" action="<?= url('/pengaturan/keuangan') ?>">
                <?= csrf_field() ?>

                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <div class="form-group mb-0">
                            <label class="form-label" for="budget_warning_percent">Ambang peringatan penyerapan (%) *</label>
                            <input class="form-control" type="number" id="budget_warning_percent"
                                   name="budget_warning_percent" value="<?= e($values['budget_warning_percent']) ?>"
                                   min="1" max="100" step="1" required>
                            <div class="form-text">
                                Penyerapan sebesar atau di atas angka ini ditandai "mendekati batas"; di atas 100% ditandai "terlampaui".
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6">
                        <div class="form-group mb-0">
                            <label class="form-label" for="schedule_due_days">Pengingat tenggat kegiatan (hari *)</label>
                            <input class="form-control" type="number" id="schedule_due_days"
                                   name="schedule_due_days" value="<?= e($values['schedule_due_days']) ?>"
                                   min="1" max="90" step="1" required>
                            <div class="form-text">
                                Kegiatan yang tanggal selesai-nya dalam rentang ini memicu notifikasi "menjelang tenggat".
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="finance_segregation_of_duties">Pemisahan tugas persetujuan</label>
                    <select class="form-select" id="finance_segregation_of_duties" name="finance_segregation_of_duties">
                        <option value="1" <?= $values['finance_segregation_of_duties'] === '1' ? 'selected' : '' ?>>
                            Aktifkan — pembuat tidak boleh menyetujui RAB atau transaksi buatannya sendiri
                        </option>
                        <option value="0" <?= $values['finance_segregation_of_duties'] === '0' ? 'selected' : '' ?>>
                            Nonaktifkan — satu pengguna boleh mengajukan dan menyetujui
                        </option>
                    </select>
                    <div class="form-text">
                        Aturan ditegakkan di server pada setiap aksi persetujuan, bukan hanya disembunyikan pada tombol.
                    </div>
                </div>

                <div class="form-actions">
                    <button class="btn btn-primary" type="submit">Simpan ambang dan kebijakan</button>
                </div>
            </form>
        </section>
    </div>

    <div class="col-12 col-xl-4">
        <!-- Status Operasional Real-time & Kesehatan Sistem -->
        <?php if (!empty($systemHealth)): ?>
            <?php
            $isHealthy = ($systemHealth['status'] ?? 'healthy') === 'healthy';
            $db = $systemHealth['database'] ?? [];
            $storage = $systemHealth['storage'] ?? [];
            $runtime = $systemHealth['runtime'] ?? [];
            ?>
            <section class="app-card mb-3 border-<?= $isHealthy ? 'success' : 'warning' ?>">
                <header class="card-head pb-2 border-bottom">
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="badge <?= $isHealthy ? 'bg-success' : 'bg-warning text-dark' ?>">
                                <i class="bi bi-circle-fill me-1" style="font-size: 0.6rem;"></i>
                                <?= $isHealthy ? 'Sistem Prima (Healthy)' : 'Perhatian (Warning)' ?>
                            </span>
                        </div>
                        <h2 class="card-title h6 mb-0">Status Operasional Real-time</h2>
                    </div>
                </header>
                <dl class="info-list mb-0">
                    <div>
                        <dt>Status Basis Data</dt>
                        <dd class="d-flex align-items-center gap-1">
                            <span class="badge bg-success-subtle text-success border border-success-subtle">Online</span>
                            <span class="small text-muted">(<?= e((string)($db['latency_ms'] ?? 0)) ?> ms)</span>
                        </dd>
                    </div>
                    <div>
                        <dt>Server MySQL / MariaDB</dt>
                        <dd class="small"><?= e((string)($db['version'] ?? '—')) ?></dd>
                    </div>
                    <div>
                        <dt>Tabel & Ukuran Data</dt>
                        <dd class="fw-semibold">
                            <?= (int)($db['tables'] ?? 0) ?> tabel
                            <span class="small text-muted font-monospace">(<?= e((string)($db['size_mb'] ?? 0)) ?> MB)</span>
                        </dd>
                    </div>
                    <div>
                        <dt>Direktori Storage / Log</dt>
                        <dd>
                            <?php if (!empty($storage['storage_writable']) && !empty($storage['logs_writable'])): ?>
                                <span class="text-success small fw-semibold"><i class="bi bi-check-circle me-1"></i>Writable</span>
                            <?php else: ?>
                                <span class="text-danger small fw-semibold"><i class="bi bi-x-circle me-1"></i>Izin Tulis Terbatas</span>
                            <?php endif; ?>
                        </dd>
                    </div>
                    <div>
                        <dt>PHP Runtime & Memori</dt>
                        <dd class="small">
                            PHP v<?= e((string)($runtime['php_version'] ?? PHP_VERSION)) ?> · 
                            <span class="fw-semibold"><?= e((string)($runtime['memory_usage_mb'] ?? 0)) ?> MB</span> / <?= e((string)($runtime['memory_limit'] ?? '128M')) ?>
                        </dd>
                    </div>
                </dl>
            </section>
        <?php endif; ?>

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
