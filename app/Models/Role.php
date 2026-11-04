<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

final class Role extends Model
{
    protected static string $table = 'roles';

    public const SUPER_ADMIN_SLUG = 'super_admin';

    /** @return array<int, array<string, mixed>> */
    public static function allWithCounts(): array
    {
        return self::fetchAll(
            'SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS user_count
             FROM roles r
             ORDER BY r.id ASC'
        );
    }

    /** @return array<int, array<string, mixed>> */
    public static function active(): array
    {
        return self::fetchAll('SELECT * FROM roles WHERE is_active = 1 ORDER BY role_name ASC');
    }

    /** @return array<string, mixed>|null */
    public static function findBySlug(string $slug): ?array
    {
        return self::fetchOne('SELECT * FROM roles WHERE role_slug = :slug LIMIT 1', ['slug' => $slug]);
    }

    public static function countUsers(int $roleId): int
    {
        $row = self::fetchOne('SELECT COUNT(*) AS total FROM users WHERE role_id = :id', ['id' => $roleId]);
        return (int) ($row['total'] ?? 0);
    }

    public static function slugExists(string $slug, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) AS total FROM roles WHERE role_slug = :slug';
        $params = ['slug' => $slug];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }

        $row = self::fetchOne($sql, $params);
        return (int) ($row['total'] ?? 0) > 0;
    }

    public static function nameExists(string $name, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) AS total FROM roles WHERE role_name = :name';
        $params = ['name' => $name];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }

        $row = self::fetchOne($sql, $params);
        return (int) ($row['total'] ?? 0) > 0;
    }

    public static function create(string $name, string $slug, string $description): int
    {
        Database::execute(
            'INSERT INTO roles (role_name, role_slug, description, is_active, created_at, updated_at)
             VALUES (:name, :slug, :description, 1, NOW(), NOW())',
            ['name' => $name, 'slug' => $slug, 'description' => $description]
        );

        return (int) Database::lastInsertId();
    }

    public static function update(int $id, string $name, string $description, bool $active): void
    {
        Database::execute(
            'UPDATE roles SET role_name = :name, description = :description, is_active = :active, updated_at = NOW()
             WHERE id = :id',
            [
                'name' => $name,
                'description' => $description,
                'active' => $active ? 1 : 0,
                'id' => $id,
            ]
        );
    }

    /** @return array<int, int> */
    public static function permissionIds(int $roleId): array
    {
        $rows = self::fetchAll(
            'SELECT permission_id FROM role_permissions WHERE role_id = :id',
            ['id' => $roleId]
        );

        return array_map(static fn (array $row): int => (int) $row['permission_id'], $rows);
    }

    /**
     * Mengganti seluruh izin peran dengan daftar izin yang diberikan.
     *
     * @param array<int, int> $permissionIds
     */
    public static function syncPermissions(int $roleId, array $permissionIds): void
    {
        $permissionIds = array_values(array_unique(array_map('intval', $permissionIds)));

        Database::transaction(static function () use ($roleId, $permissionIds): void {
            Database::execute('DELETE FROM role_permissions WHERE role_id = :role_id', ['role_id' => $roleId]);

            foreach ($permissionIds as $permissionId) {
                if ($permissionId <= 0) {
                    continue;
                }
                Database::execute(
                    'INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (:role_id, :permission_id)',
                    ['role_id' => $roleId, 'permission_id' => $permissionId]
                );
            }
        });
    }

    /** @return array<int, array{role_id: int, permission_id: int}> */
    public static function allPermissionMappings(): array
    {
        $rows = self::fetchAll('SELECT role_id, permission_id FROM role_permissions');

        return array_map(static fn (array $row): array => [
            'role_id' => (int) $row['role_id'],
            'permission_id' => (int) $row['permission_id'],
        ], $rows);
    }
}