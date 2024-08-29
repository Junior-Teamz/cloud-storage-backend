<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Auth;

class ValidateAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        // Mengecek apakah user memiliki role admin dan is_superadmin bernilai 1
        if (!($user->hasRole('admin') && $user->is_superadmin == 1)) {
            return response()->json([
                'error' => 'Anda tidak diizinkan untuk mengakses halaman ini.',
            ], 403); // 403 Forbidden
        }

        return $next($request);
    }
}
