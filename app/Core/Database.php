<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

final class Database
{
    private static ?PDO $pdo = null;

    /** @var array<string, mixed> */
    private static array $config = [];

    /** @param array<string, mixed> $config */
    public static function configure(array $config): void
    {
        self::$config = $config;
        self::$pdo = null;
    }

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        if (self::$config === []) {
            throw new RuntimeException('Konfigurasi database belum dimuat.');
        }

        $dsn = sprintf(
            '%s:host=%s;port=%d;dbname=%s;charset=%s',
            (string) (self::$config['driver'] ?? 'mysql'),
            (string) self::$config['host'],
            (int) self::$config['port'],
            (string) self::$config['database'],
            (string) self::$config['charset']
        );

        try {
            self::$pdo = new PDO(
                $dsn,
                (string) self::$config['username'],
                (string) self::$config['password'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_STRINGIFY_FETCHES => false,
                ]
            );
        } catch (PDOException $exception) {
            throw new RuntimeException(
                'Koneksi database gagal. Periksa konfigurasi pada file .env.',
                0,
                $exception
            );
        }

        return self::$pdo;
    }

    /** @param array<int|string, mixed> $params */
    public static function query(string $sql, array $params = []): PDOStatement
    {
        $statement = self::connection()->prepare($sql);
        $statement->execute($params);
        return $statement;
    }

    /** @param array<int|string, mixed> $params */
    public static function execute(string $sql, array $params = []): int
    {
        return self::query($sql, $params)->rowCount();
    }

    public static function lastInsertId(): string
    {
        return self::connection()->lastInsertId();
    }

    /**
     * Nomor otomatis dialokasikan dengan memindai baris yang sudah ada. Dua
     * pengguna yang menyimpan bersamaan berada pada snapshot yang sama dan bisa
     * memilih nomor yang sama, sehingga index UNIQUE menolak salah satunya.
     * Ulangi seluruh pekerjaan dengan transaksi baru agar alokasi berikutnya
     * melihat baris yang baru saja berkommit.
     */
    public static function transaction(callable $callback): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            $pdo = self::connection();
            $pdo->beginTransaction();

            try {
                $result = $callback();
                $pdo->commit();

                return $result;
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                if ($attempt >= 4 || !self::isCodeCollision($exception)) {
                    throw $exception;
                }
            }
        }
    }

    private static function isCodeCollision(Throwable $exception): bool
    {
        return $exception instanceof PDOException
            && (string) $exception->getCode() === '23000'
            && preg_match("/for key 'uq_[a-z_]*code'/i", $exception->getMessage()) === 1;
    }
}
