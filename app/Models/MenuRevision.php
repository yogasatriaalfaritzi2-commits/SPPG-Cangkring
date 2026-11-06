<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;
use RuntimeException;

/**
 * Usulan perubahan atas menu yang sudah disetujui. Karena menu berstatus disetujui
 * tidak boleh diubah langsung, usulan dibuat sebagai menu turunan berversi dan isi
 * usulannya disimpan sebagai salinan JSON agar perbandingan dengan versi sebelumnya
 * tetap terbaca walaupun komposisi turunannya kemudian disunting lagi.
 */
final class MenuRevision extends Model
{
    protected static string $table = 'menu_revisions';

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    /** @var array<int, string> */
    public const STATUSES = [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED];

    /**
     * Buat menu turunan sekaligus catatan usulannya dalam satu transaksi.
     *
     * @param array<string, mixed> $origin menu asal
     * @param array<string, mixed> $data isi menu usulan
     * @param array<int, array{ingredient_id: int, quantity: float|string, unit: string}> $items
     * @return array{0: int, 1: int, 2: string} menu baru, usulan baru, kode usulan
     */
    public static function create(array $origin, array $data, array $items, string $reason, int $actorId): array
    {
        return Database::transaction(static function () use ($origin, $data, $items, $reason, $actorId): array {
            $originId = (int) $origin['id'];
            $version = (int) $origin['version'] + 1;
            $newCode = self::forkCode((string) $origin['menu_code'], $version);

            $menuId = Menu::insert(
                $data + ['revision_of' => $originId, 'version' => $version],
                $items,
                $actorId,
                $newCode
            );

            $computation = Menu::compute($menuId);
            $code = self::nextCode();

            Database::execute(
                'INSERT INTO menu_revisions (
                    revision_code, menu_id, reason, previous_status, proposed_json,
                    estimated_energy_kcal, estimated_cost_per_serving, revision_status,
                    requested_by, requested_at
                 ) VALUES (
                    :revision_code, :menu_id, :reason, :previous_status, :proposed_json,
                    :estimated_energy_kcal, :estimated_cost_per_serving, \'pending\',
                    :requested_by, NOW()
                 )',
                [
                    'revision_code' => $code,
                    'menu_id' => $menuId,
                    'reason' => mb_substr($reason, 0, 500),
                    'previous_status' => (string) $origin['menu_status'],
                    'proposed_json' => json_encode(
                        [
                            'menu_code' => $newCode,
                            'menu_name' => (string) $data['menu_name'],
                            'category_id' => (int) $data['category_id'],
                            'description' => (string) ($data['description'] ?? ''),
                            'allergen_info' => (string) ($data['allergen_info'] ?? ''),
                            'nutrition_notes' => (string) ($data['nutrition_notes'] ?? ''),
                            'items' => Menu::itemsToLines($items),
                            'per_serving' => $computation['per_serving'],
                            'cost_per_serving' => $computation['cost_per_serving'],
                            'nutrition_status' => $computation['nutrition_status'],
                        ],
                        JSON_UNESCAPED_UNICODE
                    ),
                    'estimated_energy_kcal' => $computation['per_serving']['energy_kcal'] ?? null,
                    'estimated_cost_per_serving' => $computation['cost_per_serving'],
                    'requested_by' => $actorId,
                ]
            );

            $revisionId = (int) Database::lastInsertId();

            Menu::log($menuId, 'revise', (string) $origin['menu_status'], Menu::STATUS_DRAFT, $reason, $actorId);

            return [$menuId, $revisionId, $code];
        });
    }

    /** @return array<string, mixed>|null */
    public static function findWithMenu(int $id): ?array
    {
        return self::fetchOne(
            'SELECT v.*, m.menu_code, m.menu_name, m.menu_status, m.version, m.revision_of,
                    r.full_name AS requester_name, d.full_name AS decider_name,
                    o.menu_code AS origin_code, o.menu_name AS origin_name
             FROM menu_revisions v
             INNER JOIN menus m ON m.id = v.menu_id
             LEFT JOIN menus o ON o.id = m.revision_of
             LEFT JOIN users r ON r.id = v.requested_by
             LEFT JOIN users d ON d.id = v.decided_by
             WHERE v.id = :id LIMIT 1',
            ['id' => $id]
        );
    }

    public static function codeExists(string $code): bool
    {
        return (int) (self::fetchOne(
            'SELECT COUNT(*) AS total FROM menu_revisions WHERE revision_code = :code',
            ['code' => $code]
        )['total'] ?? 0) > 0;
    }

    public static function nextCode(): string
    {
        $year = date('Y');

        for ($sequence = 1; $sequence <= 9999; $sequence++) {
            $candidate = sprintf('RVM-%s-%03d', $year, $sequence);
            if (!self::codeExists($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Kode revisi menu otomatis sudah habis untuk tahun ini.');
    }

    /**
     * Setujui atau tolak usulan. Persetujuan usulan terjadi bersamaan dengan
     * persetujuan menu turunannya, sehingga pemanggil wajib berada di dalam
     * transaksi yang sama dengan perubahan status menu.
     */
    public static function decide(int $id, string $toStatus, ?string $note, int $actorId): int
    {
        return Database::execute(
            'UPDATE menu_revisions SET
                revision_status = :to, decided_by = :actor_id, decided_at = NOW(), decision_note = :note
             WHERE id = :id AND revision_status = \'pending\'',
            [
                'to' => $toStatus,
                'actor_id' => $actorId,
                'note' => $note === null || $note === '' ? null : mb_substr($note, 0, 500),
                'id' => $id,
            ]
        );
    }

    /** Tutup usulan yang menunggu pada satu menu ketika menu itu diputus. */
    public static function decideForMenu(int $menuId, string $toStatus, ?string $note, int $actorId): int
    {
        return Database::execute(
            'UPDATE menu_revisions SET
                revision_status = :to, decided_by = :actor_id, decided_at = NOW(), decision_note = :note
             WHERE menu_id = :menu_id AND revision_status = \'pending\'',
            [
                'to' => $toStatus,
                'actor_id' => $actorId,
                'note' => $note === null || $note === '' ? null : mb_substr($note, 0, 500),
                'menu_id' => $menuId,
            ]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public static function pending(int $limit = 20): array
    {
        $limit = max(1, min($limit, 100));

        return self::fetchAll(
            'SELECT v.*, m.menu_code, m.menu_name, m.version, r.full_name AS requester_name
             FROM menu_revisions v
             INNER JOIN menus m ON m.id = v.menu_id
             LEFT JOIN users r ON r.id = v.requested_by
             WHERE v.revision_status = \'pending\'
             ORDER BY v.requested_at ASC, v.id ASC
             LIMIT ' . $limit
        );
    }

    public static function countPending(): int
    {
        return (int) (self::fetchOne(
            "SELECT COUNT(*) AS total FROM menu_revisions WHERE revision_status = 'pending'"
        )['total'] ?? 0);
    }

    /** @return array<int, array<string, mixed>> */
    public static function forMenu(int $menuId): array
    {
        return self::fetchAll(
            'SELECT v.*, r.full_name AS requester_name, d.full_name AS decider_name
             FROM menu_revisions v
             LEFT JOIN users r ON r.id = v.requested_by
             LEFT JOIN users d ON d.id = v.decided_by
             WHERE v.menu_id = :menu_id
             ORDER BY v.requested_at DESC, v.id DESC
             LIMIT 30',
            ['menu_id' => $menuId]
        );
    }

    /** @return array<string, mixed> Isi usulan yang disimpan sebagai JSON. */
    public static function proposed(array $revision): array
    {
        $decoded = json_decode((string) ($revision['proposed_json'] ?? ''), true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function forkCode(string $originCode, int $version): string
    {
        $candidate = mb_substr(sprintf('%s-V%d', $originCode, $version), 0, 30);

        return Menu::codeExists($candidate) ? Menu::nextCode() : $candidate;
    }
}
