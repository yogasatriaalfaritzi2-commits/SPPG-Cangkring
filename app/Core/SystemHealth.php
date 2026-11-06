<?php
declare(strict_types=1);

namespace App\Core;

use Throwable;

final class SystemHealth
{
    /**
     * Memeriksa status kesehatan sistem, basis data, storage, dan PHP runtime.
     *
     * @return array<string, mixed>
     */
    public static function check(): array
    {
        $status = 'healthy';
        $issues = [];

        // 1. Cek Database
        $dbStatus = 'ok';
        $dbLatency = 0.0;
        $dbVersion = 'Unknown';
        $totalTables = 0;
        $dbSizeMb = 0.0;

        try {
            $start = microtime(true);
            $pdo = Database::connection();
            $dbVersion = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
            $dbLatency = round((microtime(true) - $start) * 1000, 2);

            $stats = $pdo->query('
                SELECT 
                    COUNT(*) AS total_tables,
                    SUM(data_length + index_length) AS total_bytes
                FROM information_schema.tables 
                WHERE table_schema = DATABASE()
            ')->fetch(\PDO::FETCH_ASSOC);

            if ($stats !== false) {
                $totalTables = (int) ($stats['total_tables'] ?? 0);
                $dbSizeMb = round(((float) ($stats['total_bytes'] ?? 0)) / 1048576, 2);
            }
        } catch (Throwable $e) {
            $dbStatus = 'error';
            $status = 'degraded';
            $issues[] = 'Koneksi database bermasalah: ' . $e->getMessage();
        }

        // 2. Cek Direktori Storage & Log
        $storageWritable = is_writable(BASE_PATH . '/storage');
        $logsWritable = is_writable(BASE_PATH . '/storage/logs');
        $uploadsWritable = is_writable(BASE_PATH . '/storage/uploads') || is_writable(BASE_PATH . '/public/uploads');

        if (!$storageWritable || !$logsWritable) {
            $issues[] = 'Direktori storage atau logs tidak memiliki hak izin tulis (writable).';
            if ($status === 'healthy') {
                $status = 'warning';
            }
        }

        // 3. PHP Runtime Info
        $phpVersion = PHP_VERSION;
        $memoryLimit = ini_get('memory_limit') ?: '128M';
        $memoryUsageMb = round(memory_get_usage(true) / 1048576, 2);
        $maxExecutionTime = ini_get('max_execution_time') ?: '30';

        return [
            'status' => $status,
            'issues' => $issues,
            'database' => [
                'status' => $dbStatus,
                'latency_ms' => $dbLatency,
                'version' => $dbVersion,
                'tables' => $totalTables,
                'size_mb' => $dbSizeMb,
            ],
            'storage' => [
                'storage_writable' => $storageWritable,
                'logs_writable' => $logsWritable,
                'uploads_writable' => $uploadsWritable,
            ],
            'runtime' => [
                'php_version' => $phpVersion,
                'memory_limit' => $memoryLimit,
                'memory_usage_mb' => $memoryUsageMb,
                'max_execution_time' => $maxExecutionTime . 's',
                'server_time' => date('Y-m-d H:i:s'),
                'timezone' => date_default_timezone_get(),
            ],
        ];
    }
}
