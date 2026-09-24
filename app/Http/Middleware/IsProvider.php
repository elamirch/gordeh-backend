<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class IsProvider
{
    public function handle(Request $request, Closure $next)
    {
        if (! in_array(auth()->user()->role, ['provider', 'admin'], true)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        return $next($request);
    }
}
