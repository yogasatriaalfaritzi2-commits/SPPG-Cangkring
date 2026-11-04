<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;

interface Middleware
{
    public function handle(Request $request, callable $next): void;
}
