<?php

namespace App\Http\Middleware\Custom;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ValidateSuperadmin
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        $userData = User::where('id', $user->id)->first();

        if ($userData->hasRole('superadmin')) {
            return $next($request);
        }

        return response()->json([
            'errors' => 'Anda tidak diizinkan untuk mengakses halaman ini.',
        ], 403); // 403 Forbidden
    }
}
