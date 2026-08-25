<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class IsSupportStaff
{
    public function handle(Request $request, Closure $next)
    {
        if (! auth()->user()->isSupportStaff()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        return $next($request);
    }
}
