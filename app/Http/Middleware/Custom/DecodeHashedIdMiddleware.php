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
        // Pastikan request memiliki route yang valid
        if ($request->route()) {
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
        }

        $requestMethod = $request->method();

        Log::info('Incoming Request Method: ' . $requestMethod);

        // Cek apakah request memiliki data yang dapat diakses (JSON atau form-data)
        if (in_array($requestMethod, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
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
     * Recursively decode IDs in request data and ensure they are integers.
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
     * Decode single ID value and ensure it is returned as an integer or array of integers.
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
                // Cek jika value berisi beberapa ID yang dipisahkan oleh koma
                if (strpos($value, ',') !== false) {
                    $ids = explode(',', $value);
                    return array_map(function($id) {
                        return (int) $this->attemptDecode($id);
                    }, $ids);
                }

                // Jika hanya satu ID, lakukan decode langsung
                $decodedValue = $this->attemptDecode($value);

                // Convert decoded value to integer
                if (is_numeric($decodedValue)) {
                    return (int) $decodedValue;
                }
            } catch (Exception $e) {
                Log::error('Failed to decode ID value:', [
                    'key' => $key, 
                    'value' => $value, 
                    'error' => $e->getMessage(),
                    'context' => 'Decoding process for key containing ID'
                ]);
            }
        }

        return $value; // Kembalikan nilai asli jika gagal decode
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

        return $decoded[0]; // Mengambil nilai decoded pertama
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
}
