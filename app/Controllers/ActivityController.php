<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Models\ActivityLog;

final class ActivityController extends Controller
{
    private const PER_PAGE = 15;

    public function index(Request $request): void
    {
        $search = $this->normalizeSearch($request->string('q'));
        $module = $this->normalizeModule($request->string('module'));
        $cluster = $this->normalizeCluster($request->string('cluster'));
        $startDate = $this->normalizeDate($request->string('start_date'));
        $endDate = $this->normalizeDate($request->string('end_date'));
        $page = max(1, $request->integer('page', 1));

        $result = ActivityLog::paginate(
            $search,
            $module,
            $page,
            self::PER_PAGE,
            $startDate,
            $endDate,
            $cluster
        );

        $summary = ActivityLog::clusterSummary();

        $this->view('activity.index', [
            'title' => 'Aktivitas',
            'pageTitle' => 'Log Aktivitas & Audit Perubahan',
            'filters' => [
                'q' => $search,
                'module' => $module,
                'cluster' => $cluster,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'result' => $result,
            'logs' => $result['rows'],
            'modules' => ActivityLog::modules(),
            'summary' => $summary,
        ]);
    }

    private function normalizeCluster(string $value): string
    {
        $value = strtolower(trim($value));
        return in_array($value, ['mbg', 'keuangan', 'proyek'], true) ? $value : '';
    }

    private function normalizeDate(string $value): string
    {
        $value = trim($value);
        if ($value !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }
        return '';
    }

    private function normalizeSearch(string $value): string
    {
        $value = preg_replace('/\s+/', ' ', trim($value)) ?? '';

        return mb_substr($value, 0, 60);
    }

    private function normalizeModule(string $value): string
    {
        $value = strtolower(trim($value));

        return preg_replace('/[^a-z0-9_]/', '', $value) ?? '';
    }
}
