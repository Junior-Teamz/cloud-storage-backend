<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function handle(Request $request)
    {
        // Secret yang diset di GitHub Webhook
        $secret = env('GITHUB_WEBHOOK_SECRET');
        $signature = 'sha256=' . hash_hmac('sha256', $request->getContent(), $secret);

        // Verifikasi signature untuk memastikan request asli dari GitHub
        if (!hash_equals($signature, $request->header('X-Hub-Signature-256'))) {
            Log::error('Invalid GitHub signature');
            return response('Invalid signature', 403);
        }

        // Log request yang diterima dari GitHub setelah verifikasi
        Log::info('GitHub Webhook Request Verified', [
            'headers' => $request->headers->all(),
            'payload' => $request->all(),
        ]);

        // Pastikan event adalah push ke branch 'main'
        if ($request->input('ref') === 'refs/heads/main') {
            try {
                // Lakukan git pull origin main
                $pullProcess = new Process([
                    'git', 'pull', 'origin', 'main'
                ], base_path());  // Jalankan perintah di direktori aplikasi

                $pullProcess->run();

                // Cek apakah git pull berhasil
                if (!$pullProcess->isSuccessful()) {
                    Log::warning('Git Pull Failed', ['output' => $pullProcess->getErrorOutput()]);
                }

                // Setelah git pull, lakukan git merge dengan strategi theirs
                $mergeProcess = new Process([
                    'git', 'merge', '--strategy-option=theirs'
                ], base_path());

                $mergeProcess->run();

                // Cek apakah git merge berhasil
                if (!$mergeProcess->isSuccessful()) {
                    Log::error('Git Merge Failed', ['output' => $mergeProcess->getErrorOutput()]);
                    throw new ProcessFailedException($mergeProcess);
                }

                // Log sukses jika git pull dan merge berhasil
                Log::info('Git Pull and Merge (accept theirs) Successful');

                return response('Webhook handled successfully', 200);

            } catch (ProcessFailedException $e) {
                // Jika terjadi kegagalan pada proses git
                Log::error('Git Process Failed', [
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                return response('Git process failed', 500);
            }
        }

        // Log jika event bukan push ke branch main
        Log::info('Not a push to main branch', ['ref' => $request->input('ref')]);

        return response('Not a push to main', 200);
    }
}
