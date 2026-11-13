<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequireMfa
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user && $user->requiresMfa()) {
            if (! $request->session()->get('mfa_passed')) {
                if ($request->isMethod('GET')) {
                    $request->session()->put('url.intended', $request->fullUrl());
                }

                return redirect()->route('mfa');
            }
        }

        return $next($request);
    }
}
