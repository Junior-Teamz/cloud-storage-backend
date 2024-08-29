<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;
use Hashids\Hashids;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up'
    )
    ->withMiddleware(function (Middleware $middleware) {

        $middleware->api(prepend: [
            \App\Http\Middleware\ApiForceJsonResponse::class,
        ]);

        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'hashid' => \App\Http\Middleware\HashIdMiddleware::class,
            'remove_nanoid' => \App\Http\Middleware\RemoveNanoidFromResponse::class,
            'protectRootFolder' => \App\Http\Middleware\ProtectRootFolder::class,
            'check_admin' => \App\Http\Middleware\CheckAdmin::class,
            'validate_admin' => \App\Http\Middleware\ValidateAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'errors' => 'You are not logged in. Please Login first.',
                ], 401);
            }
        });
    })->create();
