<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Session;
use App\Models\ActivityLog;

final class AuthController extends Controller
{
    public function showLogin(Request $request): void
    {
        $old = Session::pull('_old', []);

        $this->view('auth.login', [
            'title' => 'Masuk',
            'old' => is_array($old) ? $old : [],
        ], 'layouts.auth');
    }

    public function login(Request $request): void
    {
        $login = $request->string('login');
        $password = (string) $request->input('password', '');
        $ip = $request->ip();
        $agent = $request->userAgent();

        if ($login === '' || $password === '') {
            Session::flash('danger', 'Nama pengguna dan kata sandi wajib diisi.');
            Session::putOld(['login' => $login]);
            $this->redirect('/login');
        }

        $result = Auth::attempt($login, $password, $ip);

        if (!$result['ok']) {
            Session::flash('danger', $result['message']);
            Session::putOld(['login' => $login]);

            if (!$result['throttled']) {
                ActivityLog::record(
                    null,
                    'auth.login.gagal',
                    'auth',
                    'Percobaan masuk gagal untuk akun "' . mb_substr($login, 0, 60) . '".',
                    $ip,
                    $agent
                );
            }

            $this->redirect('/login');
        }

        /** @var array<string, mixed> $user */
        $user = $result['user'] ?? [];

        Session::clearOld();
        ActivityLog::record(
            isset($user['id']) ? (int) $user['id'] : null,
            'auth.login',
            'auth',
            'Masuk ke aplikasi sebagai ' . (string) ($user['role_name'] ?? 'pengguna') . '.',
            $ip,
            $agent
        );

        $intended = Session::pull('url.intended');
        $target = Auth::homePath();
        if (
            is_string($intended)
            && $intended !== ''
            && $intended[0] === '/'
            && !str_starts_with($intended, '//')
        ) {
            $target = $intended;
        }

        Session::flash('success', 'Selamat datang, ' . (string) ($user['full_name'] ?? 'Pengguna') . '.');
        $this->redirect($target);
    }

    public function logout(Request $request): void
    {
        $user = Auth::user();

        if ($user !== null) {
            ActivityLog::record(
                (int) $user['id'],
                'auth.logout',
                'auth',
                'Keluar dari aplikasi.',
                $request->ip(),
                $request->userAgent()
            );
        }

        Auth::logout();
        Session::flash('success', 'Anda telah keluar dari aplikasi.');
        $this->redirect('/login');
    }
}
