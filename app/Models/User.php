<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

final class User extends Model
{
    protected static string $table = 'users';

    public const SUPER_ADMIN_SLUG = 'super_admin';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    private const SELECT_WITH_ROLE =
        'SELECT u.*, r.role_name, r.role_slug, r.is_active AS role_active
         FROM users u
         INNER JOIN roles r ON r.id = u.role_id';

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        return self::fetchOne(self::SELECT_WITH_ROLE . ' WHERE u.id = :id LIMIT 1', ['id' => $id]);
    }

    /** @return array<string, mixed>|null */
    public static function findByLogin(string $login): ?array
    {
        return self::fetchOne(
            self::SELECT_WITH_ROLE . ' WHERE u.username = :username OR u.email = :email LIMIT 1',
            ['username' => $login, 'email' => $login]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public static function allWithRole(): array
    {
        return self::fetchAll(self::SELECT_WITH_ROLE . ' ORDER BY u.full_name ASC, u.id ASC');
    }

    /** @return array<int, array<string, mixed>> Pengguna aktif untuk pilihan penanggung jawab. */
    public static function activeList(): array
    {
        return self::fetchAll(
            'SELECT u.id, u.full_name, u.username, r.role_name, r.role_slug
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.status = :status AND r.is_active = 1
             ORDER BY u.full_name ASC, u.id ASC',
            ['status' => self::STATUS_ACTIVE]
        );
    }

    public static function countActive(): int
    {
        $row = self::fetchOne('SELECT COUNT(*) AS total FROM users WHERE status = :status', [
            'status' => self::STATUS_ACTIVE,
        ]);

        return (int) ($row['total'] ?? 0);
    }

    public static function countInactive(): int
    {
        $row = self::fetchOne('SELECT COUNT(*) AS total FROM users WHERE status = :status', [
            'status' => self::STATUS_INACTIVE,
        ]);

        return (int) ($row['total'] ?? 0);
    }

    public static function countActiveByRole(int $roleId): int
    {
        $row = self::fetchOne(
            'SELECT COUNT(*) AS total FROM users WHERE role_id = :role_id AND status = :status',
            ['role_id' => $roleId, 'status' => self::STATUS_ACTIVE]
        );

        return (int) ($row['total'] ?? 0);
    }

    public static function countActiveSuperAdmins(): int
    {
        $row = self::fetchOne(
            'SELECT COUNT(*) AS total
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE r.role_slug = :slug AND u.status = :status',
            ['slug' => self::SUPER_ADMIN_SLUG, 'status' => self::STATUS_ACTIVE]
        );

        return (int) ($row['total'] ?? 0);
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, total: int, page: int, pages: int, per_page: int}
     */
    public static function paginate(string $search, int $roleId, string $status, int $page, int $perPage = 10): array
    {
        $conditions = [];
        $params = [];

        if ($search !== '') {
            $conditions[] = '(LOCATE(:full_name, u.full_name) > 0 OR LOCATE(:username, u.username) > 0 OR LOCATE(:email, u.email) > 0)';
            $params['full_name'] = $search;
            $params['username'] = $search;
            $params['email'] = $search;
        }

        if ($roleId > 0) {
            $conditions[] = 'u.role_id = :role_id';
            $params['role_id'] = $roleId;
        }

        if (in_array($status, [self::STATUS_ACTIVE, self::STATUS_INACTIVE], true)) {
            $conditions[] = 'u.status = :status';
            $params['status'] = $status;
        }

        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

        $countRow = self::fetchOne('SELECT COUNT(*) AS total FROM users u' . $where, $params);
        $total = (int) ($countRow['total'] ?? 0);

        $perPage = max(1, min($perPage, 50));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;

        $rows = self::fetchAll(
            self::SELECT_WITH_ROLE . $where . ' ORDER BY u.full_name ASC, u.id ASC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
        ];
    }

    /**
     * Id pengguna aktif yang memegang satu permission, termasuk seluruh Super Admin
     * yang haknya memang berlaku penuh. Dipakai untuk menentukan penerima notifikasi.
     *
     * @return array<int, int>
     */
    public static function idsWithPermission(string $permissionSlug): array
    {
        $rows = self::fetchAll(
            'SELECT u.id
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             LEFT JOIN role_permissions rp ON rp.role_id = r.id
             LEFT JOIN permissions p ON p.id = rp.permission_id
             WHERE u.status = :status
               AND r.is_active = 1
               AND (r.role_slug = :super_admin OR p.permission_slug = :permission)
             GROUP BY u.id',
            [
                'status' => self::STATUS_ACTIVE,
                'super_admin' => self::SUPER_ADMIN_SLUG,
                'permission' => $permissionSlug,
            ]
        );

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    public static function usernameExists(string $username, ?int $exceptId = null): bool
    {
        return self::valueExists('username', $username, $exceptId);
    }

    public static function emailExists(string $email, ?int $exceptId = null): bool
    {
        return self::valueExists('email', $email, $exceptId);
    }

    /**
     * @param array{full_name: string, username: string, email: string, phone: ?string, password: string, role_id: int, status: string} $data
     */
    public static function create(array $data): int
    {
        Database::execute(
            'INSERT INTO users (full_name, username, email, phone, password, role_id, status, created_at, updated_at)
             VALUES (:full_name, :username, :email, :phone, :password, :role_id, :status, NOW(), NOW())',
            [
                'full_name' => $data['full_name'],
                'username' => $data['username'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'password' => password_hash($data['password'], PASSWORD_DEFAULT),
                'role_id' => $data['role_id'],
                'status' => $data['status'],
            ]
        );

        return (int) Database::lastInsertId();
    }

    /**
     * @param array{full_name: string, username: string, email: string, phone: ?string, role_id: int} $data
     */
    public static function updateProfile(int $id, array $data): void
    {
        Database::execute(
            'UPDATE users
             SET full_name = :full_name, username = :username, email = :email,
                 phone = :phone, role_id = :role_id, updated_at = NOW()
             WHERE id = :id',
            [
                'full_name' => $data['full_name'],
                'username' => $data['username'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'role_id' => $data['role_id'],
                'id' => $id,
            ]
        );
    }

    public static function updateStatus(int $id, string $status): void
    {
        Database::execute(
            'UPDATE users SET status = :status, updated_at = NOW() WHERE id = :id',
            ['status' => $status, 'id' => $id]
        );
    }

    public static function touchLogin(int $userId, string $ip): void
    {
        Database::execute(
            'UPDATE users SET last_login = NOW(), last_login_ip = :ip WHERE id = :id',
            ['ip' => $ip, 'id' => $userId]
        );
    }

    public static function updatePassword(int $userId, string $plainPassword): void
    {
        Database::execute(
            'UPDATE users SET password = :password, updated_at = NOW() WHERE id = :id',
            ['password' => password_hash($plainPassword, PASSWORD_DEFAULT), 'id' => $userId]
        );
    }

    public static function delete(int $id): void
    {
        Database::execute('DELETE FROM users WHERE id = :id', ['id' => $id]);
    }

    private static function valueExists(string $column, string $value, ?int $exceptId): bool
    {
        $sql = 'SELECT COUNT(*) AS total FROM users WHERE ' . $column . ' = :value';
        $params = ['value' => $value];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }

        $row = self::fetchOne($sql, $params);
        return (int) ($row['total'] ?? 0) > 0;
    }
}