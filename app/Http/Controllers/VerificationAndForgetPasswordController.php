<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Password;
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
        $request->validate(['email' => 'required|email|exists:users,email']);

        // Mengirimkan email reset password
        $status = Password::sendResetLink(
            $request->only('email')
        );

        return $status === Password::RESET_LINK_SENT
            ? response()->json(['message' => 'Tautan reset password telah dikirim ke email Anda.'], 200)
            : response()->json(['message' => 'Terjadi kesalahan atau email tidak terdaftar.'], 400);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|string|min:8|confirmed'
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill([
                    'password' => bcrypt($password)
                ])->save();

                $user->setRememberToken(Str::random(60));
            }
        );

        return $status === Password::PASSWORD_RESET
            ? response()->json(['message' => 'Password berhasil direset.'], 200)
            : response()->json(['message' => 'Token tidak valid atau kedaluwarsa.'], 400);
    }
}
