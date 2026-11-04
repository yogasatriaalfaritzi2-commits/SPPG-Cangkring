<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class Permission extends Model
{
    protected static string $table = 'permissions';

    /** @return array<int, array<string, mixed>> */
    public static function allOrdered(): array
    {
        return self::fetchAll('SELECT * FROM permissions ORDER BY module_name ASC, permission_name ASC, id ASC');
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    public static function groupedByModule(): array
    {
        $grouped = [];

        foreach (self::allOrdered() as $permission) {
            $grouped[(string) $permission['module_name']][] = $permission;
        }

        return $grouped;
    }

    /** @return array<int, string> */
    public static function slugs(): array
    {
        $rows = self::fetchAll('SELECT permission_slug FROM permissions ORDER BY permission_slug ASC');

        return array_map(static fn (array $row): string => (string) $row['permission_slug'], $rows);
    }

    /** @return array<int, int> */
    public static function ids(): array
    {
        $rows = self::fetchAll('SELECT id FROM permissions');

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    public static function idBySlug(string $slug): ?int
    {
        $row = self::fetchOne(
            'SELECT id FROM permissions WHERE permission_slug = :slug LIMIT 1',
            ['slug' => $slug]
        );

        return $row === null ? null : (int) $row['id'];
    }

    /** @return array<int, string> */
    public static function slugsForRole(int $roleId): array
    {
        $rows = self::fetchAll(
            'SELECT p.permission_slug
             FROM permissions p
             INNER JOIN role_permissions rp ON rp.permission_id = p.id
             WHERE rp.role_id = :role_id
             ORDER BY p.permission_slug ASC',
            ['role_id' => $roleId]
        );

        return array_map(static fn (array $row): string => (string) $row['permission_slug'], $rows);
    }
}