<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Controller;
use App\Core\Exceptions\NotFoundHttpException;
use App\Core\Request;
use App\Core\Session;
use App\Models\ActivityLog;
use App\Models\BudgetSummary;
use App\Models\FinancialTransaction;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\ProjectProgress;
use App\Models\ProjectSchedule;
use App\Models\ProjectTeam;
use App\Models\User;

final class ProjectController extends Controller
{
    private const PER_PAGE = 10;

    public function index(Request $request): void
    {
        $filters = $this->filters($request);
        $result = Project::paginate($filters, max(1, $request->integer('page', 1)), self::PER_PAGE);

        $this->view('projects/index', [
            'title' => 'Proyek Pembangunan',
            'pageTitle' => 'Manajemen Proyek Pembangunan',
            'filters' => $filters,
            'result' => $result,
            'projects' => $result['rows'],
            'statuses' => Project::STATUSES,
            'totals' => Project::summary(),
        ]);
    }

    public function show(Request $request, string $id): void
    {
        $project = $this->findProject($id);
        $projectId = (int) $project['id'];

        $progressRows = ProjectProgress::forProject($projectId, 30);
        $old = Session::pull('_old', []);

        $this->view('projects/show', [
            'title' => (string) $project['name'],
            'pageTitle' => 'Detail Proyek',
            'project' => $project,
            'statusHistory' => Project::statusHistory($projectId),
            'progressRows' => $progressRows,
            'progressCount' => ProjectProgress::countForProject($projectId),
            'latestProgress' => ProjectProgress::latest($projectId),
            'progressSeries' => ProjectProgress::series($projectId),
            'documents' => ProjectDocument::forProject($projectId),
            'team' => ProjectTeam::active($projectId),
            'teamHistory' => ProjectTeam::history($projectId),
            'candidates' => ProjectTeam::candidates($projectId),
            'statuses' => Project::STATUSES,
            'documentCategories' => array_values(array_diff(
                ProjectDocument::CATEGORIES,
                [ProjectDocument::TRANSACTION_CATEGORY]
            )),
            'old' => is_array($old) ? $old : [],
            'maxUploadMb' => (int) App::config('app.uploads.max_mb', 10),
            // Nilai uang dan jadwal hanya dikirim bila izinnya ada; tanpa izin, kolom tetap
            // muncul dengan keterangan bahwa datanya dibatasi, bukan angka nol yang menyesatkan.
            'finance' => can('budgets.view') ? BudgetSummary::projectOverview($projectId) : null,
            // Realisasi operasional MBG dilaporkan terpisah dan tanpa persen penyerapan,
            // karena nomor RAB pembangunan bukan pembanding yang sepadan untuk biaya operasional.
            'operationsSpent' => can('transactions.view')
                ? FinancialTransaction::realization($projectId, FinancialTransaction::CENTER_OPERATIONS)
                : null,
            'tasks' => can('schedules.view') ? ProjectSchedule::forProject($projectId) : null,
            'taskSummary' => can('schedules.view') ? ProjectSchedule::summary($projectId) : null,
            'pageScripts' => ['vendor/chartjs/chart.umd.min.js', 'js/charts.js', 'js/projects.js'],
        ]);
    }

    public function create(Request $request): void
    {
        $old = Session::pull('_old', []);
        $old = is_array($old) ? $old : [];

        $form = Project::emptyRecord();
        foreach ($old as $key => $value) {
            if (array_key_exists($key, $form)) {
                $form[$key] = (string) $value;
            }
        }

        if ((string) $form['project_code'] === '') {
            $form['project_code'] = Project::nextCode();
        }

        $this->view('projects/form', [
            'title' => 'Tambah Proyek',
            'pageTitle' => 'Tambah Proyek Pembangunan',
            'mode' => 'create',
            'form' => $form,
            'statuses' => Project::STATUSES,
            'users' => $this->principalCandidates(),
            'project' => null,
        ]);
    }

    public function store(Request $request): void
    {
        $actor = $this->actor();
        $input = $this->readForm($request);
        [$data, $errors] = $this->validate($input, null);

        if ($errors !== []) {
            $this->reject('/proyek/tambah', $errors[0], $input);
        }

        /** @var array<string, mixed> $data */
        $data['created_by'] = (int) $actor['id'];
        $data['updated_by'] = (int) $actor['id'];
        $projectId = Project::create($data);

        $project = Project::findWithNames($projectId);
        Project::logStatus($projectId, null, (string) $data['status'], 'Status awal proyek dicatat.', (int) $actor['id']);

        ActivityLog::record(
            (int) $actor['id'],
            'projects.create',
            'projects',
            'Menambah proyek "' . $data['project_code'] . '" — ' . $data['name'] . '.',
            $request->ip(),
            $request->userAgent()
        );

        if ($project !== null) {
            Notification::projectCreated($project, (int) $actor['id']);
        }

        Session::clearOld();
        Session::flash('success', 'Proyek "' . $data['name'] . '" berhasil ditambahkan.');
        $this->redirect('/proyek/' . $projectId);
    }

    public function edit(Request $request, string $id): void
    {
        $project = $this->findProject($id);
        $old = Session::pull('_old', []);
        $old = is_array($old) ? $old : [];

        $form = [];
        foreach (Project::emptyRecord() as $key => $unused) {
            $form[$key] = (string) ($old[$key] ?? ($project[$key] ?? ''));
        }

        $this->view('projects/form', [
            'title' => 'Sunting Proyek',
            'pageTitle' => 'Sunting Proyek Pembangunan',
            'mode' => 'edit',
            'form' => $form,
            'statuses' => Project::STATUSES,
            'users' => $this->principalCandidates(),
            'project' => $project,
        ]);
    }

    public function update(Request $request, string $id): void
    {
        $actor = $this->actor();
        $project = $this->findProject($id);
        $input = $this->readForm($request);

        [$data, $errors] = $this->validate($input, (int) $project['id']);

        $newStatus = $data['status'] ?? null;
        if ($data !== null && (string) $newStatus !== (string) $project['status']) {
            $reason = trim((string) $input['status_reason']);
            if ($this->requiresReason((string) $newStatus) && $reason === '') {
                $errors[] = 'Perubahan status ke ' . project_status_label((string) $newStatus)
                    . ' wajib disertai alasan.';
            }
            $data['status_reason'] = $reason;
        }

        if ((int) $project['is_archived'] === 1) {
            $errors[] = 'Proyek yang sudah diarsipkan tidak dapat diubah. Pulihkan arsip terlebih dahulu.';
        }

        if ($errors !== []) {
            $this->reject('/proyek/' . $project['id'] . '/ubah', $errors[0], $input);
        }

        /** @var array<string, mixed> $data */
        $data['updated_by'] = (int) $actor['id'];
        Project::update((int) $project['id'], $data);

        ActivityLog::record(
            (int) $actor['id'],
            'projects.update',
            'projects',
            'Memperbarui proyek "' . $data['project_code'] . '".',
            $request->ip(),
            $request->userAgent()
        );

        if ((string) $data['status'] !== (string) $project['status']) {
            $this->recordStatusChange($project, (string) $data['status'], (string) $data['status_reason'], $actor, $request);
        }

        Session::clearOld();
        Session::flash('success', 'Proyek "' . $data['name'] . '" berhasil diperbarui.');
        $this->redirect('/proyek/' . $project['id']);
    }

    public function status(Request $request, string $id): void
    {
        $actor = $this->actor();
        $project = $this->findProject($id);

        if ((int) $project['is_archived'] === 1) {
            $this->fail('Proyek yang sudah diarsipkan tidak dapat diubah statusnya. Pulihkan arsip terlebih dahulu.', (string) $project['id']);
        }

        $status = $request->string('status');
        if (!in_array($status, Project::STATUSES, true)) {
            $this->fail('Status proyek tidak dikenali.', (string) $project['id']);
        }

        if ($status === (string) $project['status']) {
            $this->fail('Status baru harus berbeda dari status saat ini.', (string) $project['id']);
        }

        $reason = $request->string('reason');
        if ($this->requiresReason($status) && $reason === '') {
            $this->fail('Perubahan status ke ' . project_status_label($status) . ' wajib disertai alasan.', (string) $project['id']);
        }

        Project::updateStatus((int) $project['id'], $status, (int) $actor['id']);
        $this->recordStatusChange($project, $status, $reason, $actor, $request);

        Session::flash('success', 'Status proyek diubah menjadi ' . project_status_label($status) . '.');
        $this->redirect('/proyek/' . $project['id'] . '#status');
    }

    public function archive(Request $request, string $id): void
    {
        $actor = $this->actor();
        $project = $this->findProject($id);

        $archive = $request->boolean('archive');
        Project::setArchived((int) $project['id'], $archive, (int) $actor['id']);

        ActivityLog::record(
            (int) $actor['id'],
            $archive ? 'projects.archive' : 'projects.unarchive',
            'projects',
            ($archive ? 'Mengarsipkan' : 'Memulihkan') . ' proyek "' . $project['project_code'] . '" — '
            . $project['name'] . '.',
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', $archive
            ? 'Proyek "' . $project['name'] . '" telah diarsipkan.'
            : 'Proyek "' . $project['name'] . '" telah dipulihkan dari arsip.');
        $this->redirect('/proyek/' . $project['id']);
    }

    /**
     * @param array<string, mixed> $project
     * @param array<string, mixed> $actor
     */
    private function recordStatusChange(
        array $project,
        string $toStatus,
        string $reason,
        array $actor,
        Request $request
    ): void {
        Project::logStatus(
            (int) $project['id'],
            (string) $project['status'],
            $toStatus,
            $reason === '' ? null : mb_substr($reason, 0, 255),
            (int) $actor['id']
        );

        ActivityLog::record(
            (int) $actor['id'],
            'projects.status',
            'projects',
            'Mengubah status proyek "' . $project['project_code'] . '" dari '
            . project_status_label((string) $project['status']) . ' menjadi '
            . project_status_label($toStatus) . ($reason !== '' ? '. Alasan: ' . mb_substr($reason, 0, 140) : '.'),
            $request->ip(),
            $request->userAgent()
        );

        $updated = Project::findWithNames((int) $project['id']);
        if ($updated !== null) {
            $logs = Project::statusHistory((int) $project['id']);
            Notification::statusChanged($updated, $toStatus, (int) ($logs[0]['id'] ?? 0), (int) $actor['id']);
        }
    }

    private function requiresReason(string $status): bool
    {
        return in_array($status, [Project::STATUS_DELAYED, Project::STATUS_CANCELLED], true);
    }

    /**
     * @return array{search: string, status: string, from: string, to: string, archived: string, sort: string, dir: string}
     */
    private function filters(Request $request): array
    {
        $search = preg_replace('/\s+/', ' ', trim($request->string('q'))) ?? '';
        $status = $request->string('status');
        $from = $request->string('from');
        $to = $request->string('to');
        $archived = $request->string('archived');
        $sort = $request->string('sort');
        $dir = strtolower($request->string('dir'));

        return [
            'search' => mb_substr($search, 0, 60),
            'status' => in_array($status, Project::STATUSES, true) ? $status : '',
            'from' => date_is_valid($from) ? $from : '',
            'to' => date_is_valid($to) ? $to : '',
            'archived' => in_array($archived, ['1', 'all'], true) ? $archived : '',
            'sort' => array_key_exists($sort, Project::SORTABLE) ? $sort : 'target',
            'dir' => in_array($dir, ['asc', 'desc'], true) ? $dir : 'asc',
        ];
    }

    /** @return array<string, string> */
    private function readForm(Request $request): array
    {
        return [
            'project_code' => (string) $request->input('project_code', ''),
            'name' => (string) $request->input('name', ''),
            'description' => (string) $request->input('description', ''),
            'location' => (string) $request->input('location', ''),
            'address' => (string) $request->input('address', ''),
            'latitude' => (string) $request->input('latitude', ''),
            'longitude' => (string) $request->input('longitude', ''),
            'pic_name' => (string) $request->input('pic_name', ''),
            'pic_user_id' => (string) $request->input('pic_user_id', ''),
            'contractor' => (string) $request->input('contractor', ''),
            'start_date' => (string) $request->input('start_date', ''),
            'target_date' => (string) $request->input('target_date', ''),
            'budget_amount' => (string) $request->input('budget_amount', ''),
            'status' => (string) $request->input('status', ''),
            'status_reason' => (string) $request->input('status_reason', ''),
            'notes' => (string) $request->input('notes', ''),
        ];
    }

    /**
     * @param array<string, string> $input
     * @return array{0: array<string, mixed>|null, 1: array<int, string>}
     */
    private function validate(array $input, ?int $exceptId): array
    {
        $errors = [];

        $code = mb_strtoupper(preg_replace('/\s+/', ' ', trim($input['project_code'])) ?? '', 'UTF-8');
        $name = trim($input['name']);
        $description = trim($input['description']);
        $location = trim($input['location']);
        $address = trim($input['address']);
        $latitude = trim($input['latitude']);
        $longitude = trim($input['longitude']);
        $picName = trim($input['pic_name']);
        $picUserId = trim($input['pic_user_id']);
        $contractor = trim($input['contractor']);
        $startDate = trim($input['start_date']);
        $targetDate = trim($input['target_date']);
        $budget = str_replace(' ', '', trim($input['budget_amount']));
        $status = trim($input['status']);
        $notes = trim($input['notes']);

        if ($code === '') {
            if ($exceptId === null) {
                $code = Project::nextCode();
            } else {
                $errors[] = 'Kode proyek wajib diisi.';
            }
        }

        if ($code !== '' && !project_code_is_valid($code)) {
            $errors[] = 'Kode proyek harus 3-30 karakter, diawali huruf kapital, dan hanya memuat huruf kapital, angka, titik, garis bawah, atau tanda hubung.';
        } elseif ($code !== '' && Project::codeExists($code, $exceptId)) {
            $errors[] = 'Kode proyek sudah dipakai proyek lain.';
        }

        if (mb_strlen($name) < 3 || mb_strlen($name) > 150) {
            $errors[] = 'Nama proyek harus terdiri dari 3 sampai 150 karakter.';
        }

        if (mb_strlen($description) > 2000) {
            $errors[] = 'Deskripsi proyek maksimal 2000 karakter.';
        }

        if (mb_strlen($location) < 3 || mb_strlen($location) > 150) {
            $errors[] = 'Lokasi proyek harus terdiri dari 3 sampai 150 karakter.';
        }

        if (mb_strlen($address) > 1000) {
            $errors[] = 'Alamat proyek maksimal 1000 karakter.';
        }

        foreach ([['latitude', 'Lintang', true], ['longitude', 'Bujur', false]] as $check) {
            [$field, $label, $isLatitude] = $check;
            $value = $field === 'latitude' ? $latitude : $longitude;

            if ($value !== '' && !coordinate_is_valid($value, $isLatitude)) {
                $errors[] = $label . ' harus berupa angka antara '
                    . ($isLatitude ? '-90 sampai 90' : '-180 sampai 180') . '.';
            }
        }

        if (mb_strlen($picName) < 3 || mb_strlen($picName) > 120) {
            $errors[] = 'Nama penanggung jawab harus terdiri dari 3 sampai 120 karakter.';
        }

        $picUser = null;
        if ($picUserId !== '') {
            if (!ctype_digit($picUserId) || (int) $picUserId <= 0) {
                $errors[] = 'Pengguna penanggung jawab tidak valid.';
            } else {
                $picUser = User::find((int) $picUserId);
                if ($picUser === null || (string) $picUser['status'] !== User::STATUS_ACTIVE) {
                    $errors[] = 'Penanggung jawab harus akun aktif yang terdaftar.';
                }
            }
        }

        if (mb_strlen($contractor) > 150) {
            $errors[] = 'Nama kontraktor maksimal 150 karakter.';
        }

        if (!date_is_valid($startDate)) {
            $errors[] = 'Tanggal mulai wajib diisi dengan format yang benar (contoh 2026-11-01).';
        }

        if (!date_is_valid($targetDate)) {
            $errors[] = 'Target selesai wajib diisi dengan format yang benar (contoh 2026-12-31).';
        }

        if (date_is_valid($startDate) && date_is_valid($targetDate) && $targetDate < $startDate) {
            $errors[] = 'Target selesai tidak boleh lebih awal dari tanggal mulai.';
        }

        if (preg_match('/^\d{1,13}(\.\d{1,2})?$/', $budget) !== 1) {
            $errors[] = 'Anggaran harus berupa angka tanpa pemisah, maksimal 13 digit dengan dua angka desimal.';
        }

        if (!in_array($status, Project::STATUSES, true)) {
            $errors[] = 'Status proyek tidak dikenali.';
        }

        if (mb_strlen($notes) > 2000) {
            $errors[] = 'Catatan maksimal 2000 karakter.';
        }

        if ($errors !== []) {
            return [null, $errors];
        }

        return [[
            'project_code' => $code,
            'name' => $name,
            'description' => $description === '' ? null : $description,
            'location' => $location,
            'address' => $address === '' ? null : $address,
            'latitude' => $latitude === '' ? null : $latitude,
            'longitude' => $longitude === '' ? null : $longitude,
            'pic_name' => $picName,
            'pic_user_id' => $picUser === null ? null : (int) $picUser['id'],
            'contractor' => $contractor === '' ? null : $contractor,
            'start_date' => $startDate,
            'target_date' => $targetDate,
            'budget_amount' => (float) $budget,
            'status' => $status,
            'notes' => $notes === '' ? null : $notes,
            'status_reason' => '',
        ], []];
    }

    /**
     * @param array<string, string> $input
     */
    private function reject(string $path, string $message, array $input): never
    {
        Session::flash('danger', $message);
        Session::putOld($input);
        $this->redirect($path);
    }

    private function fail(string $message, string $id): never
    {
        Session::flash('danger', $message);
        $this->redirect($id === '' ? '/proyek' : '/proyek/' . $id);
    }

    /** @return array<int, array<string, mixed>> Akun aktif yang boleh ditunjuk sebagai penanggung jawab. */
    private function principalCandidates(): array
    {
        return array_values(array_filter(
            User::allWithRole(),
            static fn (array $user): bool => (string) $user['status'] === User::STATUS_ACTIVE
                && (int) $user['role_active'] === 1
        ));
    }

    /** @return array<string, mixed> */
    private function findProject(string $id): array
    {
        if (!ctype_digit($id)) {
            throw new NotFoundHttpException();
        }

        $project = Project::findWithNames((int) $id);
        if ($project === null) {
            throw new NotFoundHttpException();
        }

        return $project;
    }
}
