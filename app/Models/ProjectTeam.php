<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

final class ProjectTeam extends Model
{
    protected static string $table = 'project_team_assignments';

    /** @return array<int, array<string, mixed>> Anggota yang masih aktif ditugaskan. */
    public static function active(int $projectId): array
    {
        return self::fetchAll(
            'SELECT t.*, u.full_name, u.username, u.email, r.role_name, a.full_name AS assigned_by_name
             FROM project_team_assignments t
             INNER JOIN users u ON u.id = t.user_id
             INNER JOIN roles r ON r.id = u.role_id
             LEFT JOIN users a ON a.id = t.assigned_by
             WHERE t.project_id = :project_id AND t.unassigned_at IS NULL
             ORDER BY t.is_lead DESC, t.assigned_at ASC, t.id ASC',
            ['project_id' => $projectId]
        );
    }

    /** @return array<int, array<string, mixed>> Riwayat penugasan, termasuk yang sudah selesai. */
    public static function history(int $projectId): array
    {
        return self::fetchAll(
            'SELECT t.*, u.full_name, a.full_name AS assigned_by_name
             FROM project_team_assignments t
             INNER JOIN users u ON u.id = t.user_id
             LEFT JOIN users a ON a.id = t.assigned_by
             WHERE t.project_id = :project_id
             ORDER BY t.assigned_at DESC, t.id DESC
             LIMIT 100',
            ['project_id' => $projectId]
        );
    }

    /** @return array<string, mixed>|null */
    public static function findActive(int $projectId, int $userId): ?array
    {
        return self::fetchOne(
            'SELECT * FROM project_team_assignments
             WHERE project_id = :project_id AND user_id = :user_id AND unassigned_at IS NULL
             LIMIT 1',
            ['project_id' => $projectId, 'user_id' => $userId]
        );
    }

    /** @return array<string, mixed>|null Penugasan aktif berdasarkan nomor penugasan. */
    public static function findInProject(int $assignmentId, int $projectId): ?array
    {
        return self::fetchOne(
            'SELECT t.*, u.full_name, u.username
             FROM project_team_assignments t
             INNER JOIN users u ON u.id = t.user_id
             WHERE t.id = :id AND t.project_id = :project_id AND t.unassigned_at IS NULL
             LIMIT 1',
            ['id' => $assignmentId, 'project_id' => $projectId]
        );
    }

    public static function assign(
        int $projectId,
        int $userId,
        string $position,
        ?string $duties,
        int $actorId,
        bool $isLead = false
    ): int {
        Database::execute(
            'INSERT INTO project_team_assignments (
                project_id, user_id, position, duties, is_lead, assigned_by, assigned_at, unassigned_at
             ) VALUES (
                :project_id, :user_id, :position, :duties, :is_lead, :assigned_by, NOW(), NULL
             )',
            [
                'project_id' => $projectId,
                'user_id' => $userId,
                'position' => mb_substr($position, 0, 80),
                'duties' => $duties !== null && $duties !== '' ? mb_substr($duties, 0, 255) : null,
                'is_lead' => $isLead ? 1 : 0,
                'assigned_by' => $actorId,
            ]
        );

        return (int) Database::lastInsertId();
    }

    public static function changeAssignment(int $assignmentId, string $position, ?string $duties): void
    {
        Database::execute(
            'UPDATE project_team_assignments
             SET position = :position, duties = :duties
             WHERE id = :id',
            [
                'position' => mb_substr($position, 0, 80),
                'duties' => $duties !== null && $duties !== '' ? mb_substr($duties, 0, 255) : null,
                'id' => $assignmentId,
            ]
        );
    }

    public static function unassign(int $assignmentId): void
    {
        Database::execute(
            'UPDATE project_team_assignments
             SET unassigned_at = NOW(), is_lead = 0
             WHERE id = :id',
            ['id' => $assignmentId]
        );
    }

    public static function clearLead(int $projectId): void
    {
        Database::execute(
            'UPDATE project_team_assignments SET is_lead = 0
             WHERE project_id = :project_id AND unassigned_at IS NULL AND is_lead = 1',
            ['project_id' => $projectId]
        );
    }

    public static function markLead(int $assignmentId): void
    {
        Database::execute(
            'UPDATE project_team_assignments SET is_lead = 1 WHERE id = :id',
            ['id' => $assignmentId]
        );
    }

    /** @return array<int, array<string, mixed>> Pengguna aktif yang belum ditugaskan pada proyek. */
    public static function candidates(int $projectId): array
    {
        return self::fetchAll(
            'SELECT u.id, u.full_name, u.username, r.role_name
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.status = :status
               AND r.is_active = 1
               AND u.id NOT IN (
                   SELECT t.user_id FROM project_team_assignments t
                   WHERE t.project_id = :project_id AND t.unassigned_at IS NULL
               )
             ORDER BY u.full_name ASC',
            ['status' => User::STATUS_ACTIVE, 'project_id' => $projectId]
        );
    }
}
