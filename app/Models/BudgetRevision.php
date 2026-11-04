<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;
use PDOException;
use RuntimeException;

final class BudgetRevision extends Model
{
    protected static string $table = 'budget_revisions';

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    /** @var array<int, string> */
    public const STATUSES = [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED];

    /**
     * Buat pengajuan perubahan anggaran. Nilai proyek tidak diubah di sini;
     * hanya tercatat sebagai usulan yang menunggu persetujuan.
     *
     * @param array<string, mixed> $data
     * @return array{0: int, 1: string}
     */
    public static function create(array $data): array
    {
        $sql = 'INSERT INTO budget_revisions (
                    revision_code, project_id, previous_amount, proposed_amount, reason, status,
                    requested_by, requested_at
                 ) VALUES (
                    :revision_code, :project_id, :previous_amount, :proposed_amount, :reason, :status,
                    :requested_by, NOW()
                 )';

        $values = [
            'project_id' => (int) $data['project_id'],
            'previous_amount' => (float) $data['previous_amount'],
            'proposed_amount' => (float) $data['proposed_amount'],
            'reason' => mb_substr((string) $data['reason'], 0, 500),
            'status' => self::STATUS_PENDING,
            'requested_by' => $data['requested_by'] ?? null,
        ];

        $explicit = trim((string) ($data['revision_code'] ?? ''));
        if ($explicit !== '') {
            Database::execute($sql, $values + ['revision_code' => $explicit]);
            return [(int) Database::lastInsertId(), $explicit];
        }

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                $code = self::nextCode();
                Database::execute($sql, $values + ['revision_code' => $code]);
                return [(int) Database::lastInsertId(), $code];
            } catch (PDOException $exception) {
                if ($exception->getCode() !== '23000' || $attempt === 5) {
                    throw $exception;
                }
            }
        }

        throw new RuntimeException('Kode perubahan anggaran sedang dipakai, silakan coba lagi.');
    }

    public static function codeExists(string $code): bool
    {
        return (int) (self::fetchOne(
            'SELECT COUNT(*) AS total FROM budget_revisions WHERE revision_code = :code',
            ['code' => $code]
        )['total'] ?? 0) > 0;
    }

    public static function nextCode(?string $year = null): string
    {
        $year = $year !== null && $year !== '' ? $year : date('Y');

        for ($sequence = 1; $sequence <= 9999; $sequence++) {
            $candidate = sprintf('RVB-%s-%03d', $year, $sequence);
            if (!self::codeExists($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Kode perubahan anggaran otomatis sudah habis untuk tahun ini.');
    }

    /** @return array<string, mixed>|null */
    public static function findWithNames(int $id): ?array
    {
        return self::fetchOne(
            'SELECT v.*, pr.name AS project_name, pr.project_code, pr.budget_amount AS current_amount,
                    r.full_name AS requester_name, d.full_name AS decider_name
             FROM budget_revisions v
             INNER JOIN projects pr ON pr.id = v.project_id
             LEFT JOIN users r ON r.id = v.requested_by
             LEFT JOIN users d ON d.id = v.decided_by
             WHERE v.id = :id
             LIMIT 1',
            ['id' => $id]
        );
    }

    /**
     * Setujui atau tolak perubahan anggaran. Saat disetujui, anggaran awal proyek
     * ikut diperbarui di dalam transaksi database yang sama agar nilai lama dan
     * riwayat perubahan tidak pernah terpisah.
     */
    public static function decide(int $id, string $toStatus, ?string $note, int $actorId): int
    {
        $affected = Database::execute(
            'UPDATE budget_revisions SET
                status = :to_status, decided_by = :actor_id, decided_at = NOW(), decision_note = :note
             WHERE id = :id AND status = :pending',
            [
                'to_status' => $toStatus,
                'actor_id' => $actorId,
                'note' => $note !== null && $note !== '' ? mb_substr($note, 0, 500) : null,
                'id' => $id,
                'pending' => self::STATUS_PENDING,
            ]
        );

        if ($affected === 0) {
            return 0;
        }

        if ($toStatus === self::STATUS_APPROVED) {
            $revision = self::findWithNames($id);
            if ($revision === null) {
                return 0;
            }

            Project::lockForUpdate((int) $revision['project_id']);
            Project::updateBudget(
                (int) $revision['project_id'],
                (float) $revision['proposed_amount'],
                $actorId
            );
        }

        return $affected;
    }

    /** @return array<int, array<string, mixed>> */
    public static function historyForProject(int $projectId): array
    {
        return self::fetchAll(
            'SELECT v.*, r.full_name AS requester_name, d.full_name AS decider_name
             FROM budget_revisions v
             LEFT JOIN users r ON r.id = v.requested_by
             LEFT JOIN users d ON d.id = v.decided_by
             WHERE v.project_id = :project_id
             ORDER BY v.requested_at DESC, v.id DESC
             LIMIT 50',
            ['project_id' => $projectId]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public static function pending(int $limit = 20): array
    {
        $limit = max(1, min($limit, 100));

        return self::fetchAll(
            'SELECT v.*, pr.name AS project_name, pr.project_code, r.full_name AS requester_name
             FROM budget_revisions v
             INNER JOIN projects pr ON pr.id = v.project_id
             LEFT JOIN users r ON r.id = v.requested_by
             WHERE v.status = \'pending\'
             ORDER BY v.requested_at ASC, v.id ASC
             LIMIT ' . $limit
        );
    }

    public static function countPending(): int
    {
        return (int) (self::fetchOne(
            "SELECT COUNT(*) AS total FROM budget_revisions WHERE status = 'pending'"
        )['total'] ?? 0);
    }

    /** Satu proyek hanya boleh punya satu usulan yang menunggu keputusan. */
    public static function hasPendingForProject(int $projectId): bool
    {
        return (int) (self::fetchOne(
            "SELECT COUNT(*) AS total FROM budget_revisions WHERE project_id = :project_id AND status = 'pending'",
            ['project_id' => $projectId]
        )['total'] ?? 0) > 0;
    }

    /** Jumlah perubahan yang sudah disetujui beserta selisih nilainya. */
    public static function approvedSummary(): array
    {
        $row = self::fetchOne(
            "SELECT COUNT(*) AS total, COALESCE(SUM(proposed_amount - previous_amount), 0) AS delta
             FROM budget_revisions WHERE status = 'approved'"
        ) ?? [];

        return [
            'count' => (int) ($row['total'] ?? 0),
            'delta' => round((float) ($row['delta'] ?? 0), 2),
        ];
    }
}
