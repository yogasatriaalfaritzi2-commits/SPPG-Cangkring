<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

final class ProjectProgress extends Model
{
    protected static string $table = 'project_progress_reports';

    /**
     * @param array<string, mixed> $data
     */
    public static function create(array $data): int
    {
        Database::execute(
            'INSERT INTO project_progress_reports (
                project_id, report_date, progress_before, progress_after, work_description,
                completed_work, obstacles, next_plan, responsible_name, reported_by, reported_by_name, created_at
             ) VALUES (
                :project_id, :report_date, :progress_before, :progress_after, :work_description,
                :completed_work, :obstacles, :next_plan, :responsible_name, :reported_by, :reported_by_name, NOW()
             )',
            [
                'project_id' => (int) $data['project_id'],
                'report_date' => (string) $data['report_date'],
                'progress_before' => (int) $data['progress_before'],
                'progress_after' => (int) $data['progress_after'],
                'work_description' => (string) $data['work_description'],
                'completed_work' => $data['completed_work'] ?? null,
                'obstacles' => $data['obstacles'] ?? null,
                'next_plan' => $data['next_plan'] ?? null,
                'responsible_name' => mb_substr((string) ($data['responsible_name'] ?? ''), 0, 120),
                'reported_by' => $data['reported_by'] ?? null,
                'reported_by_name' => (string) ($data['reported_by_name'] ?? ''),
            ]
        );

        return (int) Database::lastInsertId();
    }

    /** @return array<int, array<string, mixed>> */
    public static function forProject(int $projectId, int $limit = 30): array
    {
        $limit = max(1, min($limit, 100));

        return self::fetchAll(
            'SELECT g.*, u.full_name AS reporter_name
             FROM project_progress_reports g
             LEFT JOIN users u ON u.id = g.reported_by
             WHERE g.project_id = :project_id
             ORDER BY g.report_date DESC, g.id DESC
             LIMIT ' . $limit,
            ['project_id' => $projectId]
        );
    }

    /** @return array<string, mixed>|null */
    public static function latest(int $projectId): ?array
    {
        return self::fetchOne(
            'SELECT * FROM project_progress_reports
             WHERE project_id = :project_id
             ORDER BY report_date DESC, id DESC
             LIMIT 1',
            ['project_id' => $projectId]
        );
    }

    public static function countForProject(int $projectId): int
    {
        $row = self::fetchOne(
            'SELECT COUNT(*) AS total FROM project_progress_reports WHERE project_id = :project_id',
            ['project_id' => $projectId]
        );

        return (int) ($row['total'] ?? 0);
    }

    /**
     * Deret waktu untuk grafik progres pada halaman detail proyek.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function series(int $projectId): array
    {
        return self::fetchAll(
            'SELECT report_date, MAX(progress_after) AS progress_after
             FROM project_progress_reports
             WHERE project_id = :project_id
             GROUP BY report_date
             ORDER BY report_date ASC',
            ['project_id' => $projectId]
        );
    }

    /**
     * Rata-rata progres nasional per bulan dari laporan yang benar-benar ada.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function monthlySeries(string $from, string $to): array
    {
        $conditions = ['g.report_date >= :date_from', 'g.report_date <= :date_to', 'p.is_archived = 0'];
        $params = ['date_from' => $from, 'date_to' => $to];

        $rows = self::fetchAll(
            'SELECT DATE_FORMAT(g.report_date, \'%Y-%m\') AS period,
                    COUNT(*) AS reports,
                    ROUND(AVG(g.progress_after), 1) AS avg_progress
             FROM project_progress_reports g
             INNER JOIN projects p ON p.id = g.project_id
             WHERE ' . implode(' AND ', $conditions) . '
             GROUP BY period
             ORDER BY period ASC',
            $params
        );

        return $rows;
    }
}
