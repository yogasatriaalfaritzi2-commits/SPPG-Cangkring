<?php
declare(strict_types=1);

namespace App\Core;

use LogicException;

abstract class Model
{
    protected static string $table = '';
    protected static string $primaryKey = 'id';

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        static::guardTable();

        return static::fetchOne(
            'SELECT * FROM ' . static::$table . ' WHERE ' . static::$primaryKey . ' = :id LIMIT 1',
            ['id' => $id]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public static function all(): array
    {
        static::guardTable();

        return static::fetchAll(
            'SELECT * FROM ' . static::$table . ' ORDER BY ' . static::$primaryKey . ' ASC'
        );
    }

    public static function count(): int
    {
        static::guardTable();

        $row = static::fetchOne('SELECT COUNT(*) AS total FROM ' . static::$table);
        return (int) ($row['total'] ?? 0);
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    protected static function fetchOne(string $sql, array $params = []): ?array
    {
        $row = Database::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    protected static function fetchAll(string $sql, array $params = []): array
    {
        return Database::query($sql, $params)->fetchAll();
    }

    private static function guardTable(): void
    {
        if (static::$table === '') {
            throw new LogicException('Nama tabel model belum ditentukan.');
        }
    }
}
