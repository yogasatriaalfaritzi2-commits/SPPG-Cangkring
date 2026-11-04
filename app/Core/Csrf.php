<?php
declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\HttpException;

final class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    public static function token(): string
    {
        $token = Session::get(self::SESSION_KEY);
        if (!is_string($token) || strlen($token) < 40) {
            $token = bin2hex(random_bytes(32));
            Session::set(self::SESSION_KEY, $token);
        }

        return $token;
    }

    public static function rotate(): void
    {
        Session::forget(self::SESSION_KEY);
    }

    public static function verify(Request $request): void
    {
        if (!in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }

        $token = $request->input('_token');
        if (!is_string($token) || $token === '') {
            $token = (string) ($request->header('X-CSRF-TOKEN') ?? '');
        }

        if ($token === '' || !hash_equals(self::token(), $token)) {
            throw new HttpException(
                400,
                'Sesi formulir telah kedaluwarsa. Muat ulang halaman lalu coba lagi.'
            );
        }
    }
}
