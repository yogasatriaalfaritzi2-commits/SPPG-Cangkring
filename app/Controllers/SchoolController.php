<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Csv;
use App\Core\Exceptions\NotFoundHttpException;
use App\Core\Request;
use App\Core\Session;
use App\Models\ActivityLog;
use App\Models\Beneficiary;
use App\Models\MenuCategory;
use App\Models\Notification;
use App\Models\School;
use App\Models\SchoolLevel;
use App\Models\SchoolServiceSchedule;

/**
 * Sekolah penerima manfaat MBG. Data distribusi tidak pernah menghapus sekolah:
 * yang tersedia hanyalah penonaktifan dan pengarsipan sehingga riwayat jumlah
 * penerima manfaat serta jadwal menu lama tetap dapat dibaca.
 */
final class SchoolController extends Controller
{
    private const PER_PAGE = 12;

    public function index(Request $request): void
    {
        $filters = $this->filters($request);
        $result = School::paginate($filters, max(1, $request->integer('page', 1)), self::PER_PAGE);

        $this->view('sekolah/index', [
            'title' => 'Sekolah Penerima Manfaat',
            'pageTitle' => 'Sekolah Penerima Manfaat MBG',
            'filters' => $filters,
            'result' => $result,
            'schools' => $result['rows'],
            'levels' => SchoolLevel::activeList(),
            'regions' => array_map(
                static fn (array $row): string => (string) $row['name'],
                School::regions('regency')
            ),
            'statuses' => School::STATUSES,
            'partnerships' => School::PARTNERSHIPS,
            'totalBeneficiaries' => Beneficiary::totalAt(date('Y-m-d')),
            'basePath' => '/sekolah',
        ]);
    }

    public function create(Request $request): void
    {
        $old = $this->pullOld();
        $form = School::emptyRecord();
        foreach ($old as $key => $value) {
            if (array_key_exists($key, $form)) {
                $form[$key] = (string) $value;
            }
        }

        if ((string) $form['school_code'] === '') {
            $form['school_code'] = School::nextCode();
        }

        $this->view('sekolah/form', [
            'title' => 'Tambah Sekolah',
            'pageTitle' => 'Tambah Sekolah Penerima Manfaat',
            'mode' => 'create',
            'form' => $form,
            'school' => null,
            'levels' => SchoolLevel::activeList(),
            'statuses' => School::STATUSES,
            'partnerships' => School::PARTNERSHIPS,
        ]);
    }

    public function store(Request $request): void
    {
        $actor = $this->actor();
        $input = $this->readForm($request);
        [$data, $errors] = $this->validate($input, null);

        if ($errors !== []) {
            $this->reject('/sekolah/tambah', $errors[0], $input);
        }

        /** @var array<string, mixed> $data */
        $data['created_by'] = (int) $actor['id'];
        $data['updated_by'] = (int) $actor['id'];
        $id = School::create($data);

        ActivityLog::record(
            (int) $actor['id'],
            'schools.create',
            'schools',
            'Menambah sekolah "' . $data['school_code'] . ' ' . $data['school_name'] . '"'
            . ($data['npsn'] !== null ? ' NPSN ' . $data['npsn'] : '') . '.',
            $request->ip(),
            $request->userAgent()
        );

        $this->warnPartnership($id);

        Session::clearOld();
        Session::flash('success', 'Sekolah "' . $data['school_name'] . '" berhasil ditambahkan.');
        $this->redirect('/sekolah/' . $id);
    }

    public function show(Request $request, string $id): void
    {
        $school = $this->findSchool($id);
        $schoolId = (int) $school['id'];
        $asof = date('Y-m-d');

        $this->view('sekolah/show', [
            'title' => (string) $school['school_name'],
            'pageTitle' => 'Detail Sekolah',
            'school' => $school,
            'levels' => SchoolLevel::activeList(),
            'serviceDays' => SchoolServiceSchedule::forSchool($schoolId),
            'beneficiaryRows' => Beneficiary::rekap([
                'asof' => $asof,
                'school' => (string) $schoolId,
                'category' => '',
                'search' => '',
            ]),
            'perCategory' => Beneficiary::perCategory($schoolId, $asof),
            'totalToday' => Beneficiary::totalAt($asof, $schoolId),
            'histories' => Beneficiary::histories([
                'school' => (string) $schoolId,
                'category' => '',
                'from' => '',
                'to' => '',
            ], 20),
            'classGroups' => Beneficiary::classGroups($schoolId),
        ]);
    }

    public function edit(Request $request, string $id): void
    {
        $school = $this->findSchool($id);
        $form = $school;

        foreach (['partnership_start', 'partnership_end', 'npsn', 'address', 'province', 'regency',
            'district', 'village', 'postal_code', 'person_in_charge', 'phone', 'email', 'notes'] as $field) {
            $form[$field] = (string) ($school[$field] ?? '');
        }

        $this->view('sekolah/form', [
            'title' => 'Ubah Sekolah',
            'pageTitle' => 'Ubah Data Sekolah',
            'mode' => 'edit',
            'form' => $form,
            'school' => $school,
            'levels' => SchoolLevel::activeList(),
            'statuses' => School::STATUSES,
            'partnerships' => School::PARTNERSHIPS,
            'serviceDays' => SchoolServiceSchedule::forSchool((int) $school['id']),
        ]);
    }

    public function update(Request $request, string $id): void
    {
        $actor = $this->actor();
        $school = $this->findSchool($id);
        $input = $this->readForm($request);
        [$data, $errors] = $this->validate($input, (int) $school['id']);

        if ($errors !== []) {
            $this->reject('/sekolah/' . $school['id'] . '/ubah', $errors[0], $input);
        }

        /** @var array<string, mixed> $data */
        $data['updated_by'] = (int) $actor['id'];
        // Status hanya diubah lewat aksi khusus yang mencatat waktu pengarsipan,
        // supaya formulir tidak menutupi status terbaru.
        $data['school_status'] = (string) $school['school_status'];
        School::update((int) $school['id'], $data);

        ActivityLog::record(
            (int) $actor['id'],
            'schools.update',
            'schools',
            'Memperbarui data sekolah "' . $school['school_code'] . ' ' . $school['school_name'] . '".',
            $request->ip(),
            $request->userAgent()
        );

        $this->warnPartnership((int) $school['id']);

        Session::clearOld();
        Session::flash('success', 'Data sekolah berhasil diperbarui.');
        $this->redirect('/sekolah/' . $school['id']);
    }

    /**
     * Menonaktifkan, mengarsipkan, atau mengaktifkan kembali sekolah. Penghapusan
     * permanen tidak disediakan selama sekolah masih memiliki riwayat.
     */
    public function status(Request $request, string $id): void
    {
        $actor = $this->actor();
        $school = $this->findSchool($id);
        $to = $request->string('status');

        if (!in_array($to, School::STATUSES, true)) {
            Session::flash('danger', 'Status sekolah tidak dikenali.');
            $this->redirect('/sekolah/' . $school['id']);
        }

        if ($to === (string) $school['school_status']) {
            Session::flash('info', 'Status sekolah sudah "' . school_status_label($to) . '".');
            $this->redirect('/sekolah/' . $school['id']);
        }

        if ($to === School::STATUS_ARCHIVED && School::usageCount((int) $school['id']) === 0) {
            $this->fail(
                'Sekolah ini belum memiliki riwayat apa pun. Hapus saja bila datanya salah input, '
                . 'tidak perlu diarsipkan.',
                (string) $school['id']
            );
        }

        School::updateStatus((int) $school['id'], $to, (int) $actor['id']);

        ActivityLog::record(
            (int) $actor['id'],
            'schools.status',
            'schools',
            'Mengubah status sekolah "' . $school['school_name'] . '" dari '
            . school_status_label($school['school_status']) . ' menjadi ' . school_status_label($to) . '.',
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', 'Status sekolah "' . $school['school_name'] . '" menjadi '
            . school_status_label($to) . '. Riwayat penerima manfaat dan jadwal menu tetap terbaca.');
        $this->redirect('/sekolah/' . $school['id']);
    }

    /** Sekolah tanpa riwayat apa pun boleh dihapus; sekolah berdistribusi tidak. */
    public function destroy(Request $request, string $id): void
    {
        $actor = $this->actor();
        $school = $this->findSchool($id);
        $usage = School::usageCount((int) $school['id']);

        if ($usage > 0) {
            $this->fail(
                'Sekolah "' . $school['school_name'] . '" masih terhubung pada ' . $usage
                . ' catatan distribusi. Nonaktifkan atau arsipkan agar riwayat tetap tersimpan.',
                (string) $school['id']
            );
        }

        School::delete((int) $school['id']);

        ActivityLog::record(
            (int) $actor['id'],
            'schools.delete',
            'schools',
            'Menghapus sekolah "' . $school['school_code'] . ' ' . $school['school_name'] . '" yang belum punya riwayat.',
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', 'Sekolah yang belum punya riwayat telah dihapus.');
        $this->redirect('/sekolah');
    }

    /** Konfigurasi hari dan jam pelayanan; tiap sekolah dapat berbeda. */
    public function storeDay(Request $request, string $id): void
    {
        $actor = $this->actor();
        $school = $this->findSchool($id);
        [$data, $errors] = $this->validateDay($request, $school, null);

        if ($errors !== []) {
            $this->fail($errors[0], (string) $school['id']);
        }

        /** @var array<string, mixed> $data */
        $data['created_by'] = (int) $actor['id'];
        $data['updated_by'] = (int) $actor['id'];
        SchoolServiceSchedule::create($data);

        ActivityLog::record(
            (int) $actor['id'],
            'schools.day.create',
            'schools',
            'Menambah hari pelayanan ' . SchoolServiceSchedule::weekdayName((int) $data['service_weekday']) . ' '
            . meal_slot_label((string) $data['meal_slot']) . ' pada sekolah "' . $school['school_name'] . '".',
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', 'Hari pelayanan berhasil ditambahkan.');
        $this->redirect('/sekolah/' . $school['id'] . '#pelayanan');
    }

    public function updateDay(Request $request, string $id, string $day): void
    {
        $actor = $this->actor();
        $school = $this->findSchool($id);
        $row = $this->findDay($day, (int) $school['id']);
        [$data, $errors] = $this->validateDay($request, $school, (int) $row['id']);

        if ($errors !== []) {
            $this->fail($errors[0], (string) $school['id']);
        }

        /** @var array<string, mixed> $data */
        $data['updated_by'] = (int) $actor['id'];
        SchoolServiceSchedule::update((int) $row['id'], $data);

        ActivityLog::record(
            (int) $actor['id'],
            'schools.day.update',
            'schools',
            'Memperbarui hari pelayanan sekolah "' . $school['school_name'] . '" pada '
            . SchoolServiceSchedule::weekdayName((int) $data['service_weekday']) . ' '
            . meal_slot_label((string) $data['meal_slot']) . '.',
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', 'Hari pelayanan diperbarui. Rencana kebutuhan bahan perlu dihitung ulang.');
        $this->redirect('/sekolah/' . $school['id'] . '#pelayanan');
    }

    public function destroyDay(Request $request, string $id, string $day): void
    {
        $actor = $this->actor();
        $school = $this->findSchool($id);
        $row = $this->findDay($day, (int) $school['id']);

        SchoolServiceSchedule::delete((int) $row['id']);

        ActivityLog::record(
            (int) $actor['id'],
            'schools.day.delete',
            'schools',
            'Menghapus hari pelayanan ' . SchoolServiceSchedule::weekdayName((int) $row['service_weekday']) . ' '
            . meal_slot_label((string) $row['meal_slot']) . ' pada sekolah "' . $school['school_name'] . '".',
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', 'Hari pelayanan dihapus. Jadwal yang sudah tersusun tidak ikut berubah.');
        $this->redirect('/sekolah/' . $school['id'] . '#pelayanan');
    }

    /** Unduh daftar sekolah beserta jumlah penerima manfaat yang berlaku hari ini. */
    public function export(Request $request): void
    {
        $filters = $this->filters($request);
        $rows = [];
        $page = 1;

        // Model membatasi 50 baris per halaman; ekspor menelusuri halaman dengan filter
        // yang sama agar hasilnya identik dengan yang terlihat di layar.
        while (true) {
            $batch = School::paginate($filters, $page, 50);
            foreach ($batch['rows'] as $row) {
                $rows[] = $row;
            }
            if ($page >= $batch['pages']) {
                break;
            }
            $page++;
        }

        Csv::send(
            'sekolah-mbg-' . date('Y-m-d'),
            [
                'Kode', 'Nama Sekolah', 'NPSN', 'Jenjang', 'Status', 'Kerja Sama',
                'Mulai Kerja Sama', 'Akhir Kerja Sama', 'Provinsi', 'Kabupaten/Kota',
                'Kecamatan', 'Desa', 'PIC', 'Telepon', 'Email', 'Penerima Manfaat Aktif',
            ],
            array_map(static fn (array $row): array => [
                $row['school_code'],
                $row['school_name'],
                $row['npsn'] ?? '',
                $row['level_name'],
                school_status_label($row['school_status']),
                partnership_label($row['partnership_status']),
                $row['partnership_start'] ?? '',
                $row['partnership_end'] ?? '',
                $row['province'] ?? '',
                $row['regency'] ?? '',
                $row['district'] ?? '',
                $row['village'] ?? '',
                $row['person_in_charge'] ?? '',
                $row['phone'] ?? '',
                $row['email'] ?? '',
                (int) $row['beneficiary_total'],
            ], $rows)
        );
    }

    public function importForm(Request $request): void
    {
        $this->view('sekolah/import', [
            'title' => 'Impor Sekolah',
            'pageTitle' => 'Impor Daftar Sekolah',
            'levels' => SchoolLevel::activeList(),
        ]);
    }

    /**
     * Impor CSV sekolah. Baris baru ditambahkan dan baris berkode sama diperbarui.
     * Status arsip tidak pernah diaktifkan kembali oleh impor dan riwayat penerima
     * manfaat tidak tersentuh karena hanya kolom profil yang ditulis.
     */
    public function import(Request $request): void
    {
        $actor = $this->actor();
        $file = $request->file('berkas');

        if ($file === null) {
            Session::flash('danger', 'Pilih berkas CSV yang akan diimpor.');
            $this->redirect('/sekolah/impor');
        }

        $extension = mb_strtolower((string) pathinfo(basename(str_replace('\\', '/', $file['name'])), PATHINFO_EXTENSION));
        if ($extension !== 'csv') {
            Session::flash('danger', 'Berkas impor harus berformat CSV.');
            $this->redirect('/sekolah/impor');
        }

        $lines = Csv::read($file['tmp_name'], 2000);
        if ($lines === []) {
            Session::flash('danger', 'Berkas impor kosong atau tidak dapat dibaca.');
            $this->redirect('/sekolah/impor');
        }

        $header = array_map(static fn (string $value): string => mb_strtolower(trim($value)), array_shift($lines));
        $column = static function (string $name) use ($header): ?int {
            $position = array_search($name, $header, true);

            return $position === false ? null : $position;
        };

        $columns = [
            'school_code' => $column('kode'),
            'school_name' => $column('nama'),
            'npsn' => $column('npsn'),
            'level_code' => $column('jenjang'),
            'province' => $column('provinsi'),
            'regency' => $column('kabupaten'),
            'district' => $column('kecamatan'),
            'village' => $column('desa'),
            'address' => $column('alamat'),
            'person_in_charge' => $column('pic'),
            'phone' => $column('telepon'),
            'email' => $column('email'),
            'partnership_status' => $column('kerja_sama'),
            'partnership_end' => $column('akhir_kerja_sama'),
        ];

        if ($columns['school_code'] === null || $columns['school_name'] === null || $columns['level_code'] === null) {
            Session::flash('danger', 'Berkas impor wajib memuat kolom kode, nama, dan jenjang.');
            $this->redirect('/sekolah/impor');
        }

        $levels = [];
        foreach (SchoolLevel::activeList() as $level) {
            $levels[mb_strtoupper((string) $level['level_code'])] = (int) $level['id'];
        }

        $text = static function (string $key, array $line) use ($columns): string {
            $index = $columns[$key];

            return $index === null ? '' : trim((string) ($line[$index] ?? ''));
        };

        $created = 0;
        $updated = 0;
        $problems = [];
        $rowNumber = 1;

        foreach ($lines as $line) {
            $rowNumber++;
            $code = mb_strtoupper(preg_replace('/\s+/', '', $text('school_code', $line)) ?? '');
            $name = preg_replace('/\s+/', ' ', $text('school_name', $line)) ?? '';
            $levelCode = mb_strtoupper($text('level_code', $line));

            if ($code === '' && $name === '') {
                continue;
            }

            if ($code === '' || mb_strlen($name) < 3 || !isset($levels[$levelCode])) {
                $problems[] = 'baris ' . $rowNumber;
                continue;
            }

            $npsn = preg_replace('/\D+/', '', $text('npsn', $line)) ?? '';
            if ($npsn !== '' && (strlen($npsn) !== 8 || School::npsnExists($npsn))) {
                $problems[] = 'baris ' . $rowNumber . ' (NPSN)';
                continue;
            }

            $partnership = mb_strtolower($text('partnership_status', $line));
            $existing = School::byCode($code);

            $data = [
                'school_code' => $code,
                'school_name' => $name,
                'npsn' => $npsn === '' ? null : $npsn,
                'level_id' => $levels[$levelCode],
                'school_status' => 'aktif',
                'partnership_status' => in_array($partnership, School::PARTNERSHIPS, true) ? $partnership : 'belum_ada',
                'partnership_start' => null,
                'partnership_end' => $this->importDate($text('partnership_end', $line)),
                'address' => $text('address', $line),
                'province' => $text('province', $line),
                'regency' => $text('regency', $line),
                'district' => $text('district', $line),
                'village' => $text('village', $line),
                'postal_code' => '',
                'latitude' => null,
                'longitude' => null,
                'person_in_charge' => $text('person_in_charge', $line),
                'phone' => phone_normalize($text('phone', $line)),
                'email' => $text('email', $line),
                'notes' => 'Diimpor dari berkas CSV pada ' . date('Y-m-d') . '.',
                'updated_by' => (int) $actor['id'],
            ];

            if ($existing === null) {
                $data['created_by'] = (int) $actor['id'];
                School::create($data);
                $created++;
                continue;
            }

            $data['school_status'] = (string) $existing['school_status'];
            $data['partnership_start'] = $existing['partnership_start'];
            School::update((int) $existing['id'], $data);
            $updated++;
        }

        ActivityLog::record(
            (int) $actor['id'],
            'schools.import',
            'schools',
            'Impor CSV sekolah: ' . $created . ' baru, ' . $updated . ' diperbarui'
            . ($problems === [] ? '.' : ', ' . count($problems) . ' baris ditolak ('
                . implode(', ', array_slice($problems, 0, 5)) . ').'),
            $request->ip(),
            $request->userAgent()
        );

        Session::flash(
            $problems === [] ? 'success' : 'warning',
            'Impor selesai: ' . $created . ' sekolah baru, ' . $updated . ' diperbarui'
            . ($problems === [] ? '.' : '. Baris bermasalah: ' . implode(', ', array_slice($problems, 0, 8)) . '.')
        );
        $this->redirect('/sekolah');
    }

    /** @return array{search: string, level: string, status: string, partnership: string, regency: string, sort: string, dir: string} */
    private function filters(Request $request): array
    {
        $sort = $request->string('sort');
        $status = $request->string('status');
        $partnership = $request->string('partnership');

        return [
            'search' => mb_substr(preg_replace('/\s+/', ' ', trim($request->string('q'))) ?? '', 0, 60),
            'level' => (string) max(0, $request->integer('level')),
            'status' => in_array($status, School::STATUSES, true) || $status === 'open' ? $status : '',
            'partnership' => in_array($partnership, School::PARTNERSHIPS, true) ? $partnership : '',
            'regency' => mb_substr(preg_replace('/\s+/', ' ', trim($request->string('kabupaten'))) ?? '', 0, 60),
            'sort' => isset(School::SORTABLE[$sort]) ? $sort : 'name',
            'dir' => strtolower($request->string('dir')) === 'asc' ? 'asc' : 'desc',
        ];
    }

    /** @return array<string, string> */
    private function readForm(Request $request): array
    {
        $fields = [
            'school_code', 'school_name', 'npsn', 'level_id', 'school_status', 'partnership_status',
            'partnership_start', 'partnership_end', 'address', 'province', 'regency', 'district',
            'village', 'postal_code', 'latitude', 'longitude', 'person_in_charge', 'phone', 'email', 'notes',
        ];

        $input = [];
        foreach ($fields as $field) {
            $input[$field] = (string) $request->input($field, '');
        }

        return $input;
    }

    /**
     * @param array<string, string> $input
     * @return array{0: array<string, mixed>|null, 1: array<int, string>}
     */
    private function validate(array $input, ?int $exceptId): array
    {
        $errors = [];

        $code = mb_strtoupper(preg_replace('/\s+/', '', trim($input['school_code'])) ?? '');
        $name = preg_replace('/\s+/', ' ', trim($input['school_name'])) ?? '';
        $npsn = preg_replace('/\s+/', '', trim($input['npsn'])) ?? '';
        $levelId = (int) $input['level_id'];

        if (!preg_match('/^[A-Z0-9][A-Z0-9._-]{2,29}$/', $code)) {
            $errors[] = 'Kode sekolah harus 3-30 karakter tanpa spasi, diawali huruf atau angka.';
        } elseif (School::codeExists($code, $exceptId)) {
            $errors[] = 'Kode sekolah sudah dipakai sekolah lain.';
        }

        if (mb_strlen($name) < 3 || mb_strlen($name) > 150) {
            $errors[] = 'Nama sekolah harus terdiri dari 3 sampai 150 karakter.';
        } elseif ($name !== '' && School::nameExists($name, $exceptId)) {
            $errors[] = 'Nama sekolah sudah terdaftar.';
        }

        if ($npsn !== '') {
            if (!preg_match('/^\d{8}$/', $npsn)) {
                $errors[] = 'NPSN harus 8 digit angka. Kosongkan bila sekolah belum memiliki NPSN.';
            } elseif (School::npsnExists($npsn, $exceptId)) {
                $errors[] = 'NPSN tersebut sudah dipakai sekolah lain.';
            }
        }

        if ($levelId <= 0 || SchoolLevel::find($levelId) === null) {
            $errors[] = 'Jenjang pendidikan wajib dipilih.';
        }

        $start = $this->dateOrFail($input['partnership_start'], 'tanggal mulai kerja sama', $errors);
        $end = $this->dateOrFail($input['partnership_end'], 'tanggal akhir kerja sama', $errors);

        if ($start !== null && $end !== null && $end < $start) {
            $errors[] = 'Tanggal akhir kerja sama tidak boleh mendahului tanggal mulai.';
        }

        $partnership = trim($input['partnership_status']) === '' ? 'belum_ada' : mb_strtolower(trim($input['partnership_status']));
        if (!in_array($partnership, School::PARTNERSHIPS, true)) {
            $errors[] = 'Status kerja sama tidak dikenali.';
        }

        $status = trim($input['school_status']) === '' ? School::STATUS_ACTIVE : mb_strtolower(trim($input['school_status']));
        if (!in_array($status, School::STATUSES, true)) {
            $errors[] = 'Status sekolah tidak dikenali.';
        }

        $email = trim($input['email']);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Format email tidak valid.';
        }

        $phone = phone_normalize($input['phone']);
        if ($phone !== '' && !phone_is_valid($phone)) {
            $errors[] = 'Telepon hanya boleh memuat angka, tanda hubung, atau spasi dengan panjang yang wajar.';
        }

        foreach ([['latitude', 'Lintang', 90.0], ['longitude', 'Bujur', 180.0]] as [$field, $label, $bound]) {
            $raw = trim($input[$field]);
            if ($raw !== '' && !coordinate_is_valid($raw, $field === 'latitude')) {
                $errors[] = $label . ' harus angka antara -' . rtrim(rtrim(number_format($bound, 1, '.', ''), '0'), '.')
                    . ' sampai ' . rtrim(rtrim(number_format($bound, 1, '.', ''), '0'), '.') . '.';
            }
        }

        $limits = [
            'address' => ['Alamat', 500],
            'province' => ['Provinsi', 80],
            'regency' => ['Kabupaten/Kota', 80],
            'district' => ['Kecamatan', 80],
            'village' => ['Desa', 80],
            'postal_code' => ['Kode pos', 10],
            'person_in_charge' => ['Penanggung jawab', 120],
            'notes' => ['Catatan', 1000],
        ];
        foreach ($limits as $field => [$label, $max]) {
            if (mb_strlen(trim($input[$field])) > $max) {
                $errors[] = $label . ' maksimal ' . $max . ' karakter.';
            }
        }

        if ($errors !== []) {
            return [null, $errors];
        }

        $value = static fn (string $field): ?string => trim($input[$field]) === '' ? null : trim($input[$field]);

        return [[
            'school_code' => $code,
            'school_name' => $name,
            'npsn' => $npsn === '' ? null : $npsn,
            'level_id' => $levelId,
            'school_status' => $status,
            'partnership_status' => $partnership,
            'partnership_start' => $start,
            'partnership_end' => $end,
            'address' => $value('address'),
            'province' => $value('province'),
            'regency' => $value('regency'),
            'district' => $value('district'),
            'village' => $value('village'),
            'postal_code' => $value('postal_code'),
            'latitude' => trim($input['latitude']) === '' ? null : (float) $input['latitude'],
            'longitude' => trim($input['longitude']) === '' ? null : (float) $input['longitude'],
            'person_in_charge' => $value('person_in_charge'),
            'phone' => $phone === '' ? null : $phone,
            'email' => $email === '' ? null : $email,
            'notes' => $value('notes'),
        ], []];
    }

    /**
     * @param array<string, mixed> $school
     * @return array{0: array<string, mixed>|null, 1: array<int, string>}
     */
    private function validateDay(Request $request, array $school, ?int $exceptId): array
    {
        $weekday = (int) $request->input('service_weekday', 0);
        $slot = mb_strtolower(trim($request->string('meal_slot')));
        $time = trim($request->string('service_time'));
        $limit = trim($request->string('portion_limit'));
        $notes = trim($request->string('notes'));
        $errors = [];

        if (!isset(SchoolServiceSchedule::WEEKDAYS[$weekday])) {
            $errors[] = 'Hari pelayanan wajib dipilih.';
        }

        if (!MenuCategory::slotExists($slot)) {
            $errors[] = 'Slot makan tidak dikenali.';
        }

        if ($time !== '' && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
            $errors[] = 'Jam pelayanan memakai format HH:MM.';
        }

        if ($limit !== '' && preg_match('/^\d{1,7}$/', $limit) !== 1) {
            $errors[] = 'Batas porsi harus angka positif.';
        }

        if (mb_strlen($notes) > 255) {
            $errors[] = 'Catatan hari pelayanan maksimal 255 karakter.';
        }

        if ($errors === [] && SchoolServiceSchedule::scopeExists((int) $school['id'], $weekday, $slot, $exceptId)) {
            $errors[] = 'Sekolah ini sudah punya jadwal pada hari dan slot tersebut.';
        }

        if ($errors !== []) {
            return [null, $errors];
        }

        return [[
            'school_id' => (int) $school['id'],
            'service_weekday' => $weekday,
            'service_time' => $time,
            'meal_slot' => $slot,
            'portion_limit' => $limit,
            'is_active' => $request->boolean('is_active'),
            'notes' => $notes,
        ], []];
    }

    /** Sekolah dengan masa kerja sama yang segera habis dilaporkan ke pengelola. */
    private function warnPartnership(int $schoolId): void
    {
        $school = School::findWithNames($schoolId);
        if ($school === null || $school['partnership_end'] === null) {
            return;
        }

        if (!in_array((string) $school['partnership_status'], ['moa_aktif', 'moa_akan_habis'], true)) {
            return;
        }

        $end = substr((string) $school['partnership_end'], 0, 10);
        $days = (int) floor((strtotime($end . ' 00:00:00') - strtotime(date('Y-m-d') . ' 00:00:00')) / 86400);

        if ($days >= 0 && $days <= 30) {
            Notification::partnershipExpiring($school);
        }
    }

    /** @param array<int, string> $errors */
    private function dateOrFail(string $raw, string $label, array &$errors): ?string
    {
        $value = trim($raw);
        if ($value === '') {
            return null;
        }

        if (!date_is_valid($value)) {
            $errors[] = 'Format ' . $label . ' tidak valid, gunakan YYYY-MM-DD.';

            return null;
        }

        return $value;
    }

    private function importDate(string $raw): ?string
    {
        $value = str_replace('/', '-', trim($raw));

        return $value === '' || !date_is_valid($value) ? null : $value;
    }

    /** @return array<string, mixed> */
    private function findSchool(string $id): array
    {
        if (!ctype_digit($id)) {
            throw new NotFoundHttpException();
        }

        $school = School::findWithNames((int) $id);
        if ($school === null) {
            throw new NotFoundHttpException();
        }

        return $school;
    }

    /** @return array<string, mixed> */
    private function findDay(string $day, int $schoolId): array
    {
        if (!ctype_digit($day)) {
            throw new NotFoundHttpException();
        }

        $row = SchoolServiceSchedule::find((int) $day);
        if ($row === null || (int) $row['school_id'] !== $schoolId) {
            throw new NotFoundHttpException();
        }

        return $row;
    }

    private function fail(string $message, string $id): never
    {
        Session::flash('danger', $message);
        $this->redirect('/sekolah/' . $id);
    }

    /** @return array<string, mixed> */
    private function pullOld(): array
    {
        $old = Session::pull('_old', []);

        return is_array($old) ? $old : [];
    }

    /** @param array<string, string> $input */
    private function reject(string $path, string $message, array $input): never
    {
        Session::flash('danger', $message);
        Session::putOld($input);
        $this->redirect($path);
    }
}
