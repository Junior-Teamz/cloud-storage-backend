<?php

namespace App\Http\Middleware\Custom;

use Closure;

class ReplaceIdWithUuid
{
    public function handle($request, Closure $next)
    {
        $response = $next($request);

        // Cek apakah response adalah instance dari JsonResponse
        if ($response instanceof \Illuminate\Http\JsonResponse) {
            // Ambil data dari response
            $data = $response->getData(true);

            // Rekursif mengganti 'id' dengan 'uuid' dan menghapus field 'uuid'
            $modifiedData = $this->replaceIdWithUuid($data);

            // Replace data asli dengan data yang sudah dimodifikasi
            $response->setData($modifiedData);
        }

        return $response;
    }

    // Fungsi rekursif untuk mengganti 'id' dengan 'uuid' dan menghapus field 'uuid'
    protected function replaceIdWithUuid($input)
    {
        foreach ($input as $key => $value) {
            // Jika nilai adalah array, rekursif
            if (is_array($value)) {
                $input[$key] = $this->replaceIdWithUuid($value);
            } elseif ($key === 'uuid') {
                // Ganti 'id' dengan 'uuid', kemudian hapus 'uuid' dari response
                $input['id'] = $value;
                unset($input[$key]);
            }
        }

        return $input;
    }
}
