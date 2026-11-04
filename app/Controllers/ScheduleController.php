<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Csv;
use App\Core\Exceptions\NotFoundHttpException;
use App\Core\Request;
use App\Core\Session;
use App\Models\ActivityLog;
use App\Models\BudgetCategory;
use App\Models\BudgetSummary;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectSchedule;
use App\Models\User;

/**
 * Jadwal pembangunan: daftar, kalender, Gantt Chart, ketergantungan pekerjaan,
 * dan riwayat perubahan. Durasi dihitung server dari tanggal mulai dan selesai,
 * sehingga nilai yang tampil tidak pernah berasal dari isian browser.
 */
final class ScheduleController extends Controller
{
    private const PER_PAGE = 12;

    public function index(Request $request): void
    {
        $filters = $this->filters($request);
        $result = ProjectSchedule::paginate($filters, max(1, $request->integer('page', 1)), self::PER_PAGE);

        $this->view('jadwal/index', [
            'title' => 'Jadwal Pembangunan',
            'pageTitle' => 'Jadwal Kegiatan Pembangunan',
            'filters' => $filters,
            'result' => $result,
            'schedules' => $result['rows'],
            'statuses' => ProjectSchedule::STATUSES,
            'priorities' => ProjectSchedule::PRIORITIES,
            'categories' => BudgetCategory::activeList(),
            'projects' => Project::optionList(true),
            'summary' => ProjectSchedule::summary($filters['project'] !== '' ? (int) $filters['project'] : 0),
        ]);
    }

    public function show(Request $request, string $id): void
    {
        $task = $this->findTask($id);
        $taskId = (int) $task['id'];
        $projectId = (int) $task['project_id'];

        $this->view('jadwal/show', [
            'title' => (string) $task['name'],
            'pageTitle' => 'Rincian Kegiatan',
            'task' => $task,
            'predecessors' => ProjectSchedule::predecessors($taskId),
            'successors' => ProjectSchedule::successors($taskId),
            'history' => ProjectSchedule::history($taskId),
            'candidates' => ProjectSchedule::options($projectId, $taskId),
            'types' => ProjectSchedule::DEPENDENCY_TYPES,
            'summary' => ProjectSchedule::summary($projectId),
            'dueDays' => BudgetSummary::dueSoonDays(),
        ]);
    }

    public function create(Request $request): void
    {
        $projectId = max(0, $request->integer('proyek'));
        $old = $this->pullOld();

        $form = ProjectSchedule::emptyRecord($projectId);
        foreach ($old as $key => $value) {
            if (array_key_exists($key, $form)) {
                $form[$key] = (string) $value;
            }
        }

        if ((string) $form['activity_code'] === '') {
            $form['activity_code'] = $projectId > 0
                ? ProjectSchedule::nextActivityCode($projectId)
                : '';
        }

        $this->view('jadwal/form', [
            'title' => 'Tambah Kegiatan',
            'pageTitle' => 'Tambah Kegiatan Pembangunan',
            'mode' => 'create',
            'form' => $form,
            'task' => null,
            'projects' => Project::optionList(),
            'categories' => BudgetCategory::activeList(),
            'users' => User::activeList(),
            'statuses' => ProjectSchedule::STATUSES,
            'priorities' => ProjectSchedule::PRIORITIES,
        ]);
    }

    public function store(Request $request): void
    {
        $actor = $this->actor();
        $input = $this->readForm($request);
        [$data, $errors] = $this->validate($input, null);

        if ($errors !== []) {
            $this->reject('/jadwal/tambah', $errors[0], $input);
        }

        /** @var array<string, mixed> $data */
        $data['created_by'] = (int) $actor['id'];
        $data['updated_by'] = (int) $actor['id'];

        $taskId = ProjectSchedule::create($data);
        $task = ProjectSchedule::findWithNames($taskId);

        if ($task !== null) {
            Notification::scheduleCreated($task, (int) $actor['id']);
        }

        ActivityLog::record(
            (int) $actor['id'],
            'schedules.create',
            'schedules',
            'Menambah kegiatan "' . ($task['activity_code'] ?? '') . '" — ' . $data['name']
            . ' pada proyek "' . ($task['project_name'] ?? '') . '".',
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', 'Kegiatan berhasil disimpan.');
        $this->redirect('/jadwal/' . $taskId);
    }

    public function edit(Request $request, string $id): void
    {
        $task = $this->findTask($id);
        $old = $this->pullOld();

        $form = [];
        foreach ($this->readForm($request) as $field => $unused) {
            $form[$field] = (string) ($old[$field] ?? $task[$field] ?? '');
        }

        $this->view('jadwal/form', [
            'title' => 'Sunting Kegiatan',
            'pageTitle' => 'Sunting Kegiatan Pembangunan',
            'mode' => 'edit',
            'form' => $form,
            'task' => $task,
            'projects' => Project::optionList(true),
            'categories' => BudgetCategory::activeList(),
            'users' => User::activeList(),
            'statuses' => ProjectSchedule::STATUSES,
            'priorities' => ProjectSchedule::PRIORITIES,
        ]);
    }

    /**
     * Perubahan kegiatan selalu disertai alasan. Nilai lama dibaca dari database
     * oleh model, sehingga riwayat menampilkan kondisi sebelum perubahan, bukan
     * nilai yang dikirim browser.
     */
    public function update(Request $request, string $id): void
    {
        $actor = $this->actor();
        $task = $this->findTask($id);
        $input = $this->readForm($request);
        [$data, $errors] = $this->validate($input, (int) $task['id']);

        $reason = trim($request->string('reason'));
        $changed = $this->changedFields($task, $data);

        if ($changed !== [] && (mb_strlen($reason) < 5 || mb_strlen($reason) > 500)) {
            $errors[] = 'Alasan perubahan wajib diisi 5 sampai 500 karakter agar riwayat jadwal lengkap.';
        }

        if ($errors !== []) {
            $this->reject('/jadwal/' . $task['id'] . '/ubah', $errors[0], $input);
        }

        /** @var array<string, mixed> $data */
        $changed = ProjectSchedule::update((int) $task['id'], $data, $reason === '' ? null : $reason, (int) $actor['id']);

        $fresh = ProjectSchedule::findWithNames((int) $task['id']);
        if ($fresh !== null && $changed !== []) {
            Notification::scheduleChanged($fresh, $changed, $reason === '' ? null : $reason, (int) $actor['id']);
        }

        ActivityLog::record(
            (int) $actor['id'],
            'schedules.update',
            'schedules',
            'Mengubah kegiatan "' . $task['activity_code'] . '" (' . ($changed === []
                ? 'tidak ada bidang yang berubah'
                : implode(', ', $changed) . ')')
            . ($reason === '' ? '' : '. Alasan: ' . $reason),
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', $changed === []
            ? 'Kegiatan disimpan tanpa perubahan nilai.'
            : 'Kegiatan diperbarui. Perubahan tercatat pada riwayat jadwal.');
        $this->redirect('/jadwal/' . $task['id']);
    }

    /**
     * Penghapusan memakai soft delete. Kegiatan yang sudah punya riwayat pekerjaan
     * atau menjadi pendahulu kegiatan lain tidak boleh hilang dari jejak audit.
     */
    public function destroy(Request $request, string $id): void
    {
        $actor = $this->actor();
        $task = $this->findTask($id);
        $taskId = (int) $task['id'];

        if (ProjectSchedule::history($taskId, 1) !== []) {
            $this->fail(
                'Kegiatan ini sudah memiliki riwayat perubahan. Ubah statusnya menjadi Dibatalkan '
                . 'agar jejak pekerjaannya tetap tersimpan.',
                (string) $taskId
            );
        }

        if (ProjectSchedule::successors($taskId) !== []) {
            $this->fail(
                'Kegiatan ini masih menjadi pendahulu kegiatan lain. Hapus ketergantungan tersebut lebih dahulu.',
                (string) $taskId
            );
        }

        if (ProjectSchedule::softDelete($taskId, (int) $actor['id']) === 0) {
            $this->fail('Kegiatan sudah dihapus sebelumnya.', (string) $taskId);
        }

        ActivityLog::record(
            (int) $actor['id'],
            'schedules.delete',
            'schedules',
            'Menghapus kegiatan "' . $task['activity_code'] . '" — ' . $task['name'] . '.',
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', 'Kegiatan dihapus dari daftar jadwal.');
        $this->redirect('/jadwal');
    }

    /** Kalender harian/mingguan/bulanan; sumber kegiatan dibaca dari MySQL. */
    public function calendar(Request $request): void
    {
        $selected = $request->string('proyek');
        $projectId = ctype_digit($selected) ? max(0, (int) $selected) : 0;

        $from = $request->string('dari');
        $to = $request->string('sampai');

        if (!date_is_valid($from)) {
            $from = date('Y-m-01', strtotime('-6 months'));
        }

        if (!date_is_valid($to)) {
            $to = date('Y-m-t', strtotime('+12 months'));
        }

        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }

        $this->view('jadwal/kalender', [
            'title' => 'Kalender Jadwal',
            'pageTitle' => 'Kalender Pembangunan',
            'pageScripts' => ['vendor/fullcalendar/index.global.min.js', 'js/schedules.js'],
            'events' => ProjectSchedule::calendarEvents($from, $to, $projectId),
            'from' => $from,
            'to' => $to,
            'projectId' => $projectId,
            'projects' => Project::optionList(true),
            'summary' => ProjectSchedule::summary($projectId),
        ]);
    }

    /** Gantt Chart sederhana satu proyek: batang rentang waktu beserta ketergantungannya. */
    public function gantt(Request $request): void
    {
        $selected = $request->string('proyek');
        $projectId = ctype_digit($selected) ? max(0, (int) $selected) : 0;
        $project = $projectId > 0 ? Project::findWithNames($projectId) : null;

        if ($project === null) {
            $options = Project::optionList(true);
            $project = $options === [] ? null : Project::findWithNames((int) $options[0]['id']);
        }

        if ($project === null) {
            Session::flash('warning', 'Belum ada proyek sehingga Gantt Chart tidak dapat ditampilkan.');
            $this->redirect('/jadwal');
        }

        $projectId = (int) $project['id'];
        $rows = ProjectSchedule::ganttRows($projectId);

        $this->view('jadwal/gantt', [
            'title' => 'Gantt Chart',
            'pageTitle' => 'Bagan Gantt Pembangunan',
            'project' => $project,
            'rows' => $rows,
            'edges' => ProjectSchedule::dependencyEdges($projectId),
            'projects' => Project::optionList(true),
            'summary' => ProjectSchedule::summary($projectId),
            'span' => $this->ganttSpan($rows),
        ]);
    }

    public function storeDependency(Request $request, string $id): void
    {
        $actor = $this->actor();
        $task = $this->findTask($id);
        $taskId = (int) $task['id'];

        $type = $request->string('dependency_type');
        $predecessorId = $request->integer('predecessor_id');
        $lag = $request->integer('lag_days');

        if (!in_array($type, ProjectSchedule::DEPENDENCY_TYPES, true)) {
            $this->fail('Jenis ketergantungan tidak dikenali.', (string) $taskId);
        }

        if ($predecessorId === $taskId || $predecessorId <= 0) {
            $this->fail('Kegiatan tidak dapat bergantung pada dirinya sendiri.', (string) $taskId);
        }

        $candidates = ProjectSchedule::options((int) $task['project_id'], $taskId);
        $predecessor = null;

        foreach ($candidates as $row) {
            if ((int) $row['id'] === $predecessorId) {
                $predecessor = $row;
                break;
            }
        }

        if ($predecessor === null) {
            $this->fail('Kegiatan pendahulu harus berada pada proyek yang sama.', (string) $taskId);
        }

        if ($lag < 0 || $lag > 365) {
            $this->fail('Jeda hari harus antara 0 sampai 365.', (string) $taskId);
        }

        foreach (ProjectSchedule::predecessors($taskId) as $edge) {
            if ((int) $edge['id'] === $predecessorId) {
                $this->fail('Ketergantungan tersebut sudah tercatat.', (string) $taskId);
            }
        }

        $edges = ProjectSchedule::dependencyEdges((int) $task['project_id']);

        if (ProjectSchedule::createsCycle($taskId, $predecessorId, $edges)) {
            $this->fail(
                'Hubungan ini membentuk lingkaran ketergantungan. Perubahan ditolak agar jadwal tetap dapat dihitung.',
                (string) $taskId
            );
        }

        $dateError = ProjectSchedule::dependencyDateError(
            (string) $task['start_date'],
            (string) $task['end_date'],
            (string) $predecessor['start_date'],
            (string) $predecessor['end_date'],
            $type,
            $lag
        );

        if ($dateError !== null) {
            $this->fail('Tanggal kegiatan bertentangan dengan ketergantungan: ' . $dateError, (string) $taskId);
        }

        ProjectSchedule::addDependency($taskId, $predecessorId, $type, $lag, (int) $actor['id']);

        ActivityLog::record(
            (int) $actor['id'],
            'schedules.dependency.create',
            'schedules',
            'Menambahkan ketergantungan ' . $type . ' pada kegiatan "' . $task['activity_code']
            . '" dari "' . $predecessor['activity_code'] . '" (jeda ' . $lag . ' hari).',
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', 'Ketergantungan pekerjaan disimpan.');
        $this->redirect('/jadwal/' . $taskId . '#ketergantungan');
    }

    public function destroyDependency(Request $request, string $id): void
    {
        $actor = $this->actor();

        if (!ctype_digit($id)) {
            throw new NotFoundHttpException();
        }

        $task = $this->findTask((string) $request->input('schedule_id', ''));
        $taskId = (int) $task['id'];

        $edges = ProjectSchedule::predecessors($taskId);
        $target = null;

        foreach ($edges as $edge) {
            if ((int) $edge['dependency_id'] === (int) $id) {
                $target = $edge;
                break;
            }
        }

        if ($target === null) {
            $this->fail('Ketergantungan tersebut tidak ditemukan pada kegiatan ini.', (string) $taskId);
        }

        ProjectSchedule::removeDependency((int) $id, (int) $actor['id']);

        ActivityLog::record(
            (int) $actor['id'],
            'schedules.dependency.delete',
            'schedules',
            'Menghapus ketergantungan "' . $task['activity_code'] . '" terhadap "'
            . $target['activity_code'] . '".',
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', 'Ketergantungan pekerjaan dihapus.');
        $this->redirect('/jadwal/' . $taskId . '#ketergantungan');
    }

    public function export(Request $request): void
    {
        $actor = $this->actor();
        $filters = $this->filters($request);
        $result = ProjectSchedule::paginate($filters, 1, 2000);

        $rows = [];
        foreach ($result['rows'] as $task) {
            $rows[] = [
                $task['activity_code'],
                $task['project_code'],
                $task['project_name'],
                $task['category_name'] ?? '',
                $task['name'],
                $task['start_date'],
                $task['end_date'],
                (int) $task['duration_days'],
                $task['owner_user_name'] ?: $task['owner_name'],
                schedule_priority_label((string) $task['priority']),
                schedule_status_label((string) $task['status']),
                (int) $task['progress'] . '%',
                (int) $task['dependency_count'],
                (string) ($task['description'] ?? ''),
            ];
        }

        ActivityLog::record(
            (int) $actor['id'],
            'schedules.export',
            'schedules',
            'Mengekspor ' . count($rows) . ' baris jadwal kegiatan.',
            $request->ip(),
            $request->userAgent()
        );

        Csv::send('jadwal-' . date('Y-m-d'), [
            'Kode', 'Kode Proyek', 'Proyek', 'Kategori', 'Kegiatan', 'Mulai', 'Selesai', 'Durasi (hari)',
            'Penanggung Jawab', 'Prioritas', 'Status', 'Progres', 'Ketergantungan', 'Deskripsi',
        ], $rows);
    }

    /**
     * Rentang tampilan Gantt: ditambah bantalan beberapa hari di kedua ujung agar
     * batang tidak menempel pada sumbu.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function ganttSpan(array $rows): array
    {
        if ($rows === []) {
            $today = date('Y-m-d');

            return [
                'from' => date('Y-m-d', strtotime($today . ' -30 day')),
                'to' => date('Y-m-d', strtotime($today . ' +60 day')),
            ];
        }

        $from = (string) $rows[0]['start_date'];
        $to = $from;

        foreach ($rows as $row) {
            if ((string) $row['start_date'] < $from) {
                $from = (string) $row['start_date'];
            }

            if ((string) $row['end_date'] > $to) {
                $to = (string) $row['end_date'];
            }
        }

        $from = date('Y-m-d', strtotime($from . ' -3 day'));
        $to = date('Y-m-d', strtotime($to . ' +3 day'));

        return [
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * Bidang yang isinya berbeda dari catatan di database. Dipakai untuk menentukan
     * apakah alasan perubahan wajib diisi sebelum riwayat ditulis.
     *
     * @param array<string, mixed> $current
     * @param array<string, mixed> $data
     * @return array<int, string>
     */
    private function changedFields(array $current, array $data): array
    {
        $fields = [
            'name', 'description', 'category_id', 'start_date', 'end_date',
            'owner_user_id', 'owner_name', 'priority', 'status', 'progress', 'notes',
        ];

        $changed = [];

        foreach ($fields as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $before = $current[$field] ?? null;

            if (is_float($before)) {
                $before = rtrim(rtrim(number_format($before, 2, '.', ''), '0'), '.');
            }

            if (trim((string) ($before ?? '')) !== trim((string) ($data[$field] ?? ''))) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    /** @return array<string, string> */
    private function readForm(Request $request): array
    {
        return [
            'activity_code' => (string) $request->input('activity_code', ''),
            'project_id' => (string) $request->input('project_id', ''),
            'name' => (string) $request->input('name', ''),
            'description' => (string) $request->input('description', ''),
            'category_id' => (string) $request->input('category_id', ''),
            'start_date' => (string) $request->input('start_date', ''),
            'end_date' => (string) $request->input('end_date', ''),
            'owner_user_id' => (string) $request->input('owner_user_id', ''),
            'owner_name' => (string) $request->input('owner_name', ''),
            'priority' => (string) $request->input('priority', ''),
            'status' => (string) $request->input('status', ''),
            'progress' => (string) $request->input('progress', ''),
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

        $code = preg_replace('/\s+/', '', mb_strtoupper(trim($input['activity_code']), 'UTF-8')) ?? '';
        $projectId = trim($input['project_id']);
        $name = trim($input['name']);
        $description = trim($input['description']);
        $categoryId = trim($input['category_id']);
        $start = trim($input['start_date']);
        $end = trim($input['end_date']);
        $ownerUserId = trim($input['owner_user_id']);
        $ownerName = trim($input['owner_name']);
        $priority = trim($input['priority']);
        $status = trim($input['status']);
        $progress = trim($input['progress']);
        $notes = trim($input['notes']);

        if ($code !== '' && !preg_match('/^KGT-\d{1,4}$/', $code)) {
            $errors[] = 'Kode kegiatan harus bermotif KGT-NOMOR, contoh KGT-001.';
        }

        $project = null;
        if ($projectId === '' || !ctype_digit($projectId) || (int) $projectId <= 0) {
            $errors[] = 'Proyek wajib dipilih.';
        } else {
            $project = Project::findWithNames((int) $projectId);
            if ($project === null) {
                $errors[] = 'Proyek yang dipilih tidak ditemukan.';
            } elseif ((int) $project['is_archived'] === 1 && $exceptId === null) {
                $errors[] = 'Proyek yang sudah diarsipkan tidak dapat ditambahi kegiatan baru.';
            }
        }

        if ($code !== '' && $project !== null && ProjectSchedule::codeExists((int) $project['id'], $code, $exceptId)) {
            $errors[] = 'Kode kegiatan sudah dipakai pada proyek ini.';
        }

        if (mb_strlen($name) < 3 || mb_strlen($name) > 150) {
            $errors[] = 'Nama kegiatan harus terdiri dari 3 sampai 150 karakter.';
        }

        if (mb_strlen($description) > 1000) {
            $errors[] = 'Deskripsi maksimal 1000 karakter.';
        }

        $categoryIdValue = null;
        if ($categoryId !== '') {
            if (!ctype_digit($categoryId)) {
                $errors[] = 'Kategori pekerjaan tidak valid.';
            } else {
                $category = BudgetCategory::find((int) $categoryId);
                if ($category === null) {
                    $errors[] = 'Kategori pekerjaan tidak ditemukan.';
                } elseif ((int) $category['is_active'] !== 1) {
                    $errors[] = 'Kategori pekerjaan yang tidak aktif tidak dapat dipilih.';
                } else {
                    $categoryIdValue = (int) $categoryId;
                }
            }
        }

        if (!date_is_valid($start)) {
            $errors[] = 'Tanggal mulai wajib diisi dengan format yang benar (contoh 2026-01-01).';
        }

        if (!date_is_valid($end)) {
            $errors[] = 'Tanggal selesai wajib diisi dengan format yang benar (contoh 2026-02-28).';
        }

        if (date_is_valid($start) && date_is_valid($end) && $end < $start) {
            $errors[] = 'Tanggal selesai tidak boleh lebih awal dari tanggal mulai.';
        }

        $ownerIdValue = null;
        if ($ownerUserId !== '') {
            if (!ctype_digit($ownerUserId)) {
                $errors[] = 'Penanggung jawab tidak valid.';
            } else {
                $owner = User::find((int) $ownerUserId);
                if ($owner === null || (string) $owner['status'] !== User::STATUS_ACTIVE) {
                    $errors[] = 'Penanggung jawab harus pengguna yang masih aktif.';
                } else {
                    $ownerIdValue = (int) $ownerUserId;
                    $ownerName = $ownerName === '' ? (string) $owner['full_name'] : $ownerName;
                }
            }
        }

        if (mb_strlen($ownerName) < 2 || mb_strlen($ownerName) > 120) {
            $errors[] = 'Nama penanggung jawab wajib diisi 2 sampai 120 karakter.';
        }

        if (!in_array($priority, ProjectSchedule::PRIORITIES, true)) {
            $errors[] = 'Prioritas harus salah satu dari Rendah, Sedang, Tinggi, atau Mendesak.';
        }

        if (!in_array($status, ProjectSchedule::STATUSES, true)) {
            $errors[] = 'Status kegiatan tidak dikenali.';
        }

        if ($progress === '' || !ctype_digit($progress)) {
            $errors[] = 'Persentase progres harus angka bulat 0 sampai 100.';
        } elseif ((int) $progress > 100) {
            $errors[] = 'Persentase progres tidak boleh lebih dari 100.';
        } elseif ($status === ProjectSchedule::STATUS_COMPLETED && (int) $progress !== 100) {
            $errors[] = 'Kegiatan berstatus Selesai harus berpencapaian 100%.';
        } elseif ($status !== ProjectSchedule::STATUS_COMPLETED && (int) $progress === 100) {
            $errors[] = 'Progres 100% hanya dapat disimpan dengan status Selesai.';
        }

        if (mb_strlen($notes) > 1000) {
            $errors[] = 'Catatan maksimal 1000 karakter.';
        }

        if ($errors !== []) {
            return [null, $errors];
        }

        return [[
            'activity_code' => $code,
            'project_id' => (int) $projectId,
            'name' => $name,
            'description' => $description === '' ? null : $description,
            'category_id' => $categoryIdValue,
            'start_date' => $start,
            'end_date' => $end,
            'owner_user_id' => $ownerIdValue,
            'owner_name' => $ownerName,
            'priority' => $priority,
            'status' => $status,
            'progress' => (int) $progress,
            'notes' => $notes === '' ? null : $notes,
        ], []];
    }

    /** @return array<string, string> */
    private function filters(Request $request): array
    {
        $search = preg_replace('/\s+/', ' ', trim($request->string('q'))) ?? '';
        $status = $request->string('status');
        $priority = $request->string('prioritas');
        $project = $request->string('proyek');
        $category = $request->string('kategori');
        $sort = $request->string('sort');
        $dir = strtolower($request->string('dir'));

        return [
            'search' => mb_substr($search, 0, 60),
            'status' => in_array($status, ProjectSchedule::STATUSES, true) ? $status : '',
            'priority' => in_array($priority, ProjectSchedule::PRIORITIES, true) ? $priority : '',
            'project' => ctype_digit($project) ? $project : '',
            'category' => ctype_digit($category) ? $category : '',
            'from' => date_is_valid($request->string('dari')) ? $request->string('dari') : '',
            'to' => date_is_valid($request->string('sampai')) ? $request->string('sampai') : '',
            'late' => $request->string('terlambat') === '1' ? '1' : '',
            'sort' => array_key_exists($sort, ProjectSchedule::SORTABLE) ? $sort : 'start',
            'dir' => in_array($dir, ['asc', 'desc'], true) ? $dir : 'asc',
        ];
    }

    /** @return array<string, mixed> */
    private function findTask(string $id): array
    {
        if (!ctype_digit($id)) {
            throw new NotFoundHttpException();
        }

        $task = ProjectSchedule::findWithNames((int) $id);
        if ($task === null) {
            throw new NotFoundHttpException();
        }

        return $task;
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
        $this->redirect('/jadwal/' . $id);
    }

    /** @return array<string, mixed> */
    private function pullOld(): array
    {
        $old = Session::pull('_old', []);

        return is_array($old) ? $old : [];
    }
}
