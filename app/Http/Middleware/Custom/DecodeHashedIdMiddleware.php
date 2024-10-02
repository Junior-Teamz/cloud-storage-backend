<?php

namespace App\Http\Middleware\Custom;

use App\Services\HashIdService;
use Closure;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DecodeHashedIdMiddleware
{
    protected $idLength;

    public function __construct()
    {
        // Ambil panjang ID dari env
        $this->idLength = env('SQIDS_LENGTH', 8); // Default ke 8 jika tidak ada SQIDS_LENGTH
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
                            return response()->json(['error' => 'Invalid ID format for ' . $key], 400);
                        }

                        // Cek apakah panjang ID sesuai dengan yang diharapkan
                        if (!$this->isValidIdLength($cleanedValue)) {
                            Log::error('Invalid ID length:', [
                                'key' => $key,
                                'value' => $cleanedValue,
                                'expected_length' => $this->idLength
                            ]);
                            return response()->json(['error' => 'Invalid ID length for ' . $key], 400);
                        }

                        // Decode ID yang sudah dibersihkan
                        $decodedId = $this->attemptDecode($cleanedValue);

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
     * Membersihkan input dari spasi yang tidak terlihat.
     *
     * @param string $input
     * @return string
     */
    protected function cleanInput($input)
    {
        return trim($input);
    }

    /**
     * Cek apakah ID berbentuk integer dan oleh karena itu harus dianggap tidak valid.
     *
     * @param string $value
     * @return bool
     */
    protected function isInvalidInteger($value)
    {
        // Cek apakah nilai merupakan integer murni
        return is_numeric($value) && (int)$value == $value;
    }

    /**
     * Cek apakah panjang ID valid sesuai dengan SQIDS_LENGTH.
     *
     * @param string $value
     * @return bool
     */
    protected function isValidIdLength($value)
    {
        return strlen($value) == $this->idLength;
    }

    /**
     * Attempt to decode the hashed ID.
     *
     * @param string $value
     * @return mixed
     * @throws Exception
     */
    protected function attemptDecode($value)
    {
        if (!is_scalar($value)) {
            throw new Exception('Cannot decode non-scalar value: ' . json_encode($value));
        }

        // Decode the ID using the HashIdService
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
     * Recursively decode IDs in request data and ensure they are integers.
     *
     * @param array $input
     * @return array
     */
    protected function decodeIdsInRequest(array $input)
    {
        foreach ($input as $key => $value) {
            if (is_array($value)) {
                // Jika value adalah array, pastikan array hasil decode tetap "flat"
                $input[$key] = $this->flattenArray(array_map(function ($item) use ($key) {
                    return is_array($item) ? $this->decodeIdsInRequest($item) : $this->decodeSingleValue($key, $item);
                }, $value));
            } elseif ($this->isJsonString($value)) {
                // Jika value adalah JSON string, decode JSON menjadi array dan proses lebih lanjut
                $decodedJson = json_decode($value, true);
                if (is_array($decodedJson)) {
                    $input[$key] = $this->decodeIdsInRequest($decodedJson);
                } else {
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
     * Decode single ID value and ensure it is returned as an integer or array of integers.
     *
     * @param string $key
     * @param mixed $value
     * @return mixed
     */
    protected function decodeSingleValue($key, $value)
    {
        if ($this->isIdKey($key) && is_scalar($value)) {
            try {
                if (strpos($value, ',') !== false) {
                    // Jika terdapat beberapa ID yang dipisahkan oleh koma, decode tiap ID
                    $ids = explode(',', $value);
                    $decodedIds = array_map(function ($id) {
                        return (int) $this->attemptDecode(trim($id)); // trim untuk menghilangkan spasi ekstra
                    }, $ids);

                    return $decodedIds; // Mengembalikan array dengan ID yang terdecode
                }

                // Jika hanya satu ID, decode langsung
                $decodedValue = $this->attemptDecode($value);

                if (is_int($decodedValue)) {
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
     * Flatten a nested array.
     *
     * @param array $array
     * @return array
     */
    protected function flattenArray(array $array)
    {
        $result = [];
        array_walk_recursive($array, function ($a) use (&$result) {
            $result[] = $a;
        });
        return $result;
    }
}
