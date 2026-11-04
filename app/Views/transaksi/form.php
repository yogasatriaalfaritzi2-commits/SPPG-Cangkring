<?php
$isEdit = $mode === 'edit';
$transactionId = $isEdit ? (int) $transaction['id'] : 0;
$action = $isEdit ? url('/transaksi/' . $transactionId . '/ubah') : url('/transaksi/tambah');
$approvedPlans = array_values(array_filter(
    $plans,
    static fn (array $plan): bool => (string) $plan['status'] === \App\Models\BudgetPlan::STATUS_APPROVED
));
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Keuangan &middot; Transaksi</p>
        <h1 class="page-title"><?= $isEdit ? 'Sunting transaksi' : 'Catat transaksi keuangan' ?></h1>
        <p class="page-subtitle">
            <?= $isEdit
                ? 'Hanya transaksi berstatus draft atau ditolak yang dapat diubah. Nominal perubahan tercatat pada log aktivitas.'
                : 'Transaksi tersimpan sebagai draft. Realisasi proyek baru berubah setelah transaksi disetujui pemeriksa.' ?>
        </p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline-secondary" href="<?= $isEdit ? url('/transaksi/' . $transactionId) : url('/transaksi') ?>">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Kembali
        </a>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-8">
        <form class="app-form" method="post" enctype="multipart/form-data" action="<?= e($action) ?>" data-live-amount>
            <?= csrf_field() ?>

            <section class="app-card mb-3">
                <header class="card-head">
                    <div>
                        <h2 class="card-title">Identitas pencatatan</h2>
                        <p class="card-subtitle">Kolom bertanda bintang wajib diisi.</p>
                    </div>
                </header>

                <div class="row g-3">
                    <div class="col-12 col-md-4">
                        <div class="form-group mb-0">
                            <label class="form-label" for="transaction_code">Nomor transaksi *</label>
                            <input class="form-control" type="text" id="transaction_code" name="transaction_code"
                                   value="<?= e((string) $form['transaction_code']) ?>" maxlength="30"
                                   pattern="TRX-[0-9]{4}-[0-9]{1,4}" required>
                            <div class="form-text">Motif TRX-TAHUN-NOMOR dan harus unik.</div>
                        </div>
                    </div>

                    <div class="col-12 col-md-8">
                        <div class="form-group mb-0">
                            <label class="form-label" for="project_id">Proyek *</label>
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
                        </div>
                    </div>

                    <div class="col-12 col-md-6">
                        <div class="form-group mb-0">
                            <label class="form-label" for="transaction_type">Jenis transaksi *</label>
                            <select class="form-select" id="transaction_type" name="transaction_type" required>
                                <?php foreach ($types as $type): ?>
                                    <option value="<?= e($type) ?>" <?= (string) $form['transaction_type'] === $type ? 'selected' : '' ?>>
                                        <?= e(transaction_type_label($type)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">
                                Pengeluaran menambah realisasi, pengembalian menguranginya, dan penyesuaian
                                dipakai untuk koreksi bernilai positif maupun negatif.
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-md-6">
                        <div class="form-group mb-0">
                            <label class="form-label" for="cost_center">Pusat biaya *</label>
                            <select class="form-select" id="cost_center" name="cost_center" required>
                                <?php foreach ($centers as $center): ?>
                                    <option value="<?= e($center) ?>" <?= (string) $form['cost_center'] === $center ? 'selected' : '' ?>>
                                        <?= e(cost_center_label($center)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">
                                Anggaran pembangunan dan biaya operasional MBG dilaporkan terpisah.
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-md-4">
                        <div class="form-group mb-0">
                            <label class="form-label" for="transaction_date">Tanggal transaksi *</label>
                            <input class="form-control" type="date" id="transaction_date" name="transaction_date"
                                   value="<?= e((string) $form['transaction_date']) ?>" required>
                        </div>
                    </div>

                    <div class="col-12 col-md-4">
                        <div class="form-group mb-0">
                            <label class="form-label" for="amount">Nominal *</label>
                            <input class="form-control" type="text" id="amount" name="amount" inputmode="numeric"
                                   value="<?= e((string) $form['amount']) ?>" data-live-amount-input required>
                            <div class="form-text">Contoh 1.500.000 atau 1500000. Negatif hanya untuk penyesuaian.</div>
                        </div>
                    </div>

                    <div class="col-12 col-md-4">
                        <div class="form-group mb-0">
                            <label class="form-label" for="live_amount">Nilai terbaca</label>
                            <output class="form-control" id="live_amount" for="amount">
                                <?= e(rupiah($form['amount'] === '' ? 0 : (float) amount_normalize((string) $form['amount']))) ?>
                            </output>
                            <div class="form-text">Hanya bantuan tampilan sebelum menyimpan.</div>
                        </div>
                    </div>

                    <div class="col-12">
                        <div class="form-group mb-0">
                            <label class="form-label" for="description">Uraian *</label>
                            <input class="form-control" type="text" id="description" name="description"
                                   value="<?= e((string) $form['description']) ?>" maxlength="255" required
                                   placeholder="Contoh: Pembayaran tukang pasangan dinding bata ruang dapur">
                        </div>
                    </div>
                </div>
            </section>

            <section class="app-card mb-3">
                <header class="card-head">
                    <div>
                        <h2 class="card-title">Pembayaran dan pihak terkait</h2>
                        <p class="card-subtitle">Penerima untuk pengeluaran, sumber dana untuk pemasukan.</p>
                    </div>
                </header>

                <div class="row g-3">
                    <div class="col-12 col-md-4">
                        <div class="form-group mb-0">
                            <label class="form-label" for="payment_method">Metode pembayaran *</label>
                            <select class="form-select" id="payment_method" name="payment_method" required>
                                <?php foreach ($methods as $method): ?>
                                    <option value="<?= e($method) ?>" <?= (string) $form['payment_method'] === $method ? 'selected' : '' ?>>
                                        <?= e(payment_method_label($method)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="col-12 col-md-4">
                        <div class="form-group mb-0">
                            <label class="form-label" for="recipient">Penerima / sumber dana *</label>
                            <input class="form-control" type="text" id="recipient" name="recipient"
                                   value="<?= e((string) $form['recipient']) ?>" maxlength="150" required>
                        </div>
                    </div>

                    <div class="col-12 col-md-4">
                        <div class="form-group mb-0">
                            <label class="form-label" for="reference_number">Nomor referensi</label>
                            <input class="form-control" type="text" id="reference_number" name="reference_number"
                                   value="<?= e((string) $form['reference_number']) ?>" maxlength="80"
                                   placeholder="No. kuitansi, SPAM, atau bk">
                            <div class="form-text">Tidak boleh ganda dalam satu proyek.</div>
                        </div>
                    </div>

                    <div class="col-12 col-md-6">
                        <div class="form-group mb-0">
                            <label class="form-label" for="category_id">Kategori pekerjaan</label>
                            <select class="form-select" id="category_id" name="category_id">
                                <option value="">Tanpa kategori</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?= e((string) $category['id']) ?>"
                                        <?= (string) $form['category_id'] === (string) $category['id'] ? 'selected' : '' ?>>
                                        <?= e((string) $category['category_code']) ?> &mdash; <?= e((string) $category['category_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Wajib untuk jenis pengeluaran dan pengembalian dana.</div>
                        </div>
                    </div>

                    <div class="col-12 col-md-6">
                        <div class="form-group mb-0">
                            <label class="form-label" for="budget_plan_id">RAB acuan</label>
                            <select class="form-select" id="budget_plan_id" name="budget_plan_id">
                                <option value="">Tanpa RAB acuan</option>
                                <?php foreach ($approvedPlans as $plan): ?>
                                    <option value="<?= e((string) $plan['id']) ?>"
                                        <?= (string) $form['budget_plan_id'] === (string) $plan['id'] ? 'selected' : '' ?>>
                                        <?= e((string) $plan['rab_code']) ?> &mdash; <?= e((string) $plan['name']) ?>
                                        (<?= e(rupiah($plan['total_amount'])) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Hanya RAB berstatus disetujui pada proyek yang sama.</div>
                        </div>
                    </div>

                    <div class="col-12">
                        <div class="form-group mb-0">
                            <label class="form-label" for="reversal_of">Dikoreksi dari transaksi</label>
                            <select class="form-select" id="reversal_of" name="reversal_of">
                                <option value="">Bukan koreksi</option>
                                <?php foreach ($correctable as $row): ?>
                                    <option value="<?= e((string) $row['id']) ?>"
                                        <?= (string) $form['reversal_of'] === (string) $row['id'] ? 'selected' : '' ?>>
                                        <?= e((string) $row['transaction_code']) ?> &middot;
                                        <?= e(tanggal_id((string) $row['transaction_date'])) ?> &middot;
                                        <?= e(rupiah($row['amount'])) ?> &middot;
                                        <?= e(mb_substr((string) $row['description'], 0, 48)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">
                                Pilih transaksi yang sudah disetujui lalu gunakan jenis penyesuaian.
                                Baris asal tidak pernah dihapus.
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="app-card mb-3">
                <header class="card-head">
                    <div>
                        <h2 class="card-title">Bukti transaksi</h2>
                        <p class="card-subtitle">
                            Berkas disimpan di luar folder public dan hanya dapat dibuka melalui halaman transaksi.
                        </p>
                    </div>
                </header>
                <div class="form-group">
                    <label class="form-label" for="bukti"><?= $isEdit ? 'Ganti bukti (opsional)' : 'Berkas bukti (opsional)' ?></label>
                    <input class="form-control" type="file" id="bukti" name="bukti" accept=".jpg,.jpeg,.png,.webp,.pdf">
                    <div class="form-text">
                        JPG, PNG, WEBP, atau PDF. Maksimal <?= e((string) $maxUploadMb) ?> MB.
                        Isi berkas diperiksa di server, bukan hanya namanya.
                    </div>
                </div>
            </section>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit"><?= $isEdit ? 'Simpan perubahan' : 'Simpan sebagai draft' ?></button>
                <a class="btn btn-outline-secondary" href="<?= $isEdit ? url('/transaksi/' . $transactionId) : url('/transaksi') ?>">Batal</a>
            </div>
        </form>
    </div>

    <div class="col-12 col-xl-4">
        <?php if (!$isEdit && $approvedPlans === []): ?>
            <section class="app-card">
                <header class="card-head">
                    <div>
                        <h2 class="card-title">Muat acuan per proyek</h2>
                        <p class="card-subtitle">
                            Daftar RAB acuan dan transaksi yang dapat dikoreksi dimuat menurut proyek terpilih.
                        </p>
                    </div>
                </header>
                <form class="app-form" method="get" action="<?= url('/transaksi/tambah') ?>">
                    <div class="form-group">
                        <label class="form-label" for="proyek">Proyek</label>
                        <select class="form-select" id="proyek" name="proyek">
                            <option value="">Belum dipilih</option>
                            <?php foreach ($projects as $project): ?>
                                <option value="<?= e((string) $project['id']) ?>">
                                    <?= e((string) $project['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-actions mt-0">
                        <button class="btn btn-outline-secondary" type="submit">Muat pilihan proyek</button>
                    </div>
                </form>
                <p class="muted-note">
                    Setelah halaman dimuat ulang, formulir di samping tetap dapat diisi tanpa memilih proyek —
                    hanya RAB acuan dan koreksi yang tidak tersedia.
                </p>
            </section>
        <?php endif; ?>

        <section class="app-card">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Status pencatatan</h2>
                    <p class="card-subtitle">Diubah melalui halaman rincian transaksi.</p>
                </div>
            </header>
            <dl class="info-list">
                <div>
                    <dt>Status saat ini</dt>
                    <dd>
                        <span class="pill pill-<?= e(transaction_status_tone($isEdit ? $transaction['status'] : 'draft')) ?>">
                            <?= e(transaction_status_label($isEdit ? (string) $transaction['status'] : 'draft')) ?>
                        </span>
                    </dd>
                </div>
                <?php if ($isEdit): ?>
                    <div>
                        <dt>Pencatat</dt>
                        <dd>
                            <?= e($transaction['recorder_name'] !== null && $transaction['recorder_name'] !== ''
                                ? (string) $transaction['recorder_name'] : 'Pengguna telah dihapus') ?>
                        </dd>
                    </div>
                    <div>
                        <dt>Dibuat</dt>
                        <dd><?= e(tanggal_id((string) $transaction['created_at'], true)) ?></dd>
                    </div>
                    <?php if (!empty($transaction['original_code'])): ?>
                        <div>
                            <dt>Koreksi dari</dt>
                            <dd><a href="<?= url('/transaksi/' . $transaction['reversal_of']) ?>">
                                <?= e((string) $transaction['original_code']) ?></a></dd>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </dl>
        </section>

        <section class="app-card">
            <header class="card-head">
                <div>
                    <h2 class="card-title">Aturan pencatatan</h2>
                    <p class="card-subtitle">Diperiksa di sisi server sebelum menyimpan.</p>
                </div>
            </header>
            <ul class="rule-list">
                <li>Nominal wajib lebih dari nol; nilai negatif hanya untuk penyesuaian.</li>
                <li>Transaksi serupa pada tanggal, proyek, nominal, dan uraian yang sama ditolak agar tidak ganda.</li>
                <li>RAB acuan harus berstatus disetujui dan berasal dari proyek yang sama.</li>
                <li>Transaksi yang sudah disetujui tidak dapat dihapus maupun diubah — gunakan pembatalan atau koreksi.</li>
                <li>Realisasi dan sisa anggaran dihitung ulang setiap kali status transaksi berubah.</li>
            </ul>
        </section>
    </div>
</div>
