<?php

namespace App\Http\Middleware\Custom;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ErrorFormat
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        // Tangkap semua response dengan HTTP status code >= 400
        if ($response->status() >= 400) {
            $content = [
                'status' => $response->status(),
                'errors' => $this->formatErrorMessage($response),
            ];

            return response()->json($content, $response->status());
        }

        return $response;
    }

    /**
     * Format error message based on response content type.
     *
     * @param  \Symfony\Component\HttpFoundation\Response  $response
     * @return array|string
     */
    protected function formatErrorMessage($response)
    {
        $content = $response->getContent();

        // Jika response JSON, decode dan ambil data
        if ($this->isJson($content)) {
            $data = json_decode($content, true);

            // Jika tidak menemukan key 'message', 'errors', atau 'error'
            if (!isset($data['message']) && !isset($data['errors']) && !isset($data['error'])) {
                Log::warning('Response JSON does not contain expected keys.', [
                    'response_content' => $data,
                ]);
                return 'An unknown error occurred.';
            }

            // Jika ada key 'message', 'errors', atau 'error', gunakan salah satunya
            return $data['message'] ?? $data['errors'] ?? $data['error'];
        }

        // Jika bukan JSON, langsung kembalikan kontennya
        return $content;
    }

    /**
     * Check if a string is a valid JSON.
     *
     * @param  string  $string
     * @return bool
     */
    protected function isJson($string)
    {
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }
}
