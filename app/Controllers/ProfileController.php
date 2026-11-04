<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Session;
use App\Models\ActivityLog;
use App\Models\Permission;
use App\Models\User;

final class ProfileController extends Controller
{
    public function show(Request $request): void
    {
        $user = Auth::user();
        if ($user === null) {
            $this->redirect('/login');
        }

        $this->view('profile.show', [
            'title' => 'Profil Saya',
            'pageTitle' => 'Profil Saya',
            'user' => $user,
            'permissionCount' => count(Auth::permissions()),
            'permissionTotal' => Permission::count(),
        ]);
    }

    public function updatePassword(Request $request): void
    {
        $user = Auth::user();
        if ($user === null) {
            $this->redirect('/login');
        }

        $currentPassword = (string) $request->input('current_password', '');
        $newPassword = (string) $request->input('new_password', '');
        $confirmation = (string) $request->input('new_password_confirmation', '');

        if ($currentPassword === '' || $newPassword === '' || $confirmation === '') {
            $this->fail('Semua kolom kata sandi wajib diisi.');
        }

        if (!password_verify($currentPassword, (string) $user['password'])) {
            $this->fail('Kata sandi saat ini tidak sesuai.');
        }

        if ($newPassword !== $confirmation) {
            $this->fail('Konfirmasi kata sandi baru tidak cocok.');
        }

        $errors = password_policy_errors($newPassword, 'Kata sandi baru');
        if ($errors !== []) {
            $this->fail($errors[0]);
        }

        if ($newPassword === $currentPassword) {
            $this->fail('Kata sandi baru harus berbeda dari kata sandi saat ini.');
        }

        User::updatePassword((int) $user['id'], $newPassword);
        ActivityLog::record(
            (int) $user['id'],
            'profile.password',
            'profile',
            'Mengubah kata sandi akun sendiri.',
            $request->ip(),
            $request->userAgent()
        );

        Session::regenerate();
        Session::flash('success', 'Kata sandi berhasil diperbarui.');
        $this->redirect('/profil');
    }

    private function fail(string $message): never
    {
        Session::flash('danger', $message);
        $this->redirect('/profil');
    }
}
