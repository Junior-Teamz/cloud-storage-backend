<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie; // maybe next time
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;

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
            'email.required' => 'Email is required.',
            'password.required' => 'Password is required.',
        ]);

        // Respon error validasi
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $credentials = $request->only('email', 'password');

            // Coba melakukan autentikasi
            $accessToken = auth()->guard('api')->attempt($credentials);

            if (!$accessToken) {
                // Respon jika login gagal karena email atau password salah
                return response()->json([
                    'errors' => 'Email or Password is incorrect.',
                ], 401);
            }

            // Dapatkan informasi pengguna
            $user = auth()->guard('api')->user();
            $userData = $user->only(['name', 'email']);
            $roles = $user->roles->pluck('name');

            // Buat access token ID
            $accessTokenId = JWTAuth::setToken($accessToken)->getPayload()['jti']; // Ambil ID dari access token

            JWTAuth::factory()->setTTL(7 * 24 * 60); // Mengatur TTL untuk refresh token
            $refreshToken = JWTAuth::claims([
                'access_token_id' => $accessTokenId, // Simpan access token ID di refresh token
                'additional_time' => 3600 // Waktu tambahan (1 jam)
            ])->fromUser($user); // Buat refresh token tanpa autentikasi ulang

            // Inisialisasi array respons
            $responseData = [
                'accessToken' => $accessToken,
                'refreshToken' => $refreshToken,
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
            return response()->json(['errors' => 'An error was occured, please try again later.'], 500);
        }
    }

    public function checkAccessTokenValid(Request $request)
    {
        try {
            $getAccessToken = $request->bearerToken('Authorization');

            $check = JWTAuth::parseToken($getAccessToken)->check();

            if ($check) {
                return response()->json([
                    'token_valid' => true
                ], 200);
            } else {
                return response()->json([
                    'token_valid' => false
                ], 200);
            }
        } catch (Exception $e) {
            Log::error('Error occured while checking access token: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);
            return response()->json([
                'errors' => 'An error occured while checking access token.'
            ], 500); // HTTP 500 Internal Server Error

        }
    }

    // im little bit confused why creating this function... XD
    public function checkRefreshTokenValid(Request $request)
    {
        try {
            $getAccessToken = $request->input('refreshToken');

            if (!$getAccessToken) {
                return response()->json([
                    'errors' => "Refresh token not found. Please add 'refresh_token' in body request!"
                ]);
            }

            $check = JWTAuth::parseToken($getAccessToken)->check();

            if ($check) {
                return response()->json([
                    'token_valid' => true
                ], 200);
            } else {
                return response()->json([
                    'token_valid' => false
                ], 200);
            }
        } catch (Exception $e) {
            Log::error('Error occured while checking refresh token: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);
            return response()->json([
                'errors' => 'An error occured while checking refresh token'
            ], 500); // HTTP 500 Internal Server Error

        }
    }

    // public function refresh(Request $request)
    // {
    //     try {
    //         // Ambil refresh token dari request (misalnya dari header Authorization)
    //         $refreshToken = $request->input('refreshToken');
    //         if (!$refreshToken) {
    //             return response()->json(['errors' => 'Refresh token not found.'], 400);
    //         }

    //         // Set refresh token
    //         JWTAuth::setToken($refreshToken);

    //         // Ambil klaim dari refresh token
    //         $claims = JWTAuth::getPayload()->toArray();

    //         // Cek apakah refresh token memiliki access_token_id
    //         if (!isset($claims['access_token_id'])) {
    //             return response()->json(['errors' => 'Refresh token is invalid.'], 401);
    //         }

    //         // Ambil access token ID dari klaim refresh token
    //         $refreshTokenAccessTokenId = $claims['access_token_id'];

    //         // Periksa apakah access token yang di-refresh cocok
    //         $accessToken = $request->bearerToken('Authorization'); // Ambil access token dari input
    //         $accessTokenId = JWTAuth::setToken($accessToken)->getPayload()['jti']; // Ambil access token ID

    //         if ($refreshTokenAccessTokenId !== $accessTokenId) {
    //             return response()->json(['errors' => 'Access token does not match in refresh token.'], 401);
    //         }

    //         // Jika cocok, buat access token baru dengan waktu tambahan dari refresh token
    //         $additionalTime = $claims['additional_time'] ?? 0;
    //         $newAccessToken = auth()->guard('api')->claims([
    //             'exp' => Carbon::now()->addSeconds($additionalTime)->timestamp,
    //             'jti' => $accessTokenId // Gunakan kembali access token ID
    //         ])->refresh();

    //         return response()->json(['accessToken' => $newAccessToken]);
    //     } catch (Exception $e) {
    //         if ($e instanceof JWTException) {
    //             return response()->json([
    //                 'errors' => 'Either access token or refresh token is invalid or expired.'
    //             ], 401);
    //         } else {
    //             Log::error('Error occured while refreshing token: ' . $e->getMessage(), [
    //                 'refresh_token' => $refreshToken,
    //                 'trace' => $e->getTrace()
    //             ]);
    //             return response()->json(['errors' => 'An error occured while refreshing token.'], 500);
    //         }
    //     }
    // }

    public function refresh(Request $request)
    {
        try {
            $refreshToken = $request->input('refreshToken');
            if (!$refreshToken) {
                return response()->json(['errors' => 'Refresh token not found.'], 400);
            }

            // Set refresh token dan refresh JWT
            JWTAuth::setToken($refreshToken);
            $newAccessToken = JWTAuth::refresh();

            return response()->json(['accessToken' => $newAccessToken]);
        } catch (TokenExpiredException $e) {
            return response()->json(['errors' => 'Refresh token expired.'], 401);
        } catch (JWTException $e) {
            return response()->json(['errors' => 'Invalid refresh token.'], 401);
        } catch (Exception $e) {
            return response()->json(['errors' => 'An error occurred while refreshing token.'], 500);
        }
    }

    // Fungsi untuk logout
    public function logout(Request $request)
    {
        try {
            // Invalidate both access and refresh tokens
            JWTAuth::invalidate(JWTAuth::getToken());

            return response()->json(['message' => 'Successfully logout.']);
        } catch (JWTException $e) {
            Log::error('Logout error: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);
            return response()->json(['errors' => 'An error occured while logout.'], 500); // 500 Internal Server Error
        }
    }
}
