<?php

namespace App\Http\Middleware\Custom;

use App\Services\HashIdService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EncodeIdMiddleware
{
    public function handle($request, Closure $next)
    {
        $response = $next($request);

        // Cek apakah response adalah instance dari JsonResponse
        if ($response instanceof \Illuminate\Http\JsonResponse) {
            // Ambil data dari response
            $data = $response->getData(true);

            // Rekursif encode ID di dalam data
            $encodedData = $this->encodeIdsInResponse($data);

            // Replace data asli dengan data yang sudah di-encode
            $response->setData($encodedData);
        }

        return $response;
    }

    // Fungsi rekursif untuk meng-encode ID di dalam response
    protected function encodeIdsInResponse($input)
    {
        foreach ($input as $key => $value) {
            // Jika nilai adalah array atau object, rekursif
            if (is_array($value)) {
                $input[$key] = $this->encodeIdsInResponse($value);
            } else {
                // Jika key mengandung 'id' dan value numeric, encode
                if (strpos($key, 'id') !== false && is_numeric($value)) {
                    $input[$key] = $this->encodeId($value);
                }
            }
        }

        return $input;
    }

    // Encode plain ID menjadi hashed ID
    protected function encodeId($id)
    {
        return app(HashIdService::class)->encodeId($id);
    }
}

