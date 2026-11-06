<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Csv;
use App\Core\Database;
use App\Core\Exceptions\NotFoundHttpException;
use App\Core\Request;
use App\Core\Session;
use App\Models\ActivityLog;
use App\Models\Beneficiary;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\MenuRule;
use App\Models\MenuSchedule;
use App\Models\Notification;
use App\Models\School;
use App\Models\SchoolServiceSchedule;

/**
 * Perencanaan jadwal menu harian MBG beserta rencana kebutuhan bahannya.
 *
 * Porsi per sekolah diambil dari rekap jumlah penerima manfaat, bukan dari perkiraan,
 * dan jadwal ditolak bila sekolah tidak punya hari pelayanan untuk slot tersebut atau
 * menu belum disetujui. Rencana bahan hanya menghitung kebutuhan: stok gudang tidak
 * dikurangi pada tahap perencanaan karena realisasi ada di modul pengadaan.
 */
final class MenuScheduleController extends Controller
{
    private const WEEK_DAYS = 7;

    public function index(Request $request): void
    {
        $start = $this->startDate($request);
        $filters = $this->filters($request, $start);
        $end = date('Y-m-d', (int) strtotime($start . ' +' . (self::WEEK_DAYS - 1) . ' day'));

        $schedules = MenuSchedule::between(['from' => $start, 'to' => $end] + $filters, 300);
        $grid = [];
        for ($offset = 0; $offset < self::WEEK_DAYS; $offset++) {
            $date = date('Y-m-d', (int) strtotime($start . ' +' . $offset . ' day'));
            $grid[$date] = [
                'date' => $date,
                'weekday' => (int) date('N', (int) strtotime($date)),
                'rows' => array_values(array_filter(
                    $schedules,
                    static fn (array $row): bool => substr((string) $row['service_date'], 0, 10) === $date
                )),
            ];
        }

        $this->view('jadwal-menu/index', [
            'title' => 'Jadwal Menu Mingguan',
            'pageTitle' => 'Jadwal Menu MBG',
            'start' => $start,
            'end' => $end,
            'grid' => $grid,
            'filters' => $filters,
            'summary' => MenuSchedule::summary($start, $end),
            'statuses' => MenuSchedule::STATUSES,
            'schools' => School::optionList(),
            'menus' => Menu::schedulableList(),
            'slots' => MenuCategory::SLOTS,
            'canManage' => can('menu_schedules.manage'),
            'weekRules' => MenuRule::active(MenuRule::SCOPE_WEEK),
            'weekFindings' => $this->weekFindings($schedules),
        ]);
    }

    public function create(Request $request): void
    {
        $old = $this->pullOld();
        $date = (string) ($old['service_date'] ?? $request->string('tanggal'));
        if (!date_is_valid($date)) {
            $date = date('Y-m-d');
        }

        $this->view('jadwal-menu/form', [
            'title' => 'Susun Jadwal Menu',
            'pageTitle' => 'Susun Jadwal Menu Harian',
            'mode' => 'create',
            'form' => [
                'service_date' => $date,
                'menu_id' => (string) ($old['menu_id'] ?? $request->integer('menu')),
                'meal_slot' => (string) ($old['meal_slot'] ?? $request->string('slot', 'makan_siang')),
                'notes' => (string) ($old['notes'] ?? ''),
            ],
            'allocations' => $this->oldAllocations($old) ?: $this->suggestedAllocations(
                $date,
                (string) ($old['meal_slot'] ?? $request->string('slot', 'makan_siang'))
            ),
            'schools' => School::optionList(),
            'menus' => Menu::schedulableList(),
            'menu' => null,
            'slots' => MenuCategory::SLOTS,
            'weekday' => (int) date('N', (int) strtotime($date)),
            'serviceDay' => SchoolServiceSchedule::schoolsOn(
                (int) date('N', (int) strtotime($date)),
                (string) ($old['meal_slot'] ?? $request->string('slot', 'makan_siang'))
            ),
        ]);
    }

    public function store(Request $request): void
    {
        $actor = $this->actor();
        $input = $this->readForm($request);
        $allocations = $this->readAllocations($request);
        [$data, $errors] = $this->validate($input, $allocations, null);

        if ($errors !== []) {
            $this->reject('/jadwal-menu/tambah', $errors[0], $input, $allocations);
        }

        /** @var array<string, mixed> $data */
        $id = MenuSchedule::create($data, $allocations, (int) $actor['id']);

        ActivityLog::record(
            (int) $actor['id'],
            'menu_schedules.create',
            'menus',
            'Menyusun jadwal menu ' . $input['service_date'] . ' ' . meal_slot_label($data['meal_slot'])
            . ' dengan ' . count($allocations) . ' alokasi sekolah.',
            $request->ip(),
            $request->userAgent()
        );

        Session::clearOld();
        Session::flash('success', 'Jadwal tersimpan sebagai draf. Ajukan jadwal agar disetujui sebelum jadi acuan porsi.');
        $this->redirect('/jadwal-menu/' . $id);
    }

    public function show(Request $request, string $id): void
    {
        $schedule = $this->findSchedule($id);
        $scheduleId = (int) $schedule['id'];

        $this->view('jadwal-menu/show', [
            'title' => (string) $schedule['schedule_code'],
            'pageTitle' => 'Detail Jadwal Menu',
            'schedule' => $schedule,
            'allocations' => MenuSchedule::allocations($scheduleId),
            'plan' => MenuSchedule::materialPlan([$schedule]),
            'computation' => Menu::compute((int) $schedule['menu_id']),
            'findings' => MenuRule::assess(
                MenuRule::active(MenuRule::SCOPE_MENU),
                Menu::compute((int) $schedule['menu_id'])
            ),
            'canManage' => can('menu_schedules.manage'),
            'canDecide' => can('menus.approve') || can('menu_schedules.manage'),
            'editable' => $this->canEdit($schedule),
            'printable' => in_array((string) $schedule['schedule_status'], ['approved', 'completed'], true),
        ]);
    }

    /** Mencetak lembar surat jalan distribusi MBG siap cetak atau simpan ke PDF. */
    public function printDeliveryNote(Request $request, string $id): void
    {
        $schedule = $this->findSchedule($id);
        $scheduleId = (int) $schedule['id'];

        $this->view('jadwal-menu/delivery_note', [
            'title' => 'Surat Jalan - ' . $schedule['schedule_code'],
            'schedule' => $schedule,
            'allocations' => MenuSchedule::allocations($scheduleId),
        ]);
    }

    public function edit(Request $request, string $id): void
    {
        $schedule = $this->findSchedule($id);
        $date = substr((string) $schedule['service_date'], 0, 10);

        if (!$this->canEdit($schedule)) {
            $this->fail((int) $schedule['id'], 'Jadwal berstatus ' . mbg_schedule_status_label($schedule['schedule_status'])
                . ' tidak dapat diubah.');
        }

        $old = $this->pullOld();
        $allocations = $this->oldAllocations($old) ?: $this->allocationsToForm(MenuSchedule::allocations((int) $schedule['id']));

        $this->view('jadwal-menu/form', [
            'title' => 'Ubah Jadwal Menu',
            'pageTitle' => 'Ubah Jadwal Menu',
            'mode' => 'update',
            'form' => [
                'service_date' => (string) ($old['service_date'] ?? $date),
                'menu_id' => (string) ($old['menu_id'] ?? $schedule['menu_id']),
                'meal_slot' => (string) ($old['meal_slot'] ?? $schedule['meal_slot']),
                'notes' => (string) ($old['notes'] ?? ($schedule['notes'] ?? '')),
            ],
            'allocations' => $allocations,
            'schools' => School::optionList(),
            'menus' => Menu::schedulableList(),
            'menu' => Menu::findWithNames((int) $schedule['menu_id']),
            'slots' => MenuCategory::SLOTS,
            'weekday' => (int) date('N', (int) strtotime($date)),
            'serviceDay' => SchoolServiceSchedule::schoolsOn((int) date('N', (int) strtotime($date)), (string) $schedule['meal_slot']),
        ]);
    }

    public function update(Request $request, string $id): void
    {
        $actor = $this->actor();
        $schedule = $this->findSchedule($id);

        if (!$this->canEdit($schedule)) {
            $this->fail((int) $schedule['id'], 'Jadwal berstatus ' . mbg_schedule_status_label($schedule['schedule_status'])
                . ' tidak dapat diubah.');
        }

        $input = $this->readForm($request);
        $allocations = $this->readAllocations($request);
        [$data, $errors] = $this->validate($input, $allocations, (int) $schedule['id']);

        if ($errors !== []) {
            $this->reject('/jadwal-menu/' . (int) $schedule['id'] . '/ubah', $errors[0], $input, $allocations);
        }

        /** @var array<string, mixed> $data */
        $data['schedule_status'] = (string) $schedule['schedule_status'];
        MenuSchedule::update((int) $schedule['id'], $data, $allocations, (int) $actor['id']);

        ActivityLog::record(
            (int) $actor['id'],
            'menu_schedules.update',
            'menus',
            'Mengubah jadwal ' . $schedule['schedule_code'] . ' menjadi ' . count($allocations) . ' alokasi sekolah.',
            $request->ip(),
            $request->userAgent()
        );

        Session::clearOld();
        Session::flash('success', 'Jadwal diperbarui dan total porsi serta estimasi biayanya dihitung ulang.');
        $this->redirect('/jadwal-menu/' . (int) $schedule['id']);
    }

    public function submit(Request $request, string $id): void
    {
        $actor = $this->actor();
        $schedule = $this->findSchedule($id);
        $scheduleId = (int) $schedule['id'];

        $changed = MenuSchedule::submit($scheduleId, (int) $actor['id']);
        if ($changed === 0) {
            $this->fail($scheduleId, 'Hanya jadwal berstatus draf atau dibatalkan yang dapat diajukan.');
        }

        $fresh = MenuSchedule::findWithNames($scheduleId);
        if ($fresh !== null) {
            Notification::menuScheduleSubmitted($fresh, (int) $actor['id']);
        }

        ActivityLog::record(
            (int) $actor['id'],
            'menu_schedules.submit',
            'menus',
            'Mengajukan jadwal ' . $schedule['schedule_code'] . ' untuk disetujui.',
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', 'Jadwal diajukan untuk disetujui.');
        $this->redirect('/jadwal-menu/' . $scheduleId);
    }

    public function decide(Request $request, string $id): void
    {
        $actor = $this->actor();
        $schedule = $this->findSchedule($id);
        $scheduleId = (int) $schedule['id'];

        if ((string) $schedule['schedule_status'] !== MenuSchedule::STATUS_PENDING) {
            $this->fail($scheduleId, 'Hanya jadwal yang menunggu persetujuan yang dapat diputus.');
        }

        if (!can('menus.approve') && !can('menu_schedules.manage')) {
            $this->fail($scheduleId, 'Persetujuan jadwal memerlukan hak menus.approve atau menu_schedules.manage.');
        }

        if ((int) ($schedule['submitted_by'] ?? 0) === (int) $actor['id']) {
            $this->fail($scheduleId, 'Jadwal yang Anda ajukan sendiri tidak dapat Anda setujui. Minta pemegang hak lain.');
        }

        $to = $request->string('keputusan');
        if (!in_array($to, [MenuSchedule::STATUS_APPROVED, MenuSchedule::STATUS_CANCELLED], true)) {
            $this->fail($scheduleId, 'Pilih keputusan: setujui atau batalkan.');
        }

        $note = preg_replace('/\s+/', ' ', trim($request->string('catatan'))) ?? '';
        if (mb_strlen($note) > 500) {
            $this->fail($scheduleId, 'Catatan keputusan maksimal 500 karakter.');
        }
        if ($to === MenuSchedule::STATUS_CANCELLED && mb_strlen($note) < 5) {
            $this->fail($scheduleId, 'Pembatalan jadwal wajib disertai alasan.');
        }

        if ($to === MenuSchedule::STATUS_APPROVED) {
            $blocking = $this->blockingFindings((int) $schedule['menu_id']);
            if ($blocking !== []) {
                $this->fail($scheduleId, 'Jadwal tidak dapat disetujui: ' . (string) $blocking[0]['message']);
            }
        }

        $changed = Database::transaction(static function () use ($scheduleId, $to, $note, $actor): int {
            MenuSchedule::lock($scheduleId);

            return MenuSchedule::decide($scheduleId, $to, $note, (int) $actor['id']);
        });

        if ($changed === 0) {
            $this->fail($scheduleId, 'Status jadwal berubah sejak halaman ini dibuka, muat ulang lalu coba lagi.');
        }

        $fresh = MenuSchedule::findWithNames($scheduleId);
        if ($fresh !== null) {
            Notification::menuScheduleDecided($fresh, $to, (int) $actor['id']);
        }

        ActivityLog::record(
            (int) $actor['id'],
            'menu_schedules.decide',
            'menus',
            ($to === MenuSchedule::STATUS_APPROVED ? 'Menyetujui' : 'Membatalkan') . ' jadwal '
            . $schedule['schedule_code'] . ' tanggal ' . substr((string) $schedule['service_date'], 0, 10) . '.'
            . ($note !== '' ? ' Catatan: ' . $note : ''),
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', $to === MenuSchedule::STATUS_APPROVED
            ? 'Jadwal disetujui dan porsinya menjadi acuan rencana bahan.'
            : 'Jadwal dibatalkan beserta alasannya.');
        $this->redirect('/jadwal-menu/' . $scheduleId);
    }

    /** Tandai jadwal sebagai sudah berjalan setelah distribusi hari itu. */
    public function complete(Request $request, string $id): void
    {
        $actor = $this->actor();
        $schedule = $this->findSchedule($id);
        $scheduleId = (int) $schedule['id'];

        if (!in_array((string) $schedule['schedule_status'], [MenuSchedule::STATUS_APPROVED, MenuSchedule::STATUS_SCHEDULED], true)) {
            $this->fail($scheduleId, 'Hanya jadwal yang sudah disetujui atau terjadwal dapat ditandai selesai.');
        }

        $changed = MenuSchedule::changeStatus($scheduleId, (string) $schedule['schedule_status'], MenuSchedule::STATUS_DONE, (int) $actor['id']);
        if ($changed === 0) {
            $this->fail($scheduleId, 'Status jadwal berubah sejak halaman ini dibuka.');
        }

        ActivityLog::record(
            (int) $actor['id'],
            'menu_schedules.complete',
            'menus',
            'Menandai jadwal ' . $schedule['schedule_code'] . ' tanggal '
            . substr((string) $schedule['service_date'], 0, 10) . ' sebagai sudah berjalan.',
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', 'Jadwal ditandai sudah berjalan. Perubahan selanjutnya lewat jadwal baru.');
        $this->redirect('/jadwal-menu/' . $scheduleId);
    }

    /** Salin jadwal satu hari ke hari lain, dipakai saat ada perubahan menu mingguan. */
    public function copyDay(Request $request): void
    {
        $actor = $this->actor();
        $source = str_replace('/', '-', trim($request->string('dari')));
        $target = str_replace('/', '-', trim($request->string('ke')));
        $errors = [];

        if (!date_is_valid($source)) {
            $errors[] = 'Tanggal sumber wajib diisi dengan format YYYY-MM-DD.';
        }
        if (!date_is_valid($target)) {
            $errors[] = 'Tanggal tujuan wajib diisi dengan format YYYY-MM-DD.';
        }
        if ($errors === [] && $source === $target) {
            $errors[] = 'Tanggal sumber dan tujuan tidak boleh sama.';
        }
        if ($errors === [] && $target <= date('Y-m-d')) {
            $errors[] = 'Tanggal tujuan harus sesudah hari ini agar tidak menimpa jadwal yang sudah berjalan.';
        }

        if ($errors !== []) {
            Session::flash('danger', $errors[0]);
            $this->redirect('/jadwal-menu?dari=' . $source);
        }

        $count = MenuSchedule::countOn($target);
        if ($count > 0) {
            Session::flash('danger', 'Tanggal ' . $target . ' sudah punya ' . $count . ' jadwal, jadi penyalinan dihentikan.');
            $this->redirect('/jadwal-menu?dari=' . $target);
        }

        $created = Database::transaction(static fn (): array => MenuSchedule::copyDay($source, $target, (int) $actor['id']));

        if ($created === []) {
            Session::flash('warning', 'Tidak ada jadwal yang dapat disalin dari ' . $source . '.');
            $this->redirect('/jadwal-menu?dari=' . $source);
        }

        ActivityLog::record(
            (int) $actor['id'],
            'menu_schedules.copy',
            'menus',
            'Menyalin ' . count($created) . ' jadwal dari ' . $source . ' ke ' . $target . ' sebagai draf.',
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', count($created) . ' jadwal disalin ke ' . $target
            . ' sebagai draf; jadwal sumber tidak berubah.');
        $this->redirect('/jadwal-menu?dari=' . $target);
    }

    public function destroy(Request $request, string $id): void
    {
        $actor = $this->actor();
        $schedule = $this->findSchedule($id);
        $scheduleId = (int) $schedule['id'];

        if (in_array((string) $schedule['schedule_status'], [MenuSchedule::STATUS_DONE, MenuSchedule::STATUS_SCHEDULED], true)) {
            $this->fail($scheduleId, 'Jadwal yang sudah berjalan atau terjadwal tidak dihapus; batalkan beserta alasannya.');
        }

        MenuSchedule::delete($scheduleId);

        ActivityLog::record(
            (int) $actor['id'],
            'menu_schedules.delete',
            'menus',
            'Menghapus jadwal draf ' . $schedule['schedule_code'] . ' tanggal '
            . substr((string) $schedule['service_date'], 0, 10) . '.',
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', 'Jadwal draf dihapus. Menu dan rekap penerima manfaat tidak berubah.');
        $this->redirect('/jadwal-menu');
    }

    /**
     * Rencana kebutuhan bahan untuk satu periode. Angkanya menjadi dasar modul pengadaan
     * dan gudang, tetapi tidak mengubah stok pada halaman ini.
     */
    public function materials(Request $request): void
    {
        [$from, $to] = $this->range($request);
        $schedules = MenuSchedule::between(['from' => $from, 'to' => $to, 'menu' => '', 'status' => '', 'school' => ''], 400);

        $this->view('jadwal-menu/bahan', [
            'title' => 'Rencana Kebutuhan Bahan',
            'pageTitle' => 'Rencana Kebutuhan Bahan MBG',
            'from' => $from,
            'to' => $to,
            'schedules' => $schedules,
            'plan' => MenuSchedule::materialPlan($schedules),
            'planned' => array_values(array_filter(
                $schedules,
                static fn (array $row): bool => in_array((string) $row['schedule_status'], MenuSchedule::PLANNED_STATUSES, true)
            )),
            'canExport' => can('nutrition.export'),
        ]);
    }

    /** Unduh rencana bahan sebagai CSV untuk modul pengadaan. */
    public function exportMaterials(Request $request): void
    {
        [$from, $to] = $this->range($request);
        $schedules = MenuSchedule::between(['from' => $from, 'to' => $to, 'menu' => '', 'status' => '', 'school' => ''], 400);
        $plan = MenuSchedule::materialPlan(array_values(array_filter(
            $schedules,
            static fn (array $row): bool => in_array((string) $row['schedule_status'], MenuSchedule::PLANNED_STATUSES, true)
        )));

        Csv::send(
            'rencana-bahan-' . $from . ' sd ' . $to . '.csv',
            [
                'Kode Bahan', 'Nama Bahan', 'Kategori', 'Jumlah per Porsi', 'Satuan Dasar',
                'Total Kebutuhan (Satuan Dasar)', 'Satuan Beli', 'Faktor Konversi', 'Catatan Konversi',
                'Kebutuhan Satuan Beli', 'Jumlah Porsi', 'Estimasi Biaya', 'Sumber Harga',
            ],
            array_map(static fn (array $row): array => [
                $row['ingredient_code'] ?? '',
                $row['ingredient_name'] ?? '',
                $row['category_name'] ?? '',
                rtrim(rtrim(number_format((float) ($row['per_serving_base'] ?? 0), 4, ',', ''), '0'), ','),
                $row['base_unit'] ?? '',
                rtrim(rtrim(number_format((float) ($row['base_quantity'] ?? 0), 3, ',', ''), '0'), ','),
                (string) ($row['purchase_unit'] ?? ''),
                (string) ($row['conversion_factor'] ?? ''),
                (string) ($row['conversion_note'] ?? ''),
                $row['purchase_quantity'] === null
                    ? ''
                    : rtrim(rtrim(number_format((float) $row['purchase_quantity'], 3, ',', ''), '0'), ','),
                (int) ($row['portions'] ?? 0),
                $row['cost_estimate'] === null ? '' : rupiah((float) $row['cost_estimate']),
                (string) ($row['price_basis'] ?? ''),
            ], $plan['rows'])
        );
    }

    /** @return array<int, array<string, mixed>> Temuan aturan mingguan atas jadwal satu pekan. */
    private function weekFindings(array $schedules): array
    {
        $rules = MenuRule::active(MenuRule::SCOPE_WEEK);
        if ($rules === []) {
            return [];
        }

        $computations = [];
        foreach ($schedules as $schedule) {
            if (!in_array((string) $schedule['schedule_status'], [MenuSchedule::STATUS_CANCELLED], true)) {
                $computations[] = [
                    'computation' => Menu::compute((int) $schedule['menu_id']),
                    'menu_id' => (int) $schedule['menu_id'],
                ];
            }
        }

        return $computations === [] ? [] : MenuRule::assessWeek($rules, $computations);
    }

    /** @return array<int, array<string, mixed>> Aturan wajib yang gagal pada menu jadwal ini. */
    private function blockingFindings(int $menuId): array
    {
        $findings = MenuRule::assess(MenuRule::active(MenuRule::SCOPE_MENU), Menu::compute($menuId));

        return array_values(array_filter(
            $findings,
            static fn (array $f): bool => $f['severity'] === 'harus' && $f['passed'] === false
        ));
    }

    /** Alokasi usulan dari hari pelayanan sekolah dan rekap jumlah penerima manfaat. */
    private function suggestedAllocations(string $date, string $slot): array
    {
        $weekday = (int) date('N', (int) strtotime($date));
        $rows = SchoolServiceSchedule::schoolsOn($weekday, $slot === '' ? 'makan_siang' : $slot);

        $allocations = [];
        foreach ($rows as $row) {
            $allocations[] = [
                'school_id' => (string) $row['id'],
                'class_group' => '',
                'portion_count' => (string) ($row['beneficiary_total'] ?? 0),
                'notes' => 'Usulan dari rekap penerima manfaat.',
            ];
        }

        return $allocations;
    }

    /** @param array<int, array<string, mixed>> $allocations @return array<int, array<string, string>> */
    private function allocationsToForm(array $allocations): array
    {
        $rows = [];
        foreach ($allocations as $row) {
            $rows[] = [
                'school_id' => (string) $row['school_id'],
                'class_group' => (string) ($row['class_group'] ?? ''),
                'portion_count' => (string) $row['portion_count'],
                'notes' => (string) ($row['notes'] ?? ''),
            ];
        }

        return $rows;
    }

    /** @param array<string, mixed> $old @return array<int, array<string, string>> */
    private function oldAllocations(array $old): array
    {
        $rows = $old['allocations'] ?? null;
        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, 'is_array'));
    }

    private function startDate(Request $request): string
    {
        $start = str_replace('/', '-', trim($request->string('dari')));

        return date_is_valid($start) ? $start : date('Y-m-d', (int) strtotime('monday this week'));
    }

    /** @return array{menu: string, status: string, school: string} */
    private function filters(Request $request, string $start): array
    {
        $status = $request->string('status');

        return [
            'menu' => (string) max(0, $request->integer('menu')),
            'status' => in_array($status, MenuSchedule::STATUSES, true) ? $status : '',
            'school' => (string) max(0, $request->integer('sekolah')),
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
            $to = date('Y-m-d', (int) strtotime($from . ' +' . (self::WEEK_DAYS - 1) . ' day'));
        }

        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }

        $limit = date('Y-m-d', (int) strtotime($from . ' +90 day'));
        if ($to > $limit) {
            $to = $limit;
        }

        return [$from, $to];
    }

    /** @return array<string, string> */
    private function readForm(Request $request): array
    {
        return [
            'service_date' => str_replace('/', '-', trim($request->string('service_date'))),
            'menu_id' => (string) $request->integer('menu_id'),
            'meal_slot' => mb_strtolower(trim($request->string('meal_slot'))),
            'notes' => preg_replace('/\s+/', ' ', trim($request->string('notes'))) ?? '',
        ];
    }

    /**
     * @return array<int, array{school_id: string, class_group: string, portion_count: string, notes: string}>
     */
    private function readAllocations(Request $request): array
    {
        $ids = (array) $request->input('school_id', []);
        $groups = (array) $request->input('class_group', []);
        $counts = (array) $request->input('portion_count', []);
        $notes = (array) $request->input('allocation_notes', []);

        $rows = [];
        foreach ($ids as $index => $id) {
            $count = trim((string) ($counts[$index] ?? ''));
            if ((string) $id === '' || $count === '') {
                continue;
            }

            $rows[] = [
                'school_id' => (string) $id,
                'class_group' => trim((string) ($groups[$index] ?? '')),
                'portion_count' => $count,
                'notes' => trim((string) ($notes[$index] ?? '')),
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, string> $input
     * @param array<int, array<string, string>> $allocations
     * @return array{0: array<string, mixed>|null, 1: array<int, string>}
     */
    private function validate(array $input, array $allocations, ?int $exceptId): array
    {
        $errors = [];

        $date = $input['service_date'];
        $menuId = (int) $input['menu_id'];
        $slot = $input['meal_slot'];

        if (!date_is_valid($date)) {
            $errors[] = 'Tanggal pelayanan wajib diisi dengan format YYYY-MM-DD.';
        }

        $menu = $menuId > 0 ? Menu::findWithNames($menuId) : null;
        if ($menu === null) {
            $errors[] = 'Menu wajib dipilih dari daftar menu yang sudah disetujui.';
        } elseif (!in_array((string) $menu['menu_status'], [Menu::STATUS_APPROVED, Menu::STATUS_SCHEDULED], true)) {
            $errors[] = 'Menu "' . $menu['menu_name'] . '" belum disetujui, jadi tidak dapat dijadwalkan.';
        }

        if (!MenuCategory::slotExists($slot)) {
            $errors[] = 'Slot waktu makan tidak dikenali.';
        }

        if (mb_strlen($input['notes']) > 500) {
            $errors[] = 'Catatan jadwal maksimal 500 karakter.';
        }

        if ($errors === [] && MenuSchedule::slotTaken($date, $menuId, $slot, $exceptId)) {
            $errors[] = 'Menu ini sudah terjadwal pada ' . $date . ' untuk slot ' . meal_slot_label($slot) . '.';
        }

        if ($allocations === []) {
            $errors[] = 'Tambahkan minimal satu sekolah beserta jumlah porsinya.';
        }

        $seen = [];
        foreach ($allocations as $index => $row) {
            $position = $index + 1;
            $schoolId = (int) ($row['school_id'] ?? 0);
            $count = trim((string) ($row['portion_count'] ?? ''));
            $group = mb_substr(trim((string) ($row['class_group'] ?? '')), 0, 60);
            $key = $schoolId . '|' . mb_strtolower($group);

            $school = $schoolId > 0 ? School::findWithNames($schoolId) : null;
            if ($school === null) {
                $errors[] = 'Baris ' . $position . ': sekolah tidak ditemukan.';
                continue;
            }

            if ((string) $school['school_status'] !== School::STATUS_ACTIVE) {
                $errors[] = 'Baris ' . $position . ': sekolah "' . $school['school_name']
                    . '" berstatus ' . school_status_label($school['school_status']) . ', tidak dapat dijadwalkan.';
                continue;
            }

            if (isset($seen[$key])) {
                $errors[] = 'Baris ' . $position . ': sekolah dan kelompok ini sudah ditulis pada baris sebelumnya,'
                    . ' porsinya tidak dihitung dua kali.';
                continue;
            }
            $seen[$key] = true;

            if (preg_match('/^\d{1,7}$/', $count) !== 1 || (int) $count <= 0) {
                $errors[] = 'Baris ' . $position . ': jumlah porsi harus angka positif.';
                continue;
            }

            if ($date !== '' && date_is_valid($date)
                && !SchoolServiceSchedule::hasActiveDay($schoolId, (int) date('N', (int) strtotime($date)), $slot)) {
                $errors[] = 'Baris ' . $position . ': ' . $school['school_name'] . ' tidak punya hari pelayanan '
                    . SchoolServiceSchedule::weekdayName((int) date('N', (int) strtotime($date))) . ' pada slot '
                    . meal_slot_label($slot) . '. Atur hari pelayanannya di detail sekolah.';
            }

            $rekapTotal = $date !== '' && date_is_valid($date) ? Beneficiary::totalAt($date, $schoolId) : 0;
            if ($group === '' && $rekapTotal > 0 && (int) $count > $rekapTotal) {
                $errors[] = 'Baris ' . $position . ': porsi ' . $school['school_name'] . ' melebihi rekap penerima manfaat '
                    . $rekapTotal . ' orang pada ' . $date . '. Perbarui dulu jumlahnya atau pisahkan per kelompok kelas.';
            }

            if (mb_strlen((string) ($row['notes'] ?? '')) > 255) {
                $errors[] = 'Baris ' . $position . ': catatan alokasi maksimal 255 karakter.';
            }
        }

        if ($errors !== []) {
            return [null, $errors];
        }

        $clean = [];
        foreach ($allocations as $row) {
            $clean[] = [
                'school_id' => (int) $row['school_id'],
                'class_group' => mb_substr(trim((string) ($row['class_group'] ?? '')), 0, 60),
                'portion_count' => (int) $row['portion_count'],
                'notes' => trim((string) ($row['notes'] ?? '')) === '' ? null : mb_substr(trim((string) $row['notes']), 0, 255),
            ];
        }

        return [[
            'service_date' => $date,
            'menu_id' => $menuId,
            'meal_slot' => $slot,
            'notes' => $input['notes'] === '' ? null : $input['notes'],
        ], []];
    }

    /** @param array<string, mixed> $schedule */
    private function canEdit(array $schedule): bool
    {
        return in_array((string) $schedule['schedule_status'], MenuSchedule::EDITABLE_STATUSES, true);
    }

    /** @return array<string, mixed> */
    private function findSchedule(string $id): array
    {
        if (!ctype_digit($id)) {
            throw new NotFoundHttpException();
        }

        $schedule = MenuSchedule::findWithNames((int) $id);
        if ($schedule === null) {
            throw new NotFoundHttpException();
        }

        return $schedule;
    }

    private function fail(int $id, string $message): never
    {
        Session::flash('danger', $message);
        $this->redirect('/jadwal-menu/' . $id);
    }

    /**
     * @param array<string, string> $input
     * @param array<int, array<string, string>> $allocations
     */
    private function reject(string $path, string $message, array $input, array $allocations): never
    {
        Session::flash('danger', $message);
        Session::putOld($input + ['allocations' => $allocations]);
        $this->redirect($path);
    }

    /** @return array<string, mixed> */
    private function pullOld(): array
    {
        $old = Session::pull('_old', []);

        return is_array($old) ? $old : [];
    }
}
