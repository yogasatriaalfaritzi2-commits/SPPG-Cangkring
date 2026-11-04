<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

final class LoginAttempt extends Model
{
    protected static string $table = 'login_attempts';

    public static function failuresInWindow(string $login, string $ip, int $windowMinutes): int
    {
        $minutes = max(1, $windowMinutes);

        $row = self::fetchOne(
            'SELECT COUNT(*) AS total FROM login_attempts
             WHERE successful = 0
               AND login = :login
               AND ip_address = :ip
               AND attempted_at >= DATE_SUB(NOW(), INTERVAL ' . $minutes . ' MINUTE)',
            ['login' => $login, 'ip' => $ip]
        );

        return (int) ($row['total'] ?? 0);
    }

    public static function record(string $login, string $ip, bool $successful): void
    {
        Database::execute(
            'INSERT INTO login_attempts (login, ip_address, successful, attempted_at)
             VALUES (?, ?, ?, NOW())',
            [mb_substr($login, 0, 190), $ip, $successful ? 1 : 0]
        );

        Database::execute(
            'DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 2 DAY)'
        );
    }

    public static function clearFailures(string $login, string $ip): void
    {
        Database::execute(
            'DELETE FROM login_attempts WHERE login = ? AND ip_address = ? AND successful = 0',
            [$login, $ip]
        );
    }
}
