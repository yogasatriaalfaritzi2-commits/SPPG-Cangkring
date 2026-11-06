<?php
/** @var array<int, array<string, mixed>> $charts */
$periodOptions = [
    'today' => 'Hari ini',
    'week' => 'Minggu ini',
    'month' => 'Bulan ini',
    'year' => 'Tahun ini',
];
$chartCount = count($charts);
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Ringkasan</p>
        <h1 class="page-title">Selamat datang, <?= e($user['full_name']) ?></h1>
        <p class="page-subtitle">
            <?= e(tanggal_id(date('Y-m-d'))) ?> · Data operasional dan progres terintegrasi secara real-time dari <?= e(institution_name()) ?>.
        </p>
    </div>
    <?php if ($canViewProjects): ?>
        <div class="page-actions">
            <a class="btn btn-primary" href="<?= url('/proyek') ?>">
                <i class="bi bi-hammer me-1" aria-hidden="true"></i>Kelola proyek
            </a>
        </div>
    <?php endif; ?>
</div>

<section class="app-card period-bar mb-3">
    <div class="toolbar">
        <div class="toolbar-form period-form">
            <div class="toolbar-field period-presets">
                <span class="form-label">Periode data</span>
                <div class="btn-group flex-wrap" role="group" aria-label="Pilih periode">
                    <?php foreach ($periodOptions as $key => $label): ?>
                        <a class="btn btn-sm <?= $period === $key ? 'btn-primary' : 'btn-outline-secondary' ?>"
                           href="<?= e(query_url('/dashboard', ['period' => $key])) ?>"
                           <?= $period === $key ? 'aria-current="true"' : '' ?>><?= e($label) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
            <form class="period-custom" method="get" action="<?= url('/dashboard') ?>">
                <input type="hidden" name="period" value="custom">
                <div class="toolbar-field">
                    <label class="form-label" for="from">Dari</label>
                    <input class="form-control form-control-sm" type="date" id="from" name="from"
                           value="<?= e($range['from']) ?>">
                </div>
                <div class="toolbar-field">
                    <label class="form-label" for="to">Sampai</label>
                    <input class="form-control form-control-sm" type="date" id="to" name="to"
                           value="<?= e($range['to']) ?>">
                </div>
                <div class="toolbar-actions">
                    <button class="btn btn-sm <?= $period === 'custom' ? 'btn-primary' : 'btn-outline-secondary' ?>"
                            type="submit">Terapkan rentang</button>
                </div>
            </form>
        </div>
    </div>
    <p class="period-note">
        Periode terpilih: <?= e(tanggal_id($range['from'])) ?> sampai <?= e(tanggal_id($range['to'])) ?>.
        Grafik tren progres, aktivitas, dan anggaran mengikuti rentang ini; status proyek selalu dihitung
        pada kondisi saat ini.
    </p>
</section>

<div class="row g-3">
    <?php foreach ($cards as $card): ?>
        <div class="col-12 col-sm-6 col-xl-3">
            <section class="stat-card">
                <span class="stat-icon"><i class="bi <?= e($card['icon']) ?>" aria-hidden="true"></i></span>
                <p class="stat-label"><?= e($card['label']) ?></p>
                <p class="stat-value"><?= e($card['value']) ?></p>
                <p class="stat-meta"><?= e($card['meta']) ?></p>
            </section>
        </div>
    <?php endforeach; ?>
</div>

<?php if ($canViewProjects && $chartCount > 0): ?>
    <div class="row g-3 mt-1">
        <?php foreach ($charts as $index => $chart): ?>
            <div class="col-12 <?= $chart['type'] === 'doughnut' ? 'col-xl-4' : 'col-xl-8' ?>">
                <section class="app-card h-100">
                    <header class="card-head">
                        <div>
                            <h2 class="card-title"><?= e($chart['title']) ?></h2>
                            <p class="card-subtitle"><?= e($chart['note']) ?></p>
                        </div>
                    </header>
                    <?php if ($chart['labels'] === [] || $chart['values'] === []): ?>
                        <div class="empty-state empty-state-block">
                            <i class="bi bi-bar-chart" aria-hidden="true"></i>
                            <p class="empty-title"><?= e($chart['empty']) ?></p>
                            <p class="empty-text">Grafik terisi otomatis setelah data pada modul proyek tersedia.</p>
                        </div>
                    <?php else: ?>
                        <div class="chart-box <?= $chart['type'] === 'doughnut' ? 'chart-box-compact' : '' ?>">
                            <canvas class="chart-canvas" data-chart="<?= e((string) $index) ?>"
                                    data-chart-type="<?= e($chart['type']) ?>"
                                    data-chart-labels="<?= json_attr($chart['labels']) ?>"
                                    data-chart-values="<?= json_attr($chart['values']) ?>"
                                    data-chart-unit="<?= e((string) $chart['unit']) ?>"
                                    <?php if (isset($chart['max'])): ?>data-chart-max="<?= e((string) $chart['max']) ?>"<?php endif; ?>
                                    <?php if (!empty($chart['horizontal'])): ?>data-chart-horizontal="1"<?php endif; ?>
                                    aria-label="<?= e($chart['title']) ?>" role="img"></canvas>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        <?php endforeach; ?>
    </div>
<?php elseif ($canViewProjects): ?>
    <div class="notice-box" role="note">
        <i class="bi bi-bar-chart" aria-hidden="true"></i>
        <p>Belum ada data proyek pada periode ini, sehingga grafik tidak ditampilkan.</p>
    </div>
<?php endif; ?>

<?php if ($canViewProjects): ?>
    <div class="row g-3 mt-1">
        <div class="col-12 col-xl-6">
            <section class="app-card h-100">
                <header class="card-head">
                    <div>
                        <h2 class="card-title">Jadwal kegiatan terdekat</h2>
                        <p class="card-subtitle">Target selesai paling dekat pada proyek yang masih berjalan.</p>
                    </div>
                    <span class="card-count"><?= count($upcoming) ?> proyek</span>
                </header>
                <div class="table-responsive">
                    <table class="table app-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">Proyek</th>
                                <th scope="col">Target</th>
                                <th scope="col">Progres</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($upcoming === []): ?>
                                <tr>
                                    <td colspan="3" class="table-empty">
                                        <div class="empty-state">
                                            <i class="bi bi-calendar-check" aria-hidden="true"></i>
                                            <p class="empty-title">Belum ada agenda terdekat</p>
                                            <p class="empty-text">
                                                Tidak ada proyek berjalan dengan target selesai di masa mendatang.
                                                Rincian jadwal kegiatan akan tersedia pada modul jadwal pembangunan.
                                            </p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($upcoming as $item): ?>
                                <tr>
                                    <td>
                                        <a class="cell-strong d-block" href="<?= url('/proyek/' . $item['id']) ?>">
                                            <?= e($item['name']) ?>
                                        </a>
                                        <span class="cell-sub"><?= e($item['project_code']) ?> · <?= e($item['location']) ?></span>
                                    </td>
                                    <td class="text-nowrap">
                                        <span class="cell-strong d-block"><?= e(tanggal_id((string) $item['target_date'])) ?></span>
                                        <span class="cell-sub"><?= e(project_status_label($item['status'])) ?></span>
                                    </td>
                                    <td>
                                        <div class="progress-cell">
                                            <div class="progress app-progress" role="progressbar" aria-label="Progres"
                                                 aria-valuemin="0" aria-valuemax="100"
                                                 aria-valuenow="<?= e((string) (int) $item['progress']) ?>">
                                                <div class="progress-bar" style="width: <?= e((string) (int) $item['progress']) ?>%"></div>
                                            </div>
                                            <span class="progress-value"><?= e((string) (int) $item['progress']) ?>%</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <div class="col-12 col-xl-6">
            <section class="app-card h-100">
                <header class="card-head">
                    <div>
                        <h2 class="card-title">Proyek terlambat</h2>
                        <p class="card-subtitle">Melewati target selesai dan belum berstatus Selesai atau Dibatalkan.</p>
                    </div>
                    <span class="card-count"><?= count($overdue) ?> proyek</span>
                </header>
                <div class="table-responsive">
                    <table class="table app-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">Proyek</th>
                                <th scope="col">Target</th>
                                <th scope="col">Keterlambatan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($overdue === []): ?>
                                <tr>
                                    <td colspan="3" class="table-empty">
                                        <div class="empty-state">
                                            <i class="bi bi-check2-circle" aria-hidden="true"></i>
                                            <p class="empty-title">Tidak ada proyek terlambat</p>
                                            <p class="empty-text">Seluruh proyek berjalan masih berada pada atau sebelum target selesai.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($overdue as $item): ?>
                                <tr>
                                    <td>
                                        <a class="cell-strong d-block" href="<?= url('/proyek/' . $item['id']) ?>">
                                            <?= e($item['name']) ?>
                                        </a>
                                        <span class="cell-sub">
                                            <?= e($item['project_code']) ?> ·
                                            <span class="pill pill-<?= e(project_status_tone($item['status'])) ?>">
                                                <?= e(project_status_label($item['status'])) ?>
                                            </span>
                                        </span>
                                    </td>
                                    <td class="text-nowrap"><?= e(tanggal_id((string) $item['target_date'])) ?></td>
                                    <td class="text-nowrap text-warning">
                                        <?= e((string) (int) $item['days_late']) ?> hari
                                        <span class="cell-sub d-block">Progres <?= e((string) (int) $item['progress']) ?>%</span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
<?php endif; ?>

<div class="row g-3 mt-1">
    <div class="col-12 col-xl-8">
        <section class="app-card h-100">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Aktivitas terbaru</h2>
                    <p class="card-subtitle">
                        <?= $canViewAudit
                            ? 'Sepuluh catatan terakhir dari log aktivitas; daftar lengkap tersedia berpaginasi.'
                            : 'Rincian log aktivitas memerlukan izin audit.view.' ?>
                    </p>
                </div>
                <span class="card-count"><?= count($activities) ?> catatan</span>
                <?php if ($canViewAudit): ?>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= url('/aktivitas') ?>">Lihat semua</a>
                <?php endif; ?>
            </header>
            <div class="table-responsive">
                <table class="table app-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Waktu</th>
                            <th scope="col">Pengguna</th>
                            <th scope="col">Modul</th>
                            <th scope="col">Aktivitas</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($activities === []): ?>
                            <tr>
                                <td colspan="4" class="table-empty">
                                    <div class="empty-state">
                                        <i class="bi bi-journal-text" aria-hidden="true"></i>
                                        <p class="empty-title">
                                            <?= $canViewAudit ? 'Belum ada aktivitas tercatat' : 'Aktivitas tidak dapat ditampilkan' ?>
                                        </p>
                                        <p class="empty-text">
                                            <?= $canViewAudit
                                                ? 'Aktivitas muncul saat pengguna masuk, mengubah data, atau mengirim laporan.'
                                                : 'Peran Anda tidak memiliki izin audit.view.' ?>
                                        </p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($activities as $activity): ?>
                            <tr>
                                <td class="text-nowrap"><?= e(tanggal_id((string) $activity['created_at'], true)) ?></td>
                                <td><?= e($activity['user_name'] !== null && $activity['user_name'] !== '' ? (string) $activity['user_name'] : 'Sistem') ?></td>
                                <td><span class="pill pill-muted"><?= e(module_label((string) $activity['module'])) ?></span></td>
                                <td><?= e($activity['description']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <div class="col-12 col-xl-4">
        <section class="app-card mb-3">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Kesiapan modul</h2>
                    <p class="card-subtitle">Kondisi modul pada tahap pembangunan ini.</p>
                </div>
            </header>
            <ul class="module-list">
                <?php foreach ($modules as $module): ?>
                    <li class="module-item">
                        <div>
                            <span class="module-name"><?= e($module['name']) ?></span>
                            <span class="module-desc"><?= e($module['description']) ?></span>
                        </div>
                        <span class="module-state <?= $module['state'] === 'Aktif' ? 'is-ready' : '' ?>"><?= e($module['state']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>

        <section class="app-card">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Ringkasan operasional MBG</h2>
                    <p class="card-subtitle">Menunggu modul operasional pada tahap berikutnya.</p>
                </div>
            </header>
            <dl class="info-list">
                <?php foreach ($operasional as $metric): ?>
                    <div>
                        <dt><?= e($metric['label']) ?></dt>
                        <dd class="text-muted"><?= e($metric['value']) ?></dd>
                    </div>
                <?php endforeach; ?>
            </dl>
            <p class="muted-note">
                Metrik ini sengaja tidak diisi angka perkiraan. Sumber datanya dibuat pada tahap
                operasional sehingga nilai yang tampil selalu benar.
            </p>
        </section>
    </div>
</div>
