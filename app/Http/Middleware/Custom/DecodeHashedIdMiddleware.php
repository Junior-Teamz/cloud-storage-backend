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

        // Cek apakah request memiliki data yang dapat diakses (JSON atau form-data)
        if ($request->isMethod('post') || $request->isMethod('put') || $request->isMethod('patch') || $request->isMethod('delete')) {
            $requestData = $request->all();

            // Decode body parameters (termasuk form-data, JSON, dll.)
            $decodedRequest = $this->decodeIdsInRequest($requestData);

            // Replace original request data dengan data yang sudah di-decode (jika array)
            if (is_array($decodedRequest)) {
                $request->replace($decodedRequest);
            }
        }

        return $next($request);
    }

    /**
     * Recursively decode IDs in request data.
     *
     * @param array $input
     * @return array
     */
    protected function decodeIdsInRequest(array $input)
    {
        foreach ($input as $key => $value) {
            if (is_array($value)) {
                // Jika value adalah array, decode setiap item di dalamnya
                $input[$key] = array_map(function ($item) use ($key) {
                    return is_array($item) ? $this->decodeIdsInRequest($item) : $this->decodeSingleValue($key, $item);
                }, $value);
            } elseif ($this->isJsonString($value)) {
                // Jika value adalah JSON string, decode JSON menjadi array dan proses lebih lanjut
                $decodedJson = json_decode($value, true);
                if (is_array($decodedJson)) {
                    // Jika JSON decoded, lakukan recursive decode jika key mengandung 'id' atau 'ids'
                    $input[$key] = $this->decodeIdsInRequest($decodedJson);
                } else {
                    // Jika bukan array, langsung decode value
                    $input[$key] = $this->decodeSingleValue($key, $value);
                }
            } else {
                // Jika key mengandung 'id', coba decode value
                if ($this->isIdKey($key)) {
                    $input[$key] = $this->decodeSingleValue($key, $value);
                }
            }
        }

        return $input;
    }

    /**
     * Check if key is related to an ID field.
     *
     * @param string $key
     * @return bool
     */
    protected function isIdKey($key)
    {
        return preg_match('/id$/i', $key) || preg_match('/ids$/i', $key);
    }

    /**
     * Decode single ID value.
     *
     * @param string $key
     * @param mixed $value
     * @return mixed
     */
    protected function decodeSingleValue($key, $value)
    {
        // Hanya decode jika key mengandung 'id' atau 'ids'
        if ($this->isIdKey($key) && is_scalar($value)) {
            try {
                return $this->attemptDecode($value);
            } catch (Exception $e) {
                Log::error('Failed to decode ID value:', ['key' => $key, 'value' => $value, 'error' => $e->getMessage()]);
                return $value; // Kembalikan nilai asli jika gagal decode
            }
        }

        return $value; // Jika bukan scalar atau bukan ID, kembalikan nilai asli
    }

    /**
     * Detect if value is JSON string.
     *
     * @param string $value
     * @return bool
     */
    protected function isJsonString($value)
    {
        json_decode($value);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Attempt to decode the hashed ID.
     *
     * @param string $value
     * @return mixed
     */
    protected function attemptDecode($value)
    {
        if (!is_scalar($value)) {
            throw new Exception('Cannot decode non-scalar value: ' . json_encode($value));
        }

        $decoded = $this->decodeId($value);

        if (empty($decoded)) {
            throw new Exception('Decoding failed for value: ' . $value);
        }

        return $decoded[0];
    }

    /**
     * Decode hashed ID using the HashIdService.
     *
     * @param string $hashedId
     * @return array
     */
    protected function decodeId($hashedId)
    {
        return app(HashIdService::class)->decodeId($hashedId);
    }
}
