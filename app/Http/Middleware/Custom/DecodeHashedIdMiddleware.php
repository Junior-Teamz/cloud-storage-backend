<?php

namespace App\Http\Middleware\Custom;

use App\Services\HashIdService;
use Closure;
use Exception;
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
            if ($this->isIdKey($key)) {
                try {
                    $decodedId = $this->attemptDecode($value);
                    $request->route()->setParameter($key, $decodedId);
                    Log::info('Decoded route parameter ID:', [
                        'key' => $key,
                        'original' => $value,
                        'decoded' => $decodedId
                    ]);
                } catch (Exception $e) {
                    Log::error('Failed to decode route parameter ID:', [
                        'key' => $key, 
                        'value' => $value, 
                        'error' => $e->getMessage()
                    ]);
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
                // Jika value adalah array, decode setiap item di dalamnya
                $input[$key] = array_map(function ($item) {
                    try {
                        return is_array($item) ? $this->decodeIdsInRequest($item) : $this->attemptDecode($item);
                    } catch (Exception $e) {
                        Log::error('Failed to decode array item ID:', ['value' => $item, 'error' => $e->getMessage()]);
                        return $item; // Kembalikan nilai asli jika gagal decode
                    }
                }, $value);
            } else {
                // Jika key mengandung 'id' dan value adalah string atau bukan array, coba decode
                if ($this->isIdKey($key)) {
                    try {
                        $input[$key] = $this->attemptDecode($value);
                    } catch (Exception $e) {
                        Log::error('Failed to decode body ID:', ['key' => $key, 'value' => $value, 'error' => $e->getMessage()]);
                        return response()->json(['error' => 'Failed to decode ID for ' . $key], 400);
                    }
                }
            }
        }

        return $input;
    }

    /**
     * Memeriksa apakah key terkait dengan 'id', 'ids', atau 'tag_ids', dst.
     */
    protected function isIdKey($key)
    {
        return preg_match('/id$/i', $key) || preg_match('/ids$/i', $key);
    }

    /**
     * Coba decode ID apapun yang ada di request, baik dalam route maupun body.
     * Jika nilai bukan string, ubah menjadi string terlebih dahulu sebelum decode.
     *
     * @param mixed $value Nilai yang akan di-decode
     * @return mixed ID asli yang sudah di-decode, atau throw exception jika gagal
     * @throws Exception Jika decoding gagal
     */
    protected function attemptDecode($value)
    {
        // Jika nilai adalah array, lakukan decoding setiap elemen
        if (is_array($value)) {
            $decodedArray = [];
            foreach ($value as $item) {
                $decodedArray[] = $this->attemptDecode($item);
            }
            return $decodedArray;
        }

        // Ubah apapun menjadi string jika bukan array atau objek
        if (!is_scalar($value) && !is_null($value)) {
            throw new Exception('Cannot decode non-scalar value: ' . json_encode($value));
        }

        // Konversi ke string jika bukan null
        $value = is_null($value) ? $value : (string) $value;

        $decoded = $this->decodeId($value);

        if (empty($decoded)) {
            throw new Exception('Decoding failed for value: ' . $value);
        }

        // Kembalikan nilai ID asli yang sudah di-decode
        return $decoded[0];
    }

    protected function decodeId($hashedId)
    {
        return app(HashIdService::class)->decodeId($hashedId);
    }
}
