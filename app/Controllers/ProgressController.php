<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Exceptions\NotFoundHttpException;
use App\Core\FileUpload;
use App\Core\Request;
use App\Core\Session;
use App\Models\ActivityLog;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\ProjectProgress;

final class ProgressController extends Controller
{
    public function store(Request $request, string $id): void
    {
        $actor = $this->actor();
        $project = $this->findProject($id);
        $path = '/proyek/' . $project['id'];

        if ((int) $project['is_archived'] === 1) {
            Session::flash('danger', 'Proyek yang sudah diarsipkan tidak dapat dilaporkan progresnya. Pulihkan arsip terlebih dahulu.');
            $this->redirect($path . '#progres');
        }

        $progressRaw = $request->string('progress');
        $reportDate = $request->string('report_date');
        $workDescription = $request->string('work_description');
        $completedWork = $request->string('completed_work');
        $obstacles = $request->string('obstacles');
        $nextPlan = $request->string('next_plan');
        $responsible = $request->string('responsible_name') !== ''
            ? $request->string('responsible_name')
            : (string) $project['pic_name'];

        $errors = [];

        if (!preg_match('/^\d{1,3}$/', $progressRaw)) {
            $errors[] = 'Persentase progres harus berupa angka bulat 0 sampai 100 tanpa tanda persen.';
        } elseif ((int) $progressRaw < 0 || (int) $progressRaw > 100) {
            $errors[] = 'Persentase progres harus berada pada rentang 0 sampai 100 persen.';
        }

        if (!date_is_valid($reportDate)) {
            $errors[] = 'Tanggal pelaporan wajib diisi dengan format yang benar (contoh 2026-11-01).';
        } elseif ($reportDate > date('Y-m-d')) {
            $errors[] = 'Tanggal pelaporan tidak boleh di masa depan.';
        }

        if (mb_strlen($workDescription) < 5 || mb_strlen($workDescription) > 2000) {
            $errors[] = 'Deskripsi pekerjaan wajib diisi, 5 sampai 2000 karakter.';
        }

        $details = [
            ['label' => 'Pekerjaan selesai', 'value' => $completedWork],
            ['label' => 'Kendala lapangan', 'value' => $obstacles],
            ['label' => 'Rencana berikutnya', 'value' => $nextPlan],
        ];
        foreach ($details as $detail) {
            if (mb_strlen($detail['value']) > 2000) {
                $errors[] = $detail['label'] . ' maksimal 2000 karakter.';
            }
        }

        if (mb_strlen($responsible) < 3 || mb_strlen($responsible) > 120) {
            $errors[] = 'Penanggung jawab harus terdiri dari 3 sampai 120 karakter.';
        }

        $file = $request->file('berkas');
        $upload = null;
        if ($file !== null && $errors === []) {
            $attempt = FileUpload::store($file);
            if ($attempt['ok']) {
                $upload = $attempt;
            } else {
                $errors[] = $attempt['error'];
            }
        }

        if ($errors !== []) {
            Session::flash('danger', $errors[0]);
            Session::putOld([
                'progress' => $progressRaw,
                'report_date' => $reportDate,
                'work_description' => $workDescription,
                'completed_work' => $completedWork,
                'obstacles' => $obstacles,
                'next_plan' => $nextPlan,
                'responsible_name' => $responsible,
            ]);
            $this->redirect($path . '#progres');
        }

        $progress = (int) $progressRaw;
        $before = (int) $project['progress'];

        $reportId = ProjectProgress::create([
            'project_id' => (int) $project['id'],
            'report_date' => $reportDate,
            'progress_before' => $before,
            'progress_after' => $progress,
            'work_description' => $workDescription,
            'completed_work' => $completedWork === '' ? null : $completedWork,
            'obstacles' => $obstacles === '' ? null : $obstacles,
            'next_plan' => $nextPlan === '' ? null : $nextPlan,
            'responsible_name' => $responsible,
            'reported_by' => (int) $actor['id'],
            'reported_by_name' => (string) $actor['full_name'],
        ]);

        if ($progress !== $before) {
            Project::updateProgress((int) $project['id'], $progress, (int) $actor['id']);
        }

        if ($upload !== null) {
            ProjectDocument::create([
                'project_id' => (int) $project['id'],
                'progress_report_id' => $reportId,
                'category' => 'construction',
                'title' => 'Foto laporan progres ' . $reportDate,
                'original_name' => $upload['original'],
                'stored_name' => $upload['stored'],
                'mime_type' => $upload['mime'],
                'file_size' => $upload['size'],
                'description' => 'Diunggah bersama laporan progres nomor ' . $reportId . '.',
                'uploaded_by' => (int) $actor['id'],
            ]);
        }

        ActivityLog::record(
            (int) $actor['id'],
            'progress.create',
            'progress',
            'Melaporkan progres proyek "' . $project['project_code'] . '" dari ' . $before . '% menjadi '
            . $progress . '% pada ' . $reportDate . '.',
            $request->ip(),
            $request->userAgent()
        );

        Notification::progressReported($project, $reportId, $progress, (int) $actor['id'], $workDescription);

        Session::clearOld();
        Session::flash('success', 'Laporan progres tercatat: ' . $progress . '%.');

        if ($progress === 100 && (string) $project['status'] !== Project::STATUS_COMPLETED) {
            Session::flash('info', 'Progres sudah 100 persen. Status proyek tidak berubah otomatis —'
                . ' ubah status menjadi Selesai secara terpisah bila pekerjaan telah diserahterimakan.');
        }

        $this->redirect($path . '#progres');
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
