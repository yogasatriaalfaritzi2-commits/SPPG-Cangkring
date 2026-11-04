<?php
/**
 * Satu usulan perubahan anggaran beserta keputusannya.
 * Nilai sebelum dan sesudah dibaca dari tabel budget_revisions, bukan dihitung ulang,
 * supaya dokumen usulan tetap menjadi bukti seperti saat diajukan.
 */
$revisionId = (int) $revision['id'];
$projectId = (int) $revision['project_id'];
$status = (string) $revision['status'];
$actor = auth_user();
$isMine = $actor !== null && (int) $revision['requested_by'] === (int) $actor['id'];
$delta = (float) $revision['proposed_amount'] - (float) $revision['previous_amount'];
$percent = (float) $revision['previous_amount'] > 0.0
    ? round($delta / (float) $revision['previous_amount'] * 100, 1)
    : null;
$canDecide = can('budgets.approve') && $status === \App\Models\BudgetRevision::STATUS_PENDING;
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Anggaran</p>
        <h1 class="page-title">Perubahan anggaran <?= e((string) $revision['revision_code']) ?></h1>
        <p class="page-subtitle">
            Anggaran awal proyek hanya berubah bila usulan ini disetujui. Nilai lama, pemohon,
            pemeriksa, waktu, dan alasannya tersimpan sebagai riwayat.
        </p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline-secondary" href="<?= url('/anggaran/' . $projectId) ?>">Rekap anggaran proyek</a>
        <a class="btn btn-outline-secondary" href="<?= url('/proyek/' . $projectId) ?>">Detail proyek</a>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-lg-7">
        <section class="app-card mb-3">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Rincian usulan</h2>
                </div>
                <span class="pill pill-<?= e(budget_status_tone($status)) ?>">
                    <?= e(budget_status_label($status)) ?>
                </span>
            </header>
            <dl class="detail-list">
                <div class="detail-row">
                    <dt>Proyek</dt>
                    <dd>
                        <a href="<?= url('/proyek/' . $projectId) ?>">
                            <?= e((string) $revision['project_name']) ?>
                        </a>
                        <span class="cell-sub">(<?= e((string) $revision['project_code']) ?>)</span>
                    </dd>
                </div>
                <div class="detail-row">
                    <dt>Anggaran sebelum</dt>
                    <dd><?= e(rupiah($revision['previous_amount'])) ?></dd>
                </div>
                <div class="detail-row">
                    <dt>Usulan baru</dt>
                    <dd><?= e(rupiah($revision['proposed_amount'])) ?></dd>
                </div>
                <div class="detail-row">
                    <dt>Selisih</dt>
                    <dd>
                        <?= $delta >= 0 ? '+' : '&minus;' ?> <?= e(rupiah(abs($delta))) ?>
                        <?php if ($percent !== null): ?>
                            <span class="cell-sub">(<?= e(percent_id($percent)) ?>) dari nilai lama</span>
                        <?php endif; ?>
                    </dd>
                </div>
                <div class="detail-row">
                    <dt>Nilai berlaku sekarang</dt>
                    <dd>
                        <?= e(rupiah($revision['current_amount'])) ?>
                        <?php if ($status === \App\Models\BudgetRevision::STATUS_APPROVED): ?>
                            <span class="cell-sub">Sudah disesuaikan dengan usulan ini</span>
                        <?php else: ?>
                            <span class="cell-sub">Belum berubah oleh usulan ini</span>
                        <?php endif; ?>
                    </dd>
                </div>
                <div class="detail-row">
                    <dt>Alasan perubahan</dt>
                    <dd class="text-start"><?= e((string) $revision['reason']) ?></dd>
                </div>
                <div class="detail-row">
                    <dt>Diajukan oleh</dt>
                    <dd>
                        <?= e($revision['requester_name'] ?? '—') ?>
                        <span class="cell-sub"><?= e(tanggal_id((string) $revision['requested_at'], true)) ?></span>
                    </dd>
                </div>
                <?php if ($revision['decided_at'] !== null): ?>
                    <div class="detail-row">
                        <dt>Diputus oleh</dt>
                        <dd>
                            <?= e($revision['decider_name'] ?? '—') ?>
                            <span class="cell-sub"><?= e(tanggal_id((string) $revision['decided_at'], true)) ?></span>
                        </dd>
                    </div>
                <?php endif; ?>
                <?php if (($revision['decision_note'] ?? '') !== '' && $revision['decision_note'] !== null): ?>
                    <div class="detail-row">
                        <dt>Catatan keputusan</dt>
                        <dd class="text-start"><?= e((string) $revision['decision_note']) ?></dd>
                    </div>
                <?php endif; ?>
            </dl>
        </section>

        <?php if ($status === \App\Models\BudgetRevision::STATUS_PENDING): ?>
            <section class="app-card">
                <header class="card-head">
                    <div>
                        <h2 class="card-title">Keputusan pemeriksa</h2>
                        <p class="card-subtitle">
                            Menyetujui mengubah anggaran proyek menjadi nilai usulan dan menolak membiarkan
                            nilai lama tetap berlaku. Satu usulan hanya dapat diputus satu kali.
                        </p>
                    </div>
                </header>
                <?php if (!$canDecide): ?>
                    <p class="muted-note">
                        Halaman ini hanya untuk membaca. Keputusan memerlukan hak <code>budgets.approve</code>.
                    </p>
                <?php else: ?>
                    <?php if ($isMine && $segregation): ?>
                        <div class="notice-box">
                            <p class="mb-0">
                                Kebijakan pemisahan tugas sedang berlaku, sehingga usulan yang Anda ajukan
                                sendiri tidak dapat Anda setujui. Pemeriksa lain harus memutusnya.
                            </p>
                        </div>
                    <?php endif; ?>
                    <form class="app-form" method="post"
                          action="<?= url('/anggaran/perubahan/' . $revisionId . '/keputusan') ?>">
                        <?= csrf_field() ?>
                        <div class="form-group">
                            <label class="form-label" for="note">Catatan keputusan</label>
                            <textarea class="form-control" id="note" name="note" rows="3" maxlength="500"
                                      placeholder="Wajib diisi bila usulan ditolak."></textarea>
                        </div>
                        <div class="form-actions d-flex gap-2">
                            <button class="btn btn-success" type="submit" name="action" value="approve"
                                <?= $isMine && $segregation ? 'disabled' : '' ?>
                                data-confirm
                                data-confirm-title="Setujui perubahan anggaran"
                                data-confirm-text="<?= e('Ubah anggaran "' . $revision['project_name'] . '" dari '
                                    . rupiah($revision['previous_amount']) . ' menjadi '
                                    . rupiah($revision['proposed_amount']) . '?') ?>"
                                data-confirm-label="Ya, setujui" data-confirm-tone="warning">
                                Setujui
                            </button>
                            <button class="btn btn-outline-danger" type="submit" name="action" value="reject"
                                    data-confirm
                                    data-confirm-title="Tolak perubahan anggaran"
                                    data-confirm-text="<?= e('Tolak usulan ' . $revision['revision_code']
                                        . '? Nilai anggaran tetap ' . rupiah($revision['previous_amount'])
                                        . '. Tulis alasan pada catatan sebelum mengirim.') ?>"
                                    data-confirm-label="Ya, tolak" data-confirm-tone="danger">
                                Tolak
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </section>
        <?php else: ?>
            <section class="app-card">
                <header class="card-head">
                    <div>
                        <h2 class="card-title">Keputusan sudah tercatat</h2>
                    </div>
                </header>
                <p class="muted-note">
                    Usulan ini berstatus <?= e(budget_status_label($status)) ?>.
                    <?php if ($status === \App\Models\BudgetRevision::STATUS_APPROVED): ?>
                        Anggaran proyek <?= e((string) $revision['project_name']) ?> sudah memakai nilai
                        <?= e(rupiah($revision['proposed_amount'])) ?>.
                    <?php else: ?>
                        Anggaran proyek masih <?= e(rupiah($revision['current_amount'])) ?>.
                        Perubahan baru dapat diajukan kembali dengan alasan yang berbeda.
                    <?php endif; ?>
                </p>
            </section>
        <?php endif; ?>
    </div>

    <div class="col-12 col-lg-5">
        <section class="app-card mb-3">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Posisi anggaran proyek</h2>
                    <p class="card-subtitle">Pusat biaya <?= e(cost_center_label($overview['cost_center'])) ?>.</p>
                </div>
            </header>
            <dl class="info-list">
                <div>
                    <dt>Anggaran awal</dt>
                    <dd class="text-start"><?= e(rupiah($overview['initial_budget'])) ?></dd>
                </div>
                <div>
                    <dt>RAB disetujui</dt>
                    <dd class="text-start"><?= e(rupiah($overview['approved_budget'])) ?></dd>
                </div>
                <div>
                    <dt>Acuan perhitungan</dt>
                    <dd class="text-start"><?= e(rupiah($overview['baseline'])) ?></dd>
                </div>
                <div>
                    <dt>Realisasi</dt>
                    <dd class="text-start"><?= e(rupiah($overview['realization'])) ?></dd>
                </div>
                <div>
                    <dt>Sisa</dt>
                    <dd class="text-start"><?= e(rupiah($overview['remaining'])) ?></dd>
                </div>
                <div>
                    <dt>Penyerapan</dt>
                    <dd>
                        <?= $overview['percent'] === null ? '—' : e(percent_id($overview['percent'])) ?>
                        <span class="pill pill-<?= e(budget_indicator_tone($overview['indicator'])) ?>">
                            <?= e(budget_indicator_label($overview['indicator'])) ?>
                        </span>
                    </dd>
                </div>
            </dl>
            <?php if ($status === \App\Models\BudgetRevision::STATUS_PENDING): ?>
                <p class="muted-note">
                    Bila disetujui, anggaran awal menjadi <?= e(rupiah($revision['proposed_amount'])) ?> dan acuan
                    penyerapan berubah pada halaman rekap.
                </p>
            <?php endif; ?>
        </section>

        <section class="app-card">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Aturan perubahan anggaran</h2>
                </div>
            </header>
            <ul class="rule-list">
                <li>Usulan memakai nilai anggaran yang sedang berlaku di database, bukan nilai yang diketik pada formulir.</li>
                <li>Alasan wajib diisi 10 sampai 500 karakter; tanpa alasan, perubahan tidak dapat diajukan.</li>
                <li>Satu proyek hanya boleh memiliki satu usulan berstatus menunggu pada satu waktu.</li>
                <li>
                    Pemisahan tugas <?= $segregation ? 'sedang berlaku' : 'sedang tidak berlaku' ?>:
                    <?= $segregation
                        ? 'pemohon tidak dapat menyetujui usulan miliknya sendiri.'
                        : 'pemohon masih dapat menyetujui usulannya sendiri, namun hal ini tercatat pada log aktivitas.' ?>
                </li>
                <li>Keputusan dan perubahan anggaran dijalankan dalam satu transaksi database agar riwayat tidak pernah terpisah.</li>
            </ul>
        </section>
    </div>
</div>
