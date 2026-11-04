<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Session;

final class AuthMiddleware implements Middleware
{
    public function handle(Request $request, callable $next): void
    {
        if (!Auth::check()) {
            if ($request->isGet()) {
                Session::set('url.intended', $request->path());
            }
            Session::flash('warning', 'Silakan masuk untuk melanjutkan.');
            redirect('/login');
        }

        $next($request);
    }
}
