<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

/**
 * Basis tabel master berformat kode dan nama (jenjang sekolah, kategori penerima
 * manfaat, kategori bahan, kategori menu). Anak cukup mendeklarasikan kolom
 * tambahannya sendiri sehingga formulir baru tidak perlu mengulang SQL yang sama.
 */
abstract class CatalogCategory extends Model
{
    protected static string $codeColumn = 'category_code';
    protected static string $nameColumn = 'category_name';

    /** @var array<string, string> kolom tambahan => string|nullable|bool|int */
    protected static array $extraColumns = [];

    /** @return array<int, array<string, mixed>> */
    public static function activeList(): array
    {
        return self::fetchAll(
            'SELECT * FROM ' . static::$table
            . ' WHERE is_active = 1 ORDER BY sort_order ASC, ' . static::$nameColumn . ' ASC'
        );
    }

    /** @return array<int, array<string, mixed>> */
    public static function allOrdered(): array
    {
        return self::fetchAll(
            'SELECT * FROM ' . static::$table . ' ORDER BY sort_order ASC, ' . static::$nameColumn . ' ASC'
        );
    }

    /**
     * Daftar beserta jumlah pemakaian tiap baris pada modul lain. Angka ini dipakai
     * tampilan dan juga untuk menolak penghapusan data yang sudah punya riwayat.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function search(string $term, string $state): array
    {
        $conditions = [];
        $params = [];

        if ($term !== '') {
            $conditions[] = '(LOCATE(:term_code, ' . static::$codeColumn . ') > 0'
                . ' OR LOCATE(:term_name, ' . static::$nameColumn . ') > 0)';
            $params['term_code'] = $term;
            $params['term_name'] = $term;
        }

        if ($state === 'active') {
            $conditions[] = 'is_active = 1';
        } elseif ($state === 'inactive') {
            $conditions[] = 'is_active = 0';
        }

        $fields = 't.*';
        foreach (static::usageSubqueries() as $alias => $sql) {
            $fields .= ', (' . $sql . ') AS ' . $alias;
        }

        return self::fetchAll(
            'SELECT ' . $fields . ' FROM ' . static::$table . ' t'
            . ($conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions))
            . ' ORDER BY sort_order ASC, ' . static::$nameColumn . ' ASC',
            $params
        );
    }

    public static function codeExists(string $code, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) AS total FROM ' . static::$table
            . ' WHERE ' . static::$codeColumn . ' = :code';
        $params = ['code' => $code];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }

        return (int) (self::fetchOne($sql, $params)['total'] ?? 0) > 0;
    }

    public static function nameExists(string $name, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) AS total FROM ' . static::$table
            . ' WHERE ' . static::$nameColumn . ' = :name';
        $params = ['name' => $name];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }

        return (int) (self::fetchOne($sql, $params)['total'] ?? 0) > 0;
    }

    /** @param array<string, mixed> $data */
    public static function create(array $data): int
    {
        $values = self::payload($data);
        $columns = '`' . static::$codeColumn . '`, `' . static::$nameColumn . '`, description, sort_order, is_active';
        $markers = ':code, :name, :description, :sort_order, :is_active';

        foreach (static::$extraColumns as $column => $type) {
            $columns .= ', `' . $column . '`';
            $markers .= ', :' . $column;
        }

        Database::execute(
            'INSERT INTO ' . static::$table . ' (' . $columns . ', created_by, updated_by, created_at, updated_at)'
            . ' VALUES (' . $markers . ', :created_by, :updated_by, NOW(), NOW())',
            $values
        );

        return (int) Database::lastInsertId();
    }

    /** @param array<string, mixed> $data */
    public static function update(int $id, array $data): void
    {
        $values = self::payload($data);
        $assignments = '`' . static::$codeColumn . '` = :code, `' . static::$nameColumn . '` = :name,'
            . ' description = :description, sort_order = :sort_order, is_active = :is_active';

        foreach (static::$extraColumns as $column => $type) {
            $assignments .= ', `' . $column . '` = :' . $column;
        }

        Database::execute(
            'UPDATE ' . static::$table . ' SET ' . $assignments
            . ', updated_by = :updated_by, updated_at = NOW() WHERE id = :id',
            $values + ['id' => $id]
        );
    }

    /** Jumlah baris pada modul lain yang masih menunjuk kategori ini. */
    public static function usageCount(int $id): int
    {
        $subqueries = static::usageSubqueries();
        if ($subqueries === []) {
            return 0;
        }

        $sql = 'SELECT ' . implode(', ', array_map(
            static fn (string $alias, string $body): string => '(' . $body . ') AS `' . $alias . '`',
            array_keys($subqueries),
            array_values($subqueries)
        )) . ' FROM ' . static::$table . ' t WHERE t.id = :id';

        $row = self::fetchOne($sql, ['id' => $id]) ?? [];
        $total = 0;
        foreach (array_keys($subqueries) as $alias) {
            $total += (int) ($row[$alias] ?? 0);
        }

        return $total;
    }

    public static function delete(int $id): void
    {
        Database::execute('DELETE FROM ' . static::$table . ' WHERE id = :id', ['id' => $id]);
    }

    /**
     * Subquery skalar yang menghitung pemakaian. Didefinisikan anak sebagai
     * alias => badan SELECT COUNT(*).
     *
     * @return array<string, string>
     */
    protected static function usageSubqueries(): array
    {
        return [];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private static function payload(array $data): array
    {
        $values = [
            'code' => (string) ($data[static::$codeColumn] ?? ''),
            'name' => (string) ($data[static::$nameColumn] ?? ''),
            'description' => ($data['description'] ?? null) === null || $data['description'] === ''
                ? null
                : (string) $data['description'],
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active' => !empty($data['is_active']) ? 1 : 0,
            'created_by' => $data['created_by'] ?? null,
            'updated_by' => $data['updated_by'] ?? null,
        ];

        foreach (static::$extraColumns as $column => $type) {
            $raw = $data[$column] ?? null;
            $values[$column] = match ($type) {
                'bool' => !empty($raw) ? 1 : 0,
                'int' => (int) $raw,
                'nullable' => $raw === null || $raw === '' ? null : (string) $raw,
                default => (string) $raw,
            };
        }

        return $values;
    }
}
