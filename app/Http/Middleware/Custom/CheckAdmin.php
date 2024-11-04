<?php

namespace App\Http\Middleware\Custom;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Auth;

/**
 * This middleware checks if the authenticated user has the 'admin' role.
 *
 * If the user is an admin (regardless of 'is_superadmin' status), it returns a 403 Forbidden response,
 * indicating that they should be using routes with the "admin" prefix.
 * Otherwise, the request is allowed to proceed.
 */
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
        if (($user->hasRole('admin') && $user->is_superadmin == 0) || ($user->hasRole('admin') && $user->is_superadmin == 1)) {
            return response()->json([
                'errors' => 'Anda seharusnya tidak menggunakan route ini, Gunakan route dengan prefix "admin".',
            ], 403);
        }

        return $next($request);
    }
}
