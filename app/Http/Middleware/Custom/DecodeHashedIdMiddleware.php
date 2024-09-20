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

        // Decode route parameters
        foreach ($routeParameters as $key => $value) {
            if (strpos(strtolower($key), 'id') !== false) {
                try {
                    if (is_array($value)) {
                        // Jika parameter adalah array, decode setiap elemen array
                        $decodedIds = array_map(function ($item) {
                            return $this->tryDecodeId($item);
                        }, $value);
                        $request->route()->setParameter($key, $decodedIds); // Set array hasil decode
                    } else {
                        // Jika parameter bukan array, decode langsung
                        $decodedId = $this->tryDecodeId($value);
                        $request->route()->setParameter($key, $decodedId);
                    }
                } catch (\Exception $e) {
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
                if (strpos(strtolower($key), 'id') !== false) {
                    // Jika value adalah array dan key mengandung 'id', decode setiap item
                    $input[$key] = array_map(function ($item) {
                        return $this->tryDecodeId($item);
                    }, $value);
                } else {
                    // Jika value adalah array, tapi bukan ID, lakukan rekursi
                    $input[$key] = $this->decodeIdsInRequest($value);
                }
            } else {
                if (strpos(strtolower($key), 'id') !== false) {
                    $input[$key] = $this->tryDecodeId($value);
                }
            }
        }

        return $input;
    }

    protected function tryDecodeId($hashedId)
    {
        // Fungsi untuk mencoba decode ID hanya jika itu bukan numeric dan string
        if (!is_numeric($hashedId) && is_string($hashedId)) {
            $decodedId = $this->decodeId($hashedId);
            if (!empty($decodedId)) {
                return $decodedId[0]; // Ambil ID yang di-decode jika valid
            }
        }

        return $hashedId; // Jika tidak bisa di-decode, kembalikan nilai aslinya
    }

    protected function decodeId($hashedId)
    {
        // Decode hashed ID menggunakan HashIdService
        return app(HashIdService::class)->decodeId($hashedId);
    }
}
