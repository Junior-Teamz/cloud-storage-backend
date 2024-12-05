<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie; // maybe next time
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;

class AuthController extends Controller
{
    /**
     * Handle user login.
     *
     * This method attempts to authenticate a user based on the provided email and password.
     * It performs validation on the input, generates access and refresh tokens upon successful
     * authentication, and returns a JSON response containing user information, roles, permissions,
     * and tokens.
     *
     * @param  \Illuminate\Http\Request  $request The incoming HTTP request containing user credentials.
     * @return \Illuminate\Http\JsonResponse A JSON response indicating success or failure, including user data, roles, permissions, and tokens.
     */
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

            $userData = array_intersect_key(
                $user->toArray(),
                array_flip(['name', 'email', 'photo_profile_url'])
            );

            $userRole = $user->roles->pluck('name');

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
                'roles' => $userRole,
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

    /**
     * Check if the access token is valid.
     *
     * This method checks the validity of an access token provided in the Authorization Bearer header.
     * It returns a JSON response indicating whether the token is valid or not.
     *
     * @param  \Illuminate\Http\Request  $request The incoming HTTP request containing the access token.
     * @return \Illuminate\Http\JsonResponse A JSON response indicating whether the token is valid (true/false).
     */
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

    /**
     * Check if the refresh token is valid.
     *
     * This method checks the validity of a refresh token provided in the request body.
     * It returns a JSON response indicating whether the token is valid or not.
     *
     * @param  \Illuminate\Http\Request  $request The incoming HTTP request containing the refresh token.
     * @return \Illuminate\Http\JsonResponse A JSON response indicating whether the token is valid (true/false).
     */
    public function checkRefreshTokenValid(Request $request)
    {
        try {
            $getRefreshToken = $request->input('refreshToken');

            if (!$getRefreshToken) {
                return response()->json([
                    'errors' => "Refresh token not found. Please add 'refreshToken' in body request!"
                ]);
            }

            // Set token secara manual dari refresh token yang diambil dari body
            JWTAuth::setToken($getRefreshToken);

            // Cek apakah refresh token valid
            $check = JWTAuth::check();

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

    /**
     * Refresh the access token using the refresh token.
     *
     * This method refreshes the access token using a valid refresh token. It verifies the
     * integrity of both tokens, invalidates the old access token, and returns a new access token.
     *
     * @param  \Illuminate\Http\Request  $request The incoming HTTP request containing the refresh token and access token.
     * @return \Illuminate\Http\JsonResponse A JSON response containing the new access token or error messages.
     * @throws \Tymon\JWTAuth\Exceptions\JWTException If there is an error during token processing.
     * @throws \Exception If any other error occurs during the refresh process.
     */
    public function refresh(Request $request)
    {
        try {
            $accessToken = $request->bearerToken('Authorization');

            $refreshToken = $request->input('refreshToken');
            if (!$refreshToken) {
                return response()->json(['errors' => 'Refresh token not found.'], 400);
            }

            // Cek apakah refresh token valid
            $check = JWTAuth::setToken($refreshToken)->check();

            if (!$check) {
                return response()->json([
                    'errors' => 'Invalid refresh token.'
                ], 401);
            }

            // Ambil klaim dari refresh token
            $claims = JWTAuth::getPayload()->toArray();

            // Cek apakah refresh token memiliki access_token_id
            if (!isset($claims['access_token_id'])) {
                return response()->json(['errors' => 'Refresh token is invalid.'], 400);
            }

            // Ambil access token ID dari klaim refresh token
            $refreshTokenAccessTokenId = $claims['access_token_id'];

            // Periksa apakah access token yang di-refresh cocok
            $accessTokenId = JWTAuth::setToken($accessToken)->getPayload()['jti']; // Ambil access token ID

            if ($refreshTokenAccessTokenId !== $accessTokenId) {
                return response()->json(['errors' => 'Access token does not match in refresh token.'], 401);
            }

            // Ambil additional_time dari klaim refresh token
            $additionalTime = $claims['additional_time'];

            // Set TTL untuk JWTAuth
            JWTAuth::factory()->setTTL($additionalTime);

            // Generate access token baru dengan TTL yang disesuaikan
            $newAccessToken = JWTAuth::refresh();

            // Buat refresh token lama menjadi tidak valid.
            JWTAuth::setToken($refreshToken)->invalidate();

            // Ambil user dari token
            $user = JWTAuth::setToken($newAccessToken)->toUser();

            // Buat refresh token baru
            JWTAuth::factory()->setTTL(7 * 24 * 60); // TTL untuk refresh token (7 hari)
            $newRefreshToken = JWTAuth::claims([
                'access_token_id' => JWTAuth::setToken($newAccessToken)->getPayload()['jti'],
                'additional_time' => 3600
            ])->fromUser($user);

            // Buat access token lama menjadi tidak valid.
            JWTAuth::setToken($accessToken)->invalidate();

            return response()->json([
                'message' => 'Successfully refreshing token.',
                'new_access_token' => $newAccessToken,
                'new_refresh_token' => $newRefreshToken
            ]);
        } catch (TokenExpiredException $e) {
            return response()->json(['errors' => 'Refresh token expired.'], 401);
        } catch (JWTException $e) {
            return response()->json(['errors' => 'Invalid refresh token.'], 401);
        } catch (Exception $e) {
            Log::error('Error occured while refreshing token: ' . $e->getMessage(), [
                'accessToken' => $accessToken,
                'refreshToken' => $refreshToken,
                'trace' => $e->getTrace()
            ]);
            return response()->json(['errors' => 'An error occured while refreshing token.'], 500);
        }
    }

    /**
     * Log out the user by invalidating the access and refresh tokens.
     *
     * This method retrieves the access token from the Authorization Bearer header and the refresh token
     * from the request body. It then invalidates both tokens, effectively logging out the user.
     *
     * @param  \Illuminate\Http\Request  $request The incoming HTTP request containing the tokens.
     * @return \Illuminate\Http\JsonResponse A JSON response indicating success or failure.
     */
    public function logout(Request $request)
    {
        try {
            // Get accessToken from Authorization Bearer header
            $accessToken = $request->bearerToken('Authorization');

            if (!$accessToken) {
                return response()->json([
                    'errors' => 'Access token not found in Authorization Bearer Header!'
                ], 400);
            }

            // Get refreshToken from body request
            $refreshToken = $request->input('refreshToken');

            if (!$refreshToken) {
                return response()->json([
                    'errors' => 'Refresh token is required.'
                ], 400);
            }

            // Invalidate accessToken
            JWTAuth::setToken($accessToken)->invalidate();

            // Invalidate refreshToken
            JWTAuth::setToken($refreshToken)->invalidate();

            return response()->json(['message' => 'Successfully logout.']);
        } catch (JWTException $e) {
            Log::error('Logout error: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);
            return response()->json(['errors' => 'An error occured while logout.'], 500); // 500 Internal Server Error
        }
    }
}
