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
        // Log request yang diterima dari GitHub
        Log::info('GitHub Webhook Request Received', [
            'headers' => $request->headers->all(),  // Log semua header
            'payload' => $request->all(),           // Log semua payload
        ]);

        // Pastikan event adalah push ke branch main
        if ($request->input('ref') === 'refs/heads/main') {
            // Jalankan git pull di direktori yang diinginkan
            $process = new Process(['git', 'pull', 'origin', 'main']);
            $process->run();

            // Cek apakah git pull berhasil
            if (!$process->isSuccessful()) {
                // Log error jika git pull gagal
                Log::error('Git Pull Failed', ['output' => $process->getErrorOutput()]);
                throw new ProcessFailedException($process);
            }

            // Log sukses jika git pull berhasil
            Log::info('Git Pull Successful');
            return response('Webhook handled successfully', 200);
        }

        // Log jika event bukan push ke branch main
        Log::info('Not a push to main branch', ['ref' => $request->input('ref')]);

        return response('Not a push to main', 200);
    }
}
