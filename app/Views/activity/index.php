<div class="page-head">
    <div>
        <p class="eyebrow">Audit & Transparansi</p>
        <h1 class="page-title"><?= e($pageTitle ?? 'Log Aktivitas') ?></h1>
        <p class="page-subtitle">
            Rekaman jejak audit, autentikasi, serta riwayat perubahan data pada modul Operasional MBG, Keuangan, dan Proyek.
        </p>
    </div>
</div>

<!-- Ringkasan Statistik Kluster Aktivitas -->
<?php if (!empty($summary)): ?>
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <a href="<?= url('/aktivitas?cluster=mbg') ?>" class="text-decoration-none">
                <div class="app-card p-3 h-100 <?= ($filters['cluster'] ?? '') === 'mbg' ? 'border-primary shadow-sm bg-primary-subtle' : '' ?>">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="text-muted small fw-semibold text-uppercase">Modul MBG</span>
                        <span class="badge bg-success-subtle text-success"><i class="bi bi-egg-fried"></i></span>
                    </div>
                    <h2 class="h4 mb-0 fw-bold"><?= number_format((int)($summary['mbg'] ?? 0), 0, ',', '.') ?></h2>
                    <span class="small text-muted">Aktivitas MBG</span>
                </div>
            </a>
        </div>
        <div class="col-6 col-lg-3">
            <a href="<?= url('/aktivitas?cluster=keuangan') ?>" class="text-decoration-none">
                <div class="app-card p-3 h-100 <?= ($filters['cluster'] ?? '') === 'keuangan' ? 'border-primary shadow-sm bg-primary-subtle' : '' ?>">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="text-muted small fw-semibold text-uppercase">Keuangan</span>
                        <span class="badge bg-warning-subtle text-warning"><i class="bi bi-cash-coin"></i></span>
                    </div>
                    <h2 class="h4 mb-0 fw-bold"><?= number_format((int)($summary['keuangan'] ?? 0), 0, ',', '.') ?></h2>
                    <span class="small text-muted">Aktivitas Anggaran & RAB</span>
                </div>
            </a>
        </div>
        <div class="col-6 col-lg-3">
            <a href="<?= url('/aktivitas?cluster=proyek') ?>" class="text-decoration-none">
                <div class="app-card p-3 h-100 <?= ($filters['cluster'] ?? '') === 'proyek' ? 'border-primary shadow-sm bg-primary-subtle' : '' ?>">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="text-muted small fw-semibold text-uppercase">Proyek</span>
                        <span class="badge bg-info-subtle text-info"><i class="bi bi-hammer"></i></span>
                    </div>
                    <h2 class="h4 mb-0 fw-bold"><?= number_format((int)($summary['proyek'] ?? 0), 0, ',', '.') ?></h2>
                    <span class="small text-muted">Aktivitas Pembangunan</span>
                </div>
            </a>
        </div>
        <div class="col-6 col-lg-3">
            <a href="<?= url('/aktivitas') ?>" class="text-decoration-none">
                <div class="app-card p-3 h-100 <?= empty($filters['cluster']) && empty($filters['module']) ? 'border-primary shadow-sm' : '' ?>">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="text-muted small fw-semibold text-uppercase">Hari Ini</span>
                        <span class="badge bg-primary-subtle text-primary"><i class="bi bi-calendar-event"></i></span>
                    </div>
                    <h2 class="h4 mb-0 fw-bold"><?= number_format((int)($summary['today'] ?? 0), 0, ',', '.') ?></h2>
                    <span class="small text-muted">Dari total <?= number_format((int)($summary['total'] ?? 0), 0, ',', '.') ?> log</span>
                </div>
            </a>
        </div>
    </div>
<?php endif; ?>

<section class="app-card">
    <div class="toolbar p-3 border-bottom">
        <form class="toolbar-form row g-2 align-items-end" method="get" action="<?= url('/aktivitas') ?>">
            <div class="col-12 col-md-3">
                <label class="form-label" for="q">Pencarian</label>
                <input class="form-control form-control-sm" type="search" id="q" name="q"
                       value="<?= e($filters['q'] ?? '') ?>" maxlength="60" placeholder="Aktivitas, keterangan, atau pengguna...">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label" for="cluster">Kategori Modul</label>
                <select class="form-select form-select-sm" id="cluster" name="cluster">
                    <option value="">Semua Kategori</option>
                    <option value="mbg" <?= ($filters['cluster'] ?? '') === 'mbg' ? 'selected' : '' ?>>Operasional MBG</option>
                    <option value="keuangan" <?= ($filters['cluster'] ?? '') === 'keuangan' ? 'selected' : '' ?>>Keuangan & RAB</option>
                    <option value="proyek" <?= ($filters['cluster'] ?? '') === 'proyek' ? 'selected' : '' ?>>Proyek & Jadwal</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label" for="module">Spesifik Modul</label>
                <select class="form-select form-select-sm" id="module" name="module">
                    <option value="">Semua Modul</option>
                    <?php foreach ($modules as $module): ?>
                        <option value="<?= e($module) ?>" <?= ($filters['module'] ?? '') === $module ? 'selected' : '' ?>>
                            <?= e(module_label($module)) ?> (<?= e($module) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label" for="start_date">Dari Tanggal</label>
                <input class="form-control form-control-sm" type="date" id="start_date" name="start_date"
                       value="<?= e($filters['start_date'] ?? '') ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label" for="end_date">Sampai Tanggal</label>
                <input class="form-control form-control-sm" type="date" id="end_date" name="end_date"
                       value="<?= e($filters['end_date'] ?? '') ?>">
            </div>
            <div class="col-12 col-md-1 d-flex gap-1">
                <button class="btn btn-sm btn-primary w-100" type="submit" title="Terapkan Filter">
                    <i class="bi bi-funnel"></i>
                </button>
                <?php if (($filters['q'] ?? '') !== '' || ($filters['module'] ?? '') !== '' || ($filters['cluster'] ?? '') !== '' || ($filters['start_date'] ?? '') !== '' || ($filters['end_date'] ?? '') !== ''): ?>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= url('/aktivitas') ?>" title="Reset Filter">
                        <i class="bi bi-arrow-counterclockwise"></i>
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table app-table align-middle mb-0">
            <thead>
                <tr>
                    <th scope="col" style="min-width: 140px;">Waktu</th>
                    <th scope="col">Pengguna</th>
                    <th scope="col">Modul</th>
                    <th scope="col">Aktivitas</th>
                    <th scope="col" style="min-width: 250px;">Keterangan</th>
                    <th scope="col">IP Address</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($logs === []): ?>
                    <tr>
                        <td colspan="6" class="table-empty py-4 text-center">
                            <div class="empty-state">
                                <i class="bi bi-journal-x fs-1 text-muted" aria-hidden="true"></i>
                                <p class="empty-title fw-bold mt-2">Tidak ada aktivitas ditemukan</p>
                                <p class="empty-text text-muted small">Coba ubah kata kunci pencarian atau sesuaikan rentang tanggal filter.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td class="text-nowrap small"><?= e(tanggal_id((string) $log['created_at'], true)) ?></td>
                        <td>
                            <?php if (!empty($log['user_name'])): ?>
                                <span class="cell-strong d-block fw-semibold"><?= e($log['user_name']) ?></span>
                                <span class="cell-sub small text-muted">@<?= e($log['username']) ?></span>
                            <?php else: ?>
                                <span class="cell-sub text-muted small">Sistem / Pengunjung</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $m = (string) $log['module'];
                            $badgeClass = match ($m) {
                                'mbg', 'schools', 'beneficiaries', 'menus', 'nutrition' => 'bg-success-subtle text-success border border-success-subtle',
                                'budgets', 'transactions', 'revisions' => 'bg-warning-subtle text-dark border border-warning-subtle',
                                'projects', 'schedules', 'progress', 'documents', 'team' => 'bg-info-subtle text-info-emphasis border border-info-subtle',
                                default => 'bg-secondary-subtle text-secondary border border-secondary-subtle',
                            };
                            ?>
                            <span class="badge <?= $badgeClass ?>"><?= e(module_label($m)) ?></span>
                        </td>
                        <td><code class="permission-slug text-dark-emphasis"><?= e($log['activity']) ?></code></td>
                        <td class="log-description text-break"><?= e($log['description']) ?></td>
                        <td class="text-nowrap cell-sub small text-muted"><?= e($log['ip_address'] !== null && $log['ip_address'] !== '' ? (string) $log['ip_address'] : '—') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php
    $basePath = '/aktivitas';
    $query = array_filter([
        'q' => $filters['q'] ?? '',
        'module' => $filters['module'] ?? '',
        'cluster' => $filters['cluster'] ?? '',
        'start_date' => $filters['start_date'] ?? '',
        'end_date' => $filters['end_date'] ?? '',
    ], static fn (mixed $value): bool => $value !== '');
    require BASE_PATH . '/app/Views/partials/pagination.php';
    ?>
</section>
