<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;

final class GuestMiddleware implements Middleware
{
    public function handle(Request $request, callable $next): void
    {
        if (Auth::check()) {
            redirect(Auth::homePath());
        }

        $next($request);
    }
}
