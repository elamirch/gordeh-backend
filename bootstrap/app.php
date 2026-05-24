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
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'is_admin' => \App\Http\Middleware\IsAdmin::class,
            'check_last_logout' => \App\Http\Middleware\CheckLastLogout::class,
        ]);
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
