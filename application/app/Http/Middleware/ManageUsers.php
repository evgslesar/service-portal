<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ManageUsers
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user()?->fresh()?->canManageUsers(), 403);

        return $next($request)->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
