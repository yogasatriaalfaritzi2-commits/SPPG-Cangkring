<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Session;
use App\Models\ActivityLog;
use App\Models\BudgetSummary;
use App\Models\Setting;

final class SettingsController extends Controller
{
    private const TIMEZONES = [
        'Asia/Jakarta' => 'WIB (Asia/Jakarta)',
        'Asia/Makassar' => 'WITA (Asia/Makassar)',
        'Asia/Jayapura' => 'WIT (Asia/Jayapura)',
    ];

    public function index(Request $request): void
    {
        $old = Session::pull('_old', []);
        $old = is_array($old) ? $old : [];
        $stored = App::settings();

        $values = [
            'app_name' => (string) ($old['app_name'] ?? $stored['app_name'] ?? App::config('app.name', 'SPPG Cangkring')),
            'institution_name' => (string) ($old['institution_name'] ?? $stored['institution_name'] ?? institution_name()),
            'timezone' => (string) ($old['timezone'] ?? $stored['timezone'] ?? 'Asia/Jakarta'),
            'support_email' => (string) ($old['support_email'] ?? $stored['support_email'] ?? ''),
            'budget_warning_percent' => (string) ($old['budget_warning_percent']
                ?? $stored['budget_warning_percent'] ?? (string) BudgetSummary::warningPercent()),
            'schedule_due_days' => (string) ($old['schedule_due_days']
                ?? $stored['schedule_due_days'] ?? (string) BudgetSummary::dueSoonDays()),
            'finance_segregation_of_duties' => (string) ($old['finance_segregation_of_duties']
                ?? $stored['finance_segregation_of_duties'] ?? (BudgetSummary::segregationEnabled() ? '1' : '0')),
        ];

        $this->view('settings.index', [
            'title' => 'Pengaturan',
            'pageTitle' => 'Pengaturan',
            'values' => $values,
            'timezones' => self::TIMEZONES,
            'lastUpdate' => Setting::lastUpdate(),
        ]);
    }

    /**
     * Menyimpan ambang anggaran, batas tenggat jadwal, dan kebijakan pemisahan tugas.
     * Nilai ini dibaca ulang pada setiap permintaan, jadi perubahan berlaku tanpa
     * mengubah kode aplikasi.
     */
    public function updateFinance(Request $request): void
    {
        $warning = $request->string('budget_warning_percent');
        $dueDays = $request->string('schedule_due_days');
        $segregation = $request->string('finance_segregation_of_duties') === '1' ? '1' : '0';

        $old = [
            'budget_warning_percent' => $warning,
            'schedule_due_days' => $dueDays,
            'finance_segregation_of_duties' => $segregation,
        ];

        $errors = [];

        if (preg_match('/^\d{1,3}$/', $warning) !== 1 || (int) $warning < 1 || (int) $warning > 100) {
            $errors[] = 'Ambang peringatan penyerapan harus angka bulat 1 sampai 100.';
        }

        if (preg_match('/^\d{1,3}$/', $dueDays) !== 1 || (int) $dueDays < 1 || (int) $dueDays > 90) {
            $errors[] = 'Batas pengingat tenggat harus angka bulat 1 sampai 90 hari.';
        }

        if ($errors !== []) {
            Session::flash('danger', $errors[0]);
            Session::putOld($old);
            $this->redirect('/pengaturan');
        }

        $user = Auth::user();
        $userId = $user !== null ? (int) $user['id'] : null;

        Database::transaction(static function () use ($warning, $dueDays, $segregation, $userId): void {
            Setting::put('budget_warning_percent', (string) (int) $warning, $userId);
            Setting::put('schedule_due_days', (string) (int) $dueDays, $userId);
            Setting::put('finance_segregation_of_duties', $segregation, $userId);
        });

        ActivityLog::record(
            $userId,
            'settings.update',
            'settings',
            'Memperbarui ambang anggaran (' . $warning . '%), batas tenggat jadwal (' . $dueDays
                . ' hari), dan pemisahan tugas (' . ($segregation === '1' ? 'aktif' : 'nonaktif'). ').',
            $request->ip(),
            $request->userAgent()
        );

        Session::clearOld();
        Session::flash('success', 'Pengaturan keuangan dan jadwal berhasil disimpan.');
        $this->redirect('/pengaturan');
    }

    public function update(Request $request): void
    {
        $appName = $request->string('app_name');
        $institution = $request->string('institution_name');
        $timezone = $request->string('timezone');
        $email = $request->string('support_email');

        $old = [
            'app_name' => $appName,
            'institution_name' => $institution,
            'timezone' => $timezone,
            'support_email' => $email,
        ];

        $errors = [];

        if (mb_strlen($appName) < 2 || mb_strlen($appName) > 60) {
            $errors[] = 'Nama aplikasi harus terdiri dari 2 sampai 60 karakter.';
        }

        if (mb_strlen($institution) < 3 || mb_strlen($institution) > 120) {
            $errors[] = 'Nama instansi harus terdiri dari 3 sampai 120 karakter.';
        }

        if (!array_key_exists($timezone, self::TIMEZONES)) {
            $errors[] = 'Zona waktu yang dipilih tidak dikenali.';
        }

        if ($email !== '' && (mb_strlen($email) > 160 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            $errors[] = 'Alamat surel dukungan tidak valid.';
        }

        if ($errors !== []) {
            Session::flash('danger', $errors[0]);
            Session::putOld($old);
            $this->redirect('/pengaturan');
        }

        $user = Auth::user();
        $userId = $user !== null ? (int) $user['id'] : null;

        Database::transaction(static function () use ($appName, $institution, $timezone, $email, $userId): void {
            Setting::put('app_name', $appName, $userId);
            Setting::put('institution_name', $institution, $userId);
            Setting::put('timezone', $timezone, $userId);
            Setting::put('support_email', $email, $userId);
        });

        ActivityLog::record(
            $userId,
            'settings.update',
            'settings',
            'Memperbarui pengaturan aplikasi.',
            $request->ip(),
            $request->userAgent()
        );

        Session::clearOld();
        Session::flash('success', 'Pengaturan berhasil disimpan.');
        $this->redirect('/pengaturan');
    }
}
