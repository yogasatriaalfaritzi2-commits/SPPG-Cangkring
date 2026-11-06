<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;
use RuntimeException;

/**
 * Rekap jumlah penerima manfaat MBG. Mode utama aplikasi adalah jumlah per sekolah,
 * kategori, dan kelompok kelas pada suatu tanggal berlaku, sehingga identitas pribadi
 * siswa tidak perlu disimpan.
 *
 * Baris lama tidak dihapus saat jumlah berubah: kombinasi (sekolah, kategori, kelompok,
 * tanggal berlaku) bersifat unik, jadi tanggal baru membuat baris baru dan setiap
 * perubahan selalu disertai alasan pada tabel riwayat.
 */
final class Beneficiary extends Model
{
    protected static string $table = 'beneficiaries';

    public const STATUS_ACTIVE = 'aktif';
    public const STATUS_INACTIVE = 'nonaktif';

    /** Gabungan yang menahan hanya baris terakhir per kelompok pada tanggal tertentu. */
    private const LATEST_JOIN =
        ' INNER JOIN (
             SELECT school_id, category_id, class_group, MAX(effective_date) AS effective_date
             FROM beneficiaries
             WHERE effective_date <= :asof_a
             GROUP BY school_id, category_id, class_group
           ) x ON x.school_id = b.school_id
              AND x.category_id = b.category_id
              AND x.class_group = b.class_group
              AND x.effective_date = b.effective_date';

    /** @return array<string, mixed>|null */
    public static function findDetail(int $id): ?array
    {
        return self::fetchOne(
            'SELECT b.*, s.school_name, s.school_code, c.category_name, u.full_name AS creator_name
             FROM beneficiaries b
             INNER JOIN schools s ON s.id = b.school_id
             INNER JOIN beneficiary_categories c ON c.id = b.category_id
             LEFT JOIN users u ON u.id = b.created_by
             WHERE b.id = :id LIMIT 1',
            ['id' => $id]
        );
    }

    /**
     * Rekap jumlah yang berlaku pada satu tanggal. Setiap kelompok kelas hanya
     * menyumbang satu baris sehingga riwayat tidak menyebabkan penghitungan ganda.
     *
     * @param array{asof: string, school: string, category: string, search: string} $filters
     * @return array<int, array<string, mixed>>
     */
    public static function rekap(array $filters): array
    {
        $conditions = ['b.record_status = \'aktif\''];
        $params = ['asof_a' => $filters['asof']];

        if ($filters['school'] !== '' && ctype_digit($filters['school'])) {
            $conditions[] = 'b.school_id = :school_id';
            $params['school_id'] = $filters['school'];
        }

        if ($filters['category'] !== '' && ctype_digit($filters['category'])) {
            $conditions[] = 'b.category_id = :category_id';
            $params['category_id'] = $filters['category'];
        }

        if ($filters['search'] !== '') {
            $conditions[] = '(LOCATE(:search_name, s.school_name) > 0 OR LOCATE(:search_class, b.class_group) > 0)';
            $params['search_name'] = $filters['search'];
            $params['search_class'] = $filters['search'];
        }

        return self::fetchAll(
            'SELECT b.school_id, b.category_id, b.class_group, b.beneficiary_count, b.effective_date,
                    b.notes, s.school_name, s.school_code, s.level_id, l.level_name, c.category_name
             FROM beneficiaries b' . self::LATEST_JOIN . '
             INNER JOIN schools s ON s.id = b.school_id
             INNER JOIN school_levels l ON l.id = s.level_id
             INNER JOIN beneficiary_categories c ON c.id = b.category_id
             WHERE ' . implode(' AND ', $conditions) . '
             ORDER BY s.school_name ASC, c.sort_order ASC, b.class_group ASC',
            $params
        );
    }

    /**
     * Rekap padat: satu baris per sekolah dengan jumlah per kategori sebagai kolom.
     * Dipakai pada halaman utama penerima manfaat dan sebagai dasar rencana porsi.
     *
     * @param array<string, string> $filters
     * @return array{categories: array<int, array<string, mixed>>, rows: array<int, array<string, mixed>>, total: int}
     */
    public static function rekapBySchool(array $filters): array
    {
        $categories = self::fetchAll(
            'SELECT id, category_code, category_name, sort_order
             FROM beneficiary_categories WHERE is_active = 1 ORDER BY sort_order ASC, category_name ASC'
        );

        $rows = self::rekap(array_merge(['search' => '', 'category' => ''], $filters));

        $bySchool = [];
        foreach ($rows as $row) {
            $schoolId = (int) $row['school_id'];
            if (!isset($bySchool[$schoolId])) {
                $bySchool[$schoolId] = [
                    'school_id' => $schoolId,
                    'school_name' => $row['school_name'],
                    'school_code' => $row['school_code'],
                    'level_name' => $row['level_name'],
                    'counts' => [],
                    'total' => 0,
                ];
            }
            $count = (int) $row['beneficiary_count'];
            $categoryId = (int) $row['category_id'];
            $bySchool[$schoolId]['counts'][$categoryId] = ($bySchool[$schoolId]['counts'][$categoryId] ?? 0) + $count;
            $bySchool[$schoolId]['total'] += $count;
        }

        foreach ($bySchool as $index => $school) {
            $school['counts'] = array_map(
                static fn (array $category): int => $school['counts'][(int) $category['id']] ?? 0,
                $categories
            );
            $bySchool[$index] = $school;
        }

        usort($bySchool, static fn (array $a, array $b): int => [$a['school_name'], $a['school_id']]
            <=> [$b['school_name'], $b['school_id']]);

        return [
            'categories' => $categories,
            'rows' => $bySchool,
            'total' => array_sum(array_column($bySchool, 'total')),
        ];
    }

    /** Total penerima manfaat aktif pada satu tanggal, opsional per sekolah. */
    public static function totalAt(string $asof, ?int $schoolId = null): int
    {
        $sql = 'SELECT COALESCE(SUM(b.beneficiary_count), 0) AS total FROM beneficiaries b'
            . self::LATEST_JOIN
            . ' WHERE b.record_status = \'aktif\'';
        $params = ['asof_a' => $asof];

        if ($schoolId !== null) {
            $sql .= ' AND b.school_id = :school_id';
            $params['school_id'] = $schoolId;
        }

        return (int) (self::fetchOne($sql, $params)['total'] ?? 0);
    }

    /** Baris terakhir untuk satu kelompok, dipakai sebagai nilai sebelum perubahan. */
    /** @return array<string, mixed>|null */
    public static function latestRow(int $schoolId, int $categoryId, string $classGroup, string $asof): ?array
    {
        return self::fetchOne(
            'SELECT * FROM beneficiaries
             WHERE school_id = :school_id AND category_id = :category_id
               AND class_group = :class_group AND effective_date <= :asof
             ORDER BY effective_date DESC, id DESC
             LIMIT 1',
            ['school_id' => $schoolId, 'category_id' => $categoryId, 'class_group' => $classGroup, 'asof' => $asof]
        );
    }

    public static function rowExists(int $schoolId, int $categoryId, string $classGroup, string $effectiveDate, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) AS total FROM beneficiaries
                WHERE school_id = :school_id AND category_id = :category_id
                  AND class_group = :class_group AND effective_date = :effective_date';
        $params = [
            'school_id' => $schoolId,
            'category_id' => $categoryId,
            'class_group' => $classGroup,
            'effective_date' => $effectiveDate,
        ];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }

        return (int) (self::fetchOne($sql, $params)['total'] ?? 0) > 0;
    }

    /**
     * Simpan jumlah untuk satu kelompok pada satu tanggal berlaku. Baris dengan tanggal
     * yang sama diperbarui dan perubahan sebelumnya tetap tercatat pada riwayat, sedangkan
     * tanggal baru membuat baris baru sehingga tren jumlah dapat direkonstruksi.
     *
     * @return array{0: int, 1: string} id baris dan aksi riwayat
     */
    public static function record(
        int $schoolId,
        int $categoryId,
        string $classGroup,
        string $effectiveDate,
        int $count,
        string $reason,
        int $actorId,
        ?string $notes = null
    ): array {
        // Kolom jumlah bertipe unsigned dan mode sql server ini tidak strict, sehingga
        // nilai negatif akan diam-diam dipangkas menjadi nol. Ditolak di sini agar
        // jumlahnya tidak pernah berubah tanpa diketahui pengguna.
        if ($count < 0) {
            throw new RuntimeException('Jumlah penerima tidak boleh negatif.');
        }

        return Database::transaction(static function () use (
            $schoolId,
            $categoryId,
            $classGroup,
            $effectiveDate,
            $count,
            $reason,
            $actorId,
            $notes
        ): array {
            $existing = self::fetchOne(
                'SELECT * FROM beneficiaries
                 WHERE school_id = :school_id AND category_id = :category_id
                   AND class_group = :class_group AND effective_date = :effective_date
                 LIMIT 1',
                [
                    'school_id' => $schoolId,
                    'category_id' => $categoryId,
                    'class_group' => $classGroup,
                    'effective_date' => $effectiveDate,
                ]
            );

            $previous = self::latestRow($schoolId, $categoryId, $classGroup, $effectiveDate);

            if ($existing !== null) {
                Database::execute(
                    'UPDATE beneficiaries
                     SET beneficiary_count = :count, notes = :notes,
                         updated_by = :updated_by, updated_at = NOW()
                     WHERE id = :id',
                    [
                        'count' => $count,
                        'notes' => $notes === null || $notes === '' ? null : mb_substr($notes, 0, 500),
                        'updated_by' => $actorId,
                        'id' => (int) $existing['id'],
                    ]
                );
                $id = (int) $existing['id'];
                $action = 'ubah';
                $previousCount = (int) $existing['beneficiary_count'];
                $previousDate = (string) $existing['effective_date'];
            } else {
                Database::execute(
                    'INSERT INTO beneficiaries (
                        school_id, category_id, class_group, beneficiary_count, effective_date,
                        record_status, notes, created_by, updated_by, created_at, updated_at
                     ) VALUES (
                        :school_id, :category_id, :class_group, :beneficiary_count, :effective_date,
                        \'aktif\', :notes, :created_by, :updated_by, NOW(), NOW()
                     )',
                    [
                        'school_id' => $schoolId,
                        'category_id' => $categoryId,
                        'class_group' => $classGroup,
                        'beneficiary_count' => $count,
                        'effective_date' => $effectiveDate,
                        'notes' => $notes === null || $notes === '' ? null : mb_substr($notes, 0, 500),
                        'created_by' => $actorId,
                        'updated_by' => $actorId,
                    ]
                );
                $id = (int) Database::lastInsertId();
                $action = $previous === null ? 'buat' : 'ubah';
                $previousCount = $previous === null ? null : (int) $previous['beneficiary_count'];
                $previousDate = $previous === null ? null : (string) $previous['effective_date'];
            }

            self::logHistory(
                $id,
                $schoolId,
                $categoryId,
                $classGroup,
                $action,
                $previousCount,
                $count,
                $previousDate,
                $effectiveDate,
                $reason,
                $actorId
            );

            return [$id, $action];
        });
    }

    /** Nonaktifkan atau aktifkan kembali satu baris rekap. Riwayat tetap membaca baris lama. */
    public static function setStatus(int $id, string $status, string $reason, int $actorId): bool
    {
        $row = self::find($id);
        if ($row === null || (string) $row['record_status'] === $status) {
            return false;
        }

        return Database::transaction(static function () use ($row, $status, $reason, $actorId): bool {
            Database::execute(
                'UPDATE beneficiaries
                 SET record_status = :status, updated_by = :updated_by, updated_at = NOW()
                 WHERE id = :id',
                ['status' => $status, 'updated_by' => $actorId, 'id' => (int) $row['id']]
            );

            self::logHistory(
                (int) $row['id'],
                (int) $row['school_id'],
                (int) $row['category_id'],
                (string) $row['class_group'],
                $status === self::STATUS_ACTIVE ? 'aktifkan' : 'nonaktifkan',
                (int) $row['beneficiary_count'],
                (int) $row['beneficiary_count'],
                (string) $row['effective_date'],
                (string) $row['effective_date'],
                $reason,
                $actorId
            );

            return true;
        });
    }

    /**
     * Hapus baris rekap. Riwayatnya tetap tersimpan karena baris riwayat menyimpan
     * salinan sekolah, kategori, kelompok, dan jumlahnya.
     */
    public static function remove(int $id, string $reason, int $actorId): bool
    {
        $row = self::find($id);
        if ($row === null) {
            return false;
        }

        return Database::transaction(static function () use ($row, $reason, $actorId): bool {
            self::logHistory(
                (int) $row['id'],
                (int) $row['school_id'],
                (int) $row['category_id'],
                (string) $row['class_group'],
                'hapus',
                (int) $row['beneficiary_count'],
                null,
                (string) $row['effective_date'],
                null,
                $reason,
                $actorId
            );

            Database::execute('DELETE FROM beneficiaries WHERE id = :id', ['id' => (int) $row['id']]);

            return true;
        });
    }

    public static function logHistory(
        ?int $beneficiaryId,
        int $schoolId,
        int $categoryId,
        string $classGroup,
        string $action,
        ?int $previousCount,
        ?int $newCount,
        ?string $previousDate,
        ?string $newDate,
        string $reason,
        int $actorId
    ): void {
        Database::execute(
            'INSERT INTO beneficiary_histories (
                beneficiary_id, school_id, category_id, class_group, change_action,
                previous_count, new_count, previous_effective_date, new_effective_date,
                reason, changed_by, changed_at
             ) VALUES (
                :beneficiary_id, :school_id, :category_id, :class_group, :change_action,
                :previous_count, :new_count, :previous_effective_date, :new_effective_date,
                :reason, :changed_by, NOW()
             )',
            [
                'beneficiary_id' => $beneficiaryId,
                'school_id' => $schoolId,
                'category_id' => $categoryId,
                'class_group' => mb_substr($classGroup, 0, 60),
                'change_action' => $action,
                'previous_count' => $previousCount,
                'new_count' => $newCount,
                'previous_effective_date' => $previousDate,
                'new_effective_date' => $newDate,
                'reason' => mb_substr($reason, 0, 500),
                'changed_by' => $actorId,
            ]
        );
    }

    /**
     * @param array<string, string> $filters
     * @return array<int, array<string, mixed>>
     */
    public static function histories(array $filters, int $limit = 100): array
    {
        $conditions = [];
        $params = [];

        if ($filters['school'] !== '' && ctype_digit($filters['school'])) {
            $conditions[] = 'h.school_id = :school_id';
            $params['school_id'] = $filters['school'];
        }

        if ($filters['category'] !== '' && ctype_digit($filters['category'])) {
            $conditions[] = 'h.category_id = :category_id';
            $params['category_id'] = $filters['category'];
        }

        if ($filters['from'] !== '') {
            $conditions[] = 'h.changed_at >= :date_from';
            $params['date_from'] = $filters['from'] . ' 00:00:00';
        }

        if ($filters['to'] !== '') {
            $conditions[] = 'h.changed_at <= :date_to';
            $params['date_to'] = $filters['to'] . ' 23:59:59';
        }

        $limit = max(1, min($limit, 500));

        return self::fetchAll(
            'SELECT h.*, s.school_name, c.category_name, u.full_name AS actor_name
             FROM beneficiary_histories h
             INNER JOIN schools s ON s.id = h.school_id
             INNER JOIN beneficiary_categories c ON c.id = h.category_id
             LEFT JOIN users u ON u.id = h.changed_by'
            . ($conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions))
            . ' ORDER BY h.changed_at DESC, h.id DESC LIMIT ' . $limit,
            $params
        );
    }

    /** @return array<int, array<string, mixed>> Deret waktu jumlah satu kelompok kelas. */
    public static function series(int $schoolId, int $categoryId, string $classGroup): array
    {
        return self::fetchAll(
            'SELECT effective_date, beneficiary_count, record_status
             FROM beneficiaries
             WHERE school_id = :school_id AND category_id = :category_id AND class_group = :class_group
               AND record_status = \'aktif\'
             ORDER BY effective_date ASC, id ASC',
            ['school_id' => $schoolId, 'category_id' => $categoryId, 'class_group' => $classGroup]
        );
    }

    /** @return array<int, string> Kelompok kelas yang pernah tercatat pada satu sekolah. */
    public static function classGroups(int $schoolId): array
    {
        $rows = self::fetchAll(
            'SELECT DISTINCT class_group FROM beneficiaries
             WHERE school_id = :school_id AND class_group <> \'\'
             ORDER BY class_group ASC',
            ['school_id' => $schoolId]
        );

        return array_map(static fn (array $row): string => (string) $row['class_group'], $rows);
    }

    /** Jumlah penerima per kategori untuk satu sekolah pada satu tanggal. */
    public static function perCategory(int $schoolId, string $asof): array
    {
        $sql = 'SELECT b.category_id, c.category_name, SUM(b.beneficiary_count) AS total
                FROM beneficiaries b' . self::LATEST_JOIN . '
                INNER JOIN beneficiary_categories c ON c.id = b.category_id
                WHERE b.record_status = \'aktif\' AND b.school_id = :school_id
                GROUP BY b.category_id, c.category_name
                ORDER BY c.sort_order ASC, c.category_name ASC';

        return self::fetchAll($sql, ['asof_a' => $asof, 'school_id' => $schoolId]);
    }
}
