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
        $page = max(1, $request->integer('page', 1));

        $result = ActivityLog::paginate($search, $module, $page, self::PER_PAGE);

        $this->view('activity.index', [
            'title' => 'Aktivitas',
            'pageTitle' => 'Log Aktivitas',
            'filters' => ['q' => $search, 'module' => $module],
            'result' => $result,
            'logs' => $result['rows'],
            'modules' => ActivityLog::modules(),
        ]);
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
