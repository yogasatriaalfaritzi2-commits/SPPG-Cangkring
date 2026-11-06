<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Models\Beneficiary;
use App\Models\FoodIngredient;
use App\Models\Menu;
use App\Models\MenuRevision;
use App\Models\MenuRule;
use App\Models\MenuSchedule;
use App\Models\School;
use App\Models\SchoolServiceSchedule;

/**
 * Dashboard operasional MBG. Semua angka dibaca dari MySQL pada permintaan ini juga,
 * termasuk porsi yang dihitung dari rekap penerima manfaat dan rencana bahan yang
 * diturunkan dari jadwal yang sudah disetujui. Tidak ada nilai sementara yang
 * disimpan di sisi browser, dan rencana bahan di halaman ini tidak menyentuh stok.
 */
final class MbgDashboardController extends Controller
{
    private const SERIES_DAYS = 30;
    private const PLAN_HORIZON = 7;
    private const PARTNERSHIP_WINDOW = 60;

    public function index(Request $request): void
    {
        [$weekFrom, $weekTo] = $this->week($request);
        $today = date('Y-m-d');

        $from = date('Y-m-d', (int) strtotime($today . ' -' . (self::SERIES_DAYS - 1) . ' day'));
        $planFrom = date('Y-m-d', (int) strtotime($weekTo . ' +1 day'));
        $planTo = date('Y-m-d', (int) strtotime($planFrom . ' +' . (self::PLAN_HORIZON - 1) . ' day'));
        $next = MenuSchedule::between($this->emptyFilters($planFrom, $planTo), 200);
        $next = array_values(array_filter(
            $next,
            static fn (array $row): bool => in_array((string) $row['schedule_status'], MenuSchedule::PLANNED_STATUSES, true)
        ));

        $statusCounts = School::statusCounts();
        $nutritionCounts = Menu::nutritionCounts();

        $this->view('mbg/dashboard', [
            'title' => 'Dashboard MBG',
            'pageTitle' => 'Ringkasan Operasional MBG',
            'weekFrom' => $weekFrom,
            'weekTo' => $weekTo,
            'week' => MenuSchedule::summary($weekFrom, $weekTo),
            'series' => MenuSchedule::dailySeries($from, $today),
            'seriesFrom' => $from,
            'schools' => [
                'aktif' => $statusCounts[School::STATUS_ACTIVE] ?? 0,
                'nonaktif' => $statusCounts[School::STATUS_INACTIVE] ?? 0,
                'arsip' => $statusCounts[School::STATUS_ARCHIVED] ?? 0,
            ],
            'byLevel' => School::summaryByLevel(),
            'beneficiaries' => Beneficiary::totalAt($today),
            'menus' => [
                'total' => Menu::count(),
                'draft' => Menu::countByStatus(Menu::STATUS_DRAFT),
                'pending' => Menu::countByStatus(Menu::STATUS_PENDING),
                'approved' => Menu::countByStatus(Menu::STATUS_APPROVED),
                'scheduled' => Menu::countByStatus(Menu::STATUS_SCHEDULED),
            ],
            'menuChart' => Menu::statusDistribution(),
            'nutritionCounts' => $nutritionCounts,
            'nutritionUnknown' => $nutritionCounts['belum_tersedia'] ?? 0,
            'ingredients' => [
                'total' => FoodIngredient::count(),
            ],
            'rules' => [
                'menu' => count(MenuRule::active(MenuRule::SCOPE_MENU)),
                'week' => count(MenuRule::active(MenuRule::SCOPE_WEEK)),
            ],
            'upcoming' => MenuSchedule::upcoming(6),
            'pendingRevisions' => MenuRevision::pending(5),
            'alerts' => School::partnershipAlerts(self::PARTNERSHIP_WINDOW),
            'missingDays' => SchoolServiceSchedule::schoolsWithoutDays(6),
            'plan' => MenuSchedule::materialPlan($next),
            'planFrom' => $planFrom,
            'planTo' => $planTo,
            'canViewSchedules' => can('menu_schedules.view'),
            'canViewBeneficiaries' => can('beneficiaries.view'),
            'canViewMenus' => can('menus.view'),
            'canViewSchools' => can('schools.view'),
            'pageScripts' => ['vendor/chartjs/chart.umd.min.js', 'js/charts.js'],
        ]);
    }

    /** Pekan yang ditampilkan: mengikuti parameter tanggal bila keduanya valid. */
    private function week(Request $request): array
    {
        $from = str_replace('/', '-', trim($request->string('dari')));

        if (!date_is_valid($from)) {
            $from = date('Y-m-d', (int) strtotime('monday this week'));
        }

        return [$from, date('Y-m-d', (int) strtotime($from . ' +6 day'))];
    }

    /** @return array{from: string, to: string, menu: string, status: string, school: string} */
    private function emptyFilters(string $from, string $to): array
    {
        return ['from' => $from, 'to' => $to, 'menu' => '', 'status' => '', 'school' => ''];
    }
}
