<?php
/**
 * Bagan Gantt sederhana satu proyek.
 * Posisi dan lebar batang dihitung di sini dari rentang tampilan (ScheduleController::ganttSpan),
 * memakai durasi harian yang sudah dihitung server pada kolom duration_days.
 */
$rows = is_array($rows ?? []) ? $rows : [];
$edges = is_array($edges ?? []) ? $edges : [];
$projects = is_array($projects ?? []) ? $projects : [];
$summary = is_array($summary ?? []) ? $summary : [];
$projectId = (int) $project['id'];

$months = [
    1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
];

$statusColors = [
    'not_started' => '#5f6e80',
    'in_progress' => '#1b4472',
    'delayed' => '#c9a227',
    'completed' => '#177a55',
    'cancelled' => '#aeb8c4',
];

$spanStart = strtotime($span['from'] . ' 00:00:00');
$spanEnd = strtotime($span['to'] . ' 00:00:00');
$totalDays = max(1, (int) round(($spanEnd - $spanStart) / 86400) + 1);

$percent = static function (int $days) use ($totalDays): float {
    return round($days / $totalDays * 100, 3);
};

$offset = static function (string $date) use ($spanStart, $totalDays): float {
    return round(((strtotime($date . ' 00:00:00') - $spanStart) / 86400) / $totalDays * 100, 3);
};

// Segmen bulan untuk sumbu waktu, sekaligus garis pemisah awal bulan.
$segments = [];
$ticks = [];
$cursor = $spanStart;
$previous = '';

while ($cursor <= $spanEnd) {
    $key = date('Y-m', $cursor);

    if ($key !== $previous) {
        $previous = $key;
        $segments[] = [
            'label' => $months[(int) date('n', $cursor)] . ' ' . date('Y', $cursor),
            'left' => $offset(date('Y-m-d', $cursor)),
        ];
        $ticks[] = $segments[count($segments) - 1]['left'];
    }

    $cursor = strtotime('+1 day', $cursor);
}

$today = date('Y-m-d');
$todayLeft = ($today >= $span['from'] && $today <= $span['to']) ? $offset($today) : null;

$predecessorCount = [];
foreach ($edges as $edge) {
    $successorId = (int) $edge['schedule_id'];
    $predecessorCount[$successorId] = ($predecessorCount[$successorId] ?? 0) + 1;
}
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Jadwal</p>
        <h1 class="page-title">Bagan Gantt Pembangunan</h1>
        <p class="page-subtitle">
            Baris menunjukkan satu kegiatan, panjang batang menunjukkan rentang tanggalnya.
            Isian gelap di dalam batang menunjukkan progres yang tercatat.
        </p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline-secondary" href="<?= url('/jadwal') ?>">
            <i class="bi bi-list-ul me-1" aria-hidden="true"></i>Daftar kegiatan
        </a>
        <a class="btn btn-outline-secondary"
           href="<?= query_url('/jadwal/kalender', ['proyek' => (string) $projectId]) ?>">
            <i class="bi bi-calendar3 me-1" aria-hidden="true"></i>Kalender
        </a>
        <?php if (can('schedules.create')): ?>
            <a class="btn btn-primary" href="<?= query_url('/jadwal/tambah', ['proyek' => (string) $projectId]) ?>">
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Tambah kegiatan
            </a>
        <?php endif; ?>
    </div>
</div>

<div class="toolbar">
    <form class="toolbar-form" method="get" action="<?= url('/jadwal/gantt') ?>">
        <div class="toolbar-field toolbar-field-wide">
            <label class="form-label" for="proyek">Proyek</label>
            <select class="form-select form-select-sm" id="proyek" name="proyek">
                <?php foreach ($projects as $option): ?>
                    <?php $oid = (int) $option['id']; ?>
                    <option value="<?= e((string) $oid) ?>" <?= $oid === $projectId ? 'selected' : '' ?>>
                        <?= e((string) $option['project_code'] . ' — ' . (string) $option['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="toolbar-actions">
            <button class="btn btn-sm btn-primary" type="submit">Tampilkan</button>
        </div>
    </form>
    <p class="muted-note">
        Satu bagan menampilkan satu proyek agar skala waktunya jelas.
        <a href="<?= query_url('/jadwal', ['proyek' => (string) $projectId]) ?>">Lihat daftar kegiatan proyek ini</a>.
    </p>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <section class="stat-card">
            <p class="stat-label">Kegiatan proyek ini</p>
            <p class="stat-value"><?= e((string) count($rows)) ?></p>
            <p class="stat-meta">
                <?= e((string) $summary['completed']) ?> selesai,
                <?= e((string) $summary['cancelled']) ?> dibatalkan
            </p>
        </section>
    </div>
    <div class="col-6 col-lg-3">
        <section class="stat-card">
            <p class="stat-label">Sedang berjalan</p>
            <p class="stat-value"><?= e((string) $summary['running']) ?></p>
            <p class="stat-meta">
                <?= e((string) $summary['not_started']) ?> belum dimulai,
                <?= e((string) $summary['delayed']) ?> tertunda
            </p>
        </section>
    </div>
    <div class="col-6 col-lg-3">
        <section class="stat-card">
            <p class="stat-label">Lewat tanggal selesai</p>
            <p class="stat-value"><?= e((string) $summary['late']) ?></p>
            <p class="stat-meta">Batang bergaris merah</p>
        </section>
    </div>
    <div class="col-6 col-lg-3">
        <section class="stat-card">
            <p class="stat-label">Rata-rata progres</p>
            <p class="stat-value"><?= e((string) $summary['avg_progress']) ?>%</p>
            <p class="stat-meta">
                <?= e((string) count($edges)) ?> ketergantungan tercatat
            </p>
        </section>
    </div>
</div>

<section class="app-card">
    <header class="card-head">
        <div>
            <h2 class="card-title">
                <?= e((string) $project['project_code'] . ' — ' . (string) $project['name']) ?>
            </h2>
            <p class="card-subtitle">
                Skala waktu <?= e(tanggal_id($span['from'])) ?> &ndash; <?= e(tanggal_id($span['to'])) ?>
                (<?= e((string) $totalDays) ?> hari).
                <?php if ($todayLeft !== null): ?>
                    Garis vertikal putus-putus menandakan hari ini, <?= e(tanggal_id($today)) ?>.
                <?php endif; ?>
            </p>
        </div>
        <span class="card-count"><?= e((string) count($rows)) ?> kegiatan</span>
    </header>

    <?php if ($rows === []): ?>
        <p class="muted-note">
            Proyek ini belum memiliki kegiatan.
            <?php if (can('schedules.create')): ?>
                <a href="<?= query_url('/jadwal/tambah', ['proyek' => (string) $projectId]) ?>">Tambahkan kegiatan</a>
                agar bagan waktu dapat dibentuk.
            <?php else: ?>
                Minta penanggung jawab proyek mendaftarkan kegiatannya.
            <?php endif; ?>
        </p>
    <?php else: ?>
        <div class="gantt">
            <div class="gantt-head" aria-hidden="true">
                <div class="gantt-label">
                    <span class="gantt-axis-title">Kegiatan</span>
                </div>
                <div class="gantt-track gantt-axis">
                    <?php foreach ($segments as $segment): ?>
                        <span class="gantt-month" style="left: <?= e((string) $segment['left']) ?>%;">
                            <?= e($segment['label']) ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="gantt-body">
                <div class="gantt-grid" aria-hidden="true">
                    <div></div>
                    <div class="gantt-grid-track">
                        <?php foreach ($ticks as $tick): ?>
                            <span style="left: <?= e((string) $tick) ?>%;"></span>
                        <?php endforeach; ?>
                        <?php if ($todayLeft !== null): ?>
                            <span class="gantt-now" style="left: <?= e((string) $todayLeft) ?>%;"></span>
                        <?php endif; ?>
                    </div>
                </div>

                <?php foreach ($rows as $row): ?>
                    <?php
                    $status = (string) $row['status'];
                    $color = $statusColors[$status] ?? '#5f6e80';
                    $left = $offset((string) $row['start_date']);
                    $width = max(0.6, $percent((int) $row['duration_days']));
                    $progress = (int) $row['progress'];
                    $owner = (string) $row['owner_name'];
                    $dependencies = $predecessorCount[(int) $row['id']] ?? 0;
                    ?>
                    <div class="gantt-row">
                        <div class="gantt-label">
                            <p class="cell-strong text-truncate">
                                <a href="<?= url('/jadwal/' . (int) $row['id']) ?>">
                                    <?= e((string) $row['activity_code'] . ' — ' . (string) $row['name']) ?>
                                </a>
                            </p>
                            <p class="cell-sub text-truncate">
                                <?= e($owner === '' ? 'Tanpa penanggung jawab' : $owner) ?>
                                &middot; <?= e((string) $row['duration_days']) ?> hari
                                &middot; progres <?= e((string) $progress) ?>%
                                &middot; <?= e(schedule_status_label($status)) ?>
                                <?php if ($dependencies > 0): ?>
                                    &middot; <?= e((string) $dependencies) ?> pendahulu
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="gantt-track">
                            <div class="gantt-bar <?= (int) $row['is_late'] === 1 ? 'is-late' : '' ?>"
                                 style="left: <?= e((string) $left) ?>%; width: <?= e((string) $width) ?>%;
                                        background: <?= e($color) ?>;"
                                 title="<?= e((string) $row['activity_code'] . ' — ' . (string) $row['name'] . ': '
                                     . tanggal_id((string) $row['start_date']) . ' sampai '
                                     . tanggal_id((string) $row['end_date']) . ', progres ' . $progress . '%') ?>">
                                <span class="gantt-fill" style="width: <?= e((string) $progress) ?>%;"></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <ul class="legend-list">
            <?php foreach (['not_started', 'in_progress', 'delayed', 'completed', 'cancelled'] as $key): ?>
                <li>
                    <span class="legend-swatch" style="background: <?= e($statusColors[$key]) ?>;"></span>
                    <?= e(schedule_status_label($key)) ?>
                </li>
            <?php endforeach; ?>
            <li>
                <span class="legend-swatch legend-swatch-half"></span>
                Isian gelap di dalam batang = progres tercatat
            </li>
            <li>
                <span class="legend-swatch legend-outline"></span>
                Garis tepi merah = lewat tanggal selesai
            </li>
        </ul>
    <?php endif; ?>
</section>

<section class="app-card mt-3">
    <header class="card-head">
        <div>
            <h2 class="card-title">Ketergantungan antarkegiatan</h2>
            <p class="card-subtitle">
                Urutan kerja yang tercatat, beserta pemeriksaan apakah tanggal kegiatannya masih cocok
                dengan aturan yang sama dipakai saat menyimpan.
            </p>
        </div>
        <span class="card-count"><?= e((string) count($edges)) ?></span>
    </header>

    <?php if ($edges === []): ?>
        <p class="muted-note">
            Belum ada ketergantungan tercatat pada proyek ini. Daftar kegiatan dan pekerjaan yang
            harus selesai lebih dulu dapat dipasang dari rincian masing-masing kegiatan.
        </p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="app-table">
                <thead>
                    <tr>
                        <th scope="col">Pendahulu</th>
                        <th scope="col">Jenis</th>
                        <th scope="col" class="text-end">Jeda (hari)</th>
                        <th scope="col">Susulan</th>
                        <th scope="col">Kecocokan tanggal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($edges as $edge): ?>
                        <?php
                        $clash = \App\Models\ProjectSchedule::dependencyDateError(
                            (string) $edge['successor_start'],
                            (string) $edge['successor_end'],
                            (string) $edge['predecessor_start'],
                            (string) $edge['predecessor_end'],
                            (string) $edge['dependency_type'],
                            (int) $edge['lag_days']
                        );
                        ?>
                        <tr>
                            <td>
                                <span class="cell-strong">
                                    <?= e((string) $edge['predecessor_code']) ?>
                                </span>
                                <span class="cell-sub d-block">
                                    <?= e(tanggal_id((string) $edge['predecessor_end'])) ?>
                                    &middot; <?= e(schedule_status_label((string) $edge['predecessor_status'])) ?>
                                </span>
                            </td>
                            <td>
                                <span class="pill pill-info">
                                    <?= e(dependency_type_label((string) $edge['dependency_type'])) ?>
                                </span>
                            </td>
                            <td class="text-end"><?= e((string) (int) $edge['lag_days']) ?></td>
                            <td>
                                <span class="cell-strong">
                                    <?= e((string) $edge['successor_code']) ?>
                                </span>
                                <span class="cell-sub d-block">
                                    mulai <?= e(tanggal_id((string) $edge['successor_start'])) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($clash === null): ?>
                                    <span class="pill pill-success">Sesuai</span>
                                <?php else: ?>
                                    <span class="pill pill-warning">Perlu ditinjau</span>
                                    <span class="cell-sub d-block"><?= e($clash) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="app-card mt-3">
    <header class="card-head">
        <div>
            <h2 class="card-title">Cara membaca bagan</h2>
        </div>
    </header>
    <ul class="rule-list">
        <li>Panjang batang hanya mencerminkan tanggal mulai dan selesai; durasi dan keterlambatan dihitung server.</li>
        <li>Isian gelap di dalam batang adalah persentase progres terakhir yang disimpan, bukan perkiraan peramban.</li>
        <li>Kegiatan tanpa pendahulu dapat dimulai kapan pun; kegiatan dengan pendahulu harus mengikuti jenis ketergantungannya.</li>
        <li>Baris yang tanggalnya berada di luar skala waktu tidak digambar — persempit proyeknya atau perbarui tanggal kegiatan.</li>
        <li>Perubahan tanggal kegiatan dicatat beserta alasannya dan dapat dibaca pada rincian masing-masing kegiatan.</li>
    </ul>
</section>
