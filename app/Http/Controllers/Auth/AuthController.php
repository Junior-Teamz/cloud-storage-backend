<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use Tymon\JWTAuth\Exceptions\TokenBlacklistedException;

class AuthController extends Controller
{
    // Fungsi untuk melakukan login dan mengeluarkan access token serta refresh token
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

        try {
            $credentials = $request->only('email', 'password');

            // Coba melakukan autentikasi
            $token = auth()->guard('api')->attempt($credentials);

            if (!$token) {
                // Respon jika login gagal karena email atau password salah
                return response()->json([
                    'errors' => 'Email or Password is incorrect.',
                ], 401);
            }

            // Dapatkan informasi pengguna
            $user = auth()->guard('api')->user();
            $userData = $user->only(['name', 'email']);
            $roles = $user->roles->pluck('name');
            $accessToken = $token;

            // Inisialisasi array respons
            $responseData = [
                'accessToken' => $accessToken,
                'success' => true,
                'user' => $userData,
                'roles' => $roles,
                'permissions' => $user->getPermissionArray(),
            ];

            // Jika user adalah is_superadmin, tambahkan is_superadmin ke dalam respons JSON
            if ($user->is_superadmin == 1) {
                $responseData['is_superadmin'] = true;
            }

            // Kembalikan respons JSON dengan cookie
            return response()->json($responseData);
            // return response()->json($responseData);
        } catch (\Exception $e) {
            Log::error('Login error: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);
            return response()->json(['errors' => 'Terjadi kesalahan. Harap coba lagi nanti.'], 500);
        }
    }

    public function checkTokenValid()
    {
        try {
            $check = JWTAuth::parseToken()->authenticate();

            if ($check) {
                return response()->json([
                    'token_valid' => true
                ], 200); // HTTP 200 OK
            }
        } catch (Exception $e) {
            if ($e instanceof \Tymon\JWTAuth\Exceptions\TokenInvalidException) {
                return response()->json([
                    'token_valid' => false,
                    'errors' => 'Token is Invalid'
                ], 401); // HTTP 401 Unauthorized
            } else if ($e instanceof \Tymon\JWTAuth\Exceptions\TokenExpiredException) {
                return response()->json([
                    'token_valid' => false,
                    'errors' => 'Token has Expired'
                ], 401); // HTTP 401 Unauthorized
            } else if ($e instanceof \Tymon\JWTAuth\Exceptions\TokenBlacklistedException) {
                return response()->json([
                    'token_valid' => false,
                    'errors' => 'Token is Blacklisted'
                ], 403); // HTTP 403 Forbidden
            } else {
                Log::error('Terjadi kesalahan ketika memeriksa token: ' . $e->getMessage(), [
                    'trace' => $e->getTrace()
                ]);
                return response()->json([
                    'errors' => 'Terjadi kesalahan, harap coba lagi nanti'
                ], 500); // HTTP 500 Internal Server Error
            }
        }
    }

    // TODO: Fungsi untuk me-refresh token
    // public function refreshToken(Request $request)
    // {
    //     try {
    //         // Ambil access token dari header Authorization: Bearer {token}
    //         $accessToken = $request->header('Authorization');
    //         if (!$accessToken) {
    //             return response()->json(['errors' => 'Access Token tidak ditemukan di header.'], 401); // 401 Unauthorized
    //         }
            
    //         // Ambil refresh token dari cookie HTTP-Only
    //         $refreshToken = $request->cookie('refresh_token');
    //         if (!$refreshToken) {
    //             return response()->json(['errors' => 'Refresh Token tidak ditemukan.'], 401); // 401 Unauthorized
    //         }

    //         // Invalidate the old refresh token to prevent reuse
    //         JWTAuth::setToken($refreshToken)->invalidate();

    //         // Refresh the access token and get a new one
    //         $newAccessToken = JWTAuth::refresh($accessToken);

    //         // Create a new refresh token
    //         $newRefreshToken = JWTAuth::fromUser(auth()->guard('api')->user());

    //         // Set the new refresh token in a secure HTTP-Only cookie
    //         $cookie = Cookie::make('refresh_token', $newRefreshToken, 10080, null, null, false, true); // 7 hari

    //         return response()->json([
    //             'accessToken' => $newAccessToken
    //         ])->withCookie($cookie);
    //     } catch (TokenExpiredException $e) {
    //         return response()->json(['errors' => 'Access Token telah kadaluarsa.'], 401); // 401 Unauthorized
    //     } catch (TokenInvalidException $e) {
    //         return response()->json(['errors' => 'Access Token tidak valid.'], 401); // 401 Unauthorized
    //     } catch (TokenBlacklistedException $e) {
    //         return response()->json(['errors' => 'Refresh Token telah diblacklist.'], 403); // 403 Forbidden
    //     } catch (JWTException $e) {
    //         Log::error('Terjadi kesalahan saat mencoba me-refresh token: ' . $e->getMessage(), [
    //         'trace' => $e->getTrace()
    // ]);
    //         return response()->json(['errors' => 'Terjadi kesalahan, harap coba lagi nanti.'], 500); // 500 Internal Server Error
    //     }
    // }

    // Fungsi untuk logout dan invalidate refresh token
    public function logout()
    {
        try {
            // Invalidate both access and refresh tokens
            JWTAuth::invalidate(JWTAuth::getToken());

            return response()->json(['message' => 'Logout berhasil']);
        } catch (JWTException $e) {
            Log::error('Logout error: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);
            return response()->json(['errors' => 'Terjadi kesalahan ketika logout'], 500); // 500 Internal Server Error
        }
    }
}
