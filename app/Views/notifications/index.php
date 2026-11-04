<?php
/** @var array<string, mixed> $result */
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Pemberitahuan</p>
        <h1 class="page-title">Notifikasi Internal</h1>
        <p class="page-subtitle">
            Pemberitahuan proyek, progres, dokumen, dan tenggat waktu hanya dikirim ke dalam aplikasi ini.
            Layanan surel atau pesan eksternal belum dikonfigurasi.
        </p>
    </div>
    <?php if ($unread > 0): ?>
        <div class="page-actions">
            <form method="post" action="<?= url('/notifikasi/baca-semua') ?>">
                <?= csrf_field() ?>
                <button class="btn btn-outline-secondary" type="submit">Tandai semua dibaca</button>
            </form>
        </div>
    <?php endif; ?>
</div>

<section class="app-card">
    <div class="toolbar">
        <form class="toolbar-form" method="get" action="<?= url('/notifikasi') ?>">
            <div class="toolbar-field">
                <label class="form-label" for="filter">Tampilkan</label>
                <select class="form-select form-select-sm" id="filter" name="filter">
                    <option value="">Semua notifikasi</option>
                    <option value="unread" <?= $filter === 'unread' ? 'selected' : '' ?>>Belum dibaca saja</option>
                </select>
            </div>
            <div class="toolbar-actions">
                <button class="btn btn-sm btn-primary" type="submit">
                    <i class="bi bi-funnel me-1" aria-hidden="true"></i>Terapkan
                </button>
                <?php if ($filter === 'unread'): ?>
                    <a class="btn btn-sm btn-link" href="<?= url('/notifikasi') ?>">Bersihkan</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table app-table align-middle mb-0">
            <thead>
                <tr>
                    <th scope="col">Notifikasi</th>
                    <th scope="col">Jenis</th>
                    <th scope="col">Waktu</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="text-end">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($notifications === []): ?>
                    <tr>
                        <td colspan="5" class="table-empty">
                            <div class="empty-state">
                                <i class="bi bi-bell-slash" aria-hidden="true"></i>
                                <p class="empty-title">
                                    <?= $filter === 'unread' ? 'Tidak ada notifikasi baru' : 'Kotak masuk masih kosong' ?>
                                </p>
                                <p class="empty-text">
                                    Notifikasi muncul ketika proyek ditambahkan, progres dilaporkan,
                                    dokumen diunggah, status berubah, atau target selesai semakin dekat.
                                </p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($notifications as $notification): ?>
                    <?php
                    $isRead = (int) $notification['is_read'] === 1;
                    $link = (string) ($notification['link'] ?? '');
                    ?>
                    <tr class="<?= $isRead ? '' : 'row-unread' ?>">
                        <td>
                            <span class="cell-strong d-block"><?= e($notification['title']) ?></span>
                            <span class="cell-sub"><?= e($notification['body'] !== null && $notification['body'] !== ''
                                ? (string) $notification['body']
                                : 'Tidak ada keterangan tambahan') ?></span>
                        </td>
                        <td>
                            <span class="pill pill-<?= $isRead ? 'muted' : 'info' ?>">
                                <?= e(notification_type_label($notification['type'])) ?>
                            </span>
                        </td>
                        <td class="text-nowrap">
                            <span class="cell-strong d-block"><?= e(tanggal_id((string) $notification['created_at'], true)) ?></span>
                            <span class="cell-sub"><?= $isRead
                                ? 'Dibaca ' . e(tanggal_id((string) ($notification['read_at'] ?? ''), true))
                                : 'Belum dibaca' ?></span>
                        </td>
                        <td>
                            <?php if ($isRead): ?>
                                <i class="bi bi-check2-all text-success" role="img" aria-label="Sudah dibaca"></i>
                            <?php else: ?>
                                <i class="bi bi-circle" role="img" aria-label="Belum dibaca"></i>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="cell-actions">
                                <?php if (!$isRead && $link !== ''): ?>
                                    <form method="post" action="<?= url('/notifikasi/baca/' . $notification['id']) ?>">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm btn-primary" type="submit">Buka</button>
                                    </form>
                                <?php elseif ($link !== ''): ?>
                                    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url($link)) ?>">Buka</a>
                                <?php elseif (!$isRead): ?>
                                    <form method="post" action="<?= url('/notifikasi/baca/' . $notification['id']) ?>">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm btn-outline-secondary" type="submit">Tandai dibaca</button>
                                    </form>
                                <?php else: ?>
                                    <span class="cell-sub">Tidak ada tautan</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php
    $basePath = '/notifikasi';
    $query = array_filter(['filter' => $filter], static fn (mixed $value): bool => $value !== '');
    require BASE_PATH . '/app/Views/partials/pagination.php';
    ?>
</section>
