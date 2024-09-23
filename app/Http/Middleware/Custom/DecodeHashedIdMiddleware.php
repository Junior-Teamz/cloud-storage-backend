<?php

namespace App\Http\Middleware\Custom;

use App\Services\HashIdService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DecodeHashedIdMiddleware
{
    public function handle($request, Closure $next)
    {
        // Ambil semua parameter route
        $routeParameters = $request->route()->parameters();

        // Decode route parameters
        foreach ($routeParameters as $key => $value) {
            if (strpos(strtolower($key), 'id') !== false) {
                try {
                    $decodedId = $this->attemptDecode($value);
                    $request->route()->setParameter($key, $decodedId);
                    Log::info('Decoded route parameter ID:', [
                        'key' => $key,
                        'original' => $value,
                        'decoded' => $decodedId
                    ]);
                } catch (\Exception $e) {
                    Log::error('Failed to decode route parameter ID:', ['key' => $key, 'value' => $value, 'error' => $e->getMessage()]);
                    return response()->json(['error' => 'Failed to decode ID for ' . $key], 400);
                }
            }
        }

        // Decode body parameters (termasuk form-data, JSON, dll.)
        $decodedRequest = $this->decodeIdsInRequest($request->all());

        // Replace original request data dengan data yang sudah di-decode
        $request->replace($decodedRequest);

        return $next($request);
    }

    protected function decodeIdsInRequest($input)
    {
        foreach ($input as $key => $value) {
            if (is_array($value)) {
                // Rekursif untuk mendekode nested array
                $input[$key] = $this->decodeIdsInRequest($value);
            } else {
                // Jika key mengandung 'id', coba decode
                if (strpos(strtolower($key), 'id') !== false) {
                    try {
                        $input[$key] = $this->attemptDecode($value);
                    } catch (\Exception $e) {
                        Log::error('Failed to decode body ID:', ['key' => $key, 'value' => $value, 'error' => $e->getMessage()]);
                        return response()->json(['error' => 'Failed to decode ID for ' . $key], 400);
                    }
                }
            }
        }

        return $input;
    }

    /**
     * Coba decode ID apapun yang ada di request, baik dalam route maupun body.
     * Jika nilai bukan string, ubah menjadi string terlebih dahulu sebelum decode.
     *
     * @param mixed $value Nilai yang akan di-decode
     * @return mixed ID asli yang sudah di-decode, atau throw exception jika gagal
     * @throws \Exception Jika decoding gagal
     */
    protected function attemptDecode($value)
    {
        // Ubah apapun menjadi string untuk dicoba di-decode
        if (!is_string($value)) {
            $value = (string) $value;
        }

        $decoded = $this->decodeId($value);

        if (empty($decoded)) {
            throw new \Exception('Decoding failed for value: ' . $value);
        }

        // Kembalikan nilai ID asli yang sudah di-decode
        return $decoded[0];
    }

    protected function decodeId($hashedId)
    {
        return app(HashIdService::class)->decodeId($hashedId);
    }
}
