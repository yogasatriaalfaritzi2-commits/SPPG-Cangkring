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

final class DocumentController extends Controller
{
    public function store(Request $request, string $id): void
    {
        $actor = $this->actor();
        $project = $this->findProject($id);
        $path = '/proyek/' . $project['id'] . '#dokumen';

        if ((int) $project['is_archived'] === 1) {
            Session::flash('danger', 'Proyek yang sudah diarsipkan tidak dapat ditambah dokumen baru. Pulihkan arsip terlebih dahulu.');
            $this->redirect($path);
        }

        $category = $request->string('category');
        $title = $request->string('title');
        $description = $request->string('description');

        $errors = [];

        if (!in_array($category, ProjectDocument::CATEGORIES, true)) {
            $errors[] = 'Kategori dokumen tidak dikenali.';
        }

        if ($category === ProjectDocument::TRANSACTION_CATEGORY) {
            $errors[] = 'Bukti transaksi diunggah dari halaman transaksi, bukan dari daftar dokumen proyek.';
        }

        if (mb_strlen($title) < 3 || mb_strlen($title) > 150) {
            $errors[] = 'Judul dokumen harus terdiri dari 3 sampai 150 karakter.';
        }

        if (mb_strlen($description) > 255) {
            $errors[] = 'Keterangan dokumen maksimal 255 karakter.';
        }

        $file = $request->file('berkas');
        $upload = null;

        if ($file === null) {
            $errors[] = 'Berkas dokumen wajib diunggah.';
        } elseif ($errors === []) {
            $attempt = FileUpload::store($file);
            if ($attempt['ok']) {
                $upload = $attempt;
            } else {
                $errors[] = $attempt['error'];
            }
        }

        if ($errors !== []) {
            Session::flash('danger', $errors[0]);
            $this->redirect($path);
        }

        /** @var array{stored: string, mime: string, size: int, original: string} $upload */
        $documentId = ProjectDocument::create([
            'project_id' => (int) $project['id'],
            'category' => $category,
            'title' => $title,
            'original_name' => $upload['original'],
            'stored_name' => $upload['stored'],
            'mime_type' => $upload['mime'],
            'file_size' => $upload['size'],
            'description' => $description === '' ? null : $description,
            'uploaded_by' => (int) $actor['id'],
        ]);

        ActivityLog::record(
            (int) $actor['id'],
            'documents.upload',
            'documents',
            'Mengunggah dokumen "' . $title . '" (' . $category . ') pada proyek "'
            . $project['project_code'] . '".',
            $request->ip(),
            $request->userAgent()
        );

        Notification::documentUploaded($project, $documentId, $title, (int) $actor['id']);

        Session::flash('success', 'Dokumen "' . $title . '" berhasil diunggah.');
        $this->redirect($path);
    }

    public function download(Request $request, string $id): void
    {
        $this->serve($id, false);
    }

    public function display(Request $request, string $id): void
    {
        $this->serve($id, true);
    }

    public function destroy(Request $request, string $id): void
    {
        $actor = $this->actor();
        $document = $this->findDocument($id);

        ProjectDocument::delete((int) $document['id']);
        FileUpload::remove((string) $document['stored_name']);

        ActivityLog::record(
            (int) $actor['id'],
            'documents.delete',
            'documents',
            'Menghapus dokumen "' . $document['title'] . '" pada proyek "' . $document['project_code'] . '".',
            $request->ip(),
            $request->userAgent()
        );

        Session::flash('success', 'Dokumen "' . $document['title'] . '" telah dihapus.');
        $this->redirect('/proyek/' . $document['project_id'] . '#dokumen');
    }

    private function serve(string $id, bool $inline): never
    {
        $document = $this->findDocument($id);

        FileUpload::respond(
            (string) $document['stored_name'],
            (string) $document['mime_type'],
            (string) $document['original_name'],
            $inline
        );
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

    /** @return array<string, mixed> */
    private function findDocument(string $id): array
    {
        if (!ctype_digit($id)) {
            throw new NotFoundHttpException();
        }

        $document = ProjectDocument::findWithProject((int) $id);
        if ($document === null) {
            throw new NotFoundHttpException();
        }

        return $document;
    }
}
