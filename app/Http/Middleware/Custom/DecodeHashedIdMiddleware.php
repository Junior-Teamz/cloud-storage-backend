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
                $hashedId = $value;

                try {
                    if (!is_array($hashedId)) { // Pastikan hashedId bukan array
                        $decodedId = $this->decodeId($hashedId);
                        if (empty($decodedId)) {
                            return response()->json(['error' => 'Invalid ID'], 400);
                        }

                        $request->route()->setParameter($key, $decodedId[0]);
                        Log::info('Decoded route parameter ID:', ['key' => $key, 'original' => $hashedId, 'decoded' => $decodedId[0]]);
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
                    $input[$key] = array_map(function ($item) {
                        if (is_string($item)) { // Hanya decode jika item adalah string
                            $decodedItem = $this->decodeId($item);
                            return !empty($decodedItem) ? $decodedItem[0] : $item;
                        }
                        return $item;
                    }, $value);
                } else {
                    $input[$key] = $this->decodeIdsInRequest($value);
                }
            } else {
                if (strpos(strtolower($key), 'id') !== false && is_string($value)) {
                    $decodedValue = $this->decodeId($value);
                    if (!empty($decodedValue)) {
                        $input[$key] = $decodedValue[0];
                    }
                }
            }
        }

        return $input;
    }

    protected function decodeId($hashedId)
    {
        return app(HashIdService::class)->decodeId($hashedId);
    }
}
