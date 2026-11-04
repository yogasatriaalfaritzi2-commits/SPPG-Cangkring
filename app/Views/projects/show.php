<?php
/** @var array<string, mixed> $project */
$projectId = (int) $project['id'];
$progress = (int) $project['progress'];
$canBudget = can('budgets.view');
$isArchived = (int) $project['is_archived'] === 1;
$isOverdue = !in_array((string) $project['status'], [
    \App\Models\Project::STATUS_COMPLETED,
    \App\Models\Project::STATUS_CANCELLED,
], true) && (string) $project['target_date'] < date('Y-m-d');

$start = date_create((string) $project['start_date']) ?: null;
$target = date_create((string) $project['target_date']) ?: null;
$today = date_create('today') ?: null;

$daysTotal = 0;
$daysElapsed = 0;
$timePercent = null;
if ($start !== null && $target !== null && $today !== null) {
    $daysTotal = (int) $start->diff($target)->format('%r%a');
    $daysElapsed = max(0, min($daysTotal, (int) $start->diff($today)->format('%r%a')));
    $timePercent = $daysTotal > 0 ? (int) round($daysElapsed / $daysTotal * 100) : 100;
}
$daysLeft = $target !== null && $today !== null ? (int) $today->diff($target)->format('%r%a') : 0;

$oldValue = static function (string $key, string $default = '') use ($old): string {
    return trim((string) ($old[$key] ?? $default));
};

$seriesLabels = array_map(
    static fn (array $row): string => tanggal_id((string) $row['report_date']),
    $progressSeries
);
$seriesValues = array_map(static fn (array $row): int => (int) $row['progress_after'], $progressSeries);

$latestAfter = $latestProgress !== null ? (int) $latestProgress['progress_after'] : null;
$latestBefore = $latestProgress !== null ? (int) $latestProgress['progress_before'] : null;
// Penyerapan tidak dapat dihitung bila anggaran acuan masih nol; tampilkan tanda hubung.
$pacePercent = static fn (?float $value): string => $value === null ? '—' : percent_id($value);
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Pembangunan · <?= e($project['project_code']) ?></p>
        <h1 class="page-title"><?= e($project['name']) ?></h1>
        <p class="page-subtitle">
            <?= e($project['location']) ?> ·
            penanggung jawab <?= e($project['pic_name']) ?> ·
            <?= e(project_status_label($project['status'])) ?> · <?= e((string) $progress) ?>%
        </p>
    </div>
    <div class="page-actions">
        <?php if (can('projects.update') && !$isArchived): ?>
            <a class="btn btn-primary" href="<?= url('/proyek/' . $projectId . '/ubah') ?>">
                <i class="bi bi-pencil-square me-1" aria-hidden="true"></i>Sunting
            </a>
        <?php endif; ?>
        <?php if (can('projects.archive')): ?>
            <form method="post" action="<?= url('/proyek/' . $projectId . '/arsip') ?>"
                  data-confirm
                  data-confirm-title="<?= $isArchived ? 'Pulihkan arsip' : 'Arsipkan proyek' ?>"
                  data-confirm-text="<?= e($isArchived
                      ? 'Pulihkan proyek "' . $project['project_code'] . '" agar kembali aktif pada daftar dan dashboard?'
                      : 'Arsipkan proyek "' . $project['project_code'] . '"? Progres, dokumen, dan tim tetap tersimpan.') ?>"
                  data-confirm-label="<?= $isArchived ? 'Ya, pulihkan' : 'Ya, arsipkan' ?>"
                  data-confirm-tone="warning">
                <?= csrf_field() ?>
                <input type="hidden" name="archive" value="<?= $isArchived ? '0' : '1' ?>">
                <button class="btn btn-outline-secondary" type="submit">
                    <?= $isArchived ? 'Pulihkan arsip' : 'Arsipkan' ?>
                </button>
            </form>
        <?php endif; ?>
        <a class="btn btn-outline-secondary" href="<?= url('/proyek') ?>">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Ke daftar
        </a>
    </div>
</div>

<?php if ($isArchived): ?>
    <div class="notice-box" role="note">
        <i class="bi bi-archive" aria-hidden="true"></i>
        <p>
            Proyek ini diarsipkan pada <?= e(tanggal_id((string) ($project['archived_at'] ?? ''), true)) ?>
            dan tidak dihitung pada ringkasan dashboard. Pulihkan arsip sebelum mengubah datanya.
        </p>
    </div>
<?php endif; ?>

<?php if ($isOverdue && !$isArchived): ?>
    <div class="notice-box" role="note">
        <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
        <p>
            Target selesai <?= e(tanggal_id((string) $project['target_date'])) ?> sudah lewat
            <?= e((string) abs($daysLeft)) ?> hari dengan progres <?= e((string) $progress) ?>%.
            Perbarui status menjadi Tertunda atau buat laporan progres terbaru.
        </p>
    </div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-12 col-md-6 col-xl-3">
        <section class="stat-card">
            <p class="stat-label">Progres fisik</p>
            <p class="stat-value"><?= e((string) $progress) ?>%</p>
            <div class="progress app-progress mt-2" role="progressbar" aria-label="Progres fisik proyek"
                 aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= e((string) $progress) ?>">
                <div class="progress-bar" style="width: <?= e((string) $progress) ?>%"></div>
            </div>
            <p class="stat-meta"><?= e((string) $progressCount) ?> laporan progres tercatat</p>
        </section>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
        <section class="stat-card">
            <p class="stat-label">Status proyek</p>
            <p class="stat-value h5 mb-1"><?= e(project_status_label($project['status'])) ?></p>
            <p class="stat-meta">
                <?= e($statusHistory === []
                    ? 'Belum ada riwayat perubahan status'
                    : 'Perubahan terakhir ' . tanggal_id((string) $statusHistory[0]['changed_at'], true)) ?>
            </p>
        </section>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
        <section class="stat-card">
            <p class="stat-label">Waktu berjalan</p>
            <p class="stat-value"><?= $timePercent === null ? '—' : e((string) $timePercent) ?>%</p>
            <p class="stat-meta">
                <?= e(tanggal_id((string) $project['start_date'])) ?> &ndash; <?= e(tanggal_id((string) $project['target_date'])) ?>
                <?php if (!$isArchived): ?>
                    · <?= $daysLeft >= 0 ? e((string) $daysLeft) . ' hari menuju target' : e((string) abs($daysLeft)) . ' hari melewati target' ?>
                <?php endif; ?>
            </p>
        </section>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
        <section class="stat-card">
            <p class="stat-label">Anggaran awal</p>
            <p class="stat-value h5 mb-1"><?= $finance === null ? 'Dibatasi izin' : e(rupiah($finance['initial_budget'])) ?></p>
            <p class="stat-meta">
                <?php if ($finance === null): ?>
                    Peran Anda tidak memiliki izin budgets.view
                <?php else: ?>
                    Realisasi <?= e(rupiah($finance['realization'])) ?> &middot;
                    penyerapan <?= e($pacePercent($finance['percent'])) ?>
                <?php endif; ?>
            </p>
        </section>
    </div>
</div>

<ul class="nav nav-pills project-tabs" id="projectTab" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="informasi" data-bs-toggle="pill" data-bs-target="#pane-informasi"
                type="button" role="tab" aria-controls="pane-informasi" aria-selected="true">Informasi umum</button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="lokasi" data-bs-toggle="pill" data-bs-target="#pane-lokasi"
                type="button" role="tab" aria-controls="pane-lokasi" aria-selected="false">Lokasi</button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="penanggung-jawab" data-bs-toggle="pill" data-bs-target="#pane-penanggung-jawab"
                type="button" role="tab" aria-controls="pane-penanggung-jawab" aria-selected="false">Penanggung jawab</button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="anggaran" data-bs-toggle="pill" data-bs-target="#pane-anggaran"
                type="button" role="tab" aria-controls="pane-anggaran" aria-selected="false">Anggaran</button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="progres" data-bs-toggle="pill" data-bs-target="#pane-progres"
                type="button" role="tab" aria-controls="pane-progres" aria-selected="false">Progres</button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="status" data-bs-toggle="pill" data-bs-target="#pane-status"
                type="button" role="tab" aria-controls="pane-status" aria-selected="false">Status</button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="jadwal" data-bs-toggle="pill" data-bs-target="#pane-jadwal"
                type="button" role="tab" aria-controls="pane-jadwal" aria-selected="false">Jadwal kegiatan</button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="riwayat" data-bs-toggle="pill" data-bs-target="#pane-riwayat"
                type="button" role="tab" aria-controls="pane-riwayat" aria-selected="false">Riwayat perubahan</button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="dokumen" data-bs-toggle="pill" data-bs-target="#pane-dokumen"
                type="button" role="tab" aria-controls="pane-dokumen" aria-selected="false">
            Dokumentasi<?= count($documents) > 0 ? ' (' . count($documents) . ')' : '' ?>
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="catatan" data-bs-toggle="pill" data-bs-target="#pane-catatan"
                type="button" role="tab" aria-controls="pane-catatan" aria-selected="false">Catatan lapangan</button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="tim" data-bs-toggle="pill" data-bs-target="#pane-tim"
                type="button" role="tab" aria-controls="pane-tim" aria-selected="false">
            Tim proyek<?= count($team) > 0 ? ' (' . count($team) . ')' : '' ?>
        </button>
    </li>
</ul>

<div class="tab-content project-tab-content">
    <div class="tab-pane fade show active" id="pane-informasi" role="tabpanel" aria-labelledby="informasi">
        <section class="app-card">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Identitas proyek</h2>
                    <p class="card-subtitle">Data pokok yang tersimpan pada database.</p>
                </div>
            </header>
            <dl class="info-list info-list-wide">
                <div>
                    <dt>Kode proyek</dt>
                    <dd class="cell-strong"><?= e($project['project_code']) ?></dd>
                </div>
                <div>
                    <dt>Nama proyek</dt>
                    <dd><?= e($project['name']) ?></dd>
                </div>
                <div>
                    <dt>Kontraktor / pelaksana</dt>
                    <dd><?= e($project['contractor'] !== null && $project['contractor'] !== '' ? (string) $project['contractor'] : 'Belum diisi') ?></dd>
                </div>
                <div>
                    <dt>Tanggal mulai</dt>
                    <dd><?= e(tanggal_id((string) $project['start_date'])) ?></dd>
                </div>
                <div>
                    <dt>Target selesai</dt>
                    <dd><?= e(tanggal_id((string) $project['target_date'])) ?></dd>
                </div>
                <div>
                    <dt>Status</dt>
                    <dd>
                        <span class="pill pill-<?= e(project_status_tone($project['status'])) ?>">
                            <?= e(project_status_label($project['status'])) ?>
                        </span>
                    </dd>
                </div>
                <div>
                    <dt>Deskripsi</dt>
                    <dd class="rich-text"><?= $project['description'] !== null && $project['description'] !== ''
                        ? nl2br(e((string) $project['description']))
                        : 'Belum diisi' ?></dd>
                </div>
                <div>
                    <dt>Dibuat</dt>
                    <dd>
                        <?= e(tanggal_id((string) $project['created_at'], true)) ?>
                        <?php if ($project['creator_name'] !== null && $project['creator_name'] !== ''): ?>
                            oleh <?= e((string) $project['creator_name']) ?>
                        <?php endif; ?>
                    </dd>
                </div>
                <div>
                    <dt>Perubahan terakhir</dt>
                    <dd><?= e(tanggal_id((string) $project['updated_at'], true)) ?></dd>
                </div>
            </dl>
        </section>
    </div>

    <div class="tab-pane fade" id="pane-lokasi" role="tabpanel" aria-labelledby="lokasi">
        <section class="app-card">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Lokasi proyek</h2>
                    <p class="card-subtitle">Wilayah, alamat, dan koordinat yang tercatat.</p>
                </div>
            </header>
            <dl class="info-list info-list-wide">
                <div>
                    <dt>Lokasi / wilayah</dt>
                    <dd><?= e($project['location']) ?></dd>
                </div>
                <div>
                    <dt>Alamat lengkap</dt>
                    <dd class="rich-text"><?= $project['address'] !== null && $project['address'] !== ''
                        ? nl2br(e((string) $project['address']))
                        : 'Belum diisi' ?></dd>
                </div>
                <div>
                    <dt>Lintang (latitude)</dt>
                    <dd><?= $project['latitude'] !== null ? e((string) $project['latitude']) : 'Belum diisi' ?></dd>
                </div>
                <div>
                    <dt>Bujur (longitude)</dt>
                    <dd><?= $project['longitude'] !== null ? e((string) $project['longitude']) : 'Belum diisi' ?></dd>
                </div>
            </dl>
            <p class="muted-note">
                Peta interaktif belum tersedia karena aplikasi tidak memuat layanan peta eksternal.
                Koordinat di atas dapat disalin langsung ke aplikasi peta pilihan Anda.
            </p>
        </section>
    </div>

    <div class="tab-pane fade" id="pane-penanggung-jawab" role="tabpanel" aria-labelledby="penanggung-jawab">
        <section class="app-card">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Penanggung jawab proyek</h2>
                    <p class="card-subtitle">Nama wajib diisi; tautan akun bersifat opsional.</p>
                </div>
            </header>
            <dl class="info-list info-list-wide">
                <div>
                    <dt>Nama penanggung jawab</dt>
                    <dd class="cell-strong"><?= e($project['pic_name']) ?></dd>
                </div>
                <div>
                    <dt>Akun tertaut</dt>
                    <dd>
                        <?= $project['pic_user_name'] !== null && $project['pic_user_name'] !== ''
                            ? e((string) $project['pic_user_name']) . ' · sudah ditautkan ke akun pengguna'
                            : 'Tidak tertaut akun' ?>
                    </dd>
                </div>
                <div>
                    <dt>Anggota tim aktif</dt>
                    <dd><?= e((string) count($team)) ?> orang</dd>
                </div>
            </dl>
            <p class="muted-note">
                Menandai satu anggota tim sebagai pemimpin pada tab Tim proyek akan menempatkan orang tersebut
                sebagai penanggung jawab proyek.
            </p>
        </section>
    </div>

    <div class="tab-pane fade" id="pane-anggaran" role="tabpanel" aria-labelledby="anggaran">
        <section class="app-card">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Anggaran proyek</h2>
                    <p class="card-subtitle">
                        Realisasi dihitung dari transaksi pembangunan yang sudah disetujui pada proyek ini.
                    </p>
                </div>
                <?php if ($finance !== null): ?>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= url('/anggaran/' . $projectId) ?>">
                        Perubahan anggaran
                    </a>
                <?php endif; ?>
            </header>
            <?php if ($finance !== null): ?>
                <dl class="info-list info-list-wide">
                    <div>
                        <dt>Anggaran awal</dt>
                        <dd class="cell-strong"><?= e(rupiah($finance['initial_budget'])) ?></dd>
                    </div>
                    <div>
                        <dt>Total RAB yang disetujui</dt>
                        <dd>
                            <?= e(rupiah($finance['approved_budget'])) ?>
                            &middot; <?= e((string) $finance['approved_plan_count']) ?> RAB
                        </dd>
                    </div>
                    <div>
                        <dt>Anggaran acuan</dt>
                        <dd class="cell-strong">
                            <?= e(rupiah($finance['baseline'])) ?>
                            <span class="cell-sub d-block"><?= e(budget_baseline_label($finance['baseline_source'])) ?></span>
                        </dd>
                    </div>
                    <div>
                        <dt>Realisasi pembangunan</dt>
                        <dd><?= e(rupiah($finance['realization'])) ?></dd>
                    </div>
                    <div>
                        <dt>Pemasukan pembangunan</dt>
                        <dd><?= e(rupiah($finance['income'])) ?></dd>
                    </div>
                    <div>
                        <dt>Sisa anggaran</dt>
                        <dd>
                            <?= e(rupiah($finance['remaining'])) ?>
                            <span class="pill pill-<?= e(budget_indicator_tone($finance['indicator'])) ?> ms-2">
                                <?= e(budget_indicator_label($finance['indicator'])) ?>
                            </span>
                        </dd>
                    </div>
                    <div>
                        <dt>Penyerapan</dt>
                        <dd>
                            <?= e($pacePercent($finance['percent'])) ?>
                            <span class="cell-sub d-block">Ambang peringatan <?= e((string) $finance['warning_percent']) ?>%</span>
                        </dd>
                    </div>
                    <div>
                        <dt>Realisasi operasional MBG</dt>
                        <dd>
                            <?= $operationsSpent === null ? 'Dibatasi izin' : e(rupiah($operationsSpent)) ?>
                            <span class="cell-sub d-block">
                                Dilaporkan terpisah; biaya operasional MBG tidak mengurangi anggaran pembangunan.
                            </span>
                        </dd>
                    </div>
                    <div>
                        <dt>Progres fisik</dt>
                        <dd><?= e((string) $progress) ?>%</dd>
                    </div>
                </dl>
                <p class="muted-note">
                    Sisa = anggaran acuan dikurangi realisasi. Penyerapan = realisasi dibagi anggaran acuan
                    dikali 100%. Angka diubah hanya lewat transaksi berstatus Disetujui, dan setiap perubahan
                    anggaran dicatat sebagai usulan perubahan berserta alasannya.
                    <?php if (can('budgets.create')): ?>
                        <a href="<?= url('/rab/tambah?proyek=' . $projectId) ?>">Susun RAB</a>
                    <?php endif; ?>
                    <?php if (can('budgets.view')): ?>
                        <a href="<?= url('/rab?proyek=' . $projectId) ?>">Daftar RAB proyek ini</a>
                    <?php endif; ?>
                    <?php if (can('transactions.view')): ?>
                        <a href="<?= url('/transaksi?proyek=' . $projectId) ?>">Daftar transaksi proyek ini</a>
                    <?php endif; ?>
                </p>
            <?php else: ?>
                <p class="muted-note">
                    Rincian anggaran dibatasi izin. Peran Anda memiliki izin projects.view tanpa budgets.view,
                    sehingga nilai anggaran tidak dikirim ke halaman ini.
                </p>
            <?php endif; ?>
        </section>
    </div>

    <div class="tab-pane fade" id="pane-progres" role="tabpanel" aria-labelledby="progres">
        <div class="row g-3">
            <div class="col-12 col-xl-7">
                <section class="app-card mb-3">
                    <header class="card-head">
                        <div>
                            <h2 class="card-title">Grafik progres terhadap waktu</h2>
                            <p class="card-subtitle">Setiap titik diambil dari laporan progres yang tersimpan.</p>
                        </div>
                    </header>
                    <?php if ($progressSeries === []): ?>
                        <div class="empty-state empty-state-block">
                            <i class="bi bi-graph-up" aria-hidden="true"></i>
                            <p class="empty-title">Belum ada laporan progres</p>
                            <p class="empty-text">Grafik muncul setelah minimal satu laporan progres dicatat.</p>
                        </div>
                    <?php else: ?>
                        <div class="chart-box">
                            <canvas class="chart-canvas" data-chart-type="line"
                                    data-chart-labels="<?= json_attr($seriesLabels) ?>"
                                    data-chart-values="<?= json_attr($seriesValues) ?>"
                                    data-chart-unit="%" data-chart-max="100"
                                    aria-label="Grafik progres proyek terhadap waktu"></canvas>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="app-card">
                    <header class="card-head">
                        <div>
                            <h2 class="card-title">Target dan aktual</h2>
                            <p class="card-subtitle">Perbandingan waktu berjalan dengan progres fisik tercatat.</p>
                        </div>
                    </header>
                    <div class="compare-block">
                        <div class="compare-row">
                            <span class="compare-label">Progres fisik</span>
                            <div class="progress app-progress" role="progressbar" aria-label="Progres fisik"
                                 aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= e((string) $progress) ?>">
                                <div class="progress-bar bg-success" style="width: <?= e((string) $progress) ?>%"></div>
                            </div>
                            <span class="compare-value"><?= e((string) $progress) ?>%</span>
                        </div>
                        <div class="compare-row">
                            <span class="compare-label">Waktu berjalan</span>
                            <div class="progress app-progress" role="progressbar" aria-label="Waktu berjalan"
                                 aria-valuemin="0" aria-valuemax="100"
                                 aria-valuenow="<?= e((string) ($timePercent ?? 0)) ?>">
                                <div class="progress-bar bg-primary" style="width: <?= e((string) ($timePercent ?? 0)) ?>%"></div>
                            </div>
                            <span class="compare-value"><?= $timePercent === null ? '—' : e((string) $timePercent) ?>%</span>
                        </div>
                    </div>
                    <p class="muted-note">
                        <?= $timePercent === null
                            ? 'Durasi proyek belum dapat dihitung karena tanggal mulai atau target selesai belum terisi.'
                            : ($progress >= $timePercent
                                ? 'Progres fisik berada di depan atau setara dengan waktu berjalan (' . e((string) ($progress - $timePercent)) . ' poin lebih tinggi).'
                                : 'Progres fisik tertinggal ' . e((string) ($timePercent - $progress)) . ' poin dari waktu berjalan.') ?>
                    </p>
                </section>
            </div>

            <div class="col-12 col-xl-5">
                <?php if (can('projects.progress.update') && !$isArchived): ?>
                    <section class="app-card mb-3">
                        <header class="card-head">
                            <div>
                                <h2 class="card-title">Laporkan progres</h2>
                                <p class="card-subtitle">Setiap laporan menyimpan progres sebelum dan sesudah.</p>
                            </div>
                        </header>
                        <form class="app-form" method="post" enctype="multipart/form-data"
                              action="<?= url('/proyek/' . $projectId . '/progres') ?>">
                            <?= csrf_field() ?>

                            <div class="form-group">
                                <label class="form-label" for="progress">Persentase progres *</label>
                                <div class="progress-input">
                                    <input class="form-control" type="number" id="progress" name="progress"
                                           value="<?= e($oldValue('progress', (string) $progress)) ?>"
                                           min="0" max="100" step="1" required
                                           data-progress-input="progress-preview">
                                    <output class="progress-output" id="progress-preview"><?= e($oldValue('progress', (string) $progress)) ?>%</output>
                                </div>
                                <div class="form-text">Progres saat ini <?= e((string) $progress) ?>%. Rentang 0 sampai 100.</div>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="report_date">Tanggal pelaporan *</label>
                                <input class="form-control" type="date" id="report_date" name="report_date"
                                       value="<?= e($oldValue('report_date', date('Y-m-d'))) ?>"
                                       max="<?= e(date('Y-m-d')) ?>" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="work_description">Deskripsi pekerjaan *</label>
                                <textarea class="form-control" id="work_description" name="work_description" rows="3"
                                          maxlength="2000" required><?= e($oldValue('work_description')) ?></textarea>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="completed_work">Pekerjaan selesai</label>
                                <textarea class="form-control" id="completed_work" name="completed_work" rows="2"
                                          maxlength="2000"><?= e($oldValue('completed_work')) ?></textarea>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="obstacles">Kendala lapangan</label>
                                <textarea class="form-control" id="obstacles" name="obstacles" rows="2"
                                          maxlength="2000"><?= e($oldValue('obstacles')) ?></textarea>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="next_plan">Rencana berikutnya</label>
                                <textarea class="form-control" id="next_plan" name="next_plan" rows="2"
                                          maxlength="2000"><?= e($oldValue('next_plan')) ?></textarea>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="responsible_name">Penanggung jawab laporan *</label>
                                <input class="form-control" type="text" id="responsible_name" name="responsible_name"
                                       value="<?= e($oldValue('responsible_name', (string) $project['pic_name'])) ?>"
                                       maxlength="120" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="berkas">Foto pendukung (opsional)</label>
                                <input class="form-control" type="file" id="berkas" name="berkas"
                                       accept=".jpg,.jpeg,.png,.webp,.pdf">
                                <div class="form-text">
                                    JPG, PNG, WEBP, atau PDF. Maksimal <?= e((string) $maxUploadMb) ?> MB.
                                    Berkas disimpan di luar folder publik dan dilayani melalui controller.
                                </div>
                            </div>

                            <div class="form-actions">
                                <button class="btn btn-primary" type="submit">Simpan laporan progres</button>
                            </div>
                        </form>
                    </section>
                <?php endif; ?>

                <section class="app-card">
                    <header class="card-head">
                        <div>
                            <h2 class="card-title">Riwayat progres</h2>
                            <p class="card-subtitle"><?= e((string) $progressCount) ?> laporan tersimpan.</p>
                        </div>
                    </header>
                    <?php if ($latestProgress !== null): ?>
                        <div class="latest-progress">
                            <span class="cell-sub">Laporan terakhir <?= e(tanggal_id((string) $latestProgress['report_date'], true)) ?></span>
                            <span class="cell-strong d-block">
                                <?= e((string) $latestBefore) ?>% &rarr; <?= e((string) $latestAfter) ?>%
                                · <?= e($latestProgress['responsible_name']) ?>
                            </span>
                        </div>
                    <?php endif; ?>
                    <div class="timeline-list">
                        <?php if ($progressRows === []): ?>
                            <p class="muted-note">Belum ada laporan progres pada proyek ini.</p>
                        <?php endif; ?>
                        <?php foreach ($progressRows as $row): ?>
                            <article class="timeline-item">
                                <div class="timeline-head">
                                    <span class="timeline-date"><?= e(tanggal_id((string) $row['report_date'])) ?></span>
                                    <span class="timeline-delta">
                                        <?= e((string) (int) $row['progress_before']) ?>% &rarr; <?= e((string) (int) $row['progress_after']) ?>%
                                    </span>
                                </div>
                                <p class="timeline-title"><?= e($row['work_description']) ?></p>
                                <?php if ($row['completed_work'] !== null && $row['completed_work'] !== ''): ?>
                                    <p class="timeline-text"><strong>Selesai:</strong> <?= e((string) $row['completed_work']) ?></p>
                                <?php endif; ?>
                                <?php if ($row['obstacles'] !== null && $row['obstacles'] !== ''): ?>
                                    <p class="timeline-text"><strong>Kendala:</strong> <?= e((string) $row['obstacles']) ?></p>
                                <?php endif; ?>
                                <?php if ($row['next_plan'] !== null && $row['next_plan'] !== ''): ?>
                                    <p class="timeline-text"><strong>Rencana:</strong> <?= e((string) $row['next_plan']) ?></p>
                                <?php endif; ?>
                                <p class="timeline-foot">
                                    PJ laporan <?= e($row['responsible_name']) ?>
                                    · dicatat oleh <?= e($row['reporter_name'] !== null && $row['reporter_name'] !== ''
                                        ? (string) $row['reporter_name']
                                        : (string) $row['reported_by_name']) ?>
                                    pada <?= e(tanggal_id((string) $row['created_at'], true)) ?>
                                </p>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            </div>
        </div>
    </div>

    <div class="tab-pane fade" id="pane-status" role="tabpanel" aria-labelledby="status">
        <section class="app-card">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Ubah status proyek</h2>
                    <p class="card-subtitle">
                        Status saat ini: <?= e(project_status_label($project['status'])) ?>.
                        Perubahan selalu menyimpan nama pengguna, waktu, dan alasan bila diwajibkan.
                    </p>
                </div>
            </header>
            <?php if (can('projects.status.update') && !$isArchived): ?>
                <form class="app-form" method="post" action="<?= url('/proyek/' . $projectId . '/status') ?>"
                      data-confirm
                      data-confirm-title="Ubah status proyek"
                      data-confirm-text="Status proyek <?= e($project['project_code']) ?> akan diubah. Riwayat perubahan akan mencatat pengguna dan waktu."
                      data-confirm-label="Ya, ubah status"
                      data-confirm-tone="warning">
                    <?= csrf_field() ?>
                    <div class="row g-3">
                        <div class="col-12 col-md-4">
                            <div class="form-group mb-0">
                                <label class="form-label" for="status_new">Status baru *</label>
                                <select class="form-select" id="status_new" name="status" required>
                                    <?php foreach ($statuses as $status): ?>
                                        <option value="<?= e($status) ?>"
                                            <?= (string) $project['status'] === $status ? 'selected' : '' ?>>
                                            <?= e(project_status_label($status)) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-12 col-md-8">
                            <div class="form-group mb-0">
                                <label class="form-label" for="reason">Alasan perubahan</label>
                                <input class="form-control" type="text" id="reason" name="reason" maxlength="255"
                                       placeholder="Wajib untuk status Tertunda atau Dibatalkan.">
                            </div>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button class="btn btn-primary" type="submit">Simpan status</button>
                    </div>
                </form>
                <p class="muted-note">
                    Progres 100 persen tidak mengubah status menjadi Selesai secara otomatis.
                    Perubahan status adalah tindakan terpisah yang memerlukan otorisasi.
                </p>
            <?php else: ?>
                <p class="muted-note">
                    <?= $isArchived
                        ? 'Proyek terarsip tidak dapat diubah statusnya. Pulihkan arsip terlebih dahulu.'
                        : 'Peran Anda belum memiliki izin projects.status.update untuk mengubah status proyek.' ?>
                </p>
            <?php endif; ?>
        </section>
    </div>

    <div class="tab-pane fade" id="pane-jadwal" role="tabpanel" aria-labelledby="jadwal">
        <section class="app-card">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Jadwal kegiatan</h2>
                    <p class="card-subtitle">
                        Kegiatan pembangunan yang tercatat pada proyek ini beserta kemajuannya.
                    </p>
                </div>
                <?php if ($tasks !== null): ?>
                    <div class="d-flex gap-2">
                        <a class="btn btn-sm btn-outline-secondary" href="<?= url('/jadwal/kalender?proyek=' . $projectId) ?>">Kalender</a>
                        <a class="btn btn-sm btn-outline-secondary" href="<?= url('/jadwal/gantt?proyek=' . $projectId) ?>">Bagan Gantt</a>
                    </div>
                <?php endif; ?>
            </header>
            <?php if ($tasks === null): ?>
                <p class="muted-note">
                    Jadwal kegiatan dibatasi izin. Peran Anda tidak memiliki izin schedules.view.
                </p>
            <?php elseif ($tasks === []): ?>
                <div class="empty-state empty-state-block">
                    <i class="bi bi-calendar-x" aria-hidden="true"></i>
                    <p class="empty-title">Belum ada kegiatan</p>
                    <p class="empty-text">
                        Tambahkan kegiatan untuk melihat rentang waktu, penanggung jawab, dan kemajuannya.
                    </p>
                    <?php if (can('schedules.create')): ?>
                        <a class="btn btn-sm btn-primary" href="<?= url('/jadwal/tambah?proyek=' . $projectId) ?>">
                            Tambah kegiatan
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <dl class="detail-list">
                    <div>
                        <dt>Jumlah kegiatan</dt>
                        <dd><?= e((string) $taskSummary['total']) ?></dd>
                    </div>
                    <div>
                        <dt>Sedang berjalan</dt>
                        <dd><?= e((string) $taskSummary['running']) ?></dd>
                    </div>
                    <div>
                        <dt>Lewat tenggat</dt>
                        <dd><?= e((string) $taskSummary['late']) ?></dd>
                    </div>
                    <div>
                        <dt>Rata-rata progres</dt>
                        <dd><?= e((string) $taskSummary['avg_progress']) ?>%</dd>
                    </div>
                </dl>
                <div class="table-responsive">
                    <table class="app-table">
                        <thead>
                            <tr>
                                <th scope="col">Kegiatan</th>
                                <th scope="col">Kategori</th>
                                <th scope="col">Rentang</th>
                                <th scope="col" class="text-end">Durasi</th>
                                <th scope="col">Penanggung jawab</th>
                                <th scope="col">Status</th>
                                <th scope="col" class="text-end">Progres</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tasks as $task): ?>
                                <tr>
                                    <td>
                                        <a class="cell-strong" href="<?= url('/jadwal/' . (int) $task['id']) ?>">
                                            <?= e((string) $task['activity_code']) ?>
                                        </a>
                                        <span class="cell-sub d-block"><?= e((string) $task['name']) ?></span>
                                    </td>
                                    <td><?= e((string) ($task['category_name'] ?? '—')) ?></td>
                                    <td>
                                        <?= e(tanggal_id((string) $task['start_date'])) ?>
                                        &ndash; <?= e(tanggal_id((string) $task['end_date'])) ?>
                                        <?php if ((int) $task['is_late'] === 1): ?>
                                            <span class="cell-sub d-block">Melewati tanggal selesai</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?= e((string) \App\Models\ProjectSchedule::duration(
                                            (string) $task['start_date'],
                                            (string) $task['end_date']
                                        )) ?> hari
                                    </td>
                                    <td><?= e((string) $task['owner_name']) ?></td>
                                    <td>
                                        <span class="pill pill-<?= e(schedule_status_tone($task['status'])) ?>">
                                            <?= e(schedule_status_label((string) $task['status'])) ?>
                                        </span>
                                    </td>
                                    <td class="text-end"><?= e((string) (int) $task['progress']) ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <div class="tab-pane fade" id="pane-riwayat" role="tabpanel" aria-labelledby="riwayat">
        <section class="app-card">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Riwayat perubahan status</h2>
                    <p class="card-subtitle">Perubahan data proyek tercatat pada log aktivitas sistem.</p>
                </div>
            </header>
            <div class="table-responsive">
                <table class="table app-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Waktu</th>
                            <th scope="col">Dari</th>
                            <th scope="col">Menjadi</th>
                            <th scope="col">Alasan</th>
                            <th scope="col">Oleh</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($statusHistory === []): ?>
                            <tr>
                                <td colspan="5" class="table-empty">
                                    <div class="empty-state">
                                        <i class="bi bi-clock-history" aria-hidden="true"></i>
                                        <p class="empty-title">Belum ada riwayat status</p>
                                        <p class="empty-text">Riwayat terbentuk saat proyek dibuat atau statusnya diubah.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($statusHistory as $log): ?>
                            <tr>
                                <td class="text-nowrap"><?= e(tanggal_id((string) $log['changed_at'], true)) ?></td>
                                <td><?= e($log['from_status'] !== null && $log['from_status'] !== ''
                                    ? project_status_label((string) $log['from_status'])
                                    : 'Awal') ?></td>
                                <td>
                                    <span class="pill pill-<?= e(project_status_tone($log['to_status'])) ?>">
                                        <?= e(project_status_label((string) $log['to_status'])) ?>
                                    </span>
                                </td>
                                <td><?= e($log['reason'] !== null && $log['reason'] !== '' ? (string) $log['reason'] : '—') ?></td>
                                <td><?= e($log['changed_by_name'] !== null && $log['changed_by_name'] !== ''
                                    ? (string) $log['changed_by_name']
                                    : 'Pengguna telah dihapus') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (can('audit.view')): ?>
                <p class="muted-note">
                    <a href="<?= url('/aktivitas') ?>">Lihat log aktivitas</a> untuk perubahan field, unggahan dokumen,
                    dan penugasan tim pada proyek ini.
                </p>
            <?php endif; ?>
        </section>
    </div>

    <div class="tab-pane fade" id="pane-dokumen" role="tabpanel" aria-labelledby="dokumen">
        <div class="row g-3">
            <?php if (can('projects.documents.upload') && !$isArchived): ?>
                <div class="col-12 col-xl-5">
                    <section class="app-card">
                        <header class="card-head">
                            <div>
                                <h2 class="card-title">Unggah dokumen</h2>
                                <p class="card-subtitle">Ekstensi dan isi berkas diperiksa di server.</p>
                            </div>
                        </header>
                        <form class="app-form" method="post" enctype="multipart/form-data"
                              action="<?= url('/proyek/' . $projectId . '/dokumen') ?>">
                            <?= csrf_field() ?>
                            <div class="form-group">
                                <label class="form-label" for="category">Kategori *</label>
                                <select class="form-select" id="category" name="category" required>
                                    <?php foreach ($documentCategories as $category): ?>
                                        <option value="<?= e($category) ?>">
                                            <?= e(document_category_label($category)) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="title">Judul dokumen *</label>
                                <input class="form-control" type="text" id="title" name="title" maxlength="150" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="doc_description">Keterangan</label>
                                <input class="form-control" type="text" id="doc_description" name="description" maxlength="255">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="document_file">Berkas *</label>
                                <input class="form-control" type="file" id="document_file" name="berkas"
                                       accept=".jpg,.jpeg,.png,.webp,.pdf" required>
                                <div class="form-text">JPG, PNG, WEBP, atau PDF. Maksimal <?= e((string) $maxUploadMb) ?> MB.</div>
                            </div>
                            <div class="form-actions">
                                <button class="btn btn-primary" type="submit">Unggah dokumen</button>
                            </div>
                        </form>
                    </section>
                </div>
            <?php endif; ?>

            <div class="<?= can('projects.documents.upload') && !$isArchived ? 'col-12 col-xl-7' : 'col-12' ?>">
                <section class="app-card">
                    <header class="card-head">
                        <div>
                            <h2 class="card-title">Dokumentasi proyek</h2>
                            <p class="card-subtitle">Foto kondisi awal, proses, hasil, dan berkas pendukung.</p>
                        </div>
                        <span class="card-count"><?= count($documents) ?> berkas</span>
                    </header>
                    <div class="table-responsive">
                        <table class="table app-table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th scope="col">Judul</th>
                                    <th scope="col">Kategori</th>
                                    <th scope="col">Berkas</th>
                                    <th scope="col">Diunggah</th>
                                    <th scope="col" class="text-end">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($documents === []): ?>
                                    <tr>
                                        <td colspan="5" class="table-empty">
                                            <div class="empty-state">
                                                <i class="bi bi-file-earmark" aria-hidden="true"></i>
                                                <p class="empty-title">Belum ada dokumen</p>
                                                <p class="empty-text">Unggah foto atau berkas pendukung untuk proyek ini.</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($documents as $document): ?>
                                    <tr>
                                        <td>
                                            <span class="cell-strong d-block"><?= e($document['title']) ?></span>
                                            <span class="cell-sub"><?= e($document['description'] !== null && $document['description'] !== ''
                                                ? (string) $document['description']
                                                : 'Tanpa keterangan') ?></span>
                                        </td>
                                        <td>
                                            <span class="pill pill-info"><?= e(document_category_label($document['category'])) ?></span>
                                        </td>
                                        <td>
                                            <span class="cell-strong d-block"><?= e($document['original_name']) ?></span>
                                            <span class="cell-sub"><?= e((string) $document['mime_type']) ?> · <?= e(filesize_id($document['file_size'])) ?></span>
                                        </td>
                                        <td class="text-nowrap">
                                            <span class="cell-strong d-block"><?= e(tanggal_id((string) $document['uploaded_at'], true)) ?></span>
                                            <span class="cell-sub"><?= e($document['uploader_name'] !== null && $document['uploader_name'] !== ''
                                                ? (string) $document['uploader_name']
                                                : 'Pengguna telah dihapus') ?></span>
                                        </td>
                                        <td>
                                            <div class="cell-actions">
                                                <?php if (can('projects.documents.view')): ?>
                                                    <a class="btn btn-sm btn-outline-secondary" target="_blank"
                                                       rel="noopener" href="<?= url('/dokumen/' . $document['id'] . '/tampilan') ?>">Lihat</a>
                                                    <a class="btn btn-sm btn-outline-secondary"
                                                       href="<?= url('/dokumen/' . $document['id'] . '/unduh') ?>">Unduh</a>
                                                <?php endif; ?>
                                                <?php if (can('projects.documents.delete')): ?>
                                                    <form method="post" action="<?= url('/dokumen/' . $document['id'] . '/hapus') ?>"
                                                          data-confirm
                                                          data-confirm-title="Hapus dokumen"
                                                          data-confirm-text="<?= e('Hapus "' . $document['title'] . '" beserta berkas aslinya dari penyimpanan?') ?>"
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
                </section>
            </div>
        </div>
    </div>

    <div class="tab-pane fade" id="pane-catatan" role="tabpanel" aria-labelledby="catatan">
        <section class="app-card mb-3">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Catatan proyek</h2>
                    <p class="card-subtitle">Catatan yang disimpan bersama data proyek.</p>
                </div>
            </header>
            <p class="rich-text"><?= $project['notes'] !== null && $project['notes'] !== ''
                ? nl2br(e((string) $project['notes']))
                : 'Belum ada catatan pada data proyek.' ?></p>
        </section>

        <section class="app-card">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Kendala dan rencana dari laporan progres</h2>
                    <p class="card-subtitle">Diambil langsung dari riwayat laporan progres.</p>
                </div>
            </header>
            <?php
            $fieldNotes = [];
            foreach ($progressRows as $row) {
                foreach ([['obstacles', 'Kendala'], ['next_plan', 'Rencana berikutnya']] as [$field, $label]) {
                    $value = $row[$field] ?? null;
                    if ($value !== null && trim((string) $value) !== '') {
                        $fieldNotes[] = [
                            'date' => (string) $row['report_date'],
                            'label' => $label,
                            'value' => trim((string) $value),
                        ];
                    }
                }
            }
            ?>
            <?php if ($fieldNotes === []): ?>
                <p class="muted-note">Belum ada kendala atau rencana yang dicatat pada laporan progres proyek ini.</p>
            <?php else: ?>
                <ul class="note-list">
                    <?php foreach ($fieldNotes as $note): ?>
                        <li>
                            <span class="note-tag"><?= e($note['label']) ?></span>
                            <span class="note-date"><?= e(tanggal_id($note['date'])) ?></span>
                            <p class="note-text"><?= e($note['value']) ?></p>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>

    <div class="tab-pane fade" id="pane-tim" role="tabpanel" aria-labelledby="tim">
        <div class="row g-3">
            <div class="col-12 col-xl-7">
                <section class="app-card mb-3">
                    <header class="card-head">
                        <div>
                            <h2 class="card-title">Tim aktif</h2>
                            <p class="card-subtitle">Anggota diambil dari akun pengguna yang sudah ada.</p>
                        </div>
                        <span class="card-count"><?= count($team) ?> anggota</span>
                    </header>
                    <?php if ($team === []): ?>
                        <p class="muted-note">Belum ada anggota tim. Tambahkan pengguna dari daftar calon di samping.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table app-table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th scope="col">Anggota</th>
                                        <th scope="col">Jabatan</th>
                                        <th scope="col">Ditugaskan</th>
                                        <th scope="col" class="text-end">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($team as $member): ?>
                                        <?php
                                        $memberId = (int) $member['id'];
                                        $isPrincipal = (int) ($project['pic_user_id'] ?? 0) === (int) $member['user_id'];
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="cell-user">
                                                    <span class="avatar avatar-sm"><?= e(initials((string) $member['full_name'])) ?></span>
                                                    <span class="cell-user-text">
                                                        <span class="cell-strong">
                                                            <?= e($member['full_name']) ?>
                                                            <?php if ((int) $member['is_lead'] === 1): ?>
                                                                <span class="pill pill-success">Pemimpin</span>
                                                            <?php endif; ?>
                                                            <?php if ($isPrincipal): ?>
                                                                <span class="pill pill-info">Penanggung jawab proyek</span>
                                                            <?php endif; ?>
                                                        </span>
                                                        <span class="cell-sub"><?= e($member['role_name']) ?> · <?= e($member['username']) ?></span>
                                                        <?php if ($member['duties'] !== null && $member['duties'] !== ''): ?>
                                                            <span class="cell-sub"><?= e((string) $member['duties']) ?></span>
                                                        <?php endif; ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td><?= e($member['position']) ?></td>
                                            <td class="text-nowrap">
                                                <span class="cell-strong d-block"><?= e(tanggal_id((string) $member['assigned_at'], true)) ?></span>
                                                <span class="cell-sub"><?= e($member['assigned_by_name'] !== null && $member['assigned_by_name'] !== ''
                                                    ? 'oleh ' . (string) $member['assigned_by_name']
                                                    : 'penugaskan tidak tercatat') ?></span>
                                            </td>
                                            <td>
                                                <?php if (can('projects.team.manage') && !$isArchived): ?>
                                                    <div class="cell-actions">
                                                        <button class="btn btn-sm btn-outline-secondary" type="button"
                                                                data-bs-toggle="collapse" data-bs-target="#edit-tim-<?= e((string) $memberId) ?>"
                                                                aria-expanded="false" aria-controls="edit-tim-<?= e((string) $memberId) ?>">
                                                            Ubah penugasan
                                                        </button>
                                                        <form method="post" action="<?= url('/proyek/' . $projectId . '/tim/' . $memberId . '/hapus') ?>"
                                                              data-confirm
                                                              data-confirm-title="Lepas penugasan"
                                                              data-confirm-text="<?= e('Lepas "' . $member['full_name'] . '" dari proyek ini? Riwayat penugasan tetap tersimpan.') ?>"
                                                              data-confirm-label="Ya, lepas"
                                                              data-confirm-tone="warning">
                                                            <?= csrf_field() ?>
                                                            <button class="btn btn-sm btn-outline-danger" type="submit">Lepas</button>
                                                        </form>
                                                    </div>
                                                    <div class="collapse" id="edit-tim-<?= e((string) $memberId) ?>">
                                                        <form class="app-form team-edit-form" method="post"
                                                              action="<?= url('/proyek/' . $projectId . '/tim/' . $memberId) ?>">
                                                            <?= csrf_field() ?>
                                                            <div class="form-group">
                                                                <label class="form-label" for="position_<?= e((string) $memberId) ?>">Jabatan *</label>
                                                                <input class="form-control" type="text"
                                                                       id="position_<?= e((string) $memberId) ?>" name="position"
                                                                       value="<?= e($member['position']) ?>" maxlength="80" required>
                                                            </div>
                                                            <div class="form-group">
                                                                <label class="form-label" for="duties_<?= e((string) $memberId) ?>">Uraian tugas</label>
                                                                <input class="form-control" type="text"
                                                                       id="duties_<?= e((string) $memberId) ?>" name="duties"
                                                                       value="<?= e((string) ($member['duties'] ?? '')) ?>" maxlength="255">
                                                            </div>
                                                            <div class="form-check mb-2">
                                                                <input class="form-check-input" type="checkbox" id="lead_<?= e((string) $memberId) ?>"
                                                                       name="is_lead" value="1" <?= (int) $member['is_lead'] === 1 ? 'checked' : '' ?>
                                                                    <?= $isPrincipal ? 'disabled' : '' ?>>
                                                                <label class="form-check-label" for="lead_<?= e((string) $memberId) ?>">
                                                                    Jadikan pemimpin tim dan penanggung jawab proyek
                                                                    <?= $isPrincipal ? '(sudah berlaku)' : '' ?>
                                                                </label>
                                                            </div>
                                                            <?php if ($isPrincipal): ?>
                                                                <input type="hidden" name="is_lead" value="1">
                                                            <?php endif; ?>
                                                            <div class="form-actions">
                                                                <button class="btn btn-sm btn-primary" type="submit">Simpan penugasan</button>
                                                            </div>
                                                        </form>
                                                    </div>
                                                <?php else: ?>
                                                    <p class="cell-sub mb-0">
                                                        <?= $isArchived ? 'Proyek terarsip, penugasan tidak dapat diubah.' : 'Tidak ada izin projects.team.manage.' ?>
                                                    </p>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="app-card">
                    <header class="card-head">
                        <div>
                            <h2 class="card-title">Riwayat penugasan</h2>
                            <p class="card-subtitle">Termasuk anggota yang sudah dilepas.</p>
                        </div>
                    </header>
                    <?php if ($teamHistory === []): ?>
                        <p class="muted-note">Belum ada riwayat penugasan pada proyek ini.</p>
                    <?php else: ?>
                        <ul class="note-list">
                            <?php foreach ($teamHistory as $entry): ?>
                                <?php $isReleased = $entry['unassigned_at'] !== null && (string) $entry['unassigned_at'] !== ''; ?>
                                <li>
                                    <span class="note-tag"><?= $isReleased ? 'Dilepas' : 'Aktif' ?></span>
                                    <span class="note-date"><?= e(tanggal_id((string) $entry['assigned_at'], true)) ?></span>
                                    <p class="note-text">
                                        <?= e($entry['full_name']) ?> sebagai <?= e($entry['position']) ?>
                                        <?php if ($entry['unassigned_at'] !== null && $entry['unassigned_at'] !== ''): ?>
                                            · dilepas <?= e(tanggal_id((string) $entry['unassigned_at'], true)) ?>
                                        <?php endif; ?>
                                    </p>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>
            </div>

            <?php if (can('projects.team.manage') && !$isArchived): ?>
                <div class="col-12 col-xl-5">
                    <section class="app-card">
                        <header class="card-head">
                            <div>
                                <h2 class="card-title">Tambah anggota tim</h2>
                                <p class="card-subtitle">Akun baru dibuat pada halaman Pengguna, bukan di sini.</p>
                            </div>
                        </header>
                        <?php if ($candidates === []): ?>
                            <p class="muted-note">
                                Semua pengguna aktif sudah menjadi tim proyek ini. Tambahkan akun pengguna lebih dulu
                                bila ingin memperluas tim.
                            </p>
                        <?php else: ?>
                            <form class="app-form" method="post" action="<?= url('/proyek/' . $projectId . '/tim') ?>">
                                <?= csrf_field() ?>
                                <div class="form-group">
                                    <label class="form-label" for="user_id">Pengguna *</label>
                                    <select class="form-select" id="user_id" name="user_id" required>
                                        <option value="">Pilih pengguna aktif</option>
                                        <?php foreach ($candidates as $candidate): ?>
                                            <option value="<?= e((string) $candidate['id']) ?>">
                                                <?= e($candidate['full_name']) ?> · <?= e($candidate['role_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="new_position">Jabatan *</label>
                                    <input class="form-control" type="text" id="new_position" name="position"
                                           maxlength="80" placeholder="Misal Pengawas lapangan" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="new_duties">Uraian tugas</label>
                                    <input class="form-control" type="text" id="new_duties" name="duties" maxlength="255">
                                </div>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" id="new_lead" name="is_lead" value="1">
                                    <label class="form-check-label" for="new_lead">
                                        Jadikan pemimpin tim dan penanggung jawab proyek
                                    </label>
                                </div>
                                <div class="form-actions">
                                    <button class="btn btn-primary" type="submit">Tambahkan anggota</button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </section>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
