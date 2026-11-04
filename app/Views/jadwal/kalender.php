<?php
/**
 * Kalender kegiatan pembangunan.
 * Data kegiatan dikirim dari server sebagai atribut data; schedules.js hanya
 * memetakan angka-angka itu ke FullCalendar dan menampilkan rincian.
 * Rentang tampilan dihitung server (lihat ScheduleController::calendar).
 */
$events = is_array($events ?? []) ? $events : [];
$projects = is_array($projects ?? []) ? $projects : [];
$summary = is_array($summary ?? []) ? $summary : [];
$projectId = (int) ($projectId ?? 0);

$statusColors = [
    'not_started' => '#5f6e80',
    'in_progress' => '#1b4472',
    'delayed' => '#c9a227',
    'completed' => '#177a55',
    'cancelled' => '#aeb8c4',
];

$calendarEvents = [];
foreach ($events as $event) {
    $status = (string) $event['status'];
    $end = date('Y-m-d', strtotime((string) $event['end_date'] . ' +1 day'));
    $owner = (string) ($event['owner_user_name'] ?: $event['owner_name']);

    $calendarEvents[] = [
        'title' => trim((string) $event['activity_code'] . ' - ' . (string) $event['name']),
        'start' => (string) $event['start_date'],
        'end' => $end,
        'backgroundColor' => $statusColors[$status] ?? '#5f6e80',
        'borderColor' => (int) $event['is_late'] === 1 ? '#b4232a' : ($statusColors[$status] ?? '#5f6e80'),
        'detailUrl' => url('/jadwal/' . (int) $event['id']),
        'kode' => (string) $event['activity_code'],
        'nama' => (string) $event['name'],
        'statusLabel' => schedule_status_label($status),
        'statusTone' => schedule_status_tone($status),
        'prioritas' => schedule_priority_label((string) $event['priority']),
        'progres' => (int) $event['progress'],
        'pj' => $owner === '' ? '—' : $owner,
        'kategori' => (string) ($event['category_name'] ?? ''),
        'uraian' => (string) ($event['description'] ?? ''),
        'mulai' => tanggal_id((string) $event['start_date']),
        'selesai' => tanggal_id((string) $event['end_date']),
        'durasi' => (int) $event['duration_days'],
        'terlambat' => (int) $event['is_late'] === 1,
        'proyek' => (string) $event['project_name'],
        'proyekKode' => (string) $event['project_code'],
        'proyekUrl' => url('/proyek/' . (int) $event['project_id']),
    ];
}

$hasRange = $from !== date('Y-m-01', strtotime('-6 months')) || $to !== date('Y-m-t', strtotime('+12 months'));
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Jadwal</p>
        <h1 class="page-title">Kalender Pembangunan</h1>
        <p class="page-subtitle">
            Kegiatan tampil pada rentang tanggalnya masing-masing. Warna batang mengikuti status kegiatan,
            dan garis tepi merah menandakan kegiatan yang melewati tanggal selesai.
        </p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline-secondary" href="<?= url('/jadwal') ?>">
            <i class="bi bi-list-ul me-1" aria-hidden="true"></i>Daftar kegiatan
        </a>
        <a class="btn btn-outline-secondary" href="<?= query_url('/jadwal/gantt', ['proyek' => (string) $projectId]) ?>">
            <i class="bi bi-bar-chart-steps me-1" aria-hidden="true"></i>Bagan Gantt
        </a>
        <?php if (can('schedules.create')): ?>
            <a class="btn btn-primary" href="<?= url('/jadwal/tambah') ?>">
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Tambah kegiatan
            </a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <section class="stat-card">
            <p class="stat-label">Kegiatan pada rentang ini</p>
            <p class="stat-value"><?= e((string) count($calendarEvents)) ?></p>
            <p class="stat-meta">
                <?= e((string) $summary['total']) ?> kegiatan tercatat keseluruhan
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
            <p class="stat-label">Terlambat</p>
            <p class="stat-value"><?= e((string) $summary['late']) ?></p>
            <p class="stat-meta">Tanggal selesai sudah lewat</p>
        </section>
    </div>
    <div class="col-6 col-lg-3">
        <section class="stat-card">
            <p class="stat-label">Mendekati tenggat</p>
            <p class="stat-value"><?= e((string) $summary['due_week']) ?></p>
            <p class="stat-meta">
                Selesai dalam <?= e((string) $summary['due_days']) ?> hari ke depan
            </p>
        </section>
    </div>
</div>

<div class="toolbar">
    <form class="toolbar-form" method="get" action="<?= url('/jadwal/kalender') ?>">
        <div class="toolbar-field toolbar-field-wide">
            <label class="form-label" for="proyek">Proyek</label>
            <select class="form-select form-select-sm" id="proyek" name="proyek">
                <option value="">Semua proyek</option>
                <?php foreach ($projects as $option): ?>
                    <?php $oid = (int) $option['id']; ?>
                    <option value="<?= e((string) $oid) ?>" <?= $oid === $projectId ? 'selected' : '' ?>>
                        <?= e((string) $option['project_code'] . ' — ' . (string) $option['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="toolbar-field">
            <label class="form-label" for="dari">Mulai tampilan</label>
            <input class="form-control form-control-sm" id="dari" name="dari" type="date" value="<?= e($from) ?>">
        </div>
        <div class="toolbar-field">
            <label class="form-label" for="sampai">Akhir tampilan</label>
            <input class="form-control form-control-sm" id="sampai" name="sampai" type="date" value="<?= e($to) ?>">
        </div>
        <div class="toolbar-actions">
            <button class="btn btn-sm btn-primary" type="submit">Terapkan</button>
            <?php if ($projectId > 0 || $hasRange): ?>
                <a class="btn btn-sm btn-outline-secondary" href="<?= url('/jadwal/kalender') ?>">Bersihkan</a>
            <?php endif; ?>
        </div>
    </form>
    <p class="muted-note">
        Rentang <?= e(tanggal_id($from)) ?> &ndash; <?= e(tanggal_id($to)) ?>.
        Kegiatan di luar rentang ini tidak dimuat; persempit tanggalnya bila perlu.
    </p>
</div>

<?php if ($calendarEvents === []): ?>
    <div class="notice-box">
        <p class="mb-0">
            Belum ada kegiatan pada rentang ini.
            <?php if (can('schedules.create')): ?>
                <a href="<?= url('/jadwal/tambah') ?>">Tambah kegiatan</a> atau
                ubah rentang tanggal pada penyaringan di atas.
            <?php else: ?>
                Ubah rentang tanggal pada penyaringan di atas, atau minta kegiatan didaftarkan.
            <?php endif; ?>
        </p>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-12 <?= $calendarEvents === [] ? 'col-xl-12' : 'col-xl-8' ?>">
        <section class="app-card">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Kalender kegiatan</h2>
                    <p class="card-subtitle">
                        Gunakan tombol Hari, Minggu, dan Bulan untuk mengganti tampilan.
                    </p>
                </div>
            </header>
            <div class="calendar-shell"
                 data-calendar
                 data-events="<?= json_attr($calendarEvents) ?>"
                 data-from="<?= e($from) ?>"
                 data-to="<?= e(date('Y-m-d', strtotime($to . ' +1 day'))) ?>">
                <div id="kalender" class="calendar-host"></div>
            </div>
        </section>
    </div>

    <?php if ($calendarEvents !== []): ?>
        <div class="col-12 col-xl-4">
            <section class="app-card">
                <header class="card-head">
                    <div>
                        <h2 class="card-title">Rincian kegiatan</h2>
                        <p class="card-subtitle">Klik salah satu kegiatan pada kalender.</p>
                    </div>
                </header>
                <div class="calendar-detail" id="rincian-kegiatan" data-detail>
                    <p class="muted-note">
                        Belum ada kegiatan terpilih.
                    </p>
                </div>
            </section>

            <section class="app-card mt-3">
                <header class="card-head">
                    <div>
                        <h2 class="card-title">Keterangan warna</h2>
                    </div>
                </header>
                <ul class="legend-list">
                    <?php foreach (['not_started', 'in_progress', 'delayed', 'completed', 'cancelled'] as $key): ?>
                        <li>
                            <span class="legend-swatch"
                                  style="background: <?= e($statusColors[$key]) ?>;"></span>
                            <?= e(schedule_status_label($key)) ?>
                        </li>
                    <?php endforeach; ?>
                    <li>
                        <span class="legend-swatch legend-outline"></span>
                        Garis tepi merah &mdash; lewat tanggal selesai
                    </li>
                </ul>
            </section>
        </div>
    <?php endif; ?>
</div>

<section class="app-card mt-3">
    <header class="card-head">
        <div>
            <h2 class="card-title">Cara memakai kalender</h2>
        </div>
    </header>
    <ul class="rule-list">
        <li>Kegiatan berseri tampil mulai tanggal mulai sampai tanggal selesai, termasuk akhir pekannya.</li>
        <li>Tampilan Bulan cocok untuk melihat beban kerja per minggu; tampilan Minggu dan Hari untuk urutan kegiatan yang berimpitan.</li>
        <li>Klik kegiatan untuk membuka rincian, lalu gunakan tautan Rincian kegiatan untuk melihat riwayat dan ketergantungannya.</li>
        <li>Penyaringan proyek membatasi kalender pada satu proyek; daftar kegiatan menyediakan penyaringan status, prioritas, dan kategori.</li>
        <li>Semua nilai pada halaman ini dibaca dari database saat halaman dimuat, bukan dihitung di peramban.</li>
    </ul>
</section>
