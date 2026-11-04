<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Session;

final class PermissionMiddleware implements Middleware
{
    /** @var array<int, string> */
    private array $permissions;

    public function __construct(string $permissions)
    {
        $this->permissions = array_values(array_filter(
            array_map('trim', explode(',', $permissions)),
            static fn (string $permission): bool => $permission !== ''
        ));
    }

    public function handle(Request $request, callable $next): void
    {
        if (!Auth::check()) {
            if ($request->isGet()) {
                Session::set('url.intended', $request->path());
            }
            Session::flash('warning', 'Silakan masuk untuk melanjutkan.');
            redirect('/login');
        }

        foreach ($this->permissions as $permission) {
            if (Auth::hasPermission($permission)) {
                $next($request);
                return;
            }
        }

        throw new HttpException(403, 'Anda tidak memiliki izin untuk mengakses halaman atau tindakan ini.');
    }
}