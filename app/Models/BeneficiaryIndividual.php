<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

/**
 * Data individual opsional. Kolom sengaja dibatasi pada nama, kelompok, dan tahun lahir:
 * tidak ada NIK, nomor Kartu Keluarga, alamat rumah, atau data medis. Halaman ini hanya
 * terbuka bagi pengguna dengan izin beneficiaries.individual.view yang diperiksa di server.
 */
final class BeneficiaryIndividual extends Model
{
    protected static string $table = 'beneficiary_individuals';

    public const STATUS_ACTIVE = 'aktif';
    public const STATUS_INACTIVE = 'nonaktif';

    /** @return array<int, array<string, mixed>> */
    public static function search(
        int $schoolId,
        string $term,
        string $classGroup,
        string $status,
        int $page = 1,
        int $perPage = 20
    ): array {
        $conditions = ['i.school_id = :school_id'];
        $params = ['school_id' => $schoolId];

        if ($term !== '') {
            $conditions[] = 'LOCATE(:term, i.full_name) > 0';
            $params['term'] = $term;
        }

        if ($classGroup !== '') {
            $conditions[] = 'i.class_group = :class_group';
            $params['class_group'] = $classGroup;
        }

        if (in_array($status, [self::STATUS_ACTIVE, self::STATUS_INACTIVE], true)) {
            $conditions[] = 'i.record_status = :status';
            $params['status'] = $status;
        }

        $where = ' WHERE ' . implode(' AND ', $conditions);

        $total = (int) (self::fetchOne(
            'SELECT COUNT(*) AS total FROM beneficiary_individuals i' . $where,
            $params
        )['total'] ?? 0);

        $perPage = max(1, min($perPage, 100));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));

        $rows = self::fetchAll(
            'SELECT i.*, c.category_name
             FROM beneficiary_individuals i
             INNER JOIN beneficiary_categories c ON c.id = i.category_id'
            . $where
            . ' ORDER BY c.sort_order ASC, i.class_group ASC, i.full_name ASC'
            . ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
            $params
        );

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => $perPage];
    }

    public static function scopeExists(int $schoolId, int $categoryId, string $classGroup, string $name, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) AS total FROM beneficiary_individuals
                WHERE school_id = :school_id AND category_id = :category_id
                  AND class_group = :class_group AND full_name = :name';
        $params = [
            'school_id' => $schoolId,
            'category_id' => $categoryId,
            'class_group' => $classGroup,
            'name' => $name,
        ];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }

        return (int) (self::fetchOne($sql, $params)['total'] ?? 0) > 0;
    }

    /** @param array<string, mixed> $data */
    public static function create(array $data): int
    {
        Database::execute(
            'INSERT INTO beneficiary_individuals (
                school_id, category_id, class_group, full_name, birth_year, record_status, notes,
                created_by, updated_by, created_at, updated_at
             ) VALUES (
                :school_id, :category_id, :class_group, :full_name, :birth_year, :record_status, :notes,
                :created_by, :updated_by, NOW(), NOW()
             )',
            self::values($data) + [
                'created_by' => $data['created_by'] ?? null,
                'updated_by' => $data['updated_by'] ?? null,
            ]
        );

        return (int) Database::lastInsertId();
    }

    /** @param array<string, mixed> $data */
    public static function update(int $id, array $data): void
    {
        $values = self::values($data);
        unset($values['record_status'], $values['created_by']);

        Database::execute(
            'UPDATE beneficiary_individuals SET
                school_id = :school_id, category_id = :category_id, class_group = :class_group,
                full_name = :full_name, birth_year = :birth_year, notes = :notes,
                updated_by = :updated_by, updated_at = NOW()
             WHERE id = :id',
            $values + ['id' => $id, 'updated_by' => $data['updated_by'] ?? null]
        );
    }

    public static function setStatus(int $id, string $status, int $actorId): void
    {
        Database::execute(
            'UPDATE beneficiary_individuals
             SET record_status = :status, updated_by = :updated_by, updated_at = NOW()
             WHERE id = :id',
            ['status' => $status, 'updated_by' => $actorId, 'id' => $id]
        );
    }

    public static function delete(int $id): void
    {
        Database::execute('DELETE FROM beneficiary_individuals WHERE id = :id', ['id' => $id]);
    }

    /** Jumlah nama terdaftar dibandingkan jumlah rekap, sebagai pemeriksaan silang. */
    public static function countFor(int $schoolId, int $categoryId, string $classGroup = ''): int
    {
        $sql = 'SELECT COUNT(*) AS total FROM beneficiary_individuals
                WHERE school_id = :school_id AND category_id = :category_id
                  AND record_status = \'aktif\'';
        $params = ['school_id' => $schoolId, 'category_id' => $categoryId];

        if ($classGroup !== '') {
            $sql .= ' AND class_group = :class_group';
            $params['class_group'] = $classGroup;
        }

        return (int) (self::fetchOne($sql, $params)['total'] ?? 0);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private static function values(array $data): array
    {
        $birthYear = $data['birth_year'] ?? '';

        return [
            'school_id' => (int) $data['school_id'],
            'category_id' => (int) $data['category_id'],
            'class_group' => mb_substr((string) ($data['class_group'] ?? ''), 0, 60),
            'full_name' => (string) $data['full_name'],
            'birth_year' => $birthYear === '' || $birthYear === null ? null : (int) $birthYear,
            'record_status' => (string) ($data['record_status'] ?? self::STATUS_ACTIVE),
            'notes' => ($data['notes'] ?? '') === '' ? null : mb_substr((string) $data['notes'], 0, 255),
        ];
    }
}
