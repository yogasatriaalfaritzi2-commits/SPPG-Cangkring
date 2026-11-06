<?php
/**
 * View Rekap Penerima Manfaat
 */
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Operasional MBG</p>
        <h1 class="page-title"><?= e($pageTitle) ?></h1>
        <p class="page-subtitle">
            Total penerima terdata per hari ini: <strong><?= number_format((int)$today, 0, ',', '.') ?> jiwa</strong>.
            Data per tanggal acuan: <strong><?= e(tanggal_id($filters['asof'])) ?></strong>.
        </p>
    </div>
    <div class="page-actions d-flex gap-2">
        <?php if (can('beneficiaries.export')): ?>
            <a class="btn btn-outline-secondary" href="<?= query_url('/penerima-manfaat/ekspor', $filters) ?>">
                <i class="bi bi-download me-1"></i>Ekspor CSV
            </a>
        <?php endif; ?>
        <a class="btn btn-outline-secondary" href="<?= url('/penerima-manfaat/riwayat') ?>">
            <i class="bi bi-clock-history me-1"></i>Riwayat Perubahan
        </a>
        <?php if ($canIndividual): ?>
            <a class="btn btn-outline-secondary" href="<?= url('/penerima-manfaat/individu') ?>">
                <i class="bi bi-person-badge me-1"></i>Data Individu
            </a>
        <?php endif; ?>
        <?php if ($canManage): ?>
            <a class="btn btn-primary" href="<?= url('/penerima-manfaat/catat') ?>">
                <i class="bi bi-plus-lg me-1"></i>Catat Jumlah Penerima
            </a>
        <?php endif; ?>
    </div>
</div>

<section class="app-card mb-4">
    <div class="toolbar p-3 border-bottom">
        <form class="toolbar-form" method="get" action="<?= url('/penerima-manfaat') ?>">
            <div class="toolbar-field">
                <label class="form-label" for="asof">Per Tanggal</label>
                <input class="form-control form-control-sm" type="date" id="asof" name="asof" value="<?= e($filters['asof']) ?>">
            </div>
            <div class="toolbar-field">
                <label class="form-label" for="school">Sekolah</label>
                <select class="form-select form-select-sm" id="school" name="school">
                    <option value="">Semua Sekolah</option>
                    <?php foreach ($schools as $sch): ?>
                        <option value="<?= e($sch['id']) ?>" <?= (string)$filters['school'] === (string)$sch['id'] ? 'selected' : '' ?>>
                            <?= e($sch['school_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="toolbar-field">
                <label class="form-label" for="search">Cari</label>
                <input class="form-control form-control-sm" type="search" id="search" name="search" value="<?= e($filters['search']) ?>" placeholder="Nama sekolah...">
            </div>
            <div class="toolbar-actions">
                <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-funnel me-1"></i>Filter</button>
                <a class="btn btn-sm btn-link" href="<?= url('/penerima-manfaat') ?>">Hari Ini</a>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table app-table align-middle mb-0">
            <thead>
                <tr>
                    <th>Sekolah</th>
                    <th>Jenjang</th>
                    <?php foreach ($categories as $cat): ?>
                        <th class="text-end"><?= e($cat['category_name']) ?></th>
                    <?php endforeach; ?>
                    <th class="text-end fw-bold">Total Siswa</th>
                    <th class="text-end">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="<?= count($categories) + 4 ?>" class="text-center py-5 text-muted">
                            <i class="bi bi-people fs-2 d-block mb-2"></i>
                            Belum ada rekap penerima manfaat yang tercatat.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $item): ?>
                        <tr>
                            <td>
                                <a href="<?= url('/sekolah/' . $item['school_id']) ?>" class="fw-bold text-decoration-none">
                                    <?= e($item['school_name']) ?>
                                </a>
                                <div class="small text-muted"><?= e($item['school_code']) ?></div>
                            </td>
                            <td><span class="badge bg-light text-dark border"><?= e($item['level_name'] ?? '-') ?></span></td>
                            <?php foreach ($categories as $cat): ?>
                                <td class="text-end">
                                    <?= number_format((int)($item['by_category'][$cat['id']] ?? 0), 0, ',', '.') ?>
                                </td>
                            <?php endforeach; ?>
                            <td class="text-end fw-bold text-primary">
                                <?= number_format((int)$item['total_beneficiaries'], 0, ',', '.') ?>
                            </td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-primary" href="<?= url('/sekolah/' . $item['school_id']) ?>">
                                    Detail
                                </a>
                                <?php if ($canManage): ?>
                                    <a class="btn btn-sm btn-outline-secondary" href="<?= url('/penerima-manfaat/catat?sekolah=' . $item['school_id']) ?>">
                                        Perbarui
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
