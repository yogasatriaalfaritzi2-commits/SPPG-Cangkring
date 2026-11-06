<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

final class ActivityLog extends Model
{
    protected static string $table = 'user_activity_logs';

    public static function record(
        ?int $userId,
        string $activity,
        string $module,
        string $description,
        ?string $ip = null,
        ?string $userAgent = null
    ): void {
        Database::execute(
            'INSERT INTO user_activity_logs (user_id, activity, module, description, ip_address, user_agent, created_at)
             VALUES (:user_id, :activity, :module, :description, :ip, :agent, NOW())',
            [
                'user_id' => $userId,
                'activity' => mb_substr($activity, 0, 60),
                'module' => mb_substr($module, 0, 60),
                'description' => mb_substr($description, 0, 255),
                'ip' => $ip,
                'agent' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
            ]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public static function recent(int $limit = 8): array
    {
        $limit = max(1, min($limit, 50));

        return self::fetchAll(
            'SELECT a.activity, a.module, a.description, a.created_at, u.full_name AS user_name
             FROM user_activity_logs a
             LEFT JOIN users u ON u.id = a.user_id
             ORDER BY a.created_at DESC, a.id DESC
             LIMIT ' . $limit
        );
    }

    public static function countToday(): int
    {
        $row = self::fetchOne(
            'SELECT COUNT(*) AS total FROM user_activity_logs WHERE created_at >= CURDATE()'
        );

        return (int) ($row['total'] ?? 0);
    }

    public static function countForUser(int $userId): int
    {
        $row = self::fetchOne(
            'SELECT COUNT(*) AS total FROM user_activity_logs WHERE user_id = :user_id',
            ['user_id' => $userId]
        );

        return (int) ($row['total'] ?? 0);
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, total: int, page: int, pages: int, per_page: int}
     */
    public static function paginate(
        string $search,
        string $module,
        int $page,
        int $perPage = 15,
        string $startDate = '',
        string $endDate = '',
        string $cluster = ''
    ): array {
        $conditions = [];
        $params = [];

        if ($search !== '') {
            $conditions[] = '(LOCATE(:activity_search, a.activity) > 0 OR LOCATE(:description_search, a.description) > 0 OR LOCATE(:user_search, u.full_name) > 0)';
            $params['activity_search'] = $search;
            $params['description_search'] = $search;
            $params['user_search'] = $search;
        }

        if ($module !== '') {
            $conditions[] = 'a.module = :module';
            $params['module'] = $module;
        }

        if ($cluster === 'mbg') {
            $conditions[] = "a.module IN ('mbg', 'schools', 'beneficiaries', 'menus', 'nutrition')";
        } elseif ($cluster === 'keuangan') {
            $conditions[] = "a.module IN ('budgets', 'transactions', 'revisions')";
        } elseif ($cluster === 'proyek') {
            $conditions[] = "a.module IN ('projects', 'schedules', 'progress', 'documents', 'team')";
        }

        if ($startDate !== '') {
            $conditions[] = 'a.created_at >= :start_date';
            $params['start_date'] = $startDate . ' 00:00:00';
        }

        if ($endDate !== '') {
            $conditions[] = 'a.created_at < DATE_ADD(:end_date, INTERVAL 1 DAY)';
            $params['end_date'] = $endDate . ' 00:00:00';
        }

        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
        $from = ' FROM user_activity_logs a LEFT JOIN users u ON u.id = a.user_id';

        $countRow = self::fetchOne('SELECT COUNT(*) AS total' . $from . $where, $params);
        $total = (int) ($countRow['total'] ?? 0);

        $perPage = max(1, min($perPage, 50));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;

        $rows = self::fetchAll(
            'SELECT a.*, u.full_name AS user_name, u.username
             FROM user_activity_logs a
             LEFT JOIN users u ON u.id = a.user_id'
            . $where
            . ' ORDER BY a.created_at DESC, a.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
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
     * Ringkasan statistik aktivitas berdasarkan kluster modul
     * @return array{mbg: int, keuangan: int, proyek: int, sistem: int, today: int, total: int}
     */
    public static function clusterSummary(): array
    {
        $rows = self::fetchAll('
            SELECT 
                CASE 
                    WHEN module IN (\'mbg\', \'schools\', \'beneficiaries\', \'menus\', \'nutrition\') THEN \'mbg\'
                    WHEN module IN (\'budgets\', \'transactions\', \'revisions\') THEN \'keuangan\'
                    WHEN module IN (\'projects\', \'schedules\', \'progress\', \'documents\', \'team\') THEN \'proyek\'
                    ELSE \'sistem\'
                END AS kluster,
                COUNT(*) AS total,
                SUM(CASE WHEN created_at >= CURDATE() THEN 1 ELSE 0 END) AS today
            FROM user_activity_logs
            GROUP BY kluster
        ');

        $summary = [
            'mbg' => 0,
            'keuangan' => 0,
            'proyek' => 0,
            'sistem' => 0,
            'today' => 0,
            'total' => 0,
        ];

        foreach ($rows as $r) {
            $k = (string) $r['kluster'];
            $tot = (int) $r['total'];
            $summary[$k] = $tot;
            $summary['total'] += $tot;
            $summary['today'] += (int) $r['today'];
        }

        return $summary;
    }

    /**
     * Jumlah aktivitas per hari pada satu rentang tanggal, opsional dibatasi
     * beberapa modul. Dipakai grafik aktivitas pada dashboard.
     *
     * @param array<int, string> $modules
     * @return array<int, array<string, mixed>>
     */
    public static function dailySeries(string $from, string $to, array $modules = []): array
    {
        // Batas atas dieksklusifkan dengan menambahkan satu hari di SQL supaya
        // rentang sehari penuh (mis. periode "Hari ini") tetap ikut terhitung.
        $conditions = ['a.created_at >= :date_from', 'a.created_at < DATE_ADD(:date_to, INTERVAL 1 DAY)'];
        $params = ['date_from' => $from . ' 00:00:00', 'date_to' => $to . ' 00:00:00'];

        $modules = array_values(array_filter($modules, static fn (string $module): bool => $module !== ''));
        if ($modules !== []) {
            $placeholders = [];
            foreach ($modules as $index => $module) {
                $key = 'module_' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = $module;
            }
            $conditions[] = 'a.module IN (' . implode(', ', $placeholders) . ')';
        }

        return self::fetchAll(
            'SELECT DATE(a.created_at) AS period, COUNT(*) AS total
             FROM user_activity_logs a
             WHERE ' . implode(' AND ', $conditions) . '
             GROUP BY period
             ORDER BY period ASC',
            $params
        );
    }

    /** @return array<int, string> */
    public static function modules(): array
    {
        $rows = self::fetchAll('SELECT DISTINCT module FROM user_activity_logs ORDER BY module ASC');

        return array_map(static fn (array $row): string => (string) $row['module'], $rows);
    }
}