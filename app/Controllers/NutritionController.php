<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Csv;
use App\Core\Exceptions\NotFoundHttpException;
use App\Core\Request;
use App\Core\Session;
use App\Models\ActivityLog;
use App\Models\IngredientNutrient;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuSchedule;
use App\Models\NutritionReference;
use App\Models\NutritionTarget;
use App\Models\NutritionTargetGroup;
use App\Models\SchoolLevel;

/**
 * Analisis dan evaluasi gizi menu terhadap target yang dikonfigurasi per kelompok sasaran.
 *
 * Target tidak pernah disamakan untuk semua usia: tiap angka melekat pada kelompok
 * (jenjang, rentang umur, jenis kelamin) dan pada referensi bersumber. Angka yang
 * dihasilkan halaman ini adalah estimasi dari katalog bahan, bukan pemeriksaan
 * laboratorium, dan target yang belum diverifikasi selalu ditandai.
 */
final class NutritionController extends Controller
{
    private const MAX_DAYS = 31;
    private const SEXES = ['l', 'p', 'semua'];

    public function index(Request $request): void
    {
        $filters = $this->menuFilters($request);
        $result = Menu::paginate($filters, max(1, $request->integer('halaman')), 15);
        $groups = NutritionTargetGroup::activeList();

        $this->view('gizi/index', [
            'title' => 'Analisis Gizi',
            'pageTitle' => 'Analisis Gizi Menu MBG',
            'result' => $result,
            'filters' => $filters,
            'groups' => $groups,
            'groupId' => $this->groupSelection($request, $groups),
            'categories' => MenuCategory::activeList(),
            'statuses' => Menu::STATUSES,
            'bases' => NutritionTarget::BASES,
            'canManage' => can('nutrition.manage'),
        ]);
    }

    /** Evaluasi satu menu pada satu kelompok sasaran. */
    public function show(Request $request, string $id): void
    {
        $menu = $this->findMenu($id);
        $menuId = (int) $menu['id'];
        $computation = Menu::compute($menuId);
        $groups = NutritionTargetGroup::activeList();
        $groupId = $this->groupSelection($request, $groups);
        $group = $this->groupOf($groups, $groupId);

        $evaluation = $group === null
            ? null
            : NutritionTarget::evaluate(
                (array) $computation['per_serving'],
                (int) $group['id'],
                NutritionTarget::BASIS_PER_SERVING
            );

        $this->view('gizi/analisis', [
            'title' => 'Analisis ' . (string) $menu['menu_name'],
            'pageTitle' => 'Analisis Gizi Menu',
            'menu' => $menu,
            'computation' => $computation,
            'evaluation' => $evaluation,
            'groups' => $groups,
            'group' => $group,
            'groupId' => $groupId,
            'basis' => NutritionTarget::BASIS_PER_SERVING,
            'references' => NutritionReference::activeList(),
            'targets' => $group === null ? [] : NutritionTarget::forGroup((int) $group['id']),
            'canManage' => can('nutrition.manage'),
            'canExport' => can('nutrition.export'),
        ]);
    }

    /**
     * Evaluasi harian: menjumlahkan satu porsi tiap menu yang dijadwalkan pada satu
     * tanggal, lalu membandingkannya dengan target dasar "per hari".
     */
    public function daily(Request $request): void
    {
        [$from, $to] = $this->range($request);
        $groups = NutritionTargetGroup::activeList();
        $group = $this->groupOf($groups, $this->groupSelection($request, $groups));

        $schedules = array_values(array_filter(
            MenuSchedule::between([
                'from' => $from,
                'to' => $to,
                'menu' => '',
                'status' => '',
                'school' => (string) max(0, $request->integer('sekolah')),
            ], 400),
            static fn (array $row): bool => in_array((string) $row['schedule_status'], MenuSchedule::PLANNED_STATUSES, true)
        ));

        $cache = [];
        $days = [];

        for ($offset = 0; $offset <= (int) ((strtotime($to) - strtotime($from)) / 86400); $offset++) {
            $date = date('Y-m-d', (int) strtotime($from . ' +' . $offset . ' day'));
            $days[$date] = ['date' => $date, 'menus' => [], 'values' => [], 'portions' => 0, 'cost' => 0.0];
        }

        foreach ($schedules as $schedule) {
            $date = substr((string) $schedule['service_date'], 0, 10);
            if (!isset($days[$date])) {
                continue;
            }

            $menuId = (int) $schedule['menu_id'];
            if (isset($days[$date]['menus'][$menuId])) {
                // Menu yang sama dua slot pada hari yang sama dihitung sekali saja agar
                // nilai harian tidak terlipat dua.
                $days[$date]['menus'][$menuId]['slots'][] = meal_slot_label($schedule['meal_slot']);
                continue;
            }

            if (!array_key_exists($menuId, $cache)) {
                $cache[$menuId] = Menu::compute($menuId);
            }

            $days[$date]['menus'][$menuId] = [
                'menu_code' => (string) $schedule['menu_code'],
                'menu_name' => (string) $schedule['menu_name'],
                'nutrition_status' => (string) $cache[$menuId]['nutrition_status'],
                'slots' => [meal_slot_label($schedule['meal_slot'])],
                'portions' => (int) $schedule['portion_total'],
            ];
            $days[$date]['portions'] += (int) $schedule['portion_total'];

            foreach ((array) $cache[$menuId]['per_serving'] as $code => $value) {
                $days[$date]['values'][(string) $code] = ($days[$date]['values'][(string) $code] ?? 0.0) + (float) $value;
            }

            if ($cache[$menuId]['cost_per_serving'] !== null) {
                $days[$date]['cost'] += (float) $cache[$menuId]['cost_per_serving'];
            }
        }

        $rows = [];
        foreach ($days as $day) {
            $rows[] = $group === null ? $day + ['evaluation' => null] : $day + [
                'evaluation' => NutritionTarget::evaluate(
                    array_map(static fn (float $v): float => round($v, 3), $day['values']),
                    (int) $group['id'],
                    NutritionTarget::BASIS_PER_DAY
                ),
            ];
        }

        $this->view('gizi/harian', [
            'title' => 'Evaluasi Gizi Harian',
            'pageTitle' => 'Evaluasi Gizi Harian',
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'groups' => $groups,
            'group' => $group,
            'basis' => NutritionTarget::BASIS_PER_DAY,
            'incomplete' => array_values(array_filter(
                $rows,
                static fn (array $row): bool => $row['menus'] !== []
                    && in_array('belum_tersedia', array_column($row['menus'], 'nutrition_status'), true)
            )),
            'canExport' => can('nutrition.export'),
        ]);
    }

    /** Unduh hasil analisis per kelompok sasaran untuk menu yang tampil pada filter. */
    public function export(Request $request): void
    {
        $groups = NutritionTargetGroup::activeList();
        $group = $this->groupOf($groups, $this->groupSelection($request, $groups));
        $filters = $this->menuFilters($request);
        $menus = Menu::paginate($filters, 1, 50)['rows'];

        $header = ['Kode Menu', 'Nama Menu', 'Kategori', 'Slot', 'Status Menu', 'Status Data Gizi'];
        $codes = $this->codes();
        foreach ($codes as $code) {
            $header[] = nutrient_label($code) . ' per porsi';
        }
        $header[] = 'Estimasi Biaya per Porsi';
        $header[] = 'Kelompok Sasaran';
        $header[] = 'Catatan Target';

        $rows = [];
        foreach ($menus as $menu) {
            $computation = Menu::compute((int) $menu['id']);
            $values = (array) $computation['per_serving'];
            $line = [
                (string) $menu['menu_code'],
                (string) $menu['menu_name'],
                (string) $menu['category_name'],
                meal_slot_label($menu['meal_slot'] ?? ''),
                menu_status_label($menu['menu_status']),
                nutrition_status_label($menu['nutrition_status']),
            ];

            foreach ($codes as $code) {
                $line[] = isset($values[$code])
                    ? $this->decimal((float) $values[$code])
                    : '';
            }

            $line[] = $computation['cost_per_serving'] === null
                ? 'belum ada harga bahan'
                : number_format((float) $computation['cost_per_serving'], 2, '.', '');

            if ($group === null) {
                $line[] = '';
                $line[] = 'Kelompok sasaran belum dibuat, jadi nilai ini belum dibandingkan dengan target.';
            } else {
                $evaluation = NutritionTarget::evaluate(
                    $values,
                    (int) $group['id'],
                    NutritionTarget::BASIS_PER_SERVING
                );
                $line[] = (string) $group['group_name'];
                $line[] = $this->noteOf($evaluation);
            }

            $rows[] = $line;
        }

        Csv::send(
            'analisis-gizi-' . ($group === null ? 'tanpa-kelompok' : mb_strtolower((string) $group['group_code']))
                . '-' . date('Y-m-d') . '.csv',
            $header,
            $rows
        );
    }

    /** Kelompok sasaran beserta nilai targetnya. */
    public function targets(Request $request): void
    {
        $groups = NutritionTargetGroup::allOrdered();
        $group = $this->groupOf($groups, $this->groupSelection($request, $groups));

        $this->view('gizi/target', [
            'title' => 'Target Gizi per Kelompok Sasaran',
            'pageTitle' => 'Target Gizi per Kelompok Sasaran',
            'groups' => $groups,
            'group' => $group,
            'groupId' => $group === null ? 0 : (int) $group['id'],
            'targets' => $group === null ? [] : NutritionTarget::forGroup((int) $group['id']),
            'levels' => SchoolLevel::activeList(),
            'references' => NutritionReference::allOrdered(),
            'cores' => IngredientNutrient::CORE_LABELS,
            'coreUnits' => IngredientNutrient::CORE_UNITS,
            'bases' => NutritionTarget::BASES,
            'sexes' => ['l' => 'Laki-laki', 'p' => 'Perempuan', 'semua' => 'Semua'],
            'canManage' => can('nutrition.manage'),
        ]);
    }

    public function storeGroup(Request $request): void
    {
        $actor = $this->actor();
        $input = $this->readGroup($request);
        [$data, $errors] = $this->validateGroup($input, null);

        if ($errors !== []) {
            $this->reject('/target-gizi', $errors[0], $input, 'kelompok');
        }

        /** @var array<string, mixed> $data */
        $data['created_by'] = (int) $actor['id'];
        $data['updated_by'] = (int) $actor['id'];
        $id = NutritionTargetGroup::create($data);

        ActivityLog::record(
            (int) $actor['id'],
            'nutrition_groups.create',
            'nutrition',
            'Menambah kelompok sasaran ' . $input['group_name'] . ' (' . $input['group_code'] . ').',
            $request->ip(),
            $request->userAgent()
        );

        Session::clearOld();
        Session::flash('success', 'Kelompok sasaran dibuat. Isi nilai targetnya agar evaluasi dapat berjalan.');
        $this->redirect('/target-gizi?kelompok=' . $id);
    }

    public function updateGroup(Request $request, string $id): void
    {
        $actor = $this->actor();
        $groupId = $this->numericId($id);
        $group = NutritionTargetGroup::findDetail($groupId);
        if ($group === null) {
            throw new NotFoundHttpException();
        }

        $input = $this->readGroup($request);
        [$data, $errors] = $this->validateGroup($input, $groupId);

        if ($errors !== []) {
            $this->reject('/target-gizi?kelompok=' . $groupId, $errors[0], $input, 'kelompok');
        }

        /** @var array<string, mixed> $data */
        $data['updated_by'] = (int) $actor['id'];
        NutritionTargetGroup::update($groupId, $data);

        ActivityLog::record(
            (int) $actor['id'],
            'nutrition_groups.update',
            'nutrition',
            'Mengubah kelompok sasaran ' . $input['group_name'] . '.',
            $request->ip(),
            $request->userAgent()
        );

        Session::clearOld();
        Session::flash('success', 'Kelompok sasaran diperbarui. Evaluasi memakai angka target yang masih tersimpan.');
        $this->redirect('/target-gizi?kelompok=' . $groupId);
    }

    public function destroyGroup(Request $request, string $id): void
    {
        $actor = $this->actor();
        $groupId = $this->numericId($id);
        $group = NutritionTargetGroup::findDetail($groupId);
        if ($group === null) {
            throw new NotFoundHttpException();
        }

        $used = NutritionTargetGroup::usageCount($groupId);
        if ($used > 0) {
            Session::flash('danger', 'Kelompok ini masih dipakai ' . $used . ' nilai target. Nonaktifkan saja agar'
                . ' riwayat evaluasi tetap terbaca.');
            $this->redirect('/target-gizi?kelompok=' . $groupId);
        }

        NutritionTargetGroup::delete($groupId);

        ActivityLog::record(
            (int) $actor['id'],
            'nutrition_groups.delete',
            'nutrition',
            'Menghapus kelompok sasaran ' . $group['group_name'] . ' yang belum dipakai target mana pun.',
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', 'Kelompok sasaran dihapus.');
        $this->redirect('/target-gizi');
    }

    public function storeTarget(Request $request): void
    {
        $actor = $this->actor();
        $input = $this->readTarget($request);
        [$data, $errors] = $this->validateTarget($input, null);

        if ($errors !== []) {
            $this->reject('/target-gizi?kelompok=' . (int) $input['group_id'], $errors[0], $input, 'nilai');
        }

        /** @var array<string, mixed> $data */
        $data['created_by'] = (int) $actor['id'];
        $data['updated_by'] = (int) $actor['id'];
        NutritionTarget::create($data);

        $groupId = (int) $input['group_id'];
        ActivityLog::record(
            (int) $actor['id'],
            'nutrition_targets.create',
            'nutrition',
            'Menambah target ' . nutrient_label($input['nutrient_code']) . ' (' . $input['target_basis'] . ')'
            . ' pada kelompok ' . $this->groupName($groupId) . '.',
            $request->ip(),
            $request->userAgent()
        );

        Session::clearOld();
        Session::flash('success', 'Nilai target tersimpan.');
        $this->redirect('/target-gizi?kelompok=' . $groupId);
    }

    public function updateTarget(Request $request, string $id): void
    {
        $actor = $this->actor();
        $targetId = $this->numericId($id);
        $target = NutritionTarget::findDetail($targetId);
        if ($target === null) {
            throw new NotFoundHttpException();
        }

        $input = $this->readTarget($request);
        $input['group_id'] = (string) (int) $target['group_id'];
        [$data, $errors] = $this->validateTarget($input, $targetId);

        if ($errors !== []) {
            $this->reject('/target-gizi?kelompok=' . (int) $target['group_id'], $errors[0], $input, 'nilai');
        }

        /** @var array<string, mixed> $data */
        $data['updated_by'] = (int) $actor['id'];
        NutritionTarget::update($targetId, $data);

        ActivityLog::record(
            (int) $actor['id'],
            'nutrition_targets.update',
            'nutrition',
            'Mengubah target ' . nutrient_label($input['nutrient_code']) . ' pada kelompok '
            . $this->groupName((int) $target['group_id']) . ' beserta sumber dan tanggal pembaruannya.',
            $request->ip(),
            $request->userAgent()
        );

        Session::clearOld();
        Session::flash('success', 'Nilai target diperbarui.');
        $this->redirect('/target-gizi?kelompok=' . (int) $target['group_id']);
    }

    public function destroyTarget(Request $request, string $id): void
    {
        $actor = $this->actor();
        $targetId = $this->numericId($id);
        $target = NutritionTarget::findDetail($targetId);
        if ($target === null) {
            throw new NotFoundHttpException();
        }

        NutritionTarget::delete($targetId);

        ActivityLog::record(
            (int) $actor['id'],
            'nutrition_targets.delete',
            'nutrition',
            'Menghapus target ' . nutrient_label($target['nutrient_code']) . ' (' . $target['target_basis']
            . ') pada kelompok ' . $target['group_name'] . '.',
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', 'Nilai target dihapus. Menu yang memakainya kini tampil sebagai "belum ada target".');
        $this->redirect('/target-gizi?kelompok=' . (int) $target['group_id']);
    }

    /**
     * @param array<string, mixed>|null $existing
     * @return array{0: array<string, mixed>|null, 1: array<int, string>}
     */
    private function validateGroup(array $input, ?int $exceptId): array
    {
        $errors = [];
        $code = mb_strtoupper(preg_replace('/[^A-Z0-9_-]/i', '', mb_substr(trim((string) $input['group_code']), 0, 24)) ?? '');
        $name = preg_replace('/\s+/', ' ', trim((string) $input['group_name'])) ?? '';

        if ($code === '') {
            $errors[] = 'Kode kelompok wajib diisi dengan huruf dan angka, contoh TK-A atau SD-BAWAH.';
        } elseif (NutritionTargetGroup::codeExists($code, $exceptId)) {
            $errors[] = 'Kode ' . $code . ' sudah dipakai kelompok lain.';
        }

        if ($name === '') {
            $errors[] = 'Nama kelompok wajib diisi.';
        }

        $levelId = (int) $input['level_id'];
        if ($input['level_id'] !== '' && !$this->levelExists($levelId)) {
            $errors[] = 'Jenjang yang dipilih tidak ada di daftar jenjang aktif.';
        }

        $ageMin = $input['age_min'] === '' ? null : (int) $input['age_min'];
        $ageMax = $input['age_max'] === '' ? null : (int) $input['age_max'];
        foreach (['age_min' => $ageMin, 'age_max' => $ageMax] as $label => $value) {
            if ($value !== null && ($value < 0 || $value > 120)) {
                $errors[] = 'Rentang umur harus antara 0 sampai 120 tahun.';
                break;
            }
        }
        if ($ageMin !== null && $ageMax !== null && $ageMin > $ageMax) {
            $errors[] = 'Umur minimal tidak boleh lebih besar dari umur maksimal.';
        }

        if ($levelId <= 0 && $ageMin === null && $ageMax === null) {
            $errors[] = 'Kelompok harus dibatasi jenjang atau rentang umurnya. Jangan memakai satu standar'
                . ' kebutuhan gizi untuk semua usia.';
        }

        if (!in_array((string) $input['sex'], self::SEXES, true)) {
            $errors[] = 'Jenis kelamin hanya menerima Laki-laki, Perempuan, atau Semua.';
        }

        if (mb_strlen((string) $input['notes']) > 500) {
            $errors[] = 'Catatan kelompok maksimal 500 karakter.';
        }

        if ($errors !== []) {
            return [null, $errors];
        }

        return [[
            'group_code' => $code,
            'group_name' => $name,
            'level_id' => $levelId > 0 ? $levelId : '',
            'age_min' => $ageMin === null ? '' : (string) $ageMin,
            'age_max' => $ageMax === null ? '' : (string) $ageMax,
            'sex' => (string) $input['sex'],
            'notes' => (string) $input['notes'],
            'is_active' => $input['is_active'],
        ], []];
    }

    /**
     * @param array<string, mixed>|null $existing
     * @return array{0: array<string, mixed>|null, 1: array<int, string>}
     */
    private function validateTarget(array $input, ?int $exceptId): array
    {
        $errors = [];
        $groupId = (int) $input['group_id'];
        $code = mb_strtolower(preg_replace('/[^a-z0-9_]/', '', mb_substr(trim((string) $input['nutrient_code']), 0, 40)) ?? '');
        $unit = mb_strtolower(trim((string) $input['nutrient_unit']));
        $basis = (string) $input['target_basis'];

        if ($groupId <= 0 || NutritionTargetGroup::findDetail($groupId) === null) {
            $errors[] = 'Kelompok sasaran wajib dipilih.';
        }

        if ($code === '') {
            $errors[] = 'Kode zat gizi wajib diisi. Pilih dari daftar atau tulis kode baru beserta satuannya.';
        } elseif (isset(IngredientNutrient::CORE_UNITS[$code]) && $unit !== IngredientNutrient::CORE_UNITS[$code]) {
            $errors[] = nutrient_label($code) . ' harus memakai satuan ' . IngredientNutrient::CORE_UNITS[$code]
                . ' agar tidak tertukar dengan satuan lain.';
        }

        if ($unit === '') {
            $errors[] = 'Satuan target wajib diisi, misalnya kkal atau gram.';
        }

        $min = $this->bound($input['min_value'], 'minimal', $errors);
        $max = $this->bound($input['max_value'], 'maksimal', $errors);
        if ($min === null && $max === null && $errors === []) {
            $errors[] = 'Isi batas minimal atau maksimal. Target tanpa kedua batas tidak dapat menilai apa pun.';
        }
        if ($min !== null && $max !== null && $min > $max) {
            $errors[] = 'Batas minimal tidak boleh melebihi batas maksimal.';
        }

        if (!in_array($basis, NutritionTarget::BASES, true)) {
            $errors[] = 'Dasar target hanya per porsi atau per hari.';
        }

        if ($errors === [] && NutritionTarget::scopeExists($groupId, $code, $basis, $exceptId)) {
            $errors[] = 'Target ' . nutrient_label($code) . ' dengan dasar ' . $this->basisLabel($basis)
                . ' sudah ada pada kelompok ini. Ubah baris yang ada, jangan tambah duplikatnya.';
        }

        $referenceId = (int) $input['reference_id'];
        $reference = $referenceId > 0 ? $this->reference($referenceId) : null;
        if ($referenceId > 0 && $reference === null) {
            $errors[] = 'Referensi yang dipilih tidak ada pada daftar sumber data gizi.';
        }
        if (!empty($input['is_verified']) && $reference === null) {
            $errors[] = 'Target hanya boleh ditandai terverifikasi bila sumber referensinya dipilih.';
        }

        if (mb_strlen((string) $input['notes']) > 255) {
            $errors[] = 'Catatan target maksimal 255 karakter.';
        }

        if ($errors !== []) {
            return [null, $errors];
        }

        return [[
            'group_id' => $groupId,
            'nutrient_code' => $code,
            'nutrient_unit' => $unit,
            'min_value' => $min === null ? '' : (string) $min,
            'max_value' => $max === null ? '' : (string) $max,
            'target_basis' => $basis,
            'reference_id' => $referenceId > 0 ? (string) $referenceId : '',
            'is_verified' => !empty($input['is_verified']) ? 1 : 0,
            'notes' => (string) $input['notes'],
        ], []];
    }

    /** @return array<string, mixed> */
    private function readGroup(Request $request): array
    {
        return [
            'group_code' => trim($request->string('group_code')),
            'group_name' => trim($request->string('group_name')),
            'level_id' => $request->integer('level_id') > 0 ? (string) $request->integer('level_id') : '',
            'age_min' => trim($request->string('age_min')),
            'age_max' => trim($request->string('age_max')),
            'sex' => mb_strtolower(trim($request->string('sex', 'semua'))),
            'notes' => preg_replace('/\s+/', ' ', trim($request->string('notes'))) ?? '',
            'is_active' => $request->boolean('is_active') ? 1 : 0,
        ];
    }

    /** @return array<string, mixed> */
    private function readTarget(Request $request): array
    {
        return [
            'group_id' => (string) $request->integer('group_id'),
            'nutrient_code' => trim($request->string('nutrient_code')),
            'nutrient_unit' => trim($request->string('nutrient_unit')),
            'min_value' => str_replace(',', '.', trim($request->string('min_value'))),
            'max_value' => str_replace(',', '.', trim($request->string('max_value'))),
            'target_basis' => mb_strtolower(trim($request->string('target_basis', NutritionTarget::BASIS_PER_SERVING))),
            'reference_id' => (string) $request->integer('reference_id'),
            'is_verified' => $request->boolean('is_verified') ? 1 : 0,
            'notes' => preg_replace('/\s+/', ' ', trim($request->string('notes'))) ?? '',
        ];
    }

    /** @return array{search: string, category: string, status: string, nutrition: string, sort: string, dir: string} */
    private function menuFilters(Request $request): array
    {
        $sort = $request->string('urut');
        $nutrition = $request->string('gizi');

        return [
            'search' => trim($request->string('cari')),
            'category' => (string) max(0, $request->integer('kategori')),
            'status' => in_array($request->string('status'), Menu::STATUSES, true) ? $request->string('status') : '',
            'nutrition' => in_array($nutrition, ['lengkap', 'sebagian', 'belum_tersedia'], true) ? $nutrition : '',
            'sort' => isset(Menu::SORTABLE[$sort]) ? $sort : '',
            'dir' => mb_strtolower($request->string('arah')) === 'asc' ? 'asc' : 'desc',
        ];
    }

    /** @return array{0: string, 1: string} */
    private function range(Request $request): array
    {
        $from = str_replace('/', '-', trim($request->string('dari')));
        $to = str_replace('/', '-', trim($request->string('sampai')));

        if (!date_is_valid($from)) {
            $from = date('Y-m-d', (int) strtotime('monday this week'));
        }
        if (!date_is_valid($to)) {
            $to = date('Y-m-d', (int) strtotime($from . ' +6 day'));
        }
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }

        $limit = date('Y-m-d', (int) strtotime($from . ' +' . (self::MAX_DAYS - 1) . ' day'));
        if ($to > $limit) {
            $to = $limit;
        }

        return [$from, $to];
    }

    /** Zat gizi yang dijadikan kolom analisis: inti ditambah yang sudah punya target. */
    private function codes(): array
    {
        return array_values(array_unique(array_merge(
            IngredientNutrient::CORE_CODES,
            NutritionTarget::usedCodes()
        )));
    }

    private function noteOf(array $evaluation): string
    {
        $parts = [
            'Sesuai target: ' . (int) $evaluation['ok'],
            'Di bawah target: ' . (int) $evaluation['below'],
            'Di atas target: ' . (int) $evaluation['above'],
        ];
        if ((int) $evaluation['unverified'] > 0) {
            $parts[] = (int) $evaluation['unverified']
                . ' target belum diverifikasi, jangan dibaca sebagai standar resmi';
        }
        $parts[] = 'Angka gizi adalah estimasi dari katalog bahan, bukan hasil pemeriksaan laboratorium';

        return implode('; ', $parts) . '.';
    }

    /** Angka pendek untuk CSV: nol di belakang dibuang, desimal memakai titik. */
    private function decimal(float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');

        return $text === '' ? '0' : $text;
    }

    /** @param array<int, array<string, mixed>> $groups @return array<string, mixed>|null */
    private function groupOf(array $groups, int $groupId): ?array
    {
        foreach ($groups as $group) {
            if ((int) $group['id'] === $groupId) {
                return $group;
            }
        }

        return $groups === [] ? null : $groups[0];
    }

    /** @param array<int, array<string, mixed>> $groups */
    private function groupSelection(Request $request, array $groups): int
    {
        $groupId = max(0, $request->integer('kelompok'));
        foreach ($groups as $group) {
            if ((int) $group['id'] === $groupId) {
                return $groupId;
            }
        }

        return $groups === [] ? 0 : (int) $groups[0]['id'];
    }

    private function levelExists(int $levelId): bool
    {
        foreach (SchoolLevel::activeList() as $level) {
            if ((int) $level['id'] === $levelId) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed>|null */
    private function reference(int $id): ?array
    {
        foreach (NutritionReference::allOrdered() as $row) {
            if ((int) $row['id'] === $id) {
                return $row;
            }
        }

        return null;
    }

    private function groupName(int $id): string
    {
        $group = NutritionTargetGroup::findDetail($id);

        return $group === null ? (string) $id : (string) $group['group_name'];
    }

    private function basisLabel(string $basis): string
    {
        return $basis === NutritionTarget::BASIS_PER_DAY ? 'per hari' : 'per porsi';
    }

    /**
     * Batas target hanya menerima angka non-negatif. Nilai kosong dikembalikan sebagai
     * null sementara format yang salah langsung dicatat sebagai kesalahan, supaya tidak
     * berubah menjadi 0 dan menurunkan hasil evaluasi.
     *
     * @param array<int, string> $errors
     */
    private function bound(mixed $raw, string $label, array &$errors): ?float
    {
        $value = trim((string) ($raw ?? ''));
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{1,9}(\.\d{1,4})?$/', $value) !== 1) {
            $errors[] = 'Batas ' . $label . ' harus angka tanpa tanda minus, maksimal 4 angka setelah koma.';

            return null;
        }

        return round((float) $value, 4);
    }

    private function numericId(string $id): int
    {
        if (!ctype_digit($id)) {
            throw new NotFoundHttpException();
        }

        return (int) $id;
    }

    /** @return array<string, mixed> */
    private function findMenu(string $id): array
    {
        $menuId = $this->numericId($id);
        $menu = Menu::findWithNames($menuId);
        if ($menu === null) {
            throw new NotFoundHttpException();
        }

        return $menu;
    }

    /** @param array<string, mixed> $input */
    private function reject(string $path, string $message, array $input, string $form): never
    {
        Session::flash('danger', $message);
        Session::putOld($input + ['_form' => $form]);
        $this->redirect($path);
    }
}
