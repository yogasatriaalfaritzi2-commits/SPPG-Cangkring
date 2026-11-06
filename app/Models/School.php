<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;
use RuntimeException;

/**
 * Sekolah penerima manfaat MBG. Sekolah tidak pernah dihapus setelah punya riwayat
 * distribusi; status arsip atau nonaktif dipakai agar riwayat lama tetap terbaca.
 */
final class School extends Model
{
    protected static string $table = 'schools';

    public const STATUS_ACTIVE = 'aktif';
    public const STATUS_INACTIVE = 'nonaktif';
    public const STATUS_ARCHIVED = 'arsip';

    /** @var array<int, string> */
    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE, self::STATUS_ARCHIVED];

    /** @var array<int, string> */
    public const PARTNERSHIPS = [
        'belum_ada',
        'moa_aktif',
        'moa_akan_habis',
        'ditangguhkan',
        'berakhir',
    ];

    /** @var array<string, string> */
    public const SORTABLE = [
        'code' => 's.school_code',
        'name' => 's.school_name',
        'level' => 'l.level_name',
        'status' => 's.school_status',
        'partnership' => 's.partnership_status',
        'regency' => 's.regency',
        'beneficiaries' => 'beneficiary_total',
        'end' => 's.partnership_end',
    ];

    /**
     * Jumlah penerima manfaat yang berlaku hari ini. Satu kelompok kelas hanya
     * menyumbang satu baris, yaitu baris dengan tanggal berlaku terakhir, sehingga
     * penambahan riwayat tidak pernah menyebabkan penghitungan ganda.
     */
    public const CURRENT_TOTAL_SQL =
        "(SELECT COALESCE(SUM(b.beneficiary_count), 0) FROM beneficiaries b
          WHERE b.school_id = %s.id AND b.record_status = 'aktif' AND b.effective_date <= CURDATE()
            AND b.effective_date = (
                SELECT MAX(b2.effective_date) FROM beneficiaries b2
                 WHERE b2.school_id = b.school_id
                   AND b2.category_id = b.category_id
                   AND b2.class_group = b.class_group
                   AND b2.effective_date <= CURDATE()))";

    /** @return array<string, mixed>|null */
    public static function findWithNames(int $id): ?array
    {
        return self::fetchOne(
            'SELECT ' . self::FIELDS . self::FROM . ' WHERE s.id = :id LIMIT 1',
            ['id' => $id]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public static function optionList(bool $includeInactive = false, int $limit = 300): array
    {
        $limit = max(1, min($limit, 500));
        $conditions = $includeInactive
            ? "s.school_status <> 'arsip'"
            : "s.school_status = 'aktif'";

        return self::fetchAll(
            'SELECT s.id, s.school_code, s.school_name, s.level_id, l.level_name, s.school_status,'
            . ' ' . sprintf(self::CURRENT_TOTAL_SQL, 's') . ' AS beneficiary_total'
            . ' FROM schools s INNER JOIN school_levels l ON l.id = s.level_id'
            . ' WHERE ' . $conditions
            . ' ORDER BY s.school_name ASC LIMIT ' . $limit
        );
    }

    /** Baris sekolah berdasarkan kode, dipakai pencocokan saat impor CSV. */
    public static function byCode(string $code): ?array
    {
        return self::fetchOne('SELECT * FROM schools WHERE school_code = :code LIMIT 1', ['code' => $code]);
    }

    /** Baris sekolah berdasarkan nama sekolah. */
    public static function findByName(string $name): ?array
    {
        return self::fetchOne('SELECT * FROM schools WHERE LOWER(TRIM(school_name)) = LOWER(TRIM(:name)) LIMIT 1', [
            'name' => $name,
        ]);
    }

    public static function codeExists(string $code, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) AS total FROM schools WHERE school_code = :code';
        $params = ['code' => $code];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }

        return (int) (self::fetchOne($sql, $params)['total'] ?? 0) > 0;
    }

    /** NPSN boleh kosong, tetapi tidak boleh dipakai dua sekolah. */
    public static function npsnExists(string $npsn, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) AS total FROM schools WHERE npsn = :npsn';
        $params = ['npsn' => $npsn];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }

        return (int) (self::fetchOne($sql, $params)['total'] ?? 0) > 0;
    }

    public static function nameExists(string $name, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) AS total FROM schools WHERE school_name = :name';
        $params = ['name' => $name];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }

        return (int) (self::fetchOne($sql, $params)['total'] ?? 0) > 0;
    }

    public static function nextCode(?string $year = null): string
    {
        $year = $year !== null && $year !== '' ? $year : date('Y');

        for ($sequence = 1; $sequence <= 9999; $sequence++) {
            $candidate = sprintf('SEK-%s-%03d', $year, $sequence);
            if (!self::codeExists($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Kode sekolah otomatis sudah habis untuk tahun ini.');
    }

    /**
     * @param array{search: string, level: string, status: string, partnership: string, regency: string, sort: string, dir: string} $filters
     * @return array{rows: array<int, array<string, mixed>>, total: int, page: int, pages: int, per_page: int}
     */
    public static function paginate(array $filters, int $page, int $perPage = 10): array
    {
        [$where, $params] = self::filters($filters);

        $total = (int) (self::fetchOne(
            'SELECT COUNT(*) AS total FROM schools s INNER JOIN school_levels l ON l.id = s.level_id' . $where,
            $params
        )['total'] ?? 0);

        $perPage = max(1, min($perPage, 50));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;

        $column = self::SORTABLE[$filters['sort']] ?? 's.school_name';
        $direction = strtolower($filters['dir']) === 'asc' ? 'ASC' : 'DESC';

        $rows = self::fetchAll(
            'SELECT ' . self::FIELDS
            . ', ' . sprintf(self::CURRENT_TOTAL_SQL, 's') . ' AS beneficiary_total'
            . self::FROM . $where
            . ' ORDER BY ' . $column . ' ' . $direction . ', s.id DESC'
            . ' LIMIT ' . $perPage . ' OFFSET ' . $offset,
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

    /** @param array<string, string> $filters @return array{0: string, 1: array<string, string>} */
    private static function filters(array $filters): array
    {
        $conditions = [];
        $params = [];

        if ($filters['search'] !== '') {
            $conditions[] = '(LOCATE(:search_code, s.school_code) > 0'
                . ' OR LOCATE(:search_name, s.school_name) > 0'
                . ' OR LOCATE(:search_npsn, COALESCE(s.npsn, \'\')) > 0'
                . ' OR LOCATE(:search_district, COALESCE(s.district, \'\')) > 0'
                . ' OR LOCATE(:search_pic, COALESCE(s.person_in_charge, \'\')) > 0)';
            foreach (['search_code', 'search_name', 'search_npsn', 'search_district', 'search_pic'] as $key) {
                $params[$key] = $filters['search'];
            }
        }

        if ($filters['level'] !== '' && ctype_digit($filters['level'])) {
            $conditions[] = 's.level_id = :level_id';
            $params['level_id'] = $filters['level'];
        }

        if (in_array($filters['status'], self::STATUSES, true)) {
            $conditions[] = 's.school_status = :school_status';
            $params['school_status'] = $filters['status'];
        } elseif ($filters['status'] === 'open') {
            $conditions[] = "s.school_status <> 'arsip'";
        }

        if (in_array($filters['partnership'], self::PARTNERSHIPS, true)) {
            $conditions[] = 's.partnership_status = :partnership';
            $params['partnership'] = $filters['partnership'];
        }

        if ($filters['regency'] !== '') {
            $conditions[] = 'LOCATE(:regency, COALESCE(s.regency, \'\')) > 0';
            $params['regency'] = $filters['regency'];
        }

        return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $params];
    }

    private const FIELDS = 's.*, l.level_name, c.full_name AS creator_name';

    private const FROM = ' FROM schools s
         INNER JOIN school_levels l ON l.id = s.level_id
         LEFT JOIN users c ON c.id = s.created_by';

    /** @param array<string, mixed> $data */
    public static function create(array $data): int
    {
        Database::execute(
            'INSERT INTO schools (
                school_code, school_name, npsn, level_id, school_status, partnership_status,
                partnership_start, partnership_end, address, province, regency, district, village,
                postal_code, latitude, longitude, person_in_charge, phone, email, notes,
                created_by, updated_by, created_at, updated_at
             ) VALUES (
                :school_code, :school_name, :npsn, :level_id, :school_status, :partnership_status,
                :partnership_start, :partnership_end, :address, :province, :regency, :district, :village,
                :postal_code, :latitude, :longitude, :person_in_charge, :phone, :email, :notes,
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
        Database::execute(
            'UPDATE schools SET
                school_code = :school_code, school_name = :school_name, npsn = :npsn,
                level_id = :level_id, school_status = :school_status,
                partnership_status = :partnership_status, partnership_start = :partnership_start,
                partnership_end = :partnership_end, address = :address, province = :province,
                regency = :regency, district = :district, village = :village,
                postal_code = :postal_code, latitude = :latitude, longitude = :longitude,
                person_in_charge = :person_in_charge, phone = :phone, email = :email, notes = :notes,
                updated_by = :updated_by, updated_at = NOW()
             WHERE id = :id',
            self::values($data) + [
                'updated_by' => $data['updated_by'] ?? null,
                'id' => $id,
            ]
        );
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private static function values(array $data): array
    {
        $fields = [
            'school_code', 'school_name', 'npsn', 'level_id', 'school_status', 'partnership_status',
            'partnership_start', 'partnership_end', 'address', 'province', 'regency', 'district',
            'village', 'postal_code', 'latitude', 'longitude', 'person_in_charge', 'phone', 'email', 'notes',
        ];

        $values = [];
        foreach ($fields as $field) {
            $values[$field] = $data[$field] ?? null;
        }

        // Kolom status bersifat NOT NULL dengan default, jadi nilai kosong atau nilai
        // di luar daftar dikembalikan ke default kolom, bukan dikirim sebagai NULL.
        $values['school_status'] = in_array($values['school_status'], self::STATUSES, true)
            ? (string) $values['school_status']
            : self::STATUS_ACTIVE;

        $values['partnership_status'] = in_array($values['partnership_status'], self::PARTNERSHIPS, true)
            ? (string) $values['partnership_status']
            : self::PARTNERSHIPS[0];

        foreach (['npsn', 'address', 'province', 'regency', 'district', 'village', 'postal_code',
            'person_in_charge', 'phone', 'email', 'notes', 'partnership_start', 'partnership_end',
            'latitude', 'longitude'] as $nullable) {
            if ($values[$nullable] === '') {
                $values[$nullable] = null;
            }
        }

        return $values;
    }

    public static function updateStatus(int $id, string $status, int $actorId): void
    {
        Database::execute(
            'UPDATE schools
             SET school_status = :status,
                 archived_at = :archived_at,
                 archived_by = :archived_by,
                 updated_by = :updated_by,
                 updated_at = NOW()
             WHERE id = :id',
            [
                'status' => $status,
                'archived_at' => $status === self::STATUS_ARCHIVED ? date('Y-m-d H:i:s') : null,
                'archived_by' => $status === self::STATUS_ARCHIVED ? $actorId : null,
                'updated_by' => $actorId,
                'id' => $id,
            ]
        );
    }

    /** Riwayat distribusi membuat sekolah tidak boleh dihapus permanen. */
    public static function usageCount(int $id): int
    {
        $row = self::fetchOne(
            'SELECT
                (SELECT COUNT(*) FROM beneficiaries WHERE school_id = :id) AS records,
                (SELECT COUNT(*) FROM beneficiary_individuals WHERE school_id = :id2) AS individuals,
                (SELECT COUNT(*) FROM menu_schedule_schools WHERE school_id = :id3) AS schedules,
                (SELECT COUNT(*) FROM school_service_schedules WHERE school_id = :id4) AS service_days',
            ['id' => $id, 'id2' => $id, 'id3' => $id, 'id4' => $id]
        ) ?? [];

        return (int) ($row['records'] ?? 0) + (int) ($row['individuals'] ?? 0)
            + (int) ($row['schedules'] ?? 0) + (int) ($row['service_days'] ?? 0);
    }

    /**
     * Hanya boleh dipakai setelah usageCount() bernilai nol. Sekolah yang punya
     * riwayat distribusi tetap diarsipkan, bukan dihapus.
     */
    public static function delete(int $id): void
    {
        Database::execute('DELETE FROM schools WHERE id = :id', ['id' => $id]);
    }

    /** @return array<string, int> jumlah sekolah per status */
    public static function statusCounts(): array
    {
        $rows = self::fetchAll('SELECT school_status, COUNT(*) AS total FROM schools GROUP BY school_status');
        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['school_status']] = (int) $row['total'];
        }

        return $counts;
    }

    /** @return array<int, array<string, mixed>> jumlah sekolah dan penerima manfaat per jenjang */
    public static function summaryByLevel(): array
    {
        return self::fetchAll(
            'SELECT l.level_name, COUNT(s.id) AS school_count,
                    ' . sprintf(self::CURRENT_TOTAL_SQL, 's') . ' AS beneficiary_total
             FROM school_levels l
             LEFT JOIN schools s ON s.level_id = l.id AND s.school_status = \'aktif\'
             WHERE l.is_active = 1
             GROUP BY l.id, l.level_name, l.sort_order
             ORDER BY l.sort_order ASC, l.level_name ASC'
        );
    }

    /**
     * Sekolah aktif yang masa kerja samanya akan habis atau sudah lewat. Angka hari
     * dihitung dari tanggal berakhirnya, bukan dari status yang diketik manual.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function partnershipAlerts(int $days = 60): array
    {
        $days = max(1, min($days, 365));

        return self::fetchAll(
            'SELECT s.id, s.school_code, s.school_name, s.partnership_status, s.partnership_end,
                    DATEDIFF(s.partnership_end, CURDATE()) AS remaining
             FROM schools s
             INNER JOIN school_levels l ON l.id = s.level_id
             WHERE s.school_status = \'aktif\' AND s.partnership_end IS NOT NULL
               AND s.partnership_end <= DATE_ADD(CURDATE(), INTERVAL ' . $days . ' DAY)
             ORDER BY s.partnership_end ASC, s.school_name ASC
             LIMIT 20'
        );
    }

    /** @return array<int, array<string, mixed>> */
    public static function regions(string $field = 'regency'): array
    {
        $column = $field === 'district' ? 'district' : ($field === 'village' ? 'village' : 'regency');

        return self::fetchAll(
            'SELECT DISTINCT COALESCE(' . $column . ', \'\') AS name
             FROM schools
             WHERE ' . $column . ' IS NOT NULL AND ' . $column . ' <> \'\'
             ORDER BY name ASC'
        );
    }

    /** @return array<string, mixed> */
    public static function emptyRecord(): array
    {
        return [
            'id' => 0,
            'school_code' => '',
            'school_name' => '',
            'npsn' => '',
            'level_id' => null,
            'school_status' => self::STATUS_ACTIVE,
            'partnership_status' => 'belum_ada',
            'partnership_start' => '',
            'partnership_end' => '',
            'address' => '',
            'province' => '',
            'regency' => '',
            'district' => '',
            'village' => '',
            'postal_code' => '',
            'latitude' => null,
            'longitude' => null,
            'person_in_charge' => '',
            'phone' => '',
            'email' => '',
            'notes' => '',
        ];
    }
}
