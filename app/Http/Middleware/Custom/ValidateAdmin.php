<?php

namespace App\Http\Middleware\Custom;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Auth;

/**
 * This middleware validates if the authenticated user has the 'admin' role.
 * 
 * It checks if the user has the 'admin' role, regardless of their 'is_superadmin' status.
 * If the user is an admin, the request is allowed to proceed.
 * Otherwise, a 403 Forbidden response is returned.
 */
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

        $userData = User::where('id', $user->id)->first();

        if($userData->hasRole('admin') && $userData->hasRole('superadmin')){
            return $next($request);
        } elseif( $userData->hasRole('admin') && $userData->is_superadmin == 0){
            return $next($request);
        }

        return response()->json([
            'errors' => 'Anda tidak diizinkan untuk mengakses halaman ini.',
        ], 403); // 403 Forbidden
    }
}
