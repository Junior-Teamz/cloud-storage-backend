<?php

namespace App\Http\Middleware\Custom;

use App\Services\HashIdService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class DecodeHashedIdMiddleware
{
    public function handle($request, Closure $next)
    {
        // Ambil semua parameter route
        $routeParameters = $request->route()->parameters();

        foreach ($routeParameters as $key => $value) {
            // Cek apakah key (parameter) mengandung 'id' (bisa folderId, userId, fileId, dll.)
            if (strpos(strtolower($key), 'id') !== false) {
                $hashedId = $value;

                try {
                    // Decode hashed id
                    $decodedId = $this->decodeId($hashedId);

                    if (empty($decodedId)) {
                        // Jika hasil decode kosong, return error response
                        return response()->json(['error' => 'Invalid ID'], 400);
                    }

                    // Ganti hashed ID dengan decoded ID di route parameter
                    $request->route()->setParameter($key, $decodedId[0]);
                    
                    // Log untuk debugging
                    Log::info('Decoded ID:', ['key' => $key, 'original' => $hashedId, 'decoded' => $decodedId[0]]);
                } catch (\Exception $e) {
                    // Tangkap error jika gagal decode dan return response error
                    return response()->json(['error' => 'Failed to decode ID for ' . $key], 400);
                }
            }
        }

        // Rekursif decode semua ID yang ada dalam request
        $decodedRequest = $this->decodeIdsInRequest($request->all());

        // Replace original request data dengan data yang sudah di-decode
        $request->replace($decodedRequest);

        return $next($request);
    }

    // Fungsi rekursif untuk mendecode ID di dalam array dan nested array
    protected function decodeIdsInRequest($input)
    {
        foreach ($input as $key => $value) {
            // Jika nilai adalah array atau object, rekursif
            if (is_array($value)) {
                $input[$key] = $this->decodeIdsInRequest($value);
            } else {
                // Jika key mengandung 'id' dan value bukan numeric, coba decode
                if (strpos($key, 'id') !== false && !is_numeric($value)) {
                    $input[$key] = $this->decodeId($value);
                } elseif (!is_numeric($value)) {
                    $input[$key] = $this->decodeId($value);
                }
            }
        }

        return $input;
    }

    // Decode hashed ID menggunakan HashIdService
    protected function decodeId($hashedId)
    {
        return app(HashIdService::class)->decodeId($hashedId);
    }
}
