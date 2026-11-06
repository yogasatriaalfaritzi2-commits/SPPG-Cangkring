<?php
/**
 * Lembar Surat Jalan & Pengiriman Menu MBG ke Sekolah Mitra.
 * Dokumen siap cetak (A4) untuk petugas distribusi dan serah terima sekolah.
 */
$schedule = is_array($schedule ?? []) ? $schedule : [];
$allocations = is_array($allocations ?? []) ? $allocations : [];
$institution = institution_name();
$schCode = (string) ($schedule['schedule_code'] ?? '');
$menuName = (string) ($schedule['menu_name'] ?? '');
$serviceDate = (string) ($schedule['service_date'] ?? date('Y-m-d'));
$mealSlot = (string) ($schedule['meal_slot'] ?? 'makan_siang');
$totalPortions = (int) ($schedule['total_portions'] ?? 0);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Surat Jalan Pengiriman MBG - <?= e($schCode) ?></title>
    <link rel="stylesheet" href="<?= asset('vendor/bootstrap/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
    <style>
        body {
            background-color: #f8f9fa;
            color: #212529;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }
        .print-container {
            max-width: 800px;
            margin: 20px auto;
            background: #ffffff;
            padding: 35px 40px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            border-radius: 8px;
        }
        .doc-header {
            border-bottom: 3px double #198754;
            padding-bottom: 15px;
            margin-bottom: 25px;
        }
        .meta-table td {
            padding: 4px 8px;
            font-size: 0.95rem;
        }
        .table-delivery th {
            background-color: #f1f8f4 !important;
            color: #0f5132;
            border-top: 1px solid #198754;
        }
        .signature-box {
            margin-top: 40px;
        }
        @media print {
            body {
                background: #ffffff;
                padding: 0;
            }
            .print-container {
                box-shadow: none;
                padding: 0;
                margin: 0;
                max-width: 100%;
            }
            .no-print {
                display: none !important;
            }
        }
    </style>
</head>
<body>

<div class="print-container">
    <!-- Tombol Aksi di Layar -->
    <div class="d-flex justify-content-between align-items-center mb-4 no-print pb-3 border-bottom">
        <a href="<?= url('/jadwal-menu/' . (int)$schedule['id']) ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Kembali ke Detail
        </a>
        <div class="d-flex gap-2">
            <button onclick="window.print()" class="btn btn-primary btn-sm">
                <i class="bi bi-printer me-1"></i>Cetak Surat Jalan (Print / PDF)
            </button>
        </div>
    </div>

    <!-- Kop Dokumen -->
    <div class="doc-header d-flex align-items-center justify-content-between">
        <div>
            <span class="badge bg-success-subtle text-success border border-success-subtle mb-1 fw-semibold">
                PROGRAM MAKAN BERGIZI GRATIS (MBG)
            </span>
            <h1 class="h4 fw-bold mb-0 text-dark"><?= e($institution) ?></h1>
            <p class="text-muted small mb-0">Surat Jalan Distribusi & Bukti Serah Terima Makanan</p>
        </div>
        <div class="text-end font-monospace">
            <div class="fw-bold fs-6 text-primary"><?= e($schCode) ?></div>
            <small class="text-muted"><?= e(tanggal_id($serviceDate)) ?></small>
        </div>
    </div>

    <!-- Rincian Distribusi -->
    <div class="card bg-light border-0 mb-4 p-3 rounded">
        <table class="meta-table w-100">
            <tr>
                <td style="width: 25%;" class="text-muted">Nama Menu:</td>
                <td style="width: 35%;" class="fw-bold"><?= e($menuName) ?></td>
                <td style="width: 20%;" class="text-muted">Waktu Saji:</td>
                <td style="width: 20%;" class="fw-bold text-uppercase"><?= e(slot_label($mealSlot)) ?></td>
            </tr>
            <tr>
                <td class="text-muted">Tanggal Pelayanan:</td>
                <td class="fw-bold"><?= e(tanggal_id($serviceDate, false)) ?></td>
                <td class="text-muted">Total Distribusi:</td>
                <td class="fw-bold text-success fs-6"><?= number_format($totalPortions, 0, ',', '.') ?> Porsi</td>
            </tr>
            <tr>
                <td class="text-muted">Status Jadwal:</td>
                <td colspan="3">
                    <span class="badge bg-success">Disetujui / Siap Kirim</span>
                </td>
            </tr>
        </table>
    </div>

    <!-- Tabel Sekolah Penerima & Alokasi Porsi -->
    <h2 class="h6 fw-bold mb-2 text-dark">Daftar Sekolah Penerima & Alokasi Porsi</h2>
    <div class="table-responsive mb-4">
        <table class="table table-bordered table-delivery align-middle mb-0">
            <thead>
                <tr>
                    <th style="width: 5%;" class="text-center">No</th>
                    <th style="width: 15%;">Kode</th>
                    <th style="width: 40%;">Nama Sekolah Mitra</th>
                    <th style="width: 15%;" class="text-center">Porsi</th>
                    <th style="width: 25%;">Paraf / Nama Penerima</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($allocations === []): ?>
                    <tr>
                        <td colspan="5" class="text-center py-3 text-muted">Belum ada alokasi sekolah untuk jadwal ini.</td>
                    </tr>
                <?php else: ?>
                    <?php $no = 1; foreach ($allocations as $item): ?>
                        <tr>
                            <td class="text-center fw-semibold"><?= $no++ ?></td>
                            <td><code><?= e($item['school_code']) ?></code></td>
                            <td>
                                <strong class="d-block text-dark"><?= e($item['school_name']) ?></strong>
                                <small class="text-muted"><?= e($item['address'] ?? 'Kecamatan Cangkring') ?></small>
                            </td>
                            <td class="text-center fw-bold fs-6 text-primary">
                                <?= number_format((int)$item['portion_count'], 0, ',', '.') ?>
                            </td>
                            <td style="height: 55px;" class="border-end"></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr class="table-light fw-bold">
                    <td colspan="3" class="text-end">TOTAL KESELURUHAN:</td>
                    <td class="text-center fs-6 text-success"><?= number_format($totalPortions, 0, ',', '.') ?> Porsi</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <!-- Kolom Tanda Tangan -->
    <div class="row text-center signature-box pt-3">
        <div class="col-4">
            <p class="small text-muted mb-5">Petugas Dapur / Pengemas</p>
            <div class="border-bottom mx-auto w-75" style="margin-top: 45px;"></div>
            <p class="small fw-semibold mt-1 mb-0">( ........................................ )</p>
        </div>
        <div class="col-4">
            <p class="small text-muted mb-5">Petugas Pengantar / Kurir</p>
            <div class="border-bottom mx-auto w-75" style="margin-top: 45px;"></div>
            <p class="small fw-semibold mt-1 mb-0">( ........................................ )</p>
        </div>
        <div class="col-4">
            <p class="small text-muted mb-5">Koordinator Distribusi MBG</p>
            <div class="border-bottom mx-auto w-75" style="margin-top: 45px;"></div>
            <p class="small fw-semibold mt-1 mb-0">( ........................................ )</p>
        </div>
    </div>

    <div class="mt-4 pt-3 border-top d-flex justify-content-between text-muted small">
        <span>Dokumen Resmi Sistem Informasi SPPG Cangkring</span>
        <span>Dicetak pada: <?= date('d/m/Y H:i') ?> WIB</span>
    </div>
</div>

</body>
</html>
