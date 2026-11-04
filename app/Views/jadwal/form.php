<?php
/**
 * Formulir kegiatan jadwal. mode = 'create' atau 'edit'; pada mode edit perubahan
 * nilai wajib disertai alasan agar tercatat pada riwayat kegiatan.
 */
$isEdit = $mode === 'edit';
$action = $isEdit ? url('/jadwal/' . (int) $task['id'] . '/ubah') : url('/jadwal/tambah');
$value = static function (string $field) use ($form): string {
    $raw = $form[$field] ?? '';

    return $raw === null ? '' : (string) $raw;
};
$selectedProject = (int) $value('project_id');
$projectName = '';
foreach ($projects as $project) {
    if ((int) $project['id'] === $selectedProject) {
        $projectName = (string) $project['name'];
        break;
    }
}
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Jadwal</p>
        <h1 class="page-title"><?= $isEdit ? 'Sunting kegiatan' : 'Tambah kegiatan pembangunan' ?></h1>
        <p class="page-subtitle">
            <?php if ($isEdit): ?>
                Ubah <?= e((string) $task['activity_code']) ?> &middot; <?= e((string) $task['name']) ?>.
                Setiap bidang yang berubah akan tercatat beserta alasan perubahannya.
            <?php else: ?>
                Kegiatan menjadi isi kalender dan bagan Gantt. Tanggal menentukan durasi, bukan kolom yang diketik.
            <?php endif; ?>
        </p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline-secondary" href="<?= url('/jadwal') ?>">Kembali ke daftar</a>
        <?php if ($isEdit): ?>
            <a class="btn btn-outline-secondary" href="<?= url('/jadwal/' . (int) $task['id']) ?>">Rincian kegiatan</a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-8">
        <section class="app-card">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Data kegiatan</h2>
                    <p class="card-subtitle">Kolom bertanda bintang wajib diisi.</p>
                </div>
            </header>
            <form class="app-form" method="post" action="<?= e($action) ?>">
                <?= csrf_field() ?>

                <div class="row g-3">
                    <div class="col-12 col-md-4">
                        <div class="form-group">
                            <label class="form-label" for="activity_code">Kode kegiatan *</label>
                            <input class="form-control" type="text" id="activity_code" name="activity_code"
                                   value="<?= e($value('activity_code')) ?>" maxlength="30"
                                   pattern="KGT-[0-9]{1,4}" placeholder="KGT-001">
                            <small class="form-text text-muted">
                                Boleh dibiarkan kosong; sistem memberi nomor berikutnya pada proyek ini.
                            </small>
                        </div>
                    </div>
                    <div class="col-12 col-md-8">
                        <div class="form-group">
                            <label class="form-label" for="project_id">Proyek *</label>
                            <select class="form-select" id="project_id" name="project_id" required>
                                <option value="">Pilih proyek</option>
                                <?php foreach ($projects as $project): ?>
                                    <?php $isArchivedProject = (int) $project['is_archived'] === 1; ?>
                                    <option value="<?= e((string) $project['id']) ?>"
                                        <?= $selectedProject === (int) $project['id'] ? 'selected' : '' ?>>
                                        <?= e((string) $project['project_code']) ?> &mdash;
                                        <?= e((string) $project['name']) ?>
                                        <?= $isArchivedProject ? '(arsip)' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="name">Nama kegiatan *</label>
                    <input class="form-control" type="text" id="name" name="name" required
                           minlength="3" maxlength="150" value="<?= e($value('name')) ?>"
                           placeholder="Contoh: Pengecoran lantai dapur utama">
                </div>

                <div class="form-group">
                    <label class="form-label" for="description">Deskripsi pekerjaan</label>
                    <textarea class="form-control" id="description" name="description" rows="3"
                              maxlength="1000"><?= e($value('description')) ?></textarea>
                </div>

                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <div class="form-group">
                            <label class="form-label" for="category_id">Kategori pekerjaan</label>
                            <select class="form-select" id="category_id" name="category_id">
                                <option value="">Tanpa kategori</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?= e((string) $category['id']) ?>"
                                        <?= $value('category_id') === (string) $category['id'] ? 'selected' : '' ?>>
                                        <?= e((string) $category['category_code']) ?> &mdash;
                                        <?= e((string) $category['category_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="form-text text-muted">
                                Kategori yang sama dipakai RAB dan transaksi agar laporannya seirama.
                            </small>
                        </div>
                    </div>
                    <div class="col-12 col-md-6">
                        <div class="form-group">
                            <label class="form-label" for="owner_user_id">Penanggung jawab (pengguna)</label>
                            <select class="form-select" id="owner_user_id" name="owner_user_id">
                                <option value="">Bukan pengguna terdaftar</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?= e((string) $user['id']) ?>"
                                        <?= $value('owner_user_id') === (string) $user['id'] ? 'selected' : '' ?>>
                                        <?= e((string) $user['full_name']) ?> &mdash;
                                        <?= e((string) $user['role_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <div class="form-group">
                            <label class="form-label" for="owner_name">Nama penanggung jawab *</label>
                            <input class="form-control" type="text" id="owner_name" name="owner_name" required
                                   minlength="2" maxlength="120" value="<?= e($value('owner_name')) ?>"
                                   placeholder="Nama yang tertera pada surat tugas">
                        </div>
                    </div>
                    <div class="col-12 col-md-3">
                        <div class="form-group">
                            <label class="form-label" for="start_date">Tanggal mulai *</label>
                            <input class="form-control" type="date" id="start_date" name="start_date" required
                                   value="<?= e($value('start_date')) ?>">
                        </div>
                    </div>
                    <div class="col-12 col-md-3">
                        <div class="form-group">
                            <label class="form-label" for="end_date">Tanggal selesai *</label>
                            <input class="form-control" type="date" id="end_date" name="end_date" required
                                   value="<?= e($value('end_date')) ?>">
                        </div>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-12 col-md-4">
                        <div class="form-group">
                            <label class="form-label" for="priority">Prioritas *</label>
                            <select class="form-select" id="priority" name="priority" required>
                                <?php foreach ($priorities as $priority): ?>
                                    <option value="<?= e($priority) ?>"
                                        <?= $value('priority') === $priority ? 'selected' : '' ?>>
                                        <?= e(schedule_priority_label($priority)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="form-group">
                            <label class="form-label" for="status">Status *</label>
                            <select class="form-select" id="status" name="status" required>
                                <?php foreach ($statuses as $status): ?>
                                    <option value="<?= e($status) ?>"
                                        <?= $value('status') === $status ? 'selected' : '' ?>>
                                        <?= e(schedule_status_label($status)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="form-group">
                            <label class="form-label" for="progress">Progres (%) *</label>
                            <input class="form-control" type="number" id="progress" name="progress" required
                                   min="0" max="100" step="1" value="<?= e($value('progress')) ?>">
                            <small class="form-text text-muted">
                                Angka bulat 0&ndash;100. Progres 100 hanya dapat disimpan dengan status Selesai.
                            </small>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="notes">Catatan</label>
                    <textarea class="form-control" id="notes" name="notes" rows="2"
                              maxlength="1000"><?= e($value('notes')) ?></textarea>
                </div>

                <?php if ($isEdit): ?>
                    <div class="form-group">
                        <label class="form-label" for="reason">Alasan perubahan</label>
                        <textarea class="form-control" id="reason" name="reason" rows="2" minlength="5"
                                  maxlength="500"
                                  placeholder="Contoh: jadwal mundur karena material pondasi terlambat datang."></textarea>
                        <small class="form-text text-muted">
                            Wajib diisi 5&ndash;500 karakter bila ada nilai yang berubah.
                        </small>
                    </div>
                <?php endif; ?>

                <div class="form-actions">
                    <button class="btn btn-primary" type="submit">
                        <?= $isEdit ? 'Simpan perubahan' : 'Simpan kegiatan' ?>
                    </button>
                    <a class="btn btn-link" href="<?= url('/jadwal') ?>">Batal</a>
                </div>
            </form>
        </section>
    </div>

    <div class="col-12 col-xl-4">
        <?php if ($isEdit): ?>
            <section class="app-card mb-3">
                <header class="card-head">
                    <div>
                        <h2 class="card-title">Kondisi tersimpan</h2>
                        <p class="card-subtitle">Nilai ini yang dibandingkan saat formulir dikirim.</p>
                    </div>
                </header>
                <dl class="info-list">
                    <div>
                        <dt>Durasi</dt>
                        <dd><?= e((string) $task['duration_days']) ?> hari</dd>
                    </div>
                    <div>
                        <dt>Progres</dt>
                        <dd><?= e((string) $task['progress']) ?>%</dd>
                    </div>
                    <div>
                        <dt>Status</dt>
                        <dd>
                            <span class="pill pill-<?= e(schedule_status_tone($task['status'])) ?>">
                                <?= e(schedule_status_label((string) $task['status'])) ?>
                            </span>
                        </dd>
                    </div>
                    <div>
                        <dt>Proyek</dt>
                        <dd class="text-start">
                            <?= e((string) $task['project_name']) ?>
                            <?php if ((int) $task['is_archived'] === 1): ?>
                                <span class="cell-sub d-block">(proyek diarsipkan)</span>
                            <?php endif; ?>
                        </dd>
                    </div>
                    <div>
                        <dt>Renacana proyek</dt>
                        <dd class="text-start">
                            <?= e(tanggal_id((string) $task['project_start'])) ?>
                            &ndash; <?= e(tanggal_id((string) $task['project_target'])) ?>
                        </dd>
                    </div>
                    <div>
                        <dt>Dibuat</dt>
                        <dd class="text-start">
                            <?= e($task['creator_name'] ?? '—') ?>
                            <span class="cell-sub d-block"><?= e(tanggal_id((string) $task['created_at'], true)) ?></span>
                        </dd>
                    </div>
                    <div>
                        <dt>Perubahan terakhir</dt>
                        <dd class="text-start">
                            <?= e($task['updater_name'] ?? '—') ?>
                            <span class="cell-sub d-block"><?= e(tanggal_id((string) $task['updated_at'], true)) ?></span>
                        </dd>
                    </div>
                </dl>
            </section>
        <?php else: ?>
            <section class="app-card mb-3">
                <header class="card-head">
                    <div>
                        <h2 class="card-title">Proyek terpilih</h2>
                    </div>
                </header>
                <?php if ($projectName === ''): ?>
                    <p class="muted-note">
                        Pilih proyek lebih dahulu. Kode kegiatan otomatis dan rentang tampilan mengikuti proyek tersebut.
                    </p>
                <?php else: ?>
                    <dl class="info-list">
                        <div>
                            <dt>Proyek</dt>
                            <dd class="text-start"><?= e($projectName) ?></dd>
                        </div>
                    </dl>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <section class="app-card">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Aturan pengisian</h2>
                </div>
            </header>
            <ul class="rule-list">
                <li>Durasi dihitung server: (tanggal selesai &minus; tanggal mulai) + 1 hari. Kolom durasi tidak diisi manual.</li>
                <li>Tanggal selesai tidak boleh lebih awal dari tanggal mulai.</li>
                <li>Kode kegiatan unik dalam satu proyek dan bermotif KGT-NOMOR.</li>
                <li>Progres 100% hanya boleh disimpan bersama status Selesai; status Selesai mewajibkan progres 100%.</li>
                <li>Perubahan kegiatan selalu disertai alasan dan tercatat pada riwayat jadwal.</li>
                <?php if ($isEdit): ?>
                    <li>Kegiatan yang sudah punya riwayat tidak dapat dihapus; gunakan status Dibatalkan agar jejaknya tetap ada.</li>
                <?php endif; ?>
            </ul>
        </section>
    </div>
</div>
