<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** An approved nephrologist (checked against the profile, not the JWT role, which can lag behind approval). */
class IsNephrologist
{
    public function handle(Request $request, Closure $next)
    {
        $profile = auth()->user()->nephrologistProfile;

        if (! $profile || ! in_array($profile->status, ['approved', 'suspended'], true)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // A suspended doctor can still read their panel (earnings, history) but the write
        // endpoints below check ->isApproved() where it matters.
        return $next($request);
    }
}
