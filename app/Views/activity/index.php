<div class="page-head">
    <div>
        <p class="eyebrow">Audit</p>
        <h1 class="page-title">Log Aktivitas</h1>
        <p class="page-subtitle">
            Rekaman masuk, gagal masuk, keluar, serta perubahan pengguna, peran, dan pengaturan.
            Kata sandi dan token tidak pernah dicatat.
        </p>
    </div>
</div>

<section class="app-card">
    <div class="toolbar">
        <form class="toolbar-form" method="get" action="<?= url('/aktivitas') ?>">
            <div class="toolbar-field">
                <label class="form-label" for="q">Cari</label>
                <input class="form-control form-control-sm" type="search" id="q" name="q"
                       value="<?= e($filters['q']) ?>" maxlength="60" placeholder="Aktivitas, keterangan, atau pengguna">
            </div>
            <div class="toolbar-field">
                <label class="form-label" for="module">Modul</label>
                <select class="form-select form-select-sm" id="module" name="module">
                    <option value="">Semua modul</option>
                    <?php foreach ($modules as $module): ?>
                        <option value="<?= e($module) ?>" <?= $filters['module'] === $module ? 'selected' : '' ?>>
                            <?= e(module_label($module)) ?> (<?= e($module) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="toolbar-actions">
                <button class="btn btn-sm btn-primary" type="submit">
                    <i class="bi bi-funnel me-1" aria-hidden="true"></i>Tampilkan
                </button>
                <?php if ($filters['q'] !== '' || $filters['module'] !== ''): ?>
                    <a class="btn btn-sm btn-link" href="<?= url('/aktivitas') ?>">Bersihkan</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table app-table align-middle mb-0">
            <thead>
                <tr>
                    <th scope="col">Waktu</th>
                    <th scope="col">Pengguna</th>
                    <th scope="col">Modul</th>
                    <th scope="col">Aktivitas</th>
                    <th scope="col">Keterangan</th>
                    <th scope="col">IP</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($logs === []): ?>
                    <tr>
                        <td colspan="6" class="table-empty">
                            <div class="empty-state">
                                <i class="bi bi-journal-x" aria-hidden="true"></i>
                                <p class="empty-title">Belum ada aktivitas tercatat</p>
                                <p class="empty-text">Log terisi otomatis setelah pengguna berinteraksi dengan sistem.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td class="text-nowrap"><?= e(tanggal_id((string) $log['created_at'], true)) ?></td>
                        <td>
                            <?php if (!empty($log['user_name'])): ?>
                                <span class="cell-strong d-block"><?= e($log['user_name']) ?></span>
                                <span class="cell-sub"><?= e($log['username']) ?></span>
                            <?php else: ?>
                                <span class="cell-sub">Sistem / gagal masuk</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="pill pill-info"><?= e(module_label((string) $log['module'])) ?></span>
                        </td>
                        <td><code class="permission-slug"><?= e($log['activity']) ?></code></td>
                        <td class="log-description"><?= e($log['description']) ?></td>
                        <td class="text-nowrap cell-sub"><?= e($log['ip_address'] !== null && $log['ip_address'] !== '' ? (string) $log['ip_address'] : '—') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php
    $basePath = '/aktivitas';
    $query = array_filter([
        'q' => $filters['q'],
        'module' => $filters['module'],
    ], static fn (mixed $value): bool => $value !== '');
    require BASE_PATH . '/app/Views/partials/pagination.php';
    ?>
</section>
