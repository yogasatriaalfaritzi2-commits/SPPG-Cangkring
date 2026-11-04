<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

final class ProjectDocument extends Model
{
    protected static string $table = 'project_documents';

    /** @var array<int, string> */
    public const CATEGORIES = ['initial', 'construction', 'result', 'supporting', 'minutes', 'contract', 'receipt'];

    /**
     * Bukti transaksi dikelola dari halaman transaksi, bukan dari daftar dokumen
     * proyek, sehingga kategori ini dikecualikan ketika daftar dibuka tanpa filter.
     */
    public const TRANSACTION_CATEGORY = 'receipt';

    /**
     * @param array<string, mixed> $data
     */
    public static function create(array $data): int
    {
        Database::execute(
            'INSERT INTO project_documents (
                project_id, progress_report_id, financial_transaction_id, category, title, original_name, stored_name,
                mime_type, file_size, description, uploaded_by, uploaded_at
             ) VALUES (
                :project_id, :progress_report_id, :financial_transaction_id, :category, :title, :original_name, :stored_name,
                :mime_type, :file_size, :description, :uploaded_by, NOW()
             )',
            [
                'project_id' => (int) $data['project_id'],
                'progress_report_id' => $data['progress_report_id'] ?? null,
                'financial_transaction_id' => $data['financial_transaction_id'] ?? null,
                'category' => (string) $data['category'],
                'title' => (string) $data['title'],
                'original_name' => (string) $data['original_name'],
                'stored_name' => (string) $data['stored_name'],
                'mime_type' => (string) $data['mime_type'],
                'file_size' => (int) $data['file_size'],
                'description' => $data['description'] ?? null,
                'uploaded_by' => $data['uploaded_by'] ?? null,
            ]
        );

        return (int) Database::lastInsertId();
    }

    /** @return array<int, array<string, mixed>> */
    public static function forProject(int $projectId, string $category = ''): array
    {
        $sql = 'SELECT d.*, u.full_name AS uploader_name
                FROM project_documents d
                LEFT JOIN users u ON u.id = d.uploaded_by
                WHERE d.project_id = :project_id';
        $params = ['project_id' => $projectId];

        if (in_array($category, self::CATEGORIES, true)) {
            $sql .= ' AND d.category = :category';
            $params['category'] = $category;
        } else {
            $sql .= ' AND d.category <> \'' . self::TRANSACTION_CATEGORY . '\'';
        }

        return self::fetchAll($sql . ' ORDER BY d.uploaded_at DESC, d.id DESC', $params);
    }

    /** @return array<string, mixed>|null */
    public static function findWithProject(int $id): ?array
    {
        return self::fetchOne(
            'SELECT d.*, p.name AS project_name, p.project_code, p.is_archived
             FROM project_documents d
             INNER JOIN projects p ON p.id = d.project_id
             WHERE d.id = :id
             LIMIT 1',
            ['id' => $id]
        );
    }

    /**
     * Bukti transaksi beserta nomor pencatatan asalnya. Hanya kategori bukti
     * transaksi yang dapat dibuka melalui halaman transaksi.
     *
     * @return array<string, mixed>|null
     */
    public static function findWithTransaction(int $id): ?array
    {
        return self::fetchOne(
            'SELECT d.*, t.transaction_code, t.status AS transaction_status
             FROM project_documents d
             INNER JOIN financial_transactions t ON t.id = d.financial_transaction_id
             WHERE d.id = :id AND d.category = \'' . self::TRANSACTION_CATEGORY . '\'
             LIMIT 1',
            ['id' => $id]
        );
    }

    public static function countForProject(int $projectId): int
    {
        $row = self::fetchOne(
            'SELECT COUNT(*) AS total FROM project_documents WHERE project_id = :project_id',
            ['project_id' => $projectId]
        );

        return (int) ($row['total'] ?? 0);
    }

    public static function delete(int $id): void
    {
        Database::execute('DELETE FROM project_documents WHERE id = :id', ['id' => $id]);
    }
}
