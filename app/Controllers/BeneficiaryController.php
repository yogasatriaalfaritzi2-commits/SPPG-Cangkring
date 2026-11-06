<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Controller;
use App\Core\Csv;
use App\Core\Exceptions\NotFoundHttpException;
use App\Core\Request;
use App\Core\Session;
use App\Models\ActivityLog;
use App\Models\Beneficiary;
use App\Models\BeneficiaryCategory;
use App\Models\BeneficiaryIndividual;
use App\Models\School;

/**
 * Data penerima manfaat MBG. Mode baku aplikasi adalah rekap jumlah, jadi halaman
 * utama hanya menampilkan agregat per sekolah, kategori, dan kelompok kelas. Nama
 * siswa per individu ada di halaman terpisah yang dibuka oleh PermissionMiddleware
 * dan diperiksa ulang pada setiap aksi tulis.
 *
 * Setiap perubahan jumlah wajib disertai alasan dan selalu menulis baris riwayat,
 * sehingga tren jumlah dapat direkonstruksi tanpa menghapus data lama.
 */
final class BeneficiaryController extends Controller
{
    private const HISTORY_LIMIT = 150;

    public function index(Request $request): void
    {
        $filters = $this->filters($request);
        $rekap = Beneficiary::rekapBySchool($filters);

        $this->view('penerima-manfaat/index', [
            'title' => 'Penerima Manfaat MBG',
            'pageTitle' => 'Rekap Jumlah Penerima Manfaat',
            'filters' => $filters,
            'rekap' => $rekap,
            'rows' => $rekap['rows'],
            'categories' => $rekap['categories'],
            'schools' => School::optionList(true),
            'mode' => App::setting('mbg_beneficiary_mode', 'rekap'),
            'today' => Beneficiary::totalAt(date('Y-m-d')),
            'canManage' => can('beneficiaries.manage'),
            'canIndividual' => can('beneficiaries.individual.view'),
        ]);
    }

    public function create(Request $request): void
    {
        $old = $this->pullOld();
        $schoolId = (int) ($old['school_id'] ?? $request->integer('sekolah'));

        $schoolName = '';
        if ($schoolId > 0) {
            $sch = School::find($schoolId);
            if ($sch !== null) {
                $schoolName = (string) $sch['school_name'];
            }
        }
        if ($schoolName === '' && !empty($old['school_name'])) {
            $schoolName = (string) $old['school_name'];
        }

        $this->view('penerima-manfaat/form', [
            'title' => 'Catat Jumlah Penerima',
            'pageTitle' => 'Catat Jumlah Penerima Manfaat',
            'form' => [
                'school_id' => $schoolId > 0 ? (string) $schoolId : '',
                'school_name' => $schoolName,
                'category_id' => (string) ($old['category_id'] ?? ''),
                'class_group' => (string) ($old['class_group'] ?? ''),
                'beneficiary_count' => (string) ($old['beneficiary_count'] ?? ''),
                'effective_date' => (string) ($old['effective_date'] ?? date('Y-m-d')),
                'reason' => (string) ($old['reason'] ?? ''),
                'notes' => (string) ($old['notes'] ?? ''),
            ],
            'previous' => $this->previousValue($schoolId, $old),
            'schools' => School::optionList(),
            'categories' => BeneficiaryCategory::activeList(),
            'classGroups' => $schoolId > 0 ? Beneficiary::classGroups($schoolId) : [],
        ]);
    }

    public function store(Request $request): void
    {
        $actor = $this->actor();
        $input = $this->readForm($request);
        [$values, $errors] = $this->validate($input, null);

        if ($errors !== []) {
            $this->reject('/penerima-manfaat/catat', $errors[0], $input);
        }

        /** @var array<string, mixed> $values */
        [$id, $action] = Beneficiary::record(
            (int) $values['school_id'],
            (int) $values['category_id'],
            (string) $values['class_group'],
            (string) $values['effective_date'],
            (int) $values['beneficiary_count'],
            (string) $values['reason'],
            (int) $actor['id'],
            $values['notes'] === null ? null : (string) $values['notes']
        );

        ActivityLog::record(
            (int) $actor['id'],
            'beneficiaries.record',
            'beneficiaries',
            'Mencatat jumlah penerima ' . $values['category_name'] . ' kelompok "' . $values['class_group'] . '"'
            . ' di ' . $values['school_name'] . ' sebanyak ' . number_format((int) $values['beneficiary_count'], 0, ',', '.')
            . ' berlaku ' . $values['effective_date'] . '.',
            $request->ip(),
            $request->userAgent()
        );

        Session::clearOld();
        Session::flash(
            'success',
            ($action === 'buat' ? 'Jumlah penerima manfaat tercatat.' : 'Jumlah penerima manfaat diperbarui.')
            . ' Perubahan beserta alasannya tersimpan pada riwayat.'
        );
        $this->redirect('/penerima-manfaat/' . $id);
    }

    public function show(Request $request, string $id): void
    {
        $row = $this->findRow($id);
        $asof = date('Y-m-d');

        $this->view('penerima-manfaat/show', [
            'title' => 'Detail Jumlah Penerima',
            'pageTitle' => 'Detail Baris Penerima Manfaat',
            'row' => $row,
            'series' => Beneficiary::series(
                (int) $row['school_id'],
                (int) $row['category_id'],
                (string) $row['class_group']
            ),
            'histories' => Beneficiary::histories([
                'school' => (string) $row['school_id'],
                'category' => (string) $row['category_id'],
                'from' => '',
                'to' => '',
            ], 40),
            'schoolTotal' => Beneficiary::totalAt($asof, (int) $row['school_id']),
            'canManage' => can('beneficiaries.manage'),
        ]);
    }

    public function edit(Request $request, string $id): void
    {
        $row = $this->findRow($id);
        $old = $this->pullOld();
        $schoolId = (int) $row['school_id'];

        $form = [
            'school_id' => (string) $schoolId,
            'category_id' => (string) $row['category_id'],
            'class_group' => (string) $row['class_group'],
            'beneficiary_count' => (string) $row['beneficiary_count'],
            'effective_date' => substr((string) $row['effective_date'], 0, 10),
            'reason' => '',
            'notes' => (string) ($row['notes'] ?? ''),
        ];
        foreach ($old as $key => $value) {
            if (array_key_exists($key, $form)) {
                $form[$key] = (string) $value;
            }
        }

        $this->view('penerima-manfaat/form', [
            'title' => 'Ubah Jumlah Penerima',
            'pageTitle' => 'Ubah Jumlah Penerima Manfaat',
            'form' => $form,
            'row' => $row,
            'previous' => ['beneficiary_count' => (int) $row['beneficiary_count'], 'row' => $row],
            'schools' => School::optionList(true),
            'categories' => BeneficiaryCategory::activeList(),
            'classGroups' => Beneficiary::classGroups($schoolId),
        ]);
    }

    public function update(Request $request, string $id): void
    {
        $actor = $this->actor();
        $row = $this->findRow($id);
        $input = $this->readForm($request);
        [$values, $errors] = $this->validate($input, (int) $row['id']);

        if ($errors !== []) {
            $this->reject('/penerima-manfaat/' . (int) $row['id'] . '/ubah', $errors[0], $input);
        }

        /** @var array<string, mixed> $values */
        if ((string) $values['effective_date'] !== substr((string) $row['effective_date'], 0, 10)) {
            // Tanggal berlaku berbeda berarti baris baru; jumlah lama harus tetap terbaca
            // sebagai riwayat, jadi perubahan diarahkan ke formulir pencatatan.
            $this->reject(
                '/penerima-manfaat/catat?sekolah=' . (int) $row['school_id'],
                'Tanggal berlaku berbeda dari baris ini. Simpan sebagai data baru melalui menu catat jumlah.',
                $input
            );
        }

        Beneficiary::record(
            (int) $values['school_id'],
            (int) $values['category_id'],
            (string) $values['class_group'],
            (string) $values['effective_date'],
            (int) $values['beneficiary_count'],
            (string) $values['reason'],
            (int) $actor['id'],
            $values['notes'] === null ? null : (string) $values['notes']
        );

        ActivityLog::record(
            (int) $actor['id'],
            'beneficiaries.update',
            'beneficiaries',
            'Mengubah jumlah penerima "' . $values['class_group'] . '" ' . $values['category_name'] . ' di '
            . $values['school_name'] . ' dari ' . (int) $row['beneficiary_count'] . ' menjadi '
            . (int) $values['beneficiary_count'] . '. Alasan: ' . $values['reason'],
            $request->ip(),
            $request->userAgent()
        );

        Session::clearOld();
        Session::flash('success', 'Jumlah penerima manfaat diperbarui dan alasannya tercatat pada riwayat.');
        $this->redirect('/penerima-manfaat/' . (int) $row['id']);
    }

    public function histories(Request $request): void
    {
        $filters = $this->historyFilters($request);

        $this->view('penerima-manfaat/riwayat', [
            'title' => 'Riwayat Perubahan Penerima',
            'pageTitle' => 'Riwayat Perubahan Jumlah Penerima Manfaat',
            'filters' => $filters,
            'histories' => Beneficiary::histories($filters, self::HISTORY_LIMIT),
            'schools' => School::optionList(true),
            'categories' => BeneficiaryCategory::activeList(),
        ]);
    }

    public function export(Request $request): void
    {
        $filters = $this->filters($request);
        $rows = Beneficiary::rekap($filters);

        Csv::send(
            'rekap-penerima-manfaat-' . $filters['asof'] . '.csv',
            ['Kode Sekolah', 'Nama Sekolah', 'Jenjang', 'Kategori', 'Kelompok Kelas', 'Jumlah', 'Berlaku', 'Catatan'],
            array_map(static fn (array $row): array => [
                $row['school_code'],
                $row['school_name'],
                $row['level_name'],
                $row['category_name'],
                $row['class_group'],
                (int) $row['beneficiary_count'],
                substr((string) $row['effective_date'], 0, 10),
                (string) ($row['notes'] ?? ''),
            ], $rows)
        );
    }

    /** Nonaktifkan atau aktifkan kembali satu baris. Alasan tetap disimpan pada riwayat. */
    public function status(Request $request, string $id): void
    {
        $actor = $this->actor();
        $row = $this->findRow($id);
        $to = $request->string('status');
        $reason = $this->reason($request, $errors);

        if (!in_array($to, [Beneficiary::STATUS_ACTIVE, Beneficiary::STATUS_INACTIVE], true)) {
            $this->fail((int) $row['id'], 'Status data tidak dikenali.');
        }
        if ($errors !== []) {
            $this->fail((int) $row['id'], $errors[0]);
        }

        $changed = Beneficiary::setStatus((int) $row['id'], $to, $reason, (int) $actor['id']);

        if (!$changed) {
            $this->fail((int) $row['id'], 'Status baris ini sudah ' . $to . ', jadi tidak ada yang diubah.');
        }

        ActivityLog::record(
            (int) $actor['id'],
            'beneficiaries.status',
            'beneficiaries',
            ($to === Beneficiary::STATUS_ACTIVE ? 'Mengaktifkan kembali' : 'Menonaktifkan')
            . ' jumlah penerima "' . $row['class_group'] . '" di sekolah ' . (int) $row['school_id'] . '.'
            . ' Alasan: ' . $reason,
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', $to === Beneficiary::STATUS_ACTIVE
            ? 'Baris data diaktifkan kembali dan tidak dihitung pada rekap sebelumnya.'
            : 'Baris data dinonaktifkan, riwayatnya tetap tersimpan.');
        $this->redirect('/penerima-manfaat/' . (int) $row['id']);
    }

    /**
     * Menghapus baris rekap. Riwayat perubahan tetap ada karena setiap baris riwayat
     * menyimpan salinan sekolah, kategori, kelompok, dan jumlahnya.
     */
    public function destroy(Request $request, string $id): void
    {
        $actor = $this->actor();
        $row = $this->findRow($id);
        $reason = $this->reason($request, $errors);

        if ($errors !== []) {
            $this->fail((int) $row['id'], $errors[0]);
        }

        Beneficiary::remove((int) $row['id'], $reason, (int) $actor['id']);

        ActivityLog::record(
            (int) $actor['id'],
            'beneficiaries.delete',
            'beneficiaries',
            'Menghapus baris jumlah penerima "' . $row['class_group'] . '" (kategori ' . (int) $row['category_id']
            . ', sekolah ' . (int) $row['school_id'] . ', ' . (int) $row['beneficiary_count'] . ' orang).'
            . ' Alasan: ' . $reason,
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', 'Baris jumlah penerima dihapus. Riwayat perubahannya tetap dapat dibaca.');
        $this->redirect('/penerima-manfaat');
    }

    /**
     * Halaman individu opsional. Rutenya sudah dijaga beneficiaries.individual.view dan
     * aksi tulis diperiksa ulang di sini agar pembaca data tidak bisa mengubah data.
     */
    public function individual(Request $request): void
    {
        $schoolId = $request->integer('sekolah');
        $school = $schoolId > 0 ? School::findWithNames($schoolId) : null;
        if ($schoolId > 0 && $school === null) {
            throw new NotFoundHttpException();
        }

        $result = $school === null
            ? ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'per_page' => 20]
            : BeneficiaryIndividual::search(
                $schoolId,
                $this->term($request),
                $request->string('kelompok'),
                $request->string('status'),
                max(1, $request->integer('page', 1))
            );

        $this->view('penerima-manfaat/individu', [
            'title' => 'Data Individu Penerima',
            'pageTitle' => 'Data Individu Penerima Manfaat',
            'school' => $school,
            'schools' => School::optionList(true),
            'categories' => $school === null ? [] : BeneficiaryCategory::activeList(),
            'result' => $result,
            'rows' => $result['rows'],
            'classGroups' => $school === null ? [] : Beneficiary::classGroups($schoolId),
            'term' => $this->term($request),
            'kelompok' => $request->string('kelompok'),
            'status' => $request->string('status'),
            'rekapTotal' => $school === null ? 0 : Beneficiary::totalAt(date('Y-m-d'), $schoolId),
            'canManage' => $this->canIndividualManage(),
            'basePath' => '/penerima-manfaat/individu',
        ]);
    }

    public function storeIndividual(Request $request): void
    {
        $this->guardIndividualManage();
        $actor = $this->actor();
        $input = $this->readIndividual($request);
        [$data, $errors] = $this->validateIndividual($input, null);

        if ($errors !== []) {
            $this->reject(
                '/penerima-manfaat/individu?sekolah=' . (int) $input['school_id'],
                $errors[0],
                $input
            );
        }

        /** @var array<string, mixed> $data */
        $data['created_by'] = (int) $actor['id'];
        $data['updated_by'] = (int) $actor['id'];
        $id = BeneficiaryIndividual::create($data);

        ActivityLog::record(
            (int) $actor['id'],
            'beneficiaries.individual.create',
            'beneficiaries',
            'Menambah satu nama penerima individu pada kelompok "' . $data['class_group'] . '" di sekolah '
            . (int) $data['school_id'] . '.',
            $request->ip(),
            $request->userAgent()
        );

        Session::clearOld();
        Session::flash('success', 'Nama penerima manfaat individu tersimpan.');
        $this->redirect('/penerima-manfaat/individu?sekolah=' . (int) $data['school_id'] . '#nama-' . $id);
    }

    public function updateIndividual(Request $request, string $id): void
    {
        $this->guardIndividualManage();
        $actor = $this->actor();
        $individual = $this->findIndividual($id);
        $input = $this->readIndividual($request);
        [$data, $errors] = $this->validateIndividual($input, (int) $individual['id']);

        if ($errors !== []) {
            $this->reject(
                '/penerima-manfaat/individu?sekolah=' . (int) $individual['school_id'],
                $errors[0],
                $input
            );
        }

        /** @var array<string, mixed> $data */
        $data['updated_by'] = (int) $actor['id'];
        BeneficiaryIndividual::update((int) $individual['id'], $data);

        ActivityLog::record(
            (int) $actor['id'],
            'beneficiaries.individual.update',
            'beneficiaries',
            'Memperbarui satu nama penerima individu pada kelompok "' . $data['class_group'] . '" di sekolah '
            . (int) $data['school_id'] . '.',
            $request->ip(),
            $request->userAgent()
        );

        Session::clearOld();
        Session::flash('success', 'Data individu diperbarui.');
        $this->redirect('/penerima-manfaat/individu?sekolah=' . (int) $data['school_id']);
    }

    public function statusIndividual(Request $request, string $id): void
    {
        $this->guardIndividualManage();
        $actor = $this->actor();
        $individual = $this->findIndividual($id);
        $to = $request->string('status');

        if (!in_array($to, [BeneficiaryIndividual::STATUS_ACTIVE, BeneficiaryIndividual::STATUS_INACTIVE], true)) {
            $this->failIndividual($individual, 'Status data tidak dikenali.');
        }

        BeneficiaryIndividual::setStatus((int) $individual['id'], $to, (int) $actor['id']);

        ActivityLog::record(
            (int) $actor['id'],
            'beneficiaries.individual.status',
            'beneficiaries',
            ($to === BeneficiaryIndividual::STATUS_ACTIVE ? 'Mengaktifkan kembali' : 'Menonaktifkan')
            . ' satu nama penerima individu di sekolah ' . (int) $individual['school_id'] . '.',
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', 'Status data individu diperbarui.');
        $this->redirect('/penerima-manfaat/individu?sekolah=' . (int) $individual['school_id']);
    }

    public function destroyIndividual(Request $request, string $id): void
    {
        $this->guardIndividualManage();
        $actor = $this->actor();
        $individual = $this->findIndividual($id);

        BeneficiaryIndividual::delete((int) $individual['id']);

        ActivityLog::record(
            (int) $actor['id'],
            'beneficiaries.individual.delete',
            'beneficiaries',
            'Menghapus satu nama penerima individu dari kelompok "' . $individual['class_group']
            . '" di sekolah ' . (int) $individual['school_id'] . '.',
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', 'Nama individu dihapus. Jumlah rekap tidak berubah.');
        $this->redirect('/penerima-manfaat/individu?sekolah=' . (int) $individual['school_id']);
    }

    /** @return array<string, mixed>|null */
    private function previousValue(int $schoolId, array $old): ?array
    {
        $categoryId = (int) ($old['category_id'] ?? 0);
        $classGroup = trim((string) ($old['class_group'] ?? ''));
        $date = trim((string) ($old['effective_date'] ?? ''));

        if ($schoolId <= 0 || $categoryId <= 0 || $classGroup === '' || !date_is_valid($date)) {
            return null;
        }

        return Beneficiary::latestRow($schoolId, $categoryId, $classGroup, $date);
    }

    /** @return array{asof: string, school: string, category: string, search: string} */
    private function filters(Request $request): array
    {
        $asof = trim($request->string('tanggal'));
        if ($asof === '' || !date_is_valid($asof)) {
            $asof = date('Y-m-d');
        }

        return [
            'asof' => $asof,
            'school' => (string) max(0, $request->integer('sekolah')),
            'category' => (string) max(0, $request->integer('kategori')),
            'search' => mb_substr(preg_replace('/\s+/', ' ', trim($request->string('q'))) ?? '', 0, 60),
        ];
    }

    /** @return array{school: string, category: string, from: string, to: string} */
    private function historyFilters(Request $request): array
    {
        return [
            'school' => (string) max(0, $request->integer('sekolah')),
            'category' => (string) max(0, $request->integer('kategori')),
            'from' => $this->date($request->string('dari')),
            'to' => $this->date($request->string('sampai')),
        ];
    }

    private function date(string $raw): string
    {
        return date_is_valid(trim($raw)) ? trim($raw) : '';
    }

    private function term(Request $request): string
    {
        return mb_substr(preg_replace('/\s+/', ' ', trim($request->string('q'))) ?? '', 0, 60);
    }

    /** @return array<string, string> */
    private function readForm(Request $request): array
    {
        return [
            'school_id' => (string) $request->integer('school_id'),
            'school_name' => trim($request->string('school_name')),
            'category_id' => (string) $request->integer('category_id'),
            'class_group' => trim($request->string('class_group')),
            'beneficiary_count' => trim($request->string('beneficiary_count')),
            'effective_date' => trim($request->string('effective_date')),
            'reason' => preg_replace('/\s+/', ' ', trim($request->string('reason'))) ?? '',
            'notes' => preg_replace('/\s+/', ' ', trim($request->string('notes'))) ?? '',
        ];
    }

    /**
     * @param array<string, string> $input
     * @return array{0: array<string, mixed>|null, 1: array<int, string>}
     */
    private function validate(array $input, ?int $exceptId): array
    {
        $errors = [];

        $schoolId = (int) $input['school_id'];
        $manualSchoolName = trim($input['school_name'] ?? '');

        // Jika user mengetik manual nama sekolah dan belum memilih dari dropdown
        if ($schoolId <= 0 && $manualSchoolName !== '') {
            $existing = School::findByName($manualSchoolName);
            if ($existing !== null) {
                $schoolId = (int) $existing['id'];
            } else {
                // Buat sekolah baru secara otomatis dengan nama yang diketik manual
                $schoolCode = School::nextCode();
                $schoolId = School::create([
                    'school_code' => $schoolCode,
                    'school_name' => $manualSchoolName,
                    'level_id' => 2, // Default jenjang SD/MI
                    'school_status' => School::STATUS_ACTIVE,
                    'partnership_status' => School::PARTNERSHIP_NONE,
                    'created_by' => $exceptId !== null ? null : (auth_user()['id'] ?? null),
                    'updated_by' => auth_user()['id'] ?? null,
                ]);
            }
        }

        $categoryId = (int) $input['category_id'];
        $classGroup = mb_substr($input['class_group'], 0, 60);
        $date = $input['effective_date'];
        $notes = $input['notes'] === '' ? null : mb_substr($input['notes'], 0, 500);

        $school = $schoolId > 0 ? School::findWithNames($schoolId) : null;
        if ($school === null) {
            $errors[] = 'Sekolah penerima manfaat wajib diisi atau dipilih.';
        } elseif ((string) $school['school_status'] === School::STATUS_ARCHIVED) {
            $errors[] = 'Sekolah "' . $school['school_name'] . '" sudah diarsipkan, jadi jumlahnya tidak dapat dicatat.';
        }

        $category = $categoryId > 0 ? BeneficiaryCategory::find($categoryId) : null;
        if ($category === null) {
            $errors[] = 'Kategori penerima manfaat wajib dipilih.';
        } elseif ((int) $category['is_active'] !== 1) {
            $errors[] = 'Kategori "' . $category['category_name'] . '" sedang dinonaktifkan, aktifkan dulu kategorinya.';
        }

        if ($classGroup === '') {
            $errors[] = 'Kelompok kelas wajib diisi, misalnya "Kelas 1A" atau "Guru", agar jumlah terpisah per kelompok.';
        } elseif (preg_match('/^[^"<>]{1,60}$/u', $classGroup) !== 1) {
            $errors[] = 'Kelompok kelas maksimal 60 karakter dan tidak boleh memuat tanda kutip atau tag.';
        }

        if (!preg_match('/^\d{1,6}$/', $input['beneficiary_count'])) {
            $errors[] = 'Jumlah penerima harus angka bulat 0 sampai 999999. Jumlah negatif tidak diterima.';
        } elseif ((int) $input['beneficiary_count'] > 999999) {
            $errors[] = 'Jumlah penerima terlalu besar, periksa kembali datanya.';
        }

        if (!date_is_valid($date)) {
            $errors[] = 'Tanggal berlaku wajib diisi dengan format YYYY-MM-DD.';
        } elseif ($date > date('Y-m-d')) {
            $errors[] = 'Tanggal berlaku belum boleh di masa depan agar jumlahnya sesuai kondisi lapangan.';
        }

        $reason = $input['reason'];
        if (mb_strlen($reason) < 5) {
            $errors[] = 'Alasan perubahan wajib diisi minimal 5 karakter, misalnya "data dapodik terbaru".';
        } elseif (mb_strlen($reason) > 500) {
            $errors[] = 'Alasan perubahan maksimal 500 karakter.';
        }

        if ($errors === [] && Beneficiary::rowExists($schoolId, $categoryId, $classGroup, $date, $exceptId)) {
            $errors[] = 'Kelompok ini sudah punya jumlah pada tanggal tersebut. Ubah baris yang ada, jangan membuat duplikat.';
        }

        if ($errors !== []) {
            return [null, $errors];
        }

        /** @var array<string, mixed> $school */
        /** @var array<string, mixed> $category */
        return [[
            'school_id' => $schoolId,
            'category_id' => $categoryId,
            'class_group' => $classGroup,
            'beneficiary_count' => (int) $input['beneficiary_count'],
            'effective_date' => $date,
            'reason' => $reason,
            'notes' => $notes,
            'school_name' => (string) $school['school_name'],
            'category_name' => (string) $category['category_name'],
        ], []];
    }

    /** @return array<string, string> */
    private function readIndividual(Request $request): array
    {
        return [
            'school_id' => (string) $request->integer('school_id'),
            'category_id' => (string) $request->integer('category_id'),
            'class_group' => trim($request->string('class_group')),
            'full_name' => preg_replace('/\s+/', ' ', trim($request->string('full_name'))) ?? '',
            'birth_year' => trim($request->string('birth_year')),
            'notes' => preg_replace('/\s+/', ' ', trim($request->string('notes'))) ?? '',
        ];
    }

    /**
     * Kolom individu sengaja dibatasi: tidak ada NIK, nomor Kartu Keluarga, alamat,
     * atau data medis. Nilai yang dikirim di luar daftar ini diabaikan.
     *
     * @param array<string, string> $input
     * @return array{0: array<string, mixed>|null, 1: array<int, string>}
     */
    private function validateIndividual(array $input, ?int $exceptId): array
    {
        $errors = [];

        $schoolId = (int) $input['school_id'];
        $categoryId = (int) $input['category_id'];
        $classGroup = mb_substr($input['class_group'], 0, 60);
        $name = $input['full_name'];
        $year = $input['birth_year'];

        if ($schoolId <= 0 || School::find($schoolId) === null) {
            $errors[] = 'Sekolah tujuan data individu wajib dipilih.';
        }

        $category = $categoryId > 0 ? BeneficiaryCategory::find($categoryId) : null;
        if ($category === null) {
            $errors[] = 'Kategori penerima manfaat wajib dipilih.';
        }

        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $errors[] = 'Nama harus terdiri dari 2 sampai 120 karakter.';
        } elseif (preg_match('/^[^"<>]*$/u', $name) !== 1) {
            $errors[] = 'Nama tidak boleh memuat tanda kutip atau tag.';
        }

        if ($year !== '' && (preg_match('/^(19|20)\d{2}$/', $year) !== 1 || (int) $year > (int) date('Y'))) {
            $errors[] = 'Tahun lahir diisi 4 digit (1900 sampai ' . date('Y') . ') atau dikosongkan.';
        }

        if ($classGroup !== '' && preg_match('/^[^"<>]{1,60}$/u', $classGroup) !== 1) {
            $errors[] = 'Kelompok kelas maksimal 60 karakter dan tidak boleh memuat tanda kutip atau tag.';
        }

        if (mb_strlen($input['notes']) > 255) {
            $errors[] = 'Catatan maksimal 255 karakter.';
        }

        if ($errors === [] && BeneficiaryIndividual::scopeExists($schoolId, $categoryId, $classGroup, $name, $exceptId)) {
            $errors[] = 'Nama yang sama sudah terdaftar pada sekolah, kategori, dan kelompok ini.';
        }

        if ($errors !== []) {
            return [null, $errors];
        }

        return [[
            'school_id' => $schoolId,
            'category_id' => $categoryId,
            'class_group' => $classGroup,
            'full_name' => $name,
            'birth_year' => $year === '' ? null : (int) $year,
            'notes' => $input['notes'] === '' ? null : mb_substr($input['notes'], 0, 255),
        ], []];
    }

    /** @param array<int, string> $errors */
    private function reason(Request $request, array &$errors): string
    {
        $reason = preg_replace('/\s+/', ' ', trim($request->string('reason'))) ?? '';

        if (mb_strlen($reason) < 5) {
            $errors[] = 'Alasan wajib diisi minimal 5 karakter agar riwayatnya jelas.';
        } elseif (mb_strlen($reason) > 500) {
            $errors[] = 'Alasan maksimal 500 karakter.';
        }

        return $reason;
    }

    private function canIndividualManage(): bool
    {
        return can('beneficiaries.individual.view') && can('beneficiaries.manage');
    }

    /** Rutenya hanya memeriksa individual.view, jadi hak ubah diperiksa di sini. */
    private function guardIndividualManage(): void
    {
        if (!$this->canIndividualManage()) {
            Session::flash('danger', 'Hak akses tidak mencukupi untuk mengubah data individu penerima manfaat.');
            $this->redirect('/penerima-manfaat/individu');
        }
    }

    /** @return array<string, mixed> */
    private function findRow(string $id): array
    {
        if (!ctype_digit($id)) {
            throw new NotFoundHttpException();
        }

        $row = Beneficiary::findDetail((int) $id);
        if ($row === null) {
            throw new NotFoundHttpException();
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private function findIndividual(string $id): array
    {
        if (!ctype_digit($id)) {
            throw new NotFoundHttpException();
        }

        $row = BeneficiaryIndividual::find((int) $id);
        if ($row === null) {
            throw new NotFoundHttpException();
        }

        return $row;
    }

    private function fail(int $id, string $message): never
    {
        Session::flash('danger', $message);
        $this->redirect('/penerima-manfaat/' . $id);
    }

    /** @param array<string, mixed> $individual */
    private function failIndividual(array $individual, string $message): never
    {
        Session::flash('danger', $message);
        $this->redirect('/penerima-manfaat/individu?sekolah=' . (int) $individual['school_id']);
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
