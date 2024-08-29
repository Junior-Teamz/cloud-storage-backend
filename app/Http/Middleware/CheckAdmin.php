<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Auth;

class CheckAdmin
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

        // Mengecek apakah user memiliki role admin atau memiliki role admin dan is_superadmin bernilai 1
        if ($user->hasRole('admin') || ($user->hasRole('admin') && $user->is_superadmin == 1)) {
            return response()->json([
                'error' => 'Anda seharusnya tidak menggunakan route ini, Gunakan route dengan prefix "admin".',
            ], 403);
        }

        return $next($request);
    }
}
