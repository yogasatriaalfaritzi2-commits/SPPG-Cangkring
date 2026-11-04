<?php
/**
 * Bukti transaksi untuk dicetak. Halaman berdiri sendiri sehingga hasilnya
 * hanya berisi bukti, tanpa menu atau kerangka aplikasi.
 */
$transaction = is_array($transaction ?? []) ? $transaction : [];
$attachments = is_array($attachments ?? []) ? $attachments : [];
$institution = $institution ?? app_name();

$status = (string) ($transaction['status'] ?? '');
$amount = (float) ($transaction['amount'] ?? 0);
$sign = match ($transaction['transaction_type'] ?? '') {
    'expense', 'adjustment' => $amount >= 0 ? '+' : '-',
    'refund' => '-',
    default => '',
};
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'Bukti Transaksi') ?></title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 24px;
            background: #f5f6f8;
            color: #1d2430;
            font-family: "Segoe UI", Arial, sans-serif;
            font-size: 14px;
            line-height: 1.5;
        }

        .sheet {
            max-width: 794px;
            margin: 0 auto;
            padding: 32px 36px;
            background: #fff;
            border: 1px solid #d8dce3;
        }

        .sheet-head {
            padding-bottom: 14px;
            border-bottom: 2px solid #1d2430;
        }

        .org {
            margin: 0 0 2px;
            font-size: 17px;
            font-weight: 700;
        }

        .doc {
            margin: 0;
            font-size: 13px;
            color: #55606f;
            text-transform: uppercase;
            letter-spacing: .06em;
        }

        .meta {
            display: flex;
            flex-wrap: wrap;
            gap: 6px 32px;
            margin: 16px 0 0;
            padding: 0;
            list-style: none;
            font-size: 13px;
        }

        .meta li {
            margin: 0;
        }

        .meta span {
            color: #55606f;
        }

        table {
            width: 100%;
            margin: 18px 0 0;
            border-collapse: collapse;
        }

        th, td {
            padding: 8px 10px;
            border: 1px solid #ccd2db;
            text-align: left;
            vertical-align: top;
        }

        th {
            width: 32%;
            background: #f2f4f7;
            font-weight: 600;
        }

        .amount {
            margin: 20px 0 0;
            padding: 12px 14px;
            border: 1px solid #ccd2db;
            background: #f2f4f7;
        }

        .amount p {
            margin: 0;
            font-size: 12px;
            color: #55606f;
        }

        .amount strong {
            display: block;
            margin-top: 2px;
            font-size: 20px;
        }

        h2 {
            margin: 26px 0 0;
            font-size: 14px;
        }

        ul.plain {
            margin: 8px 0 0;
            padding-left: 20px;
        }

        .status {
            display: inline-block;
            padding: 2px 8px;
            border: 1px solid #ccd2db;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .sign {
            color: #8a1c12;
        }

        .note {
            margin: 10px 0 0;
            font-size: 12px;
            color: #55606f;
        }

        .signers {
            display: flex;
            gap: 28px;
            margin-top: 38px;
        }

        .signers div {
            flex: 1;
            text-align: center;
            font-size: 13px;
        }

        .signers .line {
            height: 62px;
        }

        .signers .who {
            margin-top: 34px;
            border-top: 1px solid #1d2430;
            padding-top: 4px;
            font-weight: 600;
        }

        .actions {
            max-width: 794px;
            margin: 14px auto 0;
            display: flex;
            gap: 10px;
        }

        .actions a,
        .actions button {
            padding: 7px 14px;
            border: 1px solid #ccd2db;
            border-radius: 3px;
            background: #fff;
            color: #1d2430;
            font: inherit;
            font-size: 13px;
            text-decoration: none;
            cursor: pointer;
        }

        .actions .hint {
            align-self: center;
            font-size: 12px;
            color: #55606f;
        }

        @media print {
            body {
                padding: 0;
                background: #fff;
            }

            .sheet {
                max-width: none;
                border: 0;
                padding: 0;
            }

            .actions {
                display: none;
            }
        }
    </style>
</head>
<body>
<?php if ($transaction === []): ?>
    <div class="sheet">
        <p>Data transaksi tidak tersedia.</p>
    </div>
<?php else: ?>
    <main class="sheet">
        <header class="sheet-head">
            <p class="org"><?= e($institution) ?></p>
            <p class="doc">Bukti Transaksi Keuangan</p>
        </header>

        <ul class="meta">
            <li><span>Nomor</span> <strong><?= e($transaction['transaction_code'] ?? '') ?></strong></li>
            <li><span>Tanggal</span> <strong><?= e(tanggal_id($transaction['transaction_date'] ?? null)) ?></strong></li>
            <li><span>Status</span> <strong><span class="status"><?= e(transaction_status_label($status)) ?></span></strong></li>
        </ul>

        <table>
            <tbody>
                <tr>
                    <th scope="row">Proyek</th>
                    <td>
                        <?= e($transaction['project_name'] ?? '—') ?>
                        <span>(<?= e($transaction['project_code'] ?? '') ?>)</span>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Jenis transaksi</th>
                    <td><?= e(transaction_type_label($transaction['transaction_type'] ?? '')) ?></td>
                </tr>
                <tr>
                    <th scope="row">Pusat biaya</th>
                    <td><?= e(cost_center_label($transaction['cost_center'] ?? '')) ?></td>
                </tr>
                <tr>
                    <th scope="row">Kategori anggaran</th>
                    <td>
                        <?php if (($transaction['category_name'] ?? null) !== null): ?>
                            <?= e($transaction['category_code'] ?? '') ?> &middot; <?= e($transaction['category_name']) ?>
                        <?php else: ?>
                            &mdash;
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">RAB acuan</th>
                    <td>
                        <?php if (($transaction['plan_name'] ?? null) !== null): ?>
                            <?= e($transaction['rab_code'] ?? '') ?> &middot; <?= e($transaction['plan_name']) ?>
                        <?php else: ?>
                            &mdash;
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Uraian</th>
                    <td><?= e($transaction['description'] ?? '—') ?></td>
                </tr>
                <tr>
                    <th scope="row">Penerima / sumber dana</th>
                    <td><?= e($transaction['recipient'] ?? '—') ?></td>
                </tr>
                <tr>
                    <th scope="row">Metode pembayaran</th>
                    <td><?= e(payment_method_label($transaction['payment_method'] ?? '')) ?></td>
                </tr>
                <tr>
                    <th scope="row">Nomor referensi</th>
                    <td><?= $transaction['reference_number'] !== null && $transaction['reference_number'] !== ''
                        ? e($transaction['reference_number'])
                        : '—' ?></td>
                </tr>
                <?php if ($sign !== '' && $transaction['transaction_type'] === 'adjustment'): ?>
                    <tr>
                        <th scope="row">Koreksi dari</th>
                        <td>
                            <?= e($transaction['original_code'] ?? '—') ?>
                            &middot; <?= e(transaction_type_label($transaction['original_type'] ?? '')) ?>
                            &middot; <?= e(rupiah($transaction['original_amount'] ?? 0)) ?>
                        </td>
                    </tr>
                <?php endif; ?>
                <tr>
                    <th scope="row">Dicatat oleh</th>
                    <td>
                        <?= e($transaction['recorder_name'] ?? '—') ?>
                        <?php if (!empty($transaction['created_at'])): ?>
                            <span class="note">Dibuat <?= e(tanggal_id($transaction['created_at'], true)) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if (!empty($transaction['submitter_name'])): ?>
                    <tr>
                        <th scope="row">Diajukan oleh</th>
                        <td>
                            <?= e($transaction['submitter_name']) ?>
                            <span class="note"><?= e(tanggal_id($transaction['submitted_at'] ?? null, true)) ?></span>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php if (!empty($transaction['approver_name'])): ?>
                    <tr>
                        <th scope="row">Disetujui oleh</th>
                        <td>
                            <?= e($transaction['approver_name']) ?>
                            <span class="note"><?= e(tanggal_id($transaction['approved_at'] ?? null, true)) ?></span>
                            <?php if (!empty($transaction['decision_note'])): ?>
                                <span class="note">Catatan: <?= e($transaction['decision_note']) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php if ($status === 'cancelled' && !empty($transaction['canceller_name'])): ?>
                    <tr>
                        <th scope="row">Dibatalkan oleh</th>
                        <td>
                            <?= e($transaction['canceller_name']) ?>
                            <span class="note"><?= e(tanggal_id($transaction['cancelled_at'] ?? null, true)) ?></span>
                            <?php if (!empty($transaction['cancel_reason'])): ?>
                                <span class="note">Alasan: <?= e($transaction['cancel_reason']) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="amount">
            <p>Nominal<?php if ($sign !== ''): ?> &middot; pengaruh terhadap realisasi: <?= $sign === '-' ? 'mengurangi' : 'menambah' ?><?php endif; ?></p>
            <strong>
                <?php if ($sign === '-'): ?><span class="sign">&minus;</span><?php endif; ?>
                <?= e(rupiah(abs($amount))) ?>
            </strong>
        </div>

        <h2>Lampiran</h2>
        <?php if ($attachments === []): ?>
            <p class="note">Tidak ada bukti lampiran yang diunggah untuk transaksi ini.</p>
        <?php else: ?>
            <ul class="plain">
                <?php foreach ($attachments as $attachment): ?>
                    <li>
                        <?= e($attachment['title']) ?>
                        <span class="note">
                            <?= e($attachment['original_name']) ?> &middot;
                            <?= e(filesize_id($attachment['file_size'] ?? 0)) ?> &middot;
                            diunggah oleh <?= e($attachment['uploader_name'] ?? '—') ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <div class="signers">
            <div>
                <p>Menyetujui</p>
                <div class="line"></div>
                <p class="who"><?= e($transaction['approver_name'] ?? '(Kepala SPPG)') ?></p>
            </div>
            <div>
                <p>Pencatat</p>
                <div class="line"></div>
                <p class="who"><?= e($transaction['recorder_name'] ?? '—') ?></p>
            </div>
        </div>

        <p class="note">
            Dicetak pada <?= e(tanggal_id(date('Y-m-d H:i:s'), true)) ?>.
            Bukti ini hanya sah untuk transaksi berstatus Disetujui.
        </p>
    </main>

    <nav class="actions">
        <a href="<?= e(url('/transaksi/' . (int) ($transaction['id'] ?? 0))) ?>">Kembali ke rincian transaksi</a>
        <span class="hint">Tekan Ctrl+P untuk mencetak halaman ini.</span>
    </nav>
<?php endif; ?>
</body>
</html>
