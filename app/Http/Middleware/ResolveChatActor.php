<?php

namespace App\Http\Middleware;

use App\Models\ChatSession;
use App\Support\ChatActor;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Identifies who is calling the support chat endpoints without requiring a login:
 *
 *  1. `Authorization: Bearer` present  -> must be a valid JWT (same checks as `auth:api` +
 *     `check_last_logout`), otherwise 401. The caller is a member.
 *  2. else `X-Chat-Token` present      -> the guest owning the session whose token hash matches.
 *  3. else                             -> no actor. Endpoints decide whether that is allowed.
 *
 * The middleware only identifies; per-session access is ChatActor::canAccess().
 */
class ResolveChatActor
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->bearerToken() !== null) {
            return app(CheckLastLogout::class)->handle($request, function (Request $request) use ($next) {
                Auth::shouldUse('api');
                $request->attributes->set(ChatActor::REQUEST_ATTRIBUTE, ChatActor::member(Auth::guard('api')->user()));

                return $next($request);
            });
        }

        if ($session = $this->guestSession($request)) {
            $request->attributes->set(ChatActor::REQUEST_ATTRIBUTE, ChatActor::guest($session));
        }

        return $next($request);
    }

    private function guestSession(Request $request): ?ChatSession
    {
        $token = $request->header('X-Chat-Token');
        if (! is_string($token) || $token === '' || strlen($token) > 256) {
            return null;
        }

        $hash = hash('sha256', $token);
        $session = ChatSession::where('guest_token_hash', $hash)->first();

        if (! $session || ! hash_equals($session->guest_token_hash, $hash)) {
            return null;
        }

        // Guest tokens are short-lived: housekeeping closes the session at the same point.
        $ttl = (int) config('support.chat.guest_ttl_hours');
        if ($session->created_at->lte(now()->subHours($ttl))) {
            return null;
        }

        return $session;
    }
}
