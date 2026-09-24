<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use Tymon\JWTAuth\Exceptions\JWTException;
use Illuminate\Http\Request;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Registered separately (instead of via withRouting's `channels:` param) so the
    // /broadcasting/auth route can run under 'api' with the same JWT guard as every
    // other endpoint — this app is stateless (Bearer tokens only), and withRouting's
    // default would otherwise register it under the session-based 'web' guard, which
    // the Next.js frontend has no cookie for and could never authenticate against.
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api', 'middleware' => ['auth:api', 'check_last_logout']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'is_admin' => \App\Http\Middleware\IsAdmin::class,
            'is_provider' => \App\Http\Middleware\IsProvider::class,
            'is_support_staff' => \App\Http\Middleware\IsSupportStaff::class,
            'check_last_logout' => \App\Http\Middleware\CheckLastLogout::class,
        ]);

        // API-only app: there is no web "login" route to redirect guests to.
        // Without this, Authenticate::redirectTo() calls route('login'), which
        // doesn't exist, and crashes with a 500 instead of a clean 401.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (TokenExpiredException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['error' => 'Token has expired'], 401);
            }
        });
        
        $exceptions->render(function (TokenInvalidException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['error' => 'Token is invalid'], 401);
            }
        });
        
        $exceptions->render(function (JWTException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['error' => 'Token is missing or malformed'], 401);
            }
        });
    })->create();
