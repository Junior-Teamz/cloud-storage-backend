<?php

namespace App\Http\Middleware\Custom;

use App\Services\HashIdService;
use Closure;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DecodeHashedIdMiddleware
{
    protected $idLength;

    public function __construct()
    {
        // Ambil panjang ID dari env
        $this->idLength = env('SQIDS_LENGTH', 10); // Default ke 10 jika tidak ada SQIDS_LENGTH
    }

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
                        // Membersihkan input dari spasi tidak terlihat
                        $cleanedValue = $this->cleanInput($value);

                        // Cek apakah ID berbentuk integer atau ID yang panjangnya tidak valid
                        if ($this->isInvalidInteger($cleanedValue)) {
                            Log::error('Invalid integer ID detected:', [
                                'key' => $key,
                                'value' => $cleanedValue
                            ]);
                            throw new HttpException(400, 'Invalid ID format for ' . $key);
                        }

                        // Cek apakah panjang ID sesuai dengan yang diharapkan
                        if (!$this->isValidIdLength($cleanedValue)) {
                            Log::error('Invalid ID length:', [
                                'key' => $key,
                                'value' => $cleanedValue,
                                'expected_length' => $this->idLength
                            ]);
                            abort(400, "Invalid ID length.");
                        }

                        // Decode ID yang sudah dibersihkan
                        $decodedId = $this->attemptDecode($cleanedValue);

                        // Set parameter yang sudah di-decode ke dalam route
                        $request->route()->setParameter($key, $decodedId);

                        Log::info('Decoded route parameter ID:', [
                            'key' => $key,
                            'original' => $value,
                            'decoded' => $decodedId
                        ]);
                    } catch (HttpException $e) {
                        // Tangani error decoding yang disebabkan oleh input invalid dari user
                        Log::error('Failed to decode route parameter ID (user error):', [
                            'key' => $key,
                            'value' => $value,
                            'error' => $e->getMessage()
                        ]);
                        throw $e; // Biarkan error HTTP 400 keluar
                    } catch (Exception $e) {
                        // Tangani error sistem (500)
                        Log::critical('Failed to decode route parameter ID (system error):', [
                            'key' => $key,
                            'value' => $value,
                            'error' => $e->getMessage()
                        ]);
                        throw new HttpException(500, 'Internal Server Error while decoding ID for ' . $key);
                    }
                }
            }
        }

        // Cek metode request
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

    protected function cleanInput($input)
    {
        return trim($input);
    }

    protected function isInvalidInteger($value)
    {
        return is_numeric($value) && (int)$value == $value;
    }

    protected function isValidIdLength($value)
    {
        return strlen($value) == $this->idLength;
    }

    protected function attemptDecode($value)
    {
        if (!is_scalar($value)) {
            throw new HttpException(400, 'Cannot decode non-scalar value: ' . json_encode($value));
        }

        try {
            // Decode the ID using the HashIdService
            $decoded = $this->decodeId($value);

            if (empty($decoded)) {
                throw new HttpException(400, 'Decoding failed for value: ' . $value);
            }

            return $decoded[0]; // Mengambil nilai decoded pertama
        } catch (Exception $e) {
            throw new HttpException(500, 'Decoding error due to system failure: ' . $e->getMessage());
        }
    }

    protected function decodeId($hashedId)
    {
        return app(HashIdService::class)->decodeId($hashedId);
    }

    protected function isIdKey($key)
    {
        return preg_match('/id$/i', $key) || preg_match('/ids$/i', $key);
    }

    protected function decodeIdsInRequest(array $input)
    {
        foreach ($input as $key => $value) {
            if (is_array($value)) {
                $input[$key] = $this->flattenArray(array_map(function ($item) use ($key) {
                    return is_array($item) ? $this->decodeIdsInRequest($item) : $this->decodeSingleValue($key, $item);
                }, $value));
            } elseif ($this->isJsonString($value)) {
                $decodedJson = json_decode($value, true);
                if (is_array($decodedJson)) {
                    $input[$key] = $this->decodeIdsInRequest($decodedJson);
                } else {
                    $input[$key] = $this->decodeSingleValue($key, $value);
                }
            } else {
                if ($this->isIdKey($key)) {
                    $input[$key] = $this->decodeSingleValue($key, $value);
                }
            }
        }

        return $input;
    }

    protected function decodeSingleValue($key, $value)
    {
        if ($this->isIdKey($key) && is_scalar($value)) {
            try {
                if (strpos($value, ',') !== false) {
                    $ids = explode(',', $value);
                    $decodedIds = array_map(function ($id) {
                        return (int) $this->attemptDecode(trim($id));
                    }, $ids);

                    return $decodedIds;
                }

                $decodedValue = $this->attemptDecode($value);

                if (is_int($decodedValue)) {
                    return (int) $decodedValue;
                }
            } catch (HttpException $e) {
                // Handle user input errors (400)
                Log::error('Failed to decode ID value (user error):', [
                    'key' => $key,
                    'value' => $value,
                    'error' => $e->getMessage()
                ]);
                throw $e;
            } catch (Exception $e) {
                // Handle system errors (500)
                Log::critical('Failed to decode ID value (system error):', [
                    'key' => $key,
                    'value' => $value,
                    'error' => $e->getMessage()
                ]);
                throw new HttpException(500, 'Internal Server Error while decoding ID for ' . $key);
            }
        }

        return $value;
    }

    protected function isJsonString($value)
    {
        json_decode($value);
        return json_last_error() === JSON_ERROR_NONE;
    }

    protected function flattenArray(array $array)
    {
        $result = [];
        array_walk_recursive($array, function ($a) use (&$result) {
            $result[] = $a;
        });
        return $result;
    }
}
