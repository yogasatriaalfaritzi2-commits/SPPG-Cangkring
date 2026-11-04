<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Exceptions\NotFoundHttpException;
use App\Core\Request;
use App\Core\Session;
use App\Models\Notification;

final class NotificationController extends Controller
{
    private const PER_PAGE = 15;

    public function index(Request $request): void
    {
        $actor = $this->actor();
        $onlyUnread = $request->string('filter') === 'unread';
        $page = max(1, $request->integer('page', 1));

        $result = Notification::inbox((int) $actor['id'], $page, self::PER_PAGE);
        if ($onlyUnread) {
            $result['rows'] = array_values(array_filter(
                $result['rows'],
                static fn (array $row): bool => (int) $row['is_read'] === 0
            ));
        }

        $this->view('notifications/index', [
            'title' => 'Notifikasi',
            'pageTitle' => 'Notifikasi Internal',
            'result' => $result,
            'notifications' => $result['rows'],
            'unread' => Notification::unreadCount((int) $actor['id']),
            'filter' => $onlyUnread ? 'unread' : '',
        ]);
    }

    public function read(Request $request, string $id): void
    {
        $actor = $this->actor();

        if (!ctype_digit($id)) {
            throw new NotFoundHttpException();
        }

        $notification = Notification::findForUser((int) $actor['id'], (int) $id);
        if ($notification === null) {
            throw new NotFoundHttpException();
        }

        Notification::markRead((int) $actor['id'], (int) $id);

        $link = (string) ($notification['link'] ?? '');
        $this->redirect($this->isInternalPath($link) ? $link : '/notifikasi');
    }

    public function readAll(Request $request): void
    {
        $actor = $this->actor();
        Notification::markAllRead((int) $actor['id']);

        Session::flash('success', 'Semua notifikasi ditandai sudah dibaca.');
        $this->redirect('/notifikasi');
    }

    /**
     * Hanya menerima jalur internal sederhana supaya tautan notifikasi tidak
     * bisa dipakai mengalihkan pengguna ke alamat luar.
     */
    private function isInternalPath(string $link): bool
    {
        return preg_match('~^/[a-z0-9_\-/]{1,180}(#[a-z0-9_\-]{1,40})?$~i', $link) === 1
            && !str_starts_with($link, '//');
    }
}
