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
        $this->idLength = env('SQIDS_LENGTH', 10); // Default ke 10 jika tidak ada SQIDS_LENGTH
    }

    public function handle($request, Closure $next)
    {
        if ($request->route()) {
            // Ambil semua parameter route
            $routeParameters = $request->route()->parameters();

            // Decode route parameters
            foreach ($routeParameters as $key => $value) {
                if ($this->isIdKey($key)) {
                    try {
                        // Membersihkan spasi yang tidak terlihat
                        $cleanedValue = $this->cleanInput($value);

                        // Cek apakah ID berbentuk integer atau tidak sesuai format hash
                        if ($this->isInvalidInteger($cleanedValue)) {
                            Log::error('Invalid integer ID detected:', [
                                'key' => $key,
                                'value' => $cleanedValue
                            ]);
                            return response()->json(['error' => 'Invalid ID detected.'], 400);
                        }

                        // Cek validitas panjang ID sebelum decoding
                        if (!$this->isValidIdLength($cleanedValue)) {
                            Log::error('Invalid ID length:', [
                                'key' => $key,
                                'value' => $cleanedValue,
                                'expected_length' => $this->idLength
                            ]);
                            return response()->json(['error' => 'Invalid ID detected.'], 400);
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
                        // Berhenti di sini dan kembalikan response JSON error
                        return response()->json(['error' => 'Failed to decode ID for ' . $key], 400);
                    }
                }
            }
        }

        // Melanjutkan request hanya jika tidak ada error dalam ID decoding
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
}
