<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<\Throwable>, \Psr\Log\LogLevel::*>
     */
    protected $levels = [
        //
    ];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<\Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    public function render($request, Throwable $exception)
    {
        // Jika route yang dipanggil adalah 'image.url' dan terjadi RouteNotFoundException
        if ($request->routeIs('image.url') && $exception instanceof RouteNotFoundException) {
            // Anda bisa mengganti 404 menjadi kode error lain yang lebih sesuai
            return response()->view('errors.401', [
                'code' => 401,
                'message' => 'Unauthorized.',
            ], 401);
        }

        return parent::render($request, $exception);
    }
    
     /**
     * Handle an unauthenticated user.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Illuminate\Auth\AuthenticationException  $exception
     * @return \Illuminate\Http\Response
     */
    protected function unauthenticated($request, \Illuminate\Auth\AuthenticationException $exception)
    {
        // Mengembalikan selalu response JSON, tanpa redirect ke login route
        return response()->json([
            'error' => 'You are not authenticated or your session has expired.',
        ], 401);
    }
}
