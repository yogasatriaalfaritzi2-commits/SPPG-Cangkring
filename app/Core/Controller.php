<?php
declare(strict_types=1);

namespace App\Core;

abstract class Controller
{
    /** @param array<string, mixed> $data */
    protected function view(string $view, array $data = [], string $layout = 'layouts.app'): void
    {
        View::render($view, $data, $layout);
    }

    protected function redirect(string $path, int $status = 302): never
    {
        redirect($path, $status);
    }

    /**
     * Pengguna yang sedang masuk. Rute modul baru selalu berada di belakang
     * AuthMiddleware, sehingga nilai ini tidak pernah null pada permintaan biasa.
     *
     * @return array<string, mixed>
     */
    protected function actor(): array
    {
        $actor = Auth::user();
        if ($actor === null) {
            $this->redirect('/login');
        }

        return $actor;
    }
}
