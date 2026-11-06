<?php
/**
 * View Riwayat Perubahan Penerima Manfaat
 */
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="<?= url('/penerima-manfaat') ?>" class="text-decoration-none">← Kembali ke Rekap Penerima</a></p>
        <h1 class="page-title"><?= e($pageTitle) ?></h1>
        <p class="page-subtitle">Jejak audit dan rekaman mutasi jumlah penerima manfaat dari seluruh sekolah.</p>
    </div>
</div>

<section class="app-card mb-4">
    <div class="toolbar p-3 border-bottom">
        <form class="toolbar-form" method="get" action="<?= url('/penerima-manfaat/riwayat') ?>">
            <div class="toolbar-field">
                <label class="form-label" for="school">Sekolah</label>
                <select class="form-select form-select-sm" id="school" name="school">
                    <option value="">Semua Sekolah</option>
                    <?php foreach ($schools as $sch): ?>
                        <option value="<?= e($sch['id']) ?>" <?= (string)($filters['school'] ?? '') === (string)$sch['id'] ? 'selected' : '' ?>>
                            <?= e($sch['school_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="toolbar-actions">
                <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-funnel me-1"></i>Filter</button>
                <a class="btn btn-sm btn-link" href="<?= url('/penerima-manfaat/riwayat') ?>">Reset</a>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table app-table align-middle mb-0">
            <thead>
                <tr>
                    <th>Waktu Catat</th>
                    <th>Sekolah</th>
                    <th>Kelompok / Kategori</th>
                    <th>Perubahan Jumlah</th>
                    <th>Alasan</th>
                    <th>Petugas</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($histories)): ?>
                    <tr>
                        <td colspan="6" class="text-center py-4 text-muted">Belum ada riwayat perubahan yang tercatat.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($histories as $h): ?>
                        <tr>
                            <td><small class="text-muted"><?= e(tanggal_id((string)$h['created_at'], true)) ?></small></td>
                            <td>
                                <strong><?= e($h['school_name']) ?></strong>
                            </td>
                            <td>
                                <div><?= e($h['class_group']) ?></div>
                                <span class="badge bg-light text-dark border"><?= e($h['category_name']) ?></span>
                            </td>
                            <td>
                                <span class="text-muted"><?= (int)$h['previous_count'] ?></span>
                                <i class="bi bi-arrow-right mx-1 text-muted"></i>
                                <strong class="text-primary"><?= (int)$h['new_count'] ?></strong>
                                <span class="small text-muted ms-1">(<?= (int)$h['new_count'] >= (int)$h['previous_count'] ? '+' : '' ?><?= (int)$h['new_count'] - (int)$h['previous_count'] ?>)</span>
                            </td>
                            <td><?= e($h['reason']) ?></td>
                            <td><small><?= e($h['user_name'] ?? 'Sistem') ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
