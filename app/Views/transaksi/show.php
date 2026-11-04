<?php
$transactionId = (int) $transaction['id'];
$projectId = (int) $transaction['project_id'];
$status = (string) $transaction['status'];
$type = (string) $transaction['transaction_type'];
$amount = (float) $transaction['amount'];
$isArchived = (int) $transaction['is_project_archived'] === 1;
$editable = in_array($status, \App\Models\FinancialTransaction::EDITABLE_STATUSES, true);
$actor = auth_user();
$actorId = (int) ($actor['id'] ?? 0);
$isMine = $actorId > 0 && (int) ($transaction['submitted_by'] ?? 0) === $actorId;
$approvalAction = [
    'create' => 'Dicatat',
    'update' => 'Diperbarui',
    'submit' => 'Diajukan',
    'approve' => 'Disetujui',
    'reject' => 'Ditolak',
    'cancel' => 'Dibatalkan',
    'correct' => 'Dikoreksi',
];
$effect = match ($type) {
    'expense' => 'Menambah realisasi pengeluaran',
    'income' => 'Pemasukan, tidak dihitung sebagai realisasi',
    'refund' => 'Mengurangi realisasi pengeluaran',
    default => 'Koreksi, dapat menambah atau mengurangi realisasi',
};
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Keuangan &middot; Transaksi</p>
        <h1 class="page-title"><?= e((string) $transaction['transaction_code']) ?></h1>
        <p class="page-subtitle">
            <?= e((string) $transaction['description']) ?> &middot;
            <a href="<?= url('/proyek/' . $projectId) ?>"><?= e((string) $transaction['project_name']) ?></a>
            &middot; <?= e(tanggal_id((string) $transaction['transaction_date'])) ?>
        </p>
    </div>
    <div class="page-actions">
        <?php if (can('transactions.export')): ?>
            <a class="btn btn-outline-secondary" href="<?= url('/transaksi/' . $transactionId . '/cetak') ?>"
               target="_blank" rel="noopener">
                <i class="bi bi-printer me-1" aria-hidden="true"></i>Cetak bukti
            </a>
        <?php endif; ?>
        <?php if (can('transactions.update') && $editable && !$isArchived): ?>
            <a class="btn btn-primary" href="<?= url('/transaksi/' . $transactionId . '/ubah') ?>">
                <i class="bi bi-pencil me-1" aria-hidden="true"></i>Sunting
            </a>
        <?php endif; ?>
        <a class="btn btn-outline-secondary" href="<?= url('/transaksi') ?>">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Kembali
        </a>
    </div>
</div>

<?php if ($isArchived): ?>
    <div class="notice-box" role="note">
        <i class="bi bi-archive" aria-hidden="true"></i>
        <p>Proyek ini sudah diarsipkan. Transaksi tetap terbaca sebagai riwayat, tetapi tidak dapat diajukan atau diubah.</p>
    </div>
<?php endif; ?>

<?php if ($status === \App\Models\FinancialTransaction::STATUS_APPROVED): ?>
    <div class="notice-box" role="note">
        <i class="bi bi-shield-check" aria-hidden="true"></i>
        <p>
            Transaksi ini sudah disetujui sehingga tidak dapat diubah atau dihapus.
            Koreksi dilakukan lewat transaksi penyesuaian baru dan pembatalan selalu disertai alasan.
        </p>
    </div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <section class="stat-card">
            <p class="stat-label">Nominal</p>
            <p class="stat-value h5 mb-1"><?= e(rupiah($amount)) ?></p>
            <p class="stat-meta"><?= e($effect) ?></p>
        </section>
    </div>
    <div class="col-6 col-lg-3">
        <section class="stat-card">
            <p class="stat-label">Status</p>
            <p class="stat-value h5 mb-1">
                <span class="pill pill-<?= e(transaction_status_tone($status)) ?>">
                    <?= e(transaction_status_label($status)) ?>
                </span>
            </p>
            <p class="stat-meta"><?= e(transaction_type_label($type)) ?> &middot; <?= e(cost_center_label((string) $transaction['cost_center'])) ?></p>
        </section>
    </div>
    <div class="col-6 col-lg-3">
        <section class="stat-card">
            <p class="stat-label">Realisasi proyek</p>
            <p class="stat-value h5 mb-1"><?= e(rupiah($summary['realization'])) ?></p>
            <p class="stat-meta"><?= e(cost_center_label((string) $transaction['cost_center'])) ?> hanya transaksi disetujui</p>
        </section>
    </div>
    <div class="col-6 col-lg-3">
        <section class="stat-card">
            <p class="stat-label">Sisa anggaran</p>
            <p class="stat-value h5 mb-1"><?= e(rupiah($summary['remaining'])) ?></p>
            <p class="stat-meta">
                <span class="pill pill-<?= e(budget_indicator_tone($summary['indicator'])) ?>">
                    <?= e(budget_indicator_label($summary['indicator'])) ?>
                </span>
            </p>
        </section>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-8">
        <section class="app-card">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Rincian pencatatan</h2>
                    <p class="card-subtitle">Seluruh nilai dibaca dari tabel financial_transactions.</p>
                </div>
            </header>
            <dl class="detail-list">
                <div class="detail-row">
                    <dt>Nomor dan tanggal</dt>
                    <dd>
                        <?= e((string) $transaction['transaction_code']) ?>
                        &middot; <?= e(tanggal_id((string) $transaction['transaction_date'])) ?>
                        <?php if (($transaction['reference_number'] ?? '') !== '' && $transaction['reference_number'] !== null): ?>
                            <br><span class="cell-sub">Referensi <?= e((string) $transaction['reference_number']) ?></span>
                        <?php endif; ?>
                    </dd>
                </div>
                <div class="detail-row">
                    <dt>Jenis dan pusat biaya</dt>
                    <dd>
                        <span class="pill pill-<?= e(transaction_type_tone($type)) ?>">
                            <?= e(transaction_type_label($type)) ?>
                        </span>
                        <span class="pill pill-<?= (string) $transaction['cost_center'] === \App\Models\FinancialTransaction::CENTER_OPERATIONS ? 'info' : 'muted' ?>">
                            <?= e(cost_center_label((string) $transaction['cost_center'])) ?>
                        </span>
                    </dd>
                </div>
                <div class="detail-row">
                    <dt>Uraian</dt>
                    <dd><?= e((string) $transaction['description']) ?></dd>
                </div>
                <div class="detail-row">
                    <dt>Metode dan penerima</dt>
                    <dd>
                        <?= e(payment_method_label((string) $transaction['payment_method'])) ?>
                        &middot; <?= e((string) $transaction['recipient']) ?>
                    </dd>
                </div>
                <div class="detail-row">
                    <dt>Kategori pekerjaan</dt>
                    <dd>
                        <?php if (($transaction['category_id'] ?? null) !== null): ?>
                            <a href="<?= query_url('/kategori', ['q' => (string) $transaction['category_code']]) ?>">
                                <?= e((string) $transaction['category_code']) ?> &mdash; <?= e((string) $transaction['category_name']) ?>
                            </a>
                        <?php else: ?>
                            Tanpa kategori
                        <?php endif; ?>
                    </dd>
                </div>
                <div class="detail-row">
                    <dt>RAB acuan</dt>
                    <dd>
                        <?php if (($transaction['budget_plan_id'] ?? null) !== null): ?>
                            <a href="<?= url('/rab/' . $transaction['budget_plan_id']) ?>">
                                <?= e((string) $transaction['rab_code']) ?>
                            </a>
                            <span class="cell-sub"><?= e((string) $transaction['plan_name']) ?></span>
                        <?php else: ?>
                            Tidak mengacu pada RAB
                        <?php endif; ?>
                    </dd>
                </div>
                <?php if (!empty($transaction['original_code'])): ?>
                    <div class="detail-row">
                        <dt>Dikoreksi dari</dt>
                        <dd>
                            <a href="<?= url('/transaksi/' . $transaction['reversal_of']) ?>">
                                <?= e((string) $transaction['original_code']) ?>
                            </a>
                            <span class="cell-sub">
                                <?= e(transaction_type_label((string) $transaction['original_type'])) ?>
                                &middot; <?= e(rupiah($transaction['original_amount'])) ?>
                            </span>
                        </dd>
                    </div>
                <?php endif; ?>
                <div class="detail-row">
                    <dt>Dicatat oleh</dt>
                    <dd>
                        <?= e($transaction['recorder_name'] !== null && $transaction['recorder_name'] !== ''
                            ? (string) $transaction['recorder_name'] : 'Pengguna telah dihapus') ?>
                        <br><span class="cell-sub"><?= e(tanggal_id((string) $transaction['created_at'], true)) ?></span>
                    </dd>
                </div>
                <div class="detail-row">
                    <dt>Diajukan</dt>
                    <dd>
                        <?= e($transaction['submitter_name'] !== null && $transaction['submitter_name'] !== ''
                            ? (string) $transaction['submitter_name'] : 'Belum diajukan') ?>
                        <?php if (($transaction['submitted_at'] ?? null) !== null): ?>
                            <br><span class="cell-sub"><?= e(tanggal_id((string) $transaction['submitted_at'], true)) ?></span>
                        <?php endif; ?>
                    </dd>
                </div>
                <div class="detail-row">
                    <dt>Keputusan</dt>
                    <dd>
                        <?= e($transaction['approver_name'] !== null && $transaction['approver_name'] !== ''
                            ? (string) $transaction['approver_name'] : 'Belum diputus') ?>
                        <?php if (($transaction['approved_at'] ?? null) !== null): ?>
                            <br><span class="cell-sub"><?= e(tanggal_id((string) $transaction['approved_at'], true)) ?></span>
                        <?php endif; ?>
                        <?php if (($transaction['decision_note'] ?? '') !== '' && $transaction['decision_note'] !== null): ?>
                            <br><span class="cell-sub"><?= e((string) $transaction['decision_note']) ?></span>
                        <?php endif; ?>
                    </dd>
                </div>
                <?php if (($transaction['cancelled_at'] ?? null) !== null): ?>
                    <div class="detail-row">
                        <dt>Dibatalkan</dt>
                        <dd>
                            <?= e($transaction['canceller_name'] !== null && $transaction['canceller_name'] !== ''
                                ? (string) $transaction['canceller_name'] : 'Pengguna telah dihapus') ?>
                            <br><span class="cell-sub"><?= e(tanggal_id((string) $transaction['cancelled_at'], true)) ?></span>
                            <?php if (($transaction['cancel_reason'] ?? '') !== '' && $transaction['cancel_reason'] !== null): ?>
                                <br><span class="cell-sub"><?= e((string) $transaction['cancel_reason']) ?></span>
                            <?php endif; ?>
                        </dd>
                    </div>
                <?php endif; ?>
            </dl>
        </section>

        <section class="app-card" id="bukti">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Bukti transaksi</h2>
                    <p class="card-subtitle">
                        Berkas berada di luar folder public dan dilayani lewat rute ber-izin.
                    </p>
                </div>
                <span class="card-count"><?= count($attachments) ?> berkas</span>
            </header>
            <div class="table-responsive">
                <table class="table app-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Judul</th>
                            <th scope="col">Berkas</th>
                            <th scope="col">Diunggah</th>
                            <th scope="col" class="text-end">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($attachments === []): ?>
                            <tr>
                                <td colspan="4" class="table-empty">
                                    <div class="empty-state">
                                        <i class="bi bi-file-earmark" aria-hidden="true"></i>
                                        <p class="empty-title">Belum ada bukti</p>
                                        <p class="empty-text">Unggah kuitansi, invoice, atau bukti transfer untuk memperkuat pencatatan.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($attachments as $attachment): ?>
                            <tr>
                                <td>
                                    <span class="cell-strong"><?= e((string) $attachment['title']) ?></span>
                                    <span class="cell-sub">
                                        <?= e($attachment['description'] !== null && $attachment['description'] !== ''
                                            ? (string) $attachment['description'] : 'Tanpa keterangan') ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="cell-sub">
                                        <?= e((string) $attachment['original_name']) ?> &middot;
                                        <?= e((string) $attachment['mime_type']) ?> &middot;
                                        <?= e(filesize_id($attachment['file_size'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= e(tanggal_id((string) $attachment['uploaded_at'], true)) ?>
                                    <span class="cell-sub">
                                        <?= e($attachment['uploader_name'] !== null && $attachment['uploader_name'] !== ''
                                            ? (string) $attachment['uploader_name'] : 'Pengguna telah dihapus') ?>
                                    </span>
                                </td>
                                <td class="text-end cell-actions">
                                    <a class="btn btn-sm btn-outline-secondary" href="<?= url('/bukti/' . $attachment['id']) ?>"
                                       target="_blank" rel="noopener">Buka</a>
                                    <a class="btn btn-sm btn-outline-secondary"
                                       href="<?= url('/bukti/' . $attachment['id'] . '?mode=unduh') ?>">Unduh</a>
                                    <?php if (can('transactions.update')): ?>
                                        <form method="post" action="<?= url('/bukti/' . $attachment['id'] . '/hapus') ?>"
                                              class="d-inline">
                                            <?= csrf_field() ?>
                                            <button class="btn btn-sm btn-outline-danger" type="submit" data-confirm
                                                    data-confirm-title="Hapus bukti"
                                                    data-confirm-text="<?= e('Hapus "' . $attachment['title'] . '" beserta berkas aslinya dari penyimpanan?') ?>"
                                                    data-confirm-label="Ya, hapus"
                                                    data-confirm-tone="danger">
                                                Hapus
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if (can('transactions.create') && !$isArchived): ?>
                <form class="app-form p-3 border-top" method="post" enctype="multipart/form-data"
                      action="<?= url('/transaksi/' . $transactionId . '/bukti') ?>">
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label class="form-label" for="berkas">Unggah bukti baru *</label>
                        <input class="form-control" type="file" id="berkas" name="berkas"
                               accept=".jpg,.jpeg,.png,.webp,.pdf" required>
                        <div class="form-text">Maksimal <?= e((string) $maxUploadMb) ?> MB. Isi berkas diperiksa di server.</div>
                    </div>
                    <div class="form-actions mt-0">
                        <button class="btn btn-primary" type="submit">Unggah bukti</button>
                    </div>
                </form>
            <?php endif; ?>
        </section>

        <section class="app-card">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Jejak perubahan</h2>
                    <p class="card-subtitle">Setiap pencatatan, pengajuan, keputusan, dan pembatalan.</p>
                </div>
                <span class="card-count"><?= count($approvals) ?></span>
            </header>
            <?php if ($approvals === []): ?>
                <p class="muted-note">Belum ada jejak perubahan pada transaksi ini.</p>
            <?php else: ?>
                <div class="timeline-list">
                    <?php foreach ($approvals as $log): ?>
                        <article class="timeline-item">
                            <div class="timeline-head">
                                <span class="timeline-date"><?= e(tanggal_id((string) $log['created_at'], true)) ?></span>
                                <span class="timeline-delta">
                                    <?= e($log['from_status'] !== null && $log['from_status'] !== ''
                                        ? transaction_status_label((string) $log['from_status']) : 'Awal') ?>
                                    &rarr; <?= e(transaction_status_label((string) $log['to_status'])) ?>
                                </span>
                            </div>
                            <p class="timeline-title">
                                <?= e($approvalAction[(string) $log['action']] ?? (string) $log['action']) ?>
                                oleh <?= e($log['actor_name'] !== null && $log['actor_name'] !== ''
                                    ? (string) $log['actor_name'] : 'Pengguna telah dihapus') ?>
                            </p>
                            <?php if ($log['amount_before'] !== null && $log['amount_after'] !== null
                                && (string) $log['amount_before'] !== (string) $log['amount_after']): ?>
                                <p class="timeline-text">
                                    <strong>Nominal:</strong> <?= e(rupiah($log['amount_before'])) ?>
                                    &rarr; <?= e(rupiah($log['amount_after'])) ?>
                                </p>
                            <?php endif; ?>
                            <?php if (($log['note'] ?? null) !== null && $log['note'] !== ''): ?>
                                <p class="timeline-text"><strong>Catatan:</strong> <?= e((string) $log['note']) ?></p>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <?php if ($reversals !== []): ?>
            <section class="app-card">
                <header class="card-head">
                    <div>
                        <h2 class="card-title">Tindak lanjut</h2>
                        <p class="card-subtitle">Transaksi koreksi yang menunjuk ke pencatatan ini.</p>
                    </div>
                    <span class="card-count"><?= count($reversals) ?></span>
                </header>
                <div class="table-responsive">
                    <table class="table app-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">Nomor</th>
                                <th scope="col">Tanggal</th>
                                <th scope="col" class="text-end">Nominal</th>
                                <th scope="col">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reversals as $reversal): ?>
                                <tr>
                                    <td>
                                        <a class="cell-strong" href="<?= url('/transaksi/' . $reversal['id']) ?>">
                                            <?= e((string) $reversal['transaction_code']) ?>
                                        </a>
                                        <span class="cell-sub">
                                            <?= e(transaction_type_label((string) $reversal['transaction_type'])) ?>
                                            &middot; <?= e(mb_substr((string) $reversal['description'], 0, 48)) ?>
                                        </span>
                                    </td>
                                    <td class="text-nowrap"><?= e(tanggal_id((string) $reversal['transaction_date'])) ?></td>
                                    <td class="text-end"><?= e(rupiah($reversal['amount'])) ?></td>
                                    <td>
                                        <span class="pill pill-<?= e(transaction_status_tone($reversal['status'])) ?>">
                                            <?= e(transaction_status_label((string) $reversal['status'])) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>
    </div>

    <div class="col-12 col-xl-4">
        <section class="app-card">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Alur persetujuan</h2>
                    <p class="card-subtitle">
                        <?= $segregation
                            ? 'Pemisahan tugas aktif: pencatat tidak dapat menyetujui transaksinya sendiri.'
                            : 'Pemisahan tugas dinonaktifkan pada pengaturan.' ?>
                    </p>
                </div>
            </header>

            <?php if ($editable && can('transactions.create') && !$isArchived): ?>
                <form class="app-form" method="post" action="<?= url('/transaksi/' . $transactionId . '/ajukan') ?>">
                    <?= csrf_field() ?>
                    <div class="form-actions mt-0">
                        <button class="btn btn-primary w-100" type="submit" data-confirm
                                data-confirm-title="Ajukan transaksi"
                                data-confirm-text="Setelah disetujui, nominalnya masuk ke realisasi proyek dan barisnya tidak dapat diubah lagi."
                                data-confirm-label="Ya, ajukan"
                                data-confirm-tone="warning">
                            <i class="bi bi-send me-1" aria-hidden="true"></i>Ajukan untuk persetujuan
                        </button>
                    </div>
                </form>
            <?php endif; ?>

            <?php if ($status === \App\Models\FinancialTransaction::STATUS_PENDING && can('transactions.approve')): ?>
                <form class="app-form" method="post" action="<?= url('/transaksi/' . $transactionId . '/keputusan') ?>">
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label class="form-label" for="note">Catatan keputusan</label>
                        <textarea class="form-control" id="note" name="note" rows="3" maxlength="500"
                                  placeholder="Wajib diisi bila transaksi ditolak."></textarea>
                    </div>
                    <?php if ($segregation && $isMine): ?>
                        <p class="muted-note">
                            Anda yang mencatat dan mengajukan transaksi ini. Pemisahan tugas menolak keputusan Anda.
                        </p>
                    <?php endif; ?>
                    <div class="form-actions mt-0">
                        <button class="btn btn-success" type="submit" name="action" value="approve"
                                <?= $segregation && $isMine ? 'disabled' : '' ?> data-confirm
                                data-confirm-title="Setujui transaksi"
                                data-confirm-text="<?= e('Realisasi ' . cost_center_label((string) $transaction['cost_center']) . ' pada proyek ' . $transaction['project_name'] . ' akan berubah sebesar ' . rupiah($amount) . '.') ?>"
                                data-confirm-label="Ya, setujui"
                                data-confirm-tone="warning">
                            Setujui
                        </button>
                        <button class="btn btn-danger" type="submit" name="action" value="reject">Tolak</button>
                    </div>
                </form>
            <?php elseif ($status === \App\Models\FinancialTransaction::STATUS_PENDING): ?>
                <p class="muted-note">Menunggu keputusan pemeriksa yang memiliki izin transactions.approve.</p>
            <?php endif; ?>

            <?php if ($status === \App\Models\FinancialTransaction::STATUS_APPROVED && can('transactions.cancel') && !$isArchived): ?>
                <form class="app-form" method="post" action="<?= url('/transaksi/' . $transactionId . '/batalkan') ?>">
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label class="form-label" for="reason">Alasan pembatalan *</label>
                        <textarea class="form-control" id="reason" name="reason" rows="3" minlength="10"
                                  maxlength="500" required
                                  placeholder="Contoh: dokumen salah_input, pembayaran dikembalikan kontraktor."></textarea>
                        <div class="form-text">Minimal 10 karakter. Baris transaksi tetap tersimpan sebagai riwayat.</div>
                    </div>
                    <div class="form-actions mt-0">
                        <button class="btn btn-outline-danger" type="submit" data-confirm
                                data-confirm-title="Batalkan transaksi"
                                data-confirm-text="Realisasi proyek dihitung ulang dan pembatalan tercatat permanen beserta nama pelaksana dan waktunya."
                                data-confirm-label="Ya, batalkan"
                                data-confirm-tone="danger">
                            Batalkan transaksi
                        </button>
                    </div>
                </form>
            <?php endif; ?>

            <?php if ($editable && can('transactions.update') && !$isArchived): ?>
                <form class="app-form" method="post" action="<?= url('/transaksi/' . $transactionId . '/hapus') ?>">
                    <?= csrf_field() ?>
                    <div class="form-actions mt-0">
                        <button class="btn btn-outline-danger" type="submit" data-confirm
                                data-confirm-title="Hapus transaksi draft"
                                data-confirm-text="<?= e('Hapus permanen ' . $transaction['transaction_code'] . ' beserta bukti yang menempel padanya?') ?>"
                                data-confirm-label="Ya, hapus"
                                data-confirm-tone="danger">
                            Hapus transaksi
                        </button>
                    </div>
                </form>
            <?php endif; ?>

            <p class="muted-note">
                <?php if ($status === \App\Models\FinancialTransaction::STATUS_APPROVED): ?>
                    Transaksi disetujui ikut dihitung sebagai realisasi
                    <?= e(cost_center_label((string) $transaction['cost_center'])) ?>.
                <?php elseif ($status === \App\Models\FinancialTransaction::STATUS_CANCELLED): ?>
                    Transaksi dibatalkan, nilainya tidak dihitung sebagai realisasi.
                <?php else: ?>
                    Transaksi belum disetujui sehingga belum memengaruhi realisasi.
                <?php endif; ?>
            </p>
        </section>

        <section class="app-card">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Posisi anggaran proyek</h2>
                    <p class="card-subtitle">
                        Dihitung untuk pusat biaya <?= e(cost_center_label((string) $transaction['cost_center'])) ?>.
                    </p>
                </div>
            </header>
            <dl class="info-list">
                <div>
                    <dt>Anggaran awal proyek</dt>
                    <dd><?= e(rupiah($summary['initial_budget'])) ?></dd>
                </div>
                <div>
                    <dt>Anggaran disetujui</dt>
                    <dd><?= e(rupiah($summary['approved_budget'])) ?></dd>
                </div>
                <div>
                    <dt>Realisasi</dt>
                    <dd><?= e(rupiah($summary['realization'])) ?></dd>
                </div>
                <div>
                    <dt>Sisa</dt>
                    <dd><?= e(rupiah($summary['remaining'])) ?></dd>
                </div>
                <div>
                    <dt>Penyerapan</dt>
                    <dd><?= $summary['percent'] === null ? '—' : e(percent_id($summary['percent'])) ?></dd>
                </div>
                <div>
                    <dt>Indikator</dt>
                    <dd>
                        <span class="pill pill-<?= e(budget_indicator_tone($summary['indicator'])) ?>">
                            <?= e(budget_indicator_label($summary['indicator'])) ?>
                        </span>
                    </dd>
                </div>
            </dl>
            <p class="muted-note">
                <a href="<?= url('/anggaran/' . $projectId) ?>">Rekap anggaran dan riwayat perubahannya</a>.
            </p>
        </section>
    </div>
</div>
