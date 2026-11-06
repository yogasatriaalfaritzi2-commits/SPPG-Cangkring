<?php
declare(strict_types=1);

/**
 * Label modul MBG. Teks hanya menerjemahkan nilai yang tersimpan di database;
 * tidak ada angka perkiraan yang ditambahkan pada lapisan tampilan.
 */

function school_status_label(mixed $status): string
{
    return match ((string) $status) {
        'aktif' => 'Aktif',
        'nonaktif' => 'Nonaktif',
        'arsip' => 'Arsip',
        default => (string) $status,
    };
}

function school_status_tone(mixed $status): string
{
    return match ((string) $status) {
        'aktif' => 'success',
        'nonaktif' => 'warning',
        'arsip' => 'muted',
        default => 'muted',
    };
}

function partnership_label(mixed $status): string
{
    return match ((string) $status) {
        'belum_ada' => 'Belum ada kerja sama',
        'moa_aktif' => 'MoA aktif',
        'moa_akan_habis' => 'MoA akan habis',
        'ditangguhkan' => 'Ditangguhkan',
        'berakhir' => 'Berakhir',
        default => (string) $status,
    };
}

function partnership_tone(mixed $status): string
{
    return match ((string) $status) {
        'moa_aktif' => 'success',
        'moa_akan_habis', 'ditangguhkan' => 'warning',
        'berakhir' => 'danger',
        default => 'muted',
    };
}

function meal_slot_label(mixed $slot): string
{
    return match ((string) $slot) {
        'sarapan' => 'Sarapan',
        'makan_pagi' => 'Snek pagi',
        'makan_siang' => 'Makan siang',
        'makan_sore' => 'Snek sore',
        default => (string) $slot,
    };
}

function menu_status_label(mixed $status): string
{
    return match ((string) $status) {
        'draft' => 'Draf',
        'pending' => 'Menunggu ahli gizi',
        'approved' => 'Disetujui',
        'rejected' => 'Ditolak',
        'scheduled' => 'Sudah dijadwalkan',
        'done' => 'Sudah disajikan',
        'cancelled' => 'Dibatalkan',
        default => (string) $status,
    };
}

function menu_status_tone(mixed $status): string
{
    return match ((string) $status) {
        'approved', 'scheduled', 'done' => 'success',
        'pending' => 'warning',
        'rejected', 'cancelled' => 'danger',
        default => 'muted',
    };
}

function menu_approval_action_label(mixed $action): string
{
    return match ((string) $action) {
        'submit' => 'Diajukan',
        'approve' => 'Disetujui',
        'reject' => 'Ditolak',
        'revise' => 'Direvisi',
        'schedule' => 'Dijadwalkan',
        'cancel' => 'Dibatalkan',
        'complete' => 'Selesai disajikan',
        default => (string) $action,
    };
}

/** Status ketersediaan angka gizi pada menu, bahan, dan hasil analisis. */
function nutrition_status_label(mixed $status): string
{
    return match ((string) $status) {
        'lengkap' => 'Data gizi lengkap',
        'sebagian' => 'Data gizi belum lengkap',
        'belum_tersedia' => 'Data gizi belum tersedia',
        default => 'Data gizi belum tersedia',
    };
}

function nutrition_status_tone(mixed $status): string
{
    return match ((string) $status) {
        'lengkap' => 'success',
        'sebagian' => 'warning',
        default => 'muted',
    };
}

function nutrient_unit_label(mixed $unit): string
{
    return match ((string) $unit) {
        'kkal' => 'kkal',
        'gram' => 'g',
        'mg' => 'mg',
        'ug' => 'mcg',
        'persen' => '%',
        default => (string) $unit,
    };
}

function nutrient_label(mixed $code): string
{
    return \App\Models\IngredientNutrient::CORE_LABELS[(string) $code] ?? (string) $code;
}

function unit_label(mixed $unit): string
{
    return match ((string) $unit) {
        'gram' => 'gram',
        'kilogram' => 'kilogram',
        'mililiter' => 'mililiter',
        'liter' => 'liter',
        'butir' => 'butir',
        'sachet' => 'sachet',
        'pack' => 'pak',
        'pcs' => 'pcs',
        default => (string) $unit,
    };
}

function beneficiary_change_label(mixed $action): string
{
    return match ((string) $action) {
        'buat' => 'Data awal dicatat',
        'ubah' => 'Jumlah diubah',
        'nonaktifkan' => 'Dinonaktifkan',
        'aktifkan' => 'Diaktifkan kembali',
        'hapus' => 'Baris dihapus',
        default => (string) $action,
    };
}

function target_status_label(mixed $status): string
{
    return match ((string) $status) {
        'di_bawah' => 'Di bawah target',
        'sesuai' => 'Sesuai target',
        'di_atas' => 'Di atas target',
        'belum_tersedia' => 'Data gizi belum tersedia',
        'tanpa_target' => 'Belum ada target untuk kelompok ini',
        default => (string) $status,
    };
}

function target_status_tone(mixed $status): string
{
    return match ((string) $status) {
        'sesuai' => 'success',
        'di_bawah', 'di_atas' => 'warning',
        default => 'muted',
    };
}

function rule_severity_label(mixed $severity): string
{
    return match ((string) $severity) {
        'harus' => 'Wajib',
        'peringatan' => 'Peringatan',
        'info' => 'Informasi',
        default => (string) $severity,
    };
}

function rule_severity_tone(mixed $severity): string
{
    return match ((string) $severity) {
        'harus' => 'danger',
        'peringatan' => 'warning',
        default => 'muted',
    };
}

function rule_type_label(mixed $type): string
{
    return match ((string) $type) {
        'kategori_min' => 'Minimal ada bahan dari kategori',
        'kategori_max' => 'Maksimal satu bahan dari kategori',
        'gizi_min' => 'Nilai gizi minimal',
        'gizi_maks' => 'Nilai gizi maksimal',
        'variasi_min' => 'Variasi bahan mingguan',
        'biaya_maks' => 'Batas biaya per porsi',
        default => (string) $type,
    };
}

function mbg_schedule_status_label(mixed $status): string
{
    return match ((string) $status) {
        'draft' => 'Draf',
        'pending' => 'Menunggu persetujuan',
        'approved' => 'Disetujui',
        'scheduled' => 'Terjadwal',
        'done' => 'Sudah berjalan',
        'cancelled' => 'Dibatalkan',
        default => (string) $status,
    };
}

function mbg_schedule_status_tone(mixed $status): string
{
    return match ((string) $status) {
        'approved', 'scheduled', 'done' => 'success',
        'pending' => 'warning',
        'cancelled' => 'danger',
        default => 'muted',
    };
}

/** Angka bahan yang ringkas: nol di belakang dibuang, satuan ditulis lengkap. */
function quantity_display(mixed $value, string $unit = ''): string
{
    $text = rtrim(rtrim(number_format((float) $value, 3, ',', '.'), '0'), ',');
    $text = $text === '' ? '0' : $text;

    return $unit === '' ? $text : $text . ' ' . unit_label($unit);
}

function revision_status_label(mixed $status): string
{
    return match ((string) $status) {
        'pending' => 'Menunggu keputusan',
        'approved' => 'Disetujui',
        'rejected' => 'Ditolak',
        default => (string) $status,
    };
}

function revision_status_tone(mixed $status): string
{
    return match ((string) $status) {
        'approved' => 'success',
        'pending' => 'warning',
        'rejected' => 'danger',
        default => 'muted',
    };
}
