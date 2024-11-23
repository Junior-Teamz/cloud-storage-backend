<?php

namespace App\Http\Controllers;

use App\Mail\ResetPasswordMail;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Exception;
use Hidehalo\Nanoid\Client;
use Illuminate\Http\Request;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class VerificationAndForgetPasswordController extends Controller
{
    // public function sendVerificationEmail(Request $request)
    // {
    //     if ($request->user()->hasVerifiedEmail()) {
    //         return response()->json(['message' => 'Email sudah diverifikasi.'], 400);
    //     }

    //     // Buat URL verifikasi bertanda tangan dengan parameter `userId`
    //     $verificationUrl = URL::temporarySignedRoute(
    //         'verification.verify',
    //         now()->addMinutes(60), // URL hanya bertahan selama 1 jam.
    //         ['id' => $request->user()->getKey()]
    //     );

    //     // Kirimkan tautan verifikasi ke email pengguna
    //     $request->user()->sendEmailVerificationNotification($verificationUrl);

    //     return response()->json(['message' => 'Tautan verifikasi telah dikirim melalui email yang terdaftar.'], 200);
    // }

    // public function verify(EmailVerificationRequest $request, $id)
    // {
    //     $frontendUrl = config('frontend.url');
    //     $build = null;

    //     // Verifikasi apakah email sudah diverifikasi
    //     if ($request->user()->hasVerifiedEmail()) {
    //         $build = 'A' . $id; // Prefix 'A' untuk menandakan user emailnya sudah diverifikasi sebelumnya.
    //         $encodedBuild = base64_encode($build);
    //         $urlBuilded = "{$frontendUrl}/verify-email/{$encodedBuild}";
    //         return redirect()->to($urlBuilded);
    //     }

    //     // Menandai email sebagai terverifikasi
    //     $request->fulfill();

    //     $build = 'V' . $id; // Prefix 'V' untuk menandakan user berhasil di verifikasi emailnya
    //     $encodedBuild = base64_encode($build);
    //     $urlBuilded = "{$frontendUrl}/verify-email/{$encodedBuild}";

    //     // Redirect ke halaman frontend setelah verifikasi berhasil
    //     return redirect()->to($urlBuilded);
    // }

    public function sendPasswordResetLink(Request $request)
    {
        // Rate limiting: Maksimum 3 permintaan dalam 5 menit
        $email = $request->email;
        $key = "password-reset:{$email}";

        if (RateLimiter::tooManyAttempts($key, 3)) {
            $seconds = RateLimiter::availableIn($key);

            return response()->json([
                'message' => 'Too many attempts. Please try again later.',
                'retry_after' => $seconds . 'seconds',
            ], 429);
        }

        // Tambahkan satu percobaan ke rate limiter
        RateLimiter::hit($key, 300); // 300 detik = 5 menit

        // Validasi email
        $validator = Validator::make(
            $request->all(),
            [
                'email' => 'required|email|exists:users,email',
            ],
            [
                'email.exists' => 'Email not registered in the system.',
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $user = User::where('email', $email)->first();
            $nameOfUser = $user->name;

            DB::beginTransaction();

            // Hapus entri token lama untuk email ini
            DB::table('password_reset_tokens')->where('email', $email)->delete();

            // Buat token manual
            $client = new Client();
            $token = $client->generateId(21);

            // Simpan token ke database
            DB::table('password_reset_tokens')->insert([
                'email' => $email,
                'token' => $token,
                'created_at' => now(),
                'expired_at' => now()->addMinutes(60),
            ]);

            DB::commit();

            $frontendUrl = config('frontend.url');

            // Kirimkan email reset password
            $resetLink = $frontendUrl[0] . "/reset-password?token={$token}";
            Mail::to($email)->send(new ResetPasswordMail($nameOfUser, $resetLink, $token));

            return response()->json([
                'message' => 'Password reset link has been sent to your email.',
            ], 200);
        } catch (Exception $e) {
            DB::rollBack();

            Log::error('An error occurred while sending password reset link', [
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred while sending password reset link. Please try again later.'
            ], 500);
        }
    }

    public function resetPassword(Request $request)
    {
        try {
            $validator = Validator::make([
                'token' => 'required',
                'password' => 'required|string|min:8|confirmed'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'errors' => $validator->errors()
                ], 422);
            }

            // Cari token di database
            $tokenEntry = DB::table('password_reset_tokens')
                ->where('token', $request->token)
                ->first();

            if (!$tokenEntry) {
                return response()->json(['message' => 'Invalid token.'], 400);
            }

            // Cek apakah token sudah kedaluwarsa
            if (now()->greaterThan($tokenEntry->expired_at)) {
                return response()->json(['message' => 'Token has expired.'], 400);
            }

            // Cari pengguna berdasarkan email
            $user = User::where('email', $tokenEntry->email)->first();

            if (!$user) {
                return response()->json(['message' => 'User not found.'], 404);
            }

            DB::beginTransaction();

            // Reset password pengguna
            $user->forceFill([
                'password' => bcrypt($request->password)
            ])->save();

            // Hapus entri di tabel password_reset_tokens untuk keamanan
            DB::table('password_reset_tokens')->where('email', $tokenEntry->email)->delete();

            DB::commit();

            return response()->json(['message' => 'Password has been reset successfully.'], 200);
        } catch (Exception $e) {
            DB::rollBack();

            Log::error('An error occurred while resetting password', [
                'token' => $request->token,
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'message' => 'An error occurred while resetting password.'
            ], 500);
        }
    }
}
