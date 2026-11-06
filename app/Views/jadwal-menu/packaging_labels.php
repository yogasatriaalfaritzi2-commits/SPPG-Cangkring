<?php
/**
 * Lembar Cetak Label Pengemasan Makanan / Ompreng MBG.
 * Siap cetak langsung untuk ditempel pada wadah/box distribusi per sekolah.
 */
$schedule = is_array($schedule ?? []) ? $schedule : [];
$allocations = is_array($allocations ?? []) ? $allocations : [];
$institution = institution_name();
$schCode = (string) ($schedule['schedule_code'] ?? '');
$menuName = (string) ($schedule['menu_name'] ?? '');
$serviceDate = (string) ($schedule['service_date'] ?? date('Y-m-d'));
$mealSlot = (string) ($schedule['meal_slot'] ?? 'makan_siang');
$notes = (string) ($schedule['notes'] ?? '');
$allergens = (string) ($schedule['allergens'] ?? '');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Label Pengemasan - <?= e($schCode) ?></title>
    <link rel="stylesheet" href="<?= asset('vendor/bootstrap/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
    <style>
        body {
            background-color: #f3f4f6;
            color: #1f2937;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
            font-size: 13px;
        }
        .action-bar {
            max-width: 900px;
            margin: 20px auto 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .labels-grid {
            max-width: 900px;
            margin: 0 auto 30px;
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
        }
        .pack-card {
            background: #ffffff;
            border: 2px solid #0f5132;
            border-radius: 8px;
            padding: 14px 18px;
            position: relative;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .pack-header {
            border-bottom: 1.5px dashed #198754;
            padding-bottom: 8px;
            margin-bottom: 8px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }
        .pack-school {
            font-size: 1.05rem;
            font-weight: 700;
            color: #0f5132;
            line-height: 1.2;
        }
        .pack-menu {
            font-size: 0.95rem;
            font-weight: 600;
            color: #111827;
            margin: 6px 0;
        }
        .pack-meta {
            font-size: 0.8rem;
            color: #4b5563;
        }
        .pack-portion {
            background: #e8f5e9;
            color: #0f5132;
            font-size: 1.15rem;
            font-weight: 800;
            padding: 2px 10px;
            border-radius: 6px;
            border: 1px solid #a3cfbb;
            display: inline-block;
        }
        .pack-footer {
            border-top: 1px solid #e5e7eb;
            margin-top: 8px;
            padding-top: 6px;
            font-size: 0.75rem;
            color: #6b7280;
            display: flex;
            justify-content: space-between;
        }
        @media print {
            body {
                background: #ffffff;
                color: #000;
            }
            .no-print {
                display: none !important;
            }
            .labels-grid {
                max-width: 100%;
                margin: 0;
                gap: 12px;
            }
            .pack-card {
                page-break-inside: avoid;
                box-shadow: none;
                border: 1.5px solid #000;
            }
            .pack-header {
                border-bottom: 1px dashed #000;
            }
            .pack-portion {
                background: #f0f0f0 !important;
                color: #000 !important;
                border: 1px solid #000;
            }
        }
    </style>
</head>
<body>
    <div class="action-bar no-print">
        <a href="<?= url('/jadwal-menu/' . (int)($schedule['id'] ?? 0)) ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Kembali ke Detail Jadwal
        </a>
        <div class="d-flex gap-2">
            <button onclick="window.print()" class="btn btn-success btn-sm">
                <i class="bi bi-printer me-1"></i>Cetak Label (Stiker / Kertas)
            </button>
        </div>
    </div>

    <div class="labels-grid">
        <?php if (empty($allocations)): ?>
            <div class="col-12 p-4 text-center bg-white rounded border">
                Tidak ada data sekolah terdaftar pada jadwal ini.
            </div>
        <?php else: ?>
            <?php foreach ($allocations as $idx => $al): ?>
                <div class="pack-card">
                    <div>
                        <div class="pack-header">
                            <div>
                                <small class="text-uppercase fw-bold text-muted" style="font-size: 0.7rem; letter-spacing: 0.5px;">
                                    PROGRAM MBG · <?= e($institution) ?>
                                </small>
                                <div class="pack-school"><?= e($al['school_name']) ?></div>
                                <div class="text-muted" style="font-size: 0.75rem;"><?= e($al['school_code']) ?> · <?= e($al['level_name'] ?? 'Sekolah Mitra') ?></div>
                            </div>
                            <div class="text-end">
                                <div class="pack-portion"><?= number_format((int)$al['portions'], 0, ',', '.') ?> Porsi</div>
                            </div>
                        </div>

                        <div class="pack-menu">
                            <i class="bi bi-egg-fried me-1 text-success"></i><?= e($menuName) ?>
                        </div>

                        <div class="pack-meta">
                            <div><strong>Tanggal Saji:</strong> <?= e(tanggal_id($serviceDate)) ?> (<?= e(slot_label($mealSlot)) ?>)</div>
                            <div><strong>Batas Konsumsi:</strong> Sebaiknya dikonsumsi s/d 4 jam setelah pengantaran</div>
                            <?php if (!empty($allergens)): ?>
                                <div class="text-danger"><strong>Perhatian Alergen:</strong> <?= e($allergens) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="pack-footer">
                        <span>Ref: <?= e($schCode) ?>-<?= str_pad((string)($idx + 1), 2, '0', STR_PAD_LEFT) ?></span>
                        <span>SPPG Cangkring Higienis & Halal</span>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</body>
</html>
