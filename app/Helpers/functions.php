<?php
declare(strict_types=1);

use App\Core\App;
use App\Core\Auth;
use App\Core\Csrf;

function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    return Csrf::token();
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
}

/** @return array<string, mixed>|null */
function auth_user(): ?array
{
    return Auth::user();
}

function can(string $permission): bool
{
    return Auth::hasPermission($permission);
}

/** Halaman awal pengguna saat ini, disesuaikan dengan hak akses yang dimiliki. */
function home_path(): string
{
    return Auth::check() ? Auth::homePath() : '/login';
}

function app_name(): string
{
    return (string) App::setting('app_name', App::config('app.name', 'SPPG Cangkring'));
}

function institution_name(): string
{
    return (string) App::setting(
        'institution_name',
        'Satuan Pelayanan Pemenuhan Gizi Cangkring'
    );
}

function support_email(): string
{
    return (string) App::setting('support_email', '');
}

function role_nama(array $user): string
{
    return (string) ($user['role_name'] ?? 'Pengguna');
}

function status_label(mixed $status): string
{
    return in_array($status, ['active', 1, '1'], true) ? 'Aktif' : 'Nonaktif';
}

function module_label(string $module): string
{
    return match ($module) {
        'auth' => 'Autentikasi',
        'users' => 'Pengguna',
        'roles' => 'Hak akses',
        'settings' => 'Pengaturan',
        'profile' => 'Profil',
        'audit' => 'Audit',
        'projects' => 'Proyek pembangunan',
        'progress' => 'Progres proyek',
        'documents' => 'Dokumentasi proyek',
        'team' => 'Tim proyek',
        'notifications' => 'Notifikasi',
        'dashboard' => 'Dashboard',
        'budgets' => 'RAB dan anggaran',
        'revisions' => 'Perubahan anggaran',
        'transactions' => 'Transaksi keuangan',
        'schedules' => 'Jadwal pembangunan',
        'categories' => 'Kategori pekerjaan',
        'mbg' => 'Data master MBG',
        'schools' => 'Sekolah penerima MBG',
        'beneficiaries' => 'Penerima manfaat MBG',
        'menus' => 'Menu dan jadwal MBG',
        'nutrition' => 'Bahan dan gizi MBG',
        default => $module === '' ? 'Sistem' : ucfirst($module),
    };
}

function environment_label(): string
{
    return match ((string) App::config('app.env', 'production')) {
        'local' => 'Lokal',
        'testing' => 'Pengujian',
        'production' => 'Produksi',
        default => ucfirst((string) App::config('app.env', '')),
    };
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $parts = array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));

    if ($parts === []) {
        return '?';
    }

    $first = mb_substr($parts[0], 0, 1);
    $last = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '';

    return mb_strtoupper($first . $last);
}

function tanggal_id(?string $datetime, bool $withTime = false): string
{
    if ($datetime === null || trim($datetime) === '') {
        return '—';
    }

    try {
        $date = new DateTimeImmutable($datetime);
    } catch (Throwable) {
        return '—';
    }

    $months = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
    ];

    $result = $date->format('j') . ' ' . $months[(int) $date->format('n')] . ' ' . $date->format('Y');

    if ($withTime) {
        $result .= ', ' . $date->format('H:i');
    }

    return $result;
}

/** Label periode "2026-11" menjadi "Nov 26" agar sumbu grafik tren tetap pendek. */
function periode_bulan_id(string $period): string
{
    if (preg_match('/^(\d{4})-(\d{2})$/', $period, $match) !== 1) {
        return $period;
    }

    $months = [
        1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun',
        'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des',
    ];

    $label = $months[(int) $match[2]] ?? null;

    return $label === null ? $period : $label . ' ' . substr($match[1], 2);
}

function nav_active(string ...$prefixes): string
{
    $current = App::currentPath();

    foreach ($prefixes as $prefix) {
        $prefix = '/' . trim($prefix, '/');
        if ($current === $prefix || str_starts_with($current, $prefix . '/')) {
            return 'active';
        }
    }

    return '';
}

function alert_icon(string $type): string
{
    return match ($type) {
        'success' => 'bi-check-circle',
        'warning' => 'bi-exclamation-triangle',
        'danger' => 'bi-exclamation-octagon',
        default => 'bi-info-circle',
    };
}

function rupiah(mixed $value): string
{
    return 'Rp' . number_format((float) $value, 0, ',', '.');
}

function project_status_label(mixed $status): string
{
    return match ((string) $status) {
        'planning' => 'Perencanaan',
        'preparation' => 'Persiapan',
        'running' => 'Sedang Berjalan',
        'delayed' => 'Tertunda',
        'completed' => 'Selesai',
        'cancelled' => 'Dibatalkan',
        default => '—',
    };
}

function project_status_tone(mixed $status): string
{
    return match ((string) $status) {
        'completed' => 'success',
        'running' => 'info',
        'delayed' => 'warning',
        'cancelled' => 'muted',
        default => 'muted',
    };
}

function document_category_label(mixed $category): string
{
    return match ((string) $category) {
        'initial' => 'Kondisi Awal',
        'construction' => 'Proses Pembangunan',
        'result' => 'Hasil Pembangunan',
        'supporting' => 'Dokumen Pendukung',
        'minutes' => 'Berita Acara',
        'contract' => 'Kontrak',
        'receipt' => 'Bukti Transaksi',
        default => '—',
    };
}

function notification_type_label(mixed $type): string
{
    return match ((string) $type) {
        'project.created' => 'Proyek baru',
        'project.status' => 'Status berubah',
        'project.progress' => 'Progres diperbarui',
        'project.document' => 'Dokumen baru',
        'project.due' => 'Menjelang batas waktu',
        'project.late' => 'Melewati target',
        'budget.submitted' => 'RAB menunggu persetujuan',
        'budget.decided' => 'Keputusan RAB',
        'revision.submitted' => 'Perubahan anggaran diajukan',
        'revision.decided' => 'Keputusan perubahan anggaran',
        'budget.warning' => 'Anggaran mendekati batas',
        'budget.exceeded' => 'Anggaran terlampaui',
        'transaction.submitted' => 'Transaksi menunggu persetujuan',
        'transaction.decided' => 'Keputusan transaksi',
        'transaction.cancelled' => 'Transaksi dibatalkan',
        'schedule.created' => 'Kegiatan baru',
        'schedule.due' => 'Jadwal mendekati tenggat',
        'schedule.late' => 'Jadwal terlambat',
        'schedule.changed' => 'Jadwal berubah',
        'menu.submitted' => 'Menu menunggu persetujuan',
        'menu.decided' => 'Keputusan menu',
        'menu.revision' => 'Revisi menu diajukan',
        'menu.revision.decided' => 'Keputusan revisi menu',
        'menu_schedule.submitted' => 'Jadwal menu menunggu persetujuan',
        'menu_schedule.decided' => 'Keputusan jadwal menu',
        'school.partnership' => 'Masa kerja sama sekolah',
        default => 'Umum',
    };
}

function budget_status_label(mixed $status): string
{
    return match ((string) $status) {
        'draft' => 'Draft',
        'pending' => 'Menunggu Persetujuan',
        'approved' => 'Disetujui',
        'rejected' => 'Ditolak',
        'revised' => 'Direvisi',
        default => '—',
    };
}

function budget_status_tone(mixed $status): string
{
    return match ((string) $status) {
        'approved' => 'success',
        'pending' => 'info',
        'rejected' => 'danger',
        'revised' => 'warning',
        default => 'muted',
    };
}

function transaction_type_label(mixed $type): string
{
    return match ((string) $type) {
        'expense' => 'Pengeluaran',
        'income' => 'Pemasukan / Sumber Dana',
        'refund' => 'Pengembalian Dana',
        'adjustment' => 'Penyesuaian (Koreksi)',
        default => '—',
    };
}

function transaction_status_label(mixed $status): string
{
    return match ((string) $status) {
        'draft' => 'Draft',
        'pending' => 'Menunggu Persetujuan',
        'approved' => 'Disetujui',
        'rejected' => 'Ditolak',
        'cancelled' => 'Dibatalkan',
        default => '—',
    };
}

function transaction_type_tone(mixed $type): string
{
    return match ((string) $type) {
        'expense' => 'warning',
        'income' => 'success',
        'refund' => 'info',
        'adjustment' => 'muted',
        default => 'muted',
    };
}

function transaction_status_tone(mixed $status): string
{
    return match ((string) $status) {
        'approved' => 'success',
        'pending' => 'info',
        'rejected' => 'danger',
        'cancelled' => 'muted',
        default => 'muted',
    };
}

function payment_method_label(mixed $method): string
{
    return match ((string) $method) {
        'cash' => 'Tunai',
        'transfer' => 'Transfer',
        'budget' => 'Pembayaran Anggaran',
        'other' => 'Lainnya',
        default => '—',
    };
}

function cost_center_label(mixed $center): string
{
    return match ((string) $center) {
        'construction' => 'Pembangunan',
        'operations' => 'Operasional MBG',
        default => '—',
    };
}

function schedule_status_label(mixed $status): string
{
    return match ((string) $status) {
        'not_started' => 'Belum Dimulai',
        'in_progress' => 'Sedang Berjalan',
        'delayed' => 'Tertunda',
        'completed' => 'Selesai',
        'cancelled' => 'Dibatalkan',
        default => '—',
    };
}

function schedule_status_tone(mixed $status): string
{
    return match ((string) $status) {
        'completed' => 'success',
        'in_progress' => 'info',
        'delayed' => 'warning',
        'cancelled' => 'muted',
        default => 'muted',
    };
}

function schedule_priority_label(mixed $priority): string
{
    return match ((string) $priority) {
        'low' => 'Rendah',
        'medium' => 'Sedang',
        'high' => 'Tinggi',
        'urgent' => 'Mendesak',
        default => '—',
    };
}

function dependency_type_label(mixed $type): string
{
    return match ((string) $type) {
        'FS' => 'Selesai ke Mulai',
        'SS' => 'Mulai ke Mulai',
        'FF' => 'Selesai ke Selesai',
        default => '—',
    };
}

function budget_indicator_label(mixed $indicator): string
{
    return match ((string) $indicator) {
        'safe' => 'Anggaran masih aman',
        'warning' => 'Anggaran mendekati batas',
        'exceeded' => 'Anggaran terlampaui',
        'unknown' => 'Anggaran dasar belum ditetapkan',
        default => '—',
    };
}

/** Label bidang pada riwayat jadwal; kunci sama dengan yang ditulis ProjectSchedule. */
function schedule_field_label(mixed $field): string
{
    return match ((string) $field) {
        'name' => 'Nama kegiatan',
        'description' => 'Deskripsi',
        'category_id' => 'Kategori pekerjaan',
        'start_date' => 'Tanggal mulai',
        'end_date' => 'Tanggal selesai',
        'owner_user_id' => 'Penanggung jawab',
        'owner_name' => 'Nama penanggung jawab',
        'priority' => 'Prioritas',
        'status' => 'Status pekerjaan',
        'progress' => 'Persentase progres',
        'notes' => 'Catatan',
        'dependency' => 'Ketergantungan pekerjaan',
        default => (string) $field,
    };
}

function budget_baseline_label(mixed $source): string
{
    return match ((string) $source) {
        'approved_rab' => 'Total RAB yang telah disetujui',
        'project_budget' => 'Anggaran awal proyek, belum ada RAB yang disetujui',
        default => 'Dasar anggaran belum ditetapkan',
    };
}

/**
 * Volume disimpan sebagai desimal tiga angka; tampilan membuang nol berlebih
 * agar "12.500" terbaca "12,5" seperti pada lembar RAB cetak.
 */
function volume_display(mixed $value): string
{
    $text = rtrim(rtrim(number_format((float) $value, 3, ',', '.'), '0'), ',');

    return $text === '' ? '0' : $text;
}

function budget_indicator_tone(mixed $indicator): string
{
    return match ((string) $indicator) {
        'safe' => 'success',
        'warning' => 'warning',
        'exceeded' => 'danger',
        default => 'muted',
    };
}

function percent_id(mixed $value): string
{
    return number_format((float) $value, 1, ',', '.') . '%';
}

/**
 * Menormalkan angka uang yang ditulis pengguna menjadi desimal dengan titik.
 * Pemisah ribuan (titik per tiga digit) dan koma desimal diterima, sehingga
 * "1.500.000,50" dan "1500000.50" menghasilkan nilai yang sama.
 * Mengembalikan null bila bentuknya tidak dapat dibaca sebagai angka.
 */
function amount_normalize(string $raw): ?string
{
    $value = trim(str_replace(["\xC2\xA0", ' '], '', $raw));

    $isGrouped = preg_match('/^-?\d{1,3}(?:\.\d{3})+(?:[.,]\d{1,2})?$/', $value) === 1;
    $isPlain = preg_match('/^-?\d+(?:[.,]\d{1,2})?$/', $value) === 1;

    if (!$isGrouped && !$isPlain) {
        return null;
    }

    $negative = str_starts_with($value, '-');
    $digits = ltrim($value, '-');
    $fraction = '';

    if (preg_match('/([.,])(\d{1,2})$/', $digits, $match) === 1) {
        $fraction = '.' . $match[2];
        $digits = substr($digits, 0, -strlen($match[0]));
    }

    $digits = str_replace('.', '', $digits);
    if ($digits === '') {
        $digits = '0';
    }

    return ($negative ? '-' : '') . $digits . $fraction;
}

/** Volume boleh sampai tiga angka desimal; nilai salah dikembalikan sebagai null. */
function volume_normalize(string $raw): ?string
{
    $value = trim(str_replace(["\xC2\xA0", ' '], '', $raw));
    $value = str_replace(',', '.', $value);

    if ($value === '') {
        return null;
    }

    return preg_match('/^\d{1,9}(\.\d{1,3})?$/', $value) === 1 ? $value : null;
}

/**
 * Mengubah nilai menjadi literal JSON yang sudah aman untuk atribut HTML
 * bersign dua. Tanda kutip dikodekan oleh e(), bukan oleh JSON_HEX_QUOT, karena
 * perayap HTML mengembalikan entity menjadi karakter aslinya sebelum atribut
 * dibaca JavaScript.
 */
function json_attr(mixed $value): string
{
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return e($json === false ? '[]' : $json);
}

function filesize_id(mixed $bytes): string
{
    $size = (float) $bytes;

    if ($size <= 0) {
        return '0 byte';
    }

    if ($size < 1024) {
        return number_format($size, 0, ',', '.') . ' byte';
    }

    if ($size < 1048576) {
        return number_format($size / 1024, 1, ',', '.') . ' KB';
    }

    return number_format($size / 1048576, 2, ',', '.') . ' MB';
}
