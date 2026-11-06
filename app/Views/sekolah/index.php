<?php
/**
 * View Index Sekolah Penerima Manfaat
 */
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Operasional MBG</p>
        <h1 class="page-title"><?= e($pageTitle) ?></h1>
        <p class="page-subtitle">Daftar sekolah mitra penerima program Makan Bergizi Gratis SPPG Cangkring.</p>
    </div>
    <div class="page-actions d-flex gap-2">
        <?php if (can('schools.export')): ?>
            <a class="btn btn-outline-secondary" href="<?= url('/sekolah/ekspor') ?>">
                <i class="bi bi-download me-1"></i>Ekspor CSV
            </a>
        <?php endif; ?>
        <?php if (can('schools.import')): ?>
            <a class="btn btn-outline-secondary" href="<?= url('/sekolah/impor') ?>">
                <i class="bi bi-upload me-1"></i>Impor Data
            </a>
        <?php endif; ?>
        <?php if (can('schools.create')): ?>
            <a class="btn btn-primary" href="<?= url('/sekolah/tambah') ?>">
                <i class="bi bi-plus-lg me-1"></i>Tambah Sekolah
            </a>
        <?php endif; ?>
    </div>
</div>

<section class="app-card">
    <div class="toolbar p-3 border-bottom">
        <form class="toolbar-form" method="get" action="<?= url('/sekolah') ?>">
            <div class="toolbar-field">
                <label class="form-label" for="q">Cari</label>
                <input class="form-control form-control-sm" type="search" id="q" name="q" value="<?= e($filters['q'] ?? $filters['search'] ?? '') ?>" placeholder="Nama, kode, atau NPSN...">
            </div>
            <div class="toolbar-field">
                <label class="form-label" for="level">Jenjang</label>
                <select class="form-select form-select-sm" id="level" name="level">
                    <option value="">Semua Jenjang</option>
                    <?php foreach ($levels as $lvl): ?>
                        <option value="<?= e($lvl['id']) ?>" <?= (string)($filters['level'] ?? '') === (string)$lvl['id'] ? 'selected' : '' ?>>
                            <?= e($lvl['level_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="toolbar-field">
                <label class="form-label" for="status">Status Layanan</label>
                <select class="form-select form-select-sm" id="status" name="status">
                    <option value="">Semua Status</option>
                    <?php foreach ($statuses as $st): ?>
                        <option value="<?= e($st) ?>" <?= ($filters['status'] ?? '') === $st ? 'selected' : '' ?>><?= e(status_label($st)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="toolbar-actions">
                <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-funnel me-1"></i>Filter</button>
                <a class="btn btn-sm btn-link" href="<?= url('/sekolah') ?>">Reset</a>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table app-table align-middle mb-0">
            <thead>
                <tr>
                    <th>Kode</th>
                    <th>Nama Sekolah & NPSN</th>
                    <th>Jenjang</th>
                    <th>Wilayah</th>
                    <th>Status Layanan</th>
                    <th>Kerjasama</th>
                    <th class="text-end">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($schools)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">
                            <i class="bi bi-mortarboard fs-2 d-block mb-2"></i>
                            Belum ada data sekolah penerima manfaat.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($schools as $s): ?>
                        <tr>
                            <td><code><?= e($s['school_code']) ?></code></td>
                            <td>
                                <a href="<?= url('/sekolah/' . $s['id']) ?>" class="fw-bold text-decoration-none">
                                    <?= e($s['school_name']) ?>
                                </a>
                                <?php if (!empty($s['npsn'])): ?>
                                    <div class="small text-muted">NPSN: <?= e($s['npsn']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge bg-light text-dark border"><?= e($s['level_name'] ?? $s['level_code'] ?? '-') ?></span></td>
                            <td>
                                <div><?= e($s['district'] ?? '-') ?></div>
                                <div class="small text-muted"><?= e($s['regency'] ?? '-') ?></div>
                            </td>
                            <td>
                                <span class="pill <?= status_badge_class((string)$s['service_status']) ?>">
                                    <?= e(status_label((string)$s['service_status'])) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-secondary-subtle text-secondary">
                                    <?= e(ucfirst((string)($s['partnership_status'] ?? '-'))) ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-primary" href="<?= url('/sekolah/' . $s['id']) ?>">
                                    Detail
                                </a>
                                <?php if (can('schools.update')): ?>
                                    <a class="btn btn-sm btn-outline-secondary" href="<?= url('/sekolah/' . $s['id'] . '/ubah') ?>">
                                        Sunting
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php
    $basePath = $basePath ?? '/sekolah';
    $query = array_filter($filters, static fn ($v): bool => $v !== '');
    require BASE_PATH . '/app/Views/partials/pagination.php';
    ?>
</section>
