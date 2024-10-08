<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    // Handle webhook push from github repository.
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

                // Cek apakah git pull berhasil tanpa throw error
                if (!$pullProcess->isSuccessful()) {
                    Log::warning('Git Pull Failed', ['output' => $pullProcess->getErrorOutput()]);
                    throw new ProcessFailedException($pullProcess);
                }

                // Tangani kemungkinan konflik saat pull
                if (strpos($pullProcess->getOutput(), 'CONFLICT') !== false) {
                    Log::info('Merge conflict detected. Attempting to resolve by accepting all incoming changes.');

                    // Accept all incoming changes (theirs) during conflict
                    $checkoutTheirsProcess = new Process([
                        'git', 'checkout', '--theirs', '.'
                    ], base_path());

                    $checkoutTheirsProcess->run();

                    if (!$checkoutTheirsProcess->isSuccessful()) {
                        Log::error('Failed to checkout --theirs during conflict resolution', ['output' => $checkoutTheirsProcess->getErrorOutput()]);
                        throw new ProcessFailedException($checkoutTheirsProcess);
                    }

                    // Tambahkan semua perubahan yang diterima
                    $addProcess = new Process([
                        'git', 'add', '.'
                    ], base_path());

                    $addProcess->run();

                    if (!$addProcess->isSuccessful()) {
                        Log::error('Failed to add changes during conflict resolution', ['output' => $addProcess->getErrorOutput()]);
                        throw new ProcessFailedException($addProcess);
                    }

                    // Simpan merge message otomatis
                    $commitProcess = new Process([
                        'git', 'commit', '-m', 'Resolved all conflicts by accepting incoming changes'
                    ], base_path());

                    $commitProcess->run();

                    if (!$commitProcess->isSuccessful()) {
                        Log::error('Failed to commit during conflict resolution', ['output' => $commitProcess->getErrorOutput()]);
                        throw new ProcessFailedException($commitProcess);
                    }

                    Log::info('Merge conflict resolved by accepting all incoming changes.');
                }

                // Tangani kemungkinan git membuka editor merge message
                if (strpos($pullProcess->getOutput(), 'Automatic merge went well;') !== false) {
                    Log::info('Merge successful. Preparing to handle merge message.');

                    // Buat merge message otomatis
                    $autoMergeMessage = new Process([
                        'git', 'commit', '--no-edit'
                    ], base_path());

                    $autoMergeMessage->run();

                    if (!$autoMergeMessage->isSuccessful()) {
                        Log::error('Failed to automatically commit merge message', ['output' => $autoMergeMessage->getErrorOutput()]);
                        throw new ProcessFailedException($autoMergeMessage);
                    }

                    Log::info('Merge message handled successfully.');
                }

                // Lanjutkan merge jika tidak ada konflik
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
