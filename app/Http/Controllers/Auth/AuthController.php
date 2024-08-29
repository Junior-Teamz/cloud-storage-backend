<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        // Validasi input
        $validator = Validator::make($request->all(), [
            'email' => [
                'required',
                'email',
                function ($attribute, $value, $fail) {
                    if (!preg_match('/^\w+([\.-]?\w+)*@\w+([\.-]?\w+)*(\.\w{2,3})+$/', $value)) {
                        $fail('Format email tidak valid.');
                    }
                },
            ],
            'password' => 'required',
        ], [
            'email.required' => 'Email wajib diisi.',
            'password.required' => 'Password wajib diisi.',
        ]);

        // Respon error validasi
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Ambil "email" dan "password" dari input
        $credentials = $request->only('email', 'password');

        try {
            // Coba melakukan autentikasi
            if (!$token = auth()->guard('api')->attempt($credentials)) {
                // Respon jika login gagal karena email atau password salah
                return response()->json([
                    'errors' => 'Email or Password is incorrect.',
                ], 401);
            }

            // Dapatkan informasi pengguna
            $user = auth()->guard('api')->user();
            $userData = $user->only(['name', 'email']);
            $roles = $user->roles->pluck('name');

            // Simpan token JWT di cookie HTTP-only
            $cookie = Cookie::make('token', $token, 30, null, null, false, true);

            // Masukkan token di field accessToken
            return response()->json([
                'success' => true,
                'user' => $userData,
                'roles' => $roles,
                'permissions' => $user->getPermissionArray(),
            ])->withCookie($cookie);
        } catch (\Exception $e) {
            Log::error('Login error: ' . $e->getMessage());
            return response()->json(['errors' => 'Terjadi kesalahan. Harap coba lagi nanti.'], 500);
        }
    }

    public function checkTokenValid()
    {
        try {
            if (auth()->guard('api')->check()) {
                return response()->json([
                    'token_valid' => true
                ]);
            } else {
                return response()->json([
                    'token_valid' => false
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Check token error: ' . $e->getMessage());
            return response()->json(['errors' => 'Terjadi kesalahan. Harap coba lagi nanti.'], 500);
        }
    }

    public function logout()
    {
        try {
            Auth::logout();

            $cookie = Cookie::forget('token');

            return response()->json(['message' => 'Logout berhasil'])
                ->withCookie($cookie);
        } catch (\Exception $e) {
            Log::error('Logout error: ' . $e->getMessage());

            return response()->json(['errors' => 'Terjadi kesalahan ketika logout'], 500);
        }
    }
}
