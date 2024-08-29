<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RemoveNanoidFromResponse
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Memproses request terlebih dahulu
        $response = $next($request);

        // Pastikan bahwa respons adalah JSON
        if ($response->headers->get('Content-Type') === 'application/json') {
            // Decode JSON response untuk memanipulasi data
            $data = json_decode($response->getContent(), true);

            // Fungsi rekursif untuk menghapus atribut 'nanoid'
            $data = $this->removeNanoid($data);

            // Set ulang isi respons
            $response->setContent(json_encode($data));
        }

        return $response;
    }

    /**
     * Fungsi untuk menghapus properti 'nanoid' dari array secara rekursif.
     */
    private function removeNanoid($data)
    {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                // Jika menemukan key 'nanoid', hapus dari array
                if ($key === 'nanoid') {
                    unset($data[$key]);
                } elseif (is_array($value)) {
                    // Jika value adalah array, lakukan proses rekursif
                    $data[$key] = $this->removeNanoid($value);
                }
            }
        }

        return $data;
    }
}