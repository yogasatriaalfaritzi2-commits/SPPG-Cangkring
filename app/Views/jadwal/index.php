<?php
/**
 * Daftar kegiatan jadwal pembangunan.
 * Durasi dan status "terlambat" dihitung server (lihat ProjectSchedule), jadi kolom
 * ini hanya menampilkan, tidak menjumlah ulang di peramban.
 */
$sortLink = static function (string $key) use ($filters): string {
    $direction = $filters['sort'] === $key && $filters['dir'] === 'asc' ? 'desc' : 'asc';

    return query_url('/jadwal', [
        'q' => $filters['search'],
        'status' => $filters['status'],
        'prioritas' => $filters['priority'],
        'proyek' => $filters['project'],
        'kategori' => $filters['category'],
        'dari' => $filters['from'],
        'sampai' => $filters['to'],
        'terlambat' => $filters['late'],
        'sort' => $key,
        'dir' => $direction,
    ]);
};
$sortIcon = static function (string $key) use ($filters): string {
    if ($filters['sort'] !== $key) {
        return 'bi-arrow-down-up text-muted';
    }

    return $filters['dir'] === 'asc' ? 'bi-sort-up' : 'bi-sort-down';
};
$hasFilter = $filters['search'] !== '' || $filters['status'] !== '' || $filters['priority'] !== ''
    || $filters['project'] !== '' || $filters['category'] !== '' || $filters['from'] !== ''
    || $filters['to'] !== '' || $filters['late'] === '1';
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Jadwal</p>
        <h1 class="page-title">Kegiatan Pembangunan</h1>
        <p class="page-subtitle">
            Setiap kegiatan memiliki kode, penanggung jawab, rentang tanggal, prioritas, dan progres.
            Durasi dihitung dari tanggal mulai dan selesai, bukan diketik pengguna.
        </p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline-secondary" href="<?= url('/jadwal/kalender') ?>">
            <i class="bi bi-calendar3 me-1" aria-hidden="true"></i>Kalender
        </a>
        <a class="btn btn-outline-secondary" href="<?= url('/jadwal/gantt') ?>">
            <i class="bi bi-bar-chart-steps me-1" aria-hidden="true"></i>Bagan Gantt
        </a>
        <?php if (can('schedules.export')): ?>
            <a class="btn btn-outline-secondary"
               href="<?= query_url('/jadwal/ekspor', [
                   'status' => $filters['status'],
                   'prioritas' => $filters['priority'],
                   'proyek' => $filters['project'],
                   'kategori' => $filters['category'],
                   'dari' => $filters['from'],
                   'sampai' => $filters['to'],
                   'terlambat' => $filters['late'],
               ]) ?>">
                <i class="bi bi-download me-1" aria-hidden="true"></i>Ekspor CSV
            </a>
        <?php endif; ?>
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
            <p class="stat-label">Kegiatan tercatat</p>
            <p class="stat-value"><?= e((string) $summary['total']) ?></p>
            <p class="stat-meta">
                <?= e((string) $result['total']) ?> cocok dengan penyaringan
            </p>
        </section>
    </div>
    <div class="col-6 col-lg-3">
        <section class="stat-card">
            <p class="stat-label">Sedang berjalan</p>
            <p class="stat-value"><?= e((string) $summary['running']) ?></p>
            <p class="stat-meta">
                <?= e((string) $summary['not_started']) ?> belum dimulai,
                <?= e((string) $summary['completed']) ?> selesai
            </p>
        </section>
    </div>
    <div class="col-6 col-lg-3">
        <section class="stat-card">
            <p class="stat-label">Terlambat</p>
            <p class="stat-value"><?= e((string) $summary['late']) ?></p>
            <p class="stat-meta">
                Tanggal selesai sudah lewat, pekerjaan belum selesai
            </p>
        </section>
    </div>
    <div class="col-6 col-lg-3">
        <section class="stat-card">
            <p class="stat-label">Mendekati tenggat</p>
            <p class="stat-value"><?= e((string) $summary['due_week']) ?></p>
            <p class="stat-meta">
                Selesai dalam <?= e((string) $summary['due_days']) ?> hari ke depan &middot;
                rata-rata progres <?= e((string) $summary['avg_progress']) ?>%
            </p>
        </section>
    </div>
</div>

<div class="toolbar">
    <form class="toolbar-form" method="get" action="<?= url('/jadwal') ?>">
        <div class="toolbar-field toolbar-field-wide">
            <label class="form-label" for="q">Cari</label>
            <input class="form-control form-control-sm" type="search" id="q" name="q"
                   value="<?= e($filters['search']) ?>" maxlength="60"
                   placeholder="Kode, nama kegiatan, penanggung jawab, atau proyek">
        </div>
        <div class="toolbar-field">
            <label class="form-label" for="proyek">Proyek</label>
            <select class="form-select form-select-sm" id="proyek" name="proyek">
                <option value="">Semua proyek</option>
                <?php foreach ($projects as $project): ?>
                    <option value="<?= e((string) $project['id']) ?>"
                        <?= $filters['project'] === (string) $project['id'] ? 'selected' : '' ?>>
                        <?= e((string) $project['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="toolbar-field">
            <label class="form-label" for="status">Status</label>
            <select class="form-select form-select-sm" id="status" name="status">
                <option value="">Semua status</option>
                <?php foreach ($statuses as $status): ?>
                    <option value="<?= e($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>>
                        <?= e(schedule_status_label($status)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="toolbar-field">
            <label class="form-label" for="prioritas">Prioritas</label>
            <select class="form-select form-select-sm" id="prioritas" name="prioritas">
                <option value="">Semua prioritas</option>
                <?php foreach ($priorities as $priority): ?>
                    <option value="<?= e($priority) ?>" <?= $filters['priority'] === $priority ? 'selected' : '' ?>>
                        <?= e(schedule_priority_label($priority)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="toolbar-field">
            <label class="form-label" for="kategori">Kategori</label>
            <select class="form-select form-select-sm" id="kategori" name="kategori">
                <option value="">Semua kategori</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= e((string) $category['id']) ?>"
                        <?= $filters['category'] === (string) $category['id'] ? 'selected' : '' ?>>
                        <?= e((string) $category['category_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="toolbar-field">
            <label class="form-label" for="dari">Mulai dari</label>
            <input class="form-control form-control-sm" type="date" id="dari" name="dari"
                   value="<?= e($filters['from']) ?>">
        </div>
        <div class="toolbar-field">
            <label class="form-label" for="sampai">Sampai</label>
            <input class="form-control form-control-sm" type="date" id="sampai" name="sampai"
                   value="<?= e($filters['to']) ?>">
        </div>
        <div class="toolbar-field">
            <label class="form-check-label" for="terlambat">Hanya terlambat</label>
            <input class="form-check-input" type="checkbox" id="terlambat" name="terlambat" value="1"
                <?= $filters['late'] === '1' ? 'checked' : '' ?>>
        </div>
        <div class="toolbar-actions">
            <button class="btn btn-sm btn-primary" type="submit">Terapkan</button>
            <?php if ($hasFilter): ?>
                <a class="btn btn-sm btn-link" href="<?= url('/jadwal') ?>">Bersihkan</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<section class="app-card">
    <header class="card-head">
        <div>
            <h2 class="card-title">Daftar kegiatan</h2>
            <p class="card-subtitle">
                Kegiatan yang tanggal selesainya sudah lewat dan belum selesai ditandai terlambat.
            </p>
        </div>
        <span class="card-count"><?= count($schedules) ?> dari <?= e((string) $result['total']) ?> baris</span>
    </header>

    <div class="table-responsive">
        <table class="table app-table align-middle mb-0">
            <thead>
                <tr>
                    <th scope="col">
                        <a class="sort-link" href="<?= e($sortLink('code')) ?>">
                            Kode <i class="bi <?= e($sortIcon('code')) ?>" aria-hidden="true"></i>
                        </a>
                    </th>
                    <th scope="col">
                        <a class="sort-link" href="<?= e($sortLink('name')) ?>">
                            Kegiatan <i class="bi <?= e($sortIcon('name')) ?>" aria-hidden="true"></i>
                        </a>
                    </th>
                    <th scope="col">Proyek</th>
                    <th scope="col">
                        <a class="sort-link" href="<?= e($sortLink('start')) ?>">
                            Rentang <i class="bi <?= e($sortIcon('start')) ?>" aria-hidden="true"></i>
                        </a>
                    </th>
                    <th scope="col">Penanggung jawab</th>
                    <th scope="col">
                        <a class="sort-link" href="<?= e($sortLink('priority')) ?>">
                            Prioritas <i class="bi <?= e($sortIcon('priority')) ?>" aria-hidden="true"></i>
                        </a>
                    </th>
                    <th scope="col">
                        <a class="sort-link" href="<?= e($sortLink('status')) ?>">
                            Status <i class="bi <?= e($sortIcon('status')) ?>" aria-hidden="true"></i>
                        </a>
                    </th>
                    <th scope="col">
                        <a class="sort-link" href="<?= e($sortLink('progress')) ?>">
                            Progres <i class="bi <?= e($sortIcon('progress')) ?>" aria-hidden="true"></i>
                        </a>
                    </th>
                    <th scope="col" class="text-end">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($schedules === []): ?>
                    <tr>
                        <td colspan="9" class="table-empty">
                            <div class="empty-state">
                                <i class="bi bi-calendar-x" aria-hidden="true"></i>
                                <p class="empty-title"><?= $hasFilter ? 'Tidak ada kegiatan yang cocok' : 'Belum ada jadwal' ?></p>
                                <p class="empty-text">
                                    <?= can('schedules.create')
                                        ? 'Tambahkan kegiatan pertama agar kalender dan bagan Gantt memiliki isi.'
                                        : 'Belum ada kegiatan pada penyaringan ini.' ?>
                                </p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($schedules as $task): ?>
                    <?php $isLate = (int) $task['is_late'] === 1; ?>
                    <tr>
                        <td class="cell-strong">
                            <a href="<?= url('/jadwal/' . $task['id']) ?>">
                                <?= e((string) $task['activity_code']) ?>
                            </a>
                            <?php if ((int) $task['dependency_count'] > 0): ?>
                                <span class="cell-sub">
                                    <?= e((string) $task['dependency_count']) ?> ketergantungan
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="cell-strong"><?= e((string) $task['name']) ?></span>
                            <span class="cell-sub">
                                <?= e($task['category_name'] !== null && $task['category_name'] !== ''
                                    ? (string) $task['category_name'] : 'Tanpa kategori') ?>
                            </span>
                        </td>
                        <td>
                            <span class="cell-strong">
                                <a href="<?= url('/proyek/' . $task['project_id']) ?>">
                                    <?= e((string) $task['project_name']) ?>
                                </a>
                            </span>
                            <span class="cell-sub"><?= e((string) $task['project_code']) ?></span>
                        </td>
                        <td class="text-nowrap">
                            <?= e(tanggal_id((string) $task['start_date'])) ?>
                            <span class="cell-sub d-block">
                                s/d <?= e(tanggal_id((string) $task['end_date'])) ?>
                                &middot; <?= e((string) $task['duration_days']) ?> hari
                            </span>
                        </td>
                        <td>
                            <?= e($task['owner_user_name'] !== null && $task['owner_user_name'] !== ''
                                ? (string) $task['owner_user_name'] : (string) $task['owner_name']) ?>
                        </td>
                        <td>
                            <?= e(schedule_priority_label($task['priority'])) ?>
                        </td>
                        <td>
                            <span class="pill pill-<?= e(schedule_status_tone($task['status'])) ?>">
                                <?= e(schedule_status_label((string) $task['status'])) ?>
                            </span>
                            <?php if ($isLate): ?>
                                <span class="cell-sub d-block text-danger">Lewat tanggal selesai</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="progress-cell">
                                <div class="progress app-progress" role="progressbar"
                                     aria-label="Progres kegiatan" aria-valuemin="0" aria-valuemax="100"
                                     aria-valuenow="<?= e((string) $task['progress']) ?>">
                                    <div class="progress-bar" style="width: <?= e((string) $task['progress']) ?>%"></div>
                                </div>
                                <span class="progress-value"><?= e((string) $task['progress']) ?>%</span>
                            </div>
                        </td>
                        <td class="text-end cell-actions">
                            <a class="btn btn-sm btn-outline-secondary" href="<?= url('/jadwal/' . $task['id']) ?>">
                                Rincian
                            </a>
                            <?php if (can('schedules.update')): ?>
                                <a class="btn btn-sm btn-outline-secondary"
                                   href="<?= url('/jadwal/' . $task['id'] . '/ubah') ?>">Sunting</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php
    $basePath = '/jadwal';
    $query = array_filter([
        'q' => $filters['search'],
        'status' => $filters['status'],
        'prioritas' => $filters['priority'],
        'proyek' => $filters['project'],
        'kategori' => $filters['category'],
        'dari' => $filters['from'],
        'sampai' => $filters['to'],
        'terlambat' => $filters['late'],
        'sort' => $filters['sort'],
        'dir' => $filters['dir'],
    ], static fn (string $value): bool => $value !== '');
    require BASE_PATH . '/app/Views/partials/pagination.php';
    ?>
</section>
