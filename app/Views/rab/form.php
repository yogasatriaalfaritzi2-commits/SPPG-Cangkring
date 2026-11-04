<?php
$isEdit = $mode === 'edit';
$planId = $isEdit ? (int) $plan['id'] : 0;
$action = $isEdit ? url('/rab/' . $planId . '/ubah') : url('/rab/tambah');
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Keuangan &middot; RAB</p>
        <h1 class="page-title"><?= $isEdit ? 'Sunting rencana anggaran biaya' : 'Tambah rencana anggaran biaya' ?></h1>
        <p class="page-subtitle">
            <?= $isEdit
                ? 'Nomor, nama, dan periode RAB diubah di sini. Nilai total tidak dapat diketik — server menghitungnya dari item.'
                : 'Isi identitas RAB. Nomor dibuat otomatis dan wajib unik; setelah tersimpan, rincian item ditambahkan pada halaman RAB.' ?>
        </p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline-secondary" href="<?= $isEdit ? url('/rab/' . $planId) : url('/rab') ?>">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Kembali
        </a>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-8">
        <form class="app-form" method="post" action="<?= e($action) ?>">
            <?= csrf_field() ?>

            <section class="app-card mb-3">
                <header class="card-head">
                    <div>
                        <h2 class="card-title">Identitas RAB</h2>
                        <p class="card-subtitle">Kolom bertanda bintang wajib diisi.</p>
                    </div>
                </header>

                <div class="row g-3">
                    <div class="col-12 col-md-4">
                        <div class="form-group mb-0">
                            <label class="form-label" for="rab_code">Nomor RAB *</label>
                            <input class="form-control" type="text" id="rab_code" name="rab_code"
                                   value="<?= e((string) $form['rab_code']) ?>" maxlength="30"
                                   pattern="RAB-[0-9]{4}-[0-9]{1,4}" required>
                            <div class="form-text">Motif RAB-TAHUN-NOMOR, contoh RAB-2026-001. Harus unik.</div>
                        </div>
                    </div>

                    <div class="col-12 col-md-8">
                        <div class="form-group mb-0">
                            <label class="form-label" for="name">Nama RAB *</label>
                            <input class="form-control" type="text" id="name" name="name"
                                   value="<?= e((string) $form['name']) ?>" maxlength="150" required
                                   placeholder="Contoh: RAB Pembangunan SPPG Desa Cangkring Tahap I">
                        </div>
                    </div>

                    <div class="col-12">
                        <div class="form-group mb-0">
                            <label class="form-label" for="project_id">Proyek terkait *</label>
                            <select class="form-select" id="project_id" name="project_id" required>
                                <option value="">Pilih proyek</option>
                                <?php foreach ($projects as $project): ?>
                                    <option value="<?= e((string) $project['id']) ?>"
                                        <?= (string) $form['project_id'] === (string) $project['id'] ? 'selected' : '' ?>>
                                        <?= e((string) $project['project_code']) ?> &mdash; <?= e((string) $project['name']) ?>
                                        <?= (int) $project['is_archived'] === 1 ? ' (arsip)' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">
                                <?= $isEdit
                                    ? 'Memindahkan RAB ke proyek lain mengubah dasar anggaran kedua proyek.'
                                    : 'Proyek yang sudah diarsipkan tidak dapat dibuatkan RAB baru.' ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-12">
                        <div class="form-group mb-0">
                            <label class="form-label" for="notes">Catatan</label>
                            <textarea class="form-control" id="notes" name="notes" rows="4" maxlength="1000"
                                      placeholder="Asumsi harga, sumber data harga, atau catatan penting lainnya."><?= e((string) $form['notes']) ?></textarea>
                        </div>
                    </div>
                </div>
            </section>

            <section class="app-card mb-3">
                <header class="card-head">
                    <div>
                        <h2 class="card-title">Periode anggaran</h2>
                        <p class="card-subtitle">Tanggal akhir tidak boleh lebih awal dari tanggal mulai.</p>
                    </div>
                </header>

                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <div class="form-group mb-0">
                            <label class="form-label" for="period_start">Tanggal mulai *</label>
                            <input class="form-control" type="date" id="period_start" name="period_start"
                                   value="<?= e((string) $form['period_start']) ?>" required>
                        </div>
                    </div>
                    <div class="col-12 col-md-6">
                        <div class="form-group mb-0">
                            <label class="form-label" for="period_end">Tanggal akhir *</label>
                            <input class="form-control" type="date" id="period_end" name="period_end"
                                   value="<?= e((string) $form['period_end']) ?>" required>
                        </div>
                    </div>
                </div>
            </section>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">
                    <?= $isEdit ? 'Simpan perubahan' : 'Simpan dan buka rincian item' ?>
                </button>
                <a class="btn btn-outline-secondary" href="<?= $isEdit ? url('/rab/' . $planId) : url('/rab') ?>">Batal</a>
            </div>
        </form>
    </div>

    <div class="col-12 col-xl-4">
        <section class="app-card">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Nilai yang dihitung server</h2>
                    <p class="card-subtitle">
                        Total RAB merupakan jumlah seluruh item. Kolom ini tidak dapat diketik dari formulir.
                    </p>
                </div>
            </header>
            <dl class="info-list">
                <div>
                    <dt>Total tersimpan</dt>
                    <dd><?= e(rupiah($form['total_amount'])) ?></dd>
                </div>
                <?php if ($isEdit): ?>
                    <div>
                        <dt>Status</dt>
                        <dd>
                            <span class="pill pill-<?= e(budget_status_tone($plan['status'])) ?>">
                                <?= e(budget_status_label((string) $plan['status'])) ?>
                            </span>
                        </dd>
                    </div>
                    <div>
                        <dt>Versi</dt>
                        <dd>v<?= e((string) $plan['version']) ?></dd>
                    </div>
                    <?php if (!empty($plan['revision_of_code'])): ?>
                        <div>
                            <dt>Revisi dari</dt>
                            <dd><a href="<?= url('/rab/' . $plan['revision_of']) ?>"><?= e((string) $plan['revision_of_code']) ?></a></dd>
                        </div>
                    <?php endif; ?>
                    <div>
                        <dt>Dibuat</dt>
                        <dd><?= e(tanggal_id((string) $plan['created_at'], true)) ?></dd>
                    </div>
                <?php else: ?>
                    <div>
                        <dt>Status awal</dt>
                        <dd><?= e(budget_status_label(\App\Models\BudgetPlan::STATUS_DRAFT)) ?></dd>
                    </div>
                <?php endif; ?>
            </dl>
        </section>

        <?php if ($isEdit): ?>
            <section class="app-card">
                <header class="card-head">
                    <div>
                        <h2 class="card-title">Rincian item</h2>
                        <p class="card-subtitle">Item ditambahkan pada halaman RAB, bukan di formulir ini.</p>
                    </div>
                </header>
                <p class="muted-note">
                    <a href="<?= url('/rab/' . $planId) ?>">Buka rincian item <?= e((string) $plan['rab_code']) ?></a>
                    untuk menambah, menyalin, mengimpor, atau menghapus pekerjaan.
                </p>
            </section>
        <?php endif; ?>

        <section class="app-card">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Aturan modul RAB</h2>
                    <p class="card-subtitle">Berlaku di sisi server, bukan hanya pada tampilan.</p>
                </div>
            </header>
            <ul class="rule-list">
                <li>Total item = volume &times; harga satuan; total RAB = jumlah seluruh item.</li>
                <li>Alur status: Draft &rarr; Menunggu Persetujuan &rarr; Disetujui / Ditolak &rarr; Direvisi.</li>
                <li>RAB yang sudah disetujui terkunci; perubahan memerlukan revisi dan persetujuan ulang.</li>
                <li>RAB tanpa item tidak dapat diajukan untuk persetujuan.</li>
                <li>Nomor RAB dibuat otomatis dan tidak boleh dipakai RAB lain.</li>
            </ul>
        </section>
    </div>
</div>
