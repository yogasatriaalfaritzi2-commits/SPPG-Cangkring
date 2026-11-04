<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Exceptions\NotFoundHttpException;
use App\Core\Request;
use App\Core\Session;
use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\ProjectTeam;
use App\Models\User;

final class TeamController extends Controller
{
    public function store(Request $request, string $id): void
    {
        $actor = $this->actor();
        $project = $this->findProject($id);
        $path = '/proyek/' . $project['id'] . '#tim';

        $this->guardActive($project, $path);

        [$position, $duties, $errors] = $this->readRoles($request);

        $userId = $request->integer('user_id');
        $member = null;

        if ($userId <= 0) {
            $errors[] = 'Anggota tim wajib dipilih dari daftar pengguna.';
        } else {
            $member = User::find($userId);
            if ($member === null || (string) $member['status'] !== User::STATUS_ACTIVE) {
                $errors[] = 'Anggota tim harus akun aktif yang terdaftar pada sistem.';
            } elseif (ProjectTeam::findActive((int) $project['id'], $userId) !== null) {
                $errors[] = 'Pengguna tersebut sudah menjadi tim aktif proyek ini.';
            }
        }

        if ($errors !== []) {
            $this->reject($path, $errors[0]);
        }

        /** @var array<string, mixed> $member */
        $isLead = $request->boolean('is_lead');
        $assignmentId = ProjectTeam::assign(
            (int) $project['id'],
            (int) $member['id'],
            $position,
            $duties === '' ? null : $duties,
            (int) $actor['id'],
            $isLead
        );

        if ($isLead) {
            $this->makePrincipal($project, (int) $member['id'], $assignmentId, (int) $actor['id']);
        }

        ActivityLog::record(
            (int) $actor['id'],
            'team.assign',
            'team',
            'Menugaskan "' . $member['full_name'] . '" sebagai ' . $position . ' pada proyek "'
            . $project['project_code'] . '"' . ($isLead ? ' (penanggung jawab)' : '') . '.',
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', 'Anggota tim berhasil ditambahkan.');
        $this->redirect($path);
    }

    public function update(Request $request, string $id, string $assignment): void
    {
        $actor = $this->actor();
        $project = $this->findProject($id);
        $path = '/proyek/' . $project['id'] . '#tim';

        $this->guardActive($project, $path);

        $current = $this->findAssignment($assignment, (int) $project['id']);
        [$position, $duties, $errors] = $this->readRoles($request);

        if ($errors !== []) {
            $this->reject($path, $errors[0]);
        }

        $wasLead = (int) $current['is_lead'] === 1;
        $isLead = $request->boolean('is_lead');
        $isPrincipal = (int) ($project['pic_user_id'] ?? 0) === (int) $current['user_id'];

        if ($isPrincipal && !$isLead) {
            $errors[] = 'Anggota ini adalah penanggung jawab proyek. Tetap tandai sebagai pemimpin tim, atau tetapkan dahulu penanggung jawab penggantinya.';
            $this->reject($path, $errors[0]);
        }

        ProjectTeam::changeAssignment((int) $current['id'], $position, $duties === '' ? null : $duties);

        if ($isLead && !$wasLead) {
            $this->makePrincipal($project, (int) $current['user_id'], (int) $current['id'], (int) $actor['id']);
        }

        ActivityLog::record(
            (int) $actor['id'],
            'team.update',
            'team',
            'Mengubah penugasan "' . $current['full_name'] . '" menjadi ' . $position . ' pada proyek "'
            . $project['project_code'] . '".',
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', 'Penugasan tim diperbarui.');
        $this->redirect($path);
    }

    public function unassign(Request $request, string $id, string $assignment): void
    {
        $actor = $this->actor();
        $project = $this->findProject($id);
        $path = '/proyek/' . $project['id'] . '#tim';

        $this->guardActive($project, $path);

        $current = $this->findAssignment($assignment, (int) $project['id']);

        if ((int) $project['pic_user_id'] === (int) $current['user_id']) {
            $this->reject(
                $path,
                'Anggota ini adalah penanggung jawab proyek. Tetapkan penanggung jawab baru terlebih dahulu sebelum melepasnya.'
            );
        }

        ProjectTeam::unassign((int) $current['id']);

        ActivityLog::record(
            (int) $actor['id'],
            'team.unassign',
            'team',
            'Melepas penugasan "' . $current['full_name'] . '" dari proyek "' . $project['project_code'] . '".',
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', 'Penugasan "' . $current['full_name'] . '" telah dilepas.');
        $this->redirect($path);
    }

    /**
     * @param array<string, mixed> $project
     * @return array{0: string, 1: string, 2: array<int, string>}
     */
    private function readRoles(Request $request): array
    {
        $position = $request->string('position');
        $duties = $request->string('duties');
        $errors = [];

        if (mb_strlen($position) < 3 || mb_strlen($position) > 80) {
            $errors[] = 'Jabatan atau tugas utama harus terdiri dari 3 sampai 80 karakter.';
        }

        if (mb_strlen($duties) > 255) {
            $errors[] = 'Uraian tugas maksimal 255 karakter.';
        }

        return [$position, $duties, $errors];
    }

    /**
     * @param array<string, mixed> $project
     */
    private function makePrincipal(array $project, int $userId, int $assignmentId, int $actorId): void
    {
        ProjectTeam::clearLead((int) $project['id']);
        ProjectTeam::markLead($assignmentId);

        $member = User::find($userId);
        if ($member !== null) {
            Project::setPrincipal((int) $project['id'], $userId, (string) $member['full_name'], $actorId);
        }
    }

    /**
     * @param array<string, mixed> $project
     */
    private function guardActive(array $project, string $path): void
    {
        if ((int) $project['is_archived'] === 1) {
            $this->reject($path, 'Proyek yang sudah diarsipkan tidak dapat diubah keanggotaan timnya.');
        }
    }

    private function reject(string $path, string $message): never
    {
        Session::flash('danger', $message);
        $this->redirect($path);
    }

    /**
     * @return array<string, mixed>
     */
    private function findAssignment(string $assignmentId, int $projectId): array
    {
        if (!ctype_digit($assignmentId)) {
            throw new NotFoundHttpException();
        }

        $assignment = ProjectTeam::findInProject((int) $assignmentId, $projectId);
        if ($assignment === null) {
            throw new NotFoundHttpException();
        }

        return $assignment;
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
