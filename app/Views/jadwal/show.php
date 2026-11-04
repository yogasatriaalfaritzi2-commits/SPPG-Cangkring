<?php
/**
 * Rincian satu kegiatan jadwal beserta ketergantungan dan riwayat perubahannya.
 * Durasi, status terlambat, dan jumlah ketergantungan datang dari query model.
 */
$taskId = (int) $task['id'];
$projectId = (int) $task['project_id'];
$status = (string) $task['status'];
$isLate = (int) $task['is_late'] === 1;
$isOpen = in_array($status, [
    \App\Models\ProjectSchedule::STATUS_NOT_STARTED,
    \App\Models\ProjectSchedule::STATUS_IN_PROGRESS,
    \App\Models\ProjectSchedule::STATUS_DELAYED,
], true);
$dueSoon = $isOpen && !$isLate
    && (string) $task['end_date'] <= date('Y-m-d', strtotime('+' . $dueDays . ' day'));
$remainDays = (int) ((strtotime((string) $task['end_date'] . ' 00:00:00')
    - strtotime(date('Y-m-d') . ' 00:00:00')) / 86400);
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Jadwal &middot; <?= e((string) $task['activity_code']) ?></p>
        <h1 class="page-title"><?= e((string) $task['name']) ?></h1>
        <p class="page-subtitle">
            <?= e((string) $task['project_name']) ?>
            (<?= e((string) $task['project_code']) ?>) &middot;
            <?= e(tanggal_id((string) $task['start_date'])) ?>
            &ndash; <?= e(tanggal_id((string) $task['end_date'])) ?>
            &middot; <?= e((string) $task['duration_days']) ?> hari
        </p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline-secondary"
           href="<?= query_url('/jadwal/kalender', ['proyek' => (string) $projectId]) ?>">Lihat di kalender</a>
        <a class="btn btn-outline-secondary"
           href="<?= query_url('/jadwal/gantt', ['proyek' => (string) $projectId]) ?>">Bagan Gantt</a>
        <?php if (can('schedules.update')): ?>
            <a class="btn btn-primary" href="<?= url('/jadwal/' . $taskId . '/ubah') ?>">Sunting kegiatan</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($isLate): ?>
    <div class="notice-box">
        <p class="mb-0">
            <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
            Kegiatan ini lewat tanggal selesai pada <?= e(tanggal_id((string) $task['end_date'])) ?>
            dan belum berstatus selesai. Perbaiki progres atau ajukan perubahan jadwal disertai alasan.
        </p>
    </div>
<?php elseif ($dueSoon): ?>
    <div class="notice-box">
        <p class="mb-0">
            <i class="bi bi-clock me-1" aria-hidden="true"></i>
            Tenggat tinggal <?= e((string) max(0, $remainDays)) ?> hari
            (<?= e(tanggal_id((string) $task['end_date'])) ?>), sesuai batas pengingat
            <?= e((string) $dueDays) ?> hari yang dapat diatur pada Pengaturan.
        </p>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-12 col-xl-8">
        <section class="app-card mb-3">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Data kegiatan</h2>
                </div>
                <span class="pill pill-<?= e(schedule_status_tone($status)) ?>">
                    <?= e(schedule_status_label($status)) ?>
                </span>
            </header>
            <dl class="detail-list">
                <div class="detail-row">
                    <dt>Proyek</dt>
                    <dd>
                        <a href="<?= url('/proyek/' . $projectId) ?>"><?= e((string) $task['project_name']) ?></a>
                        <span class="cell-sub">(<?= e((string) $task['project_code']) ?>)</span>
                    </dd>
                </div>
                <div class="detail-row">
                    <dt>Kode kegiatan</dt>
                    <dd><?= e((string) $task['activity_code']) ?></dd>
                </div>
                <div class="detail-row">
                    <dt>Kategori pekerjaan</dt>
                    <dd>
                        <?= $task['category_name'] !== null && $task['category_name'] !== ''
                            ? e((string) $task['category_code'] . ' — ' . (string) $task['category_name'])
                            : 'Tanpa kategori' ?>
                    </dd>
                </div>
                <div class="detail-row">
                    <dt>Rentang waktu</dt>
                    <dd>
                        <?= e(tanggal_id((string) $task['start_date'])) ?>
                        &ndash; <?= e(tanggal_id((string) $task['end_date'])) ?>
                        <span class="cell-sub">Durasi <?= e((string) $task['duration_days']) ?> hari, dihitung server</span>
                    </dd>
                </div>
                <div class="detail-row">
                    <dt>Penanggung jawab</dt>
                    <dd>
                        <?= e($task['owner_user_name'] !== null && $task['owner_user_name'] !== ''
                            ? (string) $task['owner_user_name'] : (string) $task['owner_name']) ?>
                        <?php if ($task['owner_user_name'] !== null && $task['owner_user_name'] !== ''
                            && (string) $task['owner_name'] !== (string) $task['owner_user_name']): ?>
                            <span class="cell-sub">Tercatat: <?= e((string) $task['owner_name']) ?></span>
                        <?php endif; ?>
                    </dd>
                </div>
                <div class="detail-row">
                    <dt>Prioritas</dt>
                    <dd><?= e(schedule_priority_label($task['priority'])) ?></dd>
                </div>
                <div class="detail-row">
                    <dt>Progres</dt>
                    <dd>
                        <div class="progress-cell">
                            <div class="progress app-progress" role="progressbar"
                                 aria-label="Progres kegiatan" aria-valuemin="0" aria-valuemax="100"
                                 aria-valuenow="<?= e((string) $task['progress']) ?>">
                                <div class="progress-bar" style="width: <?= e((string) $task['progress']) ?>%"></div>
                            </div>
                            <span class="progress-value"><?= e((string) $task['progress']) ?>%</span>
                        </div>
                    </dd>
                </div>
                <div class="detail-row">
                    <dt>Deskripsi</dt>
                    <dd class="text-start">
                        <?= $task['description'] !== null && $task['description'] !== ''
                            ? e((string) $task['description']) : '—' ?>
                    </dd>
                </div>
                <div class="detail-row">
                    <dt>Catatan</dt>
                    <dd class="text-start">
                        <?= $task['notes'] !== null && $task['notes'] !== ''
                            ? e((string) $task['notes']) : '—' ?>
                    </dd>
                </div>
                <div class="detail-row">
                    <dt>Dibuat / diubah</dt>
                    <dd class="text-start">
                        <?= e($task['creator_name'] ?? '—') ?>
                        &middot; <?= e(tanggal_id((string) $task['created_at'], true)) ?><br>
                        <?= e($task['updater_name'] ?? '—') ?>
                        &middot; <?= e(tanggal_id((string) $task['updated_at'], true)) ?>
                    </dd>
                </div>
            </dl>
        </section>

        <section class="app-card mb-3" id="ketergantungan">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Ketergantungan pekerjaan</h2>
                    <p class="card-subtitle">
                        Pendahulu harus selesai (FS), mulai (SS), atau selesai (FF) lebih dahulu sesuai jenis
                        hubungannya, ditambah jeda hari bila ada.
                    </p>
                </div>
                <span class="card-count"><?= count($predecessors) ?> pendahulu</span>
            </header>

            <?php if ($predecessors === []): ?>
                <p class="muted-note">
                    Kegiatan ini belum bergantung pada kegiatan lain, sehingga dapat dimulai kapan saja
                    dalam rentangnya.
                </p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table app-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">Kegiatan pendahulu</th>
                                <th scope="col">Jenis</th>
                                <th scope="col" class="text-end">Jeda</th>
                                <th scope="col">Rentang pendahulu</th>
                                <th scope="col">Status</th>
                                <th scope="col" class="text-end">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($predecessors as $edge): ?>
                                <tr>
                                    <td class="cell-strong">
                                        <a href="<?= url('/jadwal/' . $edge['id']) ?>">
                                            <?= e((string) $edge['activity_code']) ?>
                                        </a>
                                        <span class="cell-sub"><?= e((string) $edge['name']) ?></span>
                                    </td>
                                    <td><?= e(dependency_type_label($edge['dependency_type'])) ?></td>
                                    <td class="text-end">
                                        <?= e((string) $edge['lag_days']) ?> hari
                                    </td>
                                    <td class="text-nowrap">
                                        <?= e(tanggal_id((string) $edge['start_date'])) ?>
                                        &ndash; <?= e(tanggal_id((string) $edge['end_date'])) ?>
                                    </td>
                                    <td>
                                        <span class="pill pill-<?= e(schedule_status_tone($edge['status'])) ?>">
                                            <?= e(schedule_status_label((string) $edge['status'])) ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <?php if (can('schedules.update')): ?>
                                            <form method="post"
                                                  action="<?= url('/ketergantungan/' . (int) $edge['dependency_id'] . '/hapus') ?>"
                                                  data-confirm
                                                  data-confirm-title="Hapus ketergantungan"
                                                  data-confirm-text="<?= e('Hapus ketergantungan "'
                                                      . $task['activity_code'] . '" terhadap "'
                                                      . $edge['activity_code'] . '"? Penghapusan tercatat pada riwayat kegiatan.') ?>"
                                                  data-confirm-label="Ya, hapus" data-confirm-tone="danger">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="schedule_id" value="<?= e((string) $taskId) ?>">
                                                <button class="btn btn-sm btn-outline-danger" type="submit">Hapus</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if ($successors !== []): ?>
                <h3 class="card-title mt-3">Kegiatan yang bergantung pada kegiatan ini</h3>
                <div class="table-responsive">
                    <table class="table app-table align-middle mb-0">
                        <tbody>
                            <?php foreach ($successors as $edge): ?>
                                <tr>
                                    <td class="cell-strong">
                                        <a href="<?= url('/jadwal/' . $edge['id']) ?>">
                                            <?= e((string) $edge['activity_code']) ?>
                                        </a>
                                        <span class="cell-sub"><?= e((string) $edge['name']) ?></span>
                                    </td>
                                    <td><?= e(dependency_type_label($edge['dependency_type'])) ?></td>
                                    <td class="text-end"><?= e((string) $edge['lag_days']) ?> hari</td>
                                    <td class="text-nowrap">
                                        <?= e(tanggal_id((string) $edge['start_date'])) ?>
                                        &ndash; <?= e(tanggal_id((string) $edge['end_date'])) ?>
                                    </td>
                                    <td>
                                        <span class="pill pill-<?= e(schedule_status_tone($edge['status'])) ?>">
                                            <?= e(schedule_status_label((string) $edge['status'])) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if (can('schedules.create')): ?>
                <hr class="my-3">
                <form class="app-form" method="post" action="<?= url('/jadwal/' . $taskId . '/ketergantungan') ?>">
                    <?= csrf_field() ?>
                    <div class="row g-3">
                        <div class="col-12 col-md-5">
                            <div class="form-group">
                                <label class="form-label" for="predecessor_id">Kegiatan pendahulu</label>
                                <select class="form-select" id="predecessor_id" name="predecessor_id" required>
                                    <option value="">Pilih kegiatan pada proyek ini</option>
                                    <?php foreach ($candidates as $candidate): ?>
                                        <option value="<?= e((string) $candidate['id']) ?>">
                                            <?= e((string) $candidate['activity_code']) ?> &mdash;
                                            <?= e((string) $candidate['name']) ?>
                                            (<?= e(tanggal_id((string) $candidate['end_date'])) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-12 col-md-4">
                            <div class="form-group">
                                <label class="form-label" for="dependency_type">Jenis hubungan</label>
                                <select class="form-select" id="dependency_type" name="dependency_type" required>
                                    <?php foreach ($types as $type): ?>
                                        <option value="<?= e($type) ?>">
                                            <?= e($type) ?> &mdash; <?= e(dependency_type_label($type)) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-12 col-md-3">
                            <div class="form-group">
                                <label class="form-label" for="lag_days">Jeda (hari)</label>
                                <input class="form-control" type="number" id="lag_days" name="lag_days"
                                       min="0" max="365" step="1" value="0">
                            </div>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button class="btn btn-primary" type="submit"
                            <?= $candidates === [] ? 'disabled' : '' ?>>
                            Simpan ketergantungan
                        </button>
                        <?php if ($candidates === []): ?>
                            <span class="muted-note ms-2">
                                Belum ada kegiatan lain pada proyek ini yang dapat dijadikan pendahulu.
                            </span>
                        <?php endif; ?>
                    </div>
                </form>
            <?php else: ?>
                <p class="muted-note">
                    Hanya pengguna dengan hak <code>schedules.create</code> yang dapat menambah ketergantungan.
                </p>
            <?php endif; ?>
        </section>

        <section class="app-card">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Riwayat perubahan jadwal</h2>
                    <p class="card-subtitle">Nilai lama dibaca dari database sebelum perubahan, bukan dari browser.</p>
                </div>
                <span class="card-count"><?= count($history) ?> catatan</span>
            </header>
            <?php if ($history === []): ?>
                <div class="empty-state empty-state-block">
                    <i class="bi bi-clock-history" aria-hidden="true"></i>
                    <p class="empty-title">Belum ada perubahan tercatat</p>
                    <p class="empty-text">
                        Riwayat terbentuk setiap kali bidang kegiatan atau ketergantungannya diubah.
                    </p>
                </div>
            <?php else: ?>
                <div class="timeline-list">
                    <?php foreach ($history as $entry): ?>
                        <article class="timeline-item">
                            <div class="timeline-head">
                                <span class="timeline-date">
                                    <?= e(tanggal_id((string) $entry['changed_at'], true)) ?>
                                </span>
                                <span class="timeline-delta"><?= e(schedule_field_label($entry['field_name'])) ?></span>
                            </div>
                            <p class="timeline-text">
                                <?= e($entry['old_value'] !== null && $entry['old_value'] !== ''
                                    ? (string) $entry['old_value'] : '—') ?>
                                &rarr;
                                <?= e($entry['new_value'] !== null && $entry['new_value'] !== ''
                                    ? (string) $entry['new_value'] : '—') ?>
                            </p>
                            <p class="timeline-foot">
                                <?= e($entry['changed_by_name'] ?? 'Sistem') ?>
                                <?php if (($entry['reason'] ?? '') !== '' && $entry['reason'] !== null): ?>
                                    &middot; <?= e((string) $entry['reason']) ?>
                                <?php endif; ?>
                            </p>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <div class="col-12 col-xl-4">
        <section class="app-card mb-3">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Ringkasan jadwal proyek</h2>
                    <p class="card-subtitle">
                        Seluruh kegiatan pada proyek <?= e((string) $task['project_code'] . ' — ' . (string) $task['project_name']) ?>.
                    </p>
                </div>
            </header>
            <dl class="info-list">
                <div>
                    <dt>Total kegiatan</dt>
                    <dd><?= e((string) $summary['total']) ?></dd>
                </div>
                <div>
                    <dt>Sedang berjalan</dt>
                    <dd><?= e((string) $summary['running']) ?></dd>
                </div>
                <div>
                    <dt>Belum dimulai</dt>
                    <dd><?= e((string) $summary['not_started']) ?></dd>
                </div>
                <div>
                    <dt>Berstatus tertunda</dt>
                    <dd><?= e((string) $summary['delayed']) ?></dd>
                </div>
                <div>
                    <dt>Selesai</dt>
                    <dd><?= e((string) $summary['completed']) ?></dd>
                </div>
                <div>
                    <dt>Dibatalkan</dt>
                    <dd><?= e((string) $summary['cancelled']) ?></dd>
                </div>
                <div>
                    <dt>Lewat tenggat</dt>
                    <dd><?= e((string) $summary['late']) ?></dd>
                </div>
                <div>
                    <dt>Menunggu <?= e((string) $summary['due_days']) ?> hari</dt>
                    <dd><?= e((string) $summary['due_week']) ?></dd>
                </div>
                <div>
                    <dt>Rata-rata progres</dt>
                    <dd><?= e((string) $summary['avg_progress']) ?>%</dd>
                </div>
            </dl>
            <p class="muted-note">
                Rencana proyek berlangsung <?= e(tanggal_id((string) $task['project_start'])) ?>
                &ndash; <?= e(tanggal_id((string) $task['project_target'])) ?>.
                <?php if ((int) $task['is_archived'] === 1): ?>
                    Proyek ini sudah diarsipkan.
                <?php endif; ?>
            </p>
        </section>

        <?php if (can('schedules.delete')): ?>
            <section class="app-card">
                <header class="card-head">
                    <div>
                        <h2 class="card-title">Hapus kegiatan</h2>
                        <p class="card-subtitle">
                            Penghapusan bersifat lunak. Kegiatan yang sudah memiliki riwayat atau menjadi
                            pendahulu kegiatan lain tidak dapat dihapus.
                        </p>
                    </div>
                </header>
                <div class="app-form">
                    <form method="post" action="<?= url('/jadwal/' . $taskId . '/hapus') ?>"
                          class="d-flex flex-wrap gap-2 align-items-center"
                          data-confirm
                          data-confirm-title="Hapus kegiatan"
                          data-confirm-text="<?= e('Hapus kegiatan "' . $task['activity_code'] . '" — '
                              . $task['name'] . '" dari daftar jadwal? Riwayatnya tetap tersimpan.') ?>"
                          data-confirm-label="Ya, hapus" data-confirm-tone="danger">
                        <?= csrf_field() ?>
                        <button class="btn btn-outline-danger" type="submit"
                            <?= $history !== [] || $successors !== [] ? 'disabled' : '' ?>>
                            Hapus kegiatan
                        </button>
                    </form>
                    <?php if ($history !== [] || $successors !== []): ?>
                        <p class="mb-0 flex-grow-1" style="font-size:12.5px;color:var(--ink-500);">
                            Tombol nonaktif karena
                            <?= $history !== [] ? 'kegiatan ini sudah punya riwayat perubahan' : '' ?>
                            <?= $history !== [] && $successors !== [] ? ' dan ' : '' ?>
                            <?= $successors !== [] ? 'masih menjadi pendahulu kegiatan lain' : '' ?>.
                            Gunakan status Dibatalkan agar jejak pekerjaan tetap utuh.
                        </p>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>
    </div>
</div>
