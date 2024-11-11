<?php

namespace App\Http\Middleware\Custom;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * This middleware removes the 'photo_profile_path' attribute from JSON responses.
 * 
 * It intercepts the response after the request is handled and checks if it's a JSON response.
 * If it is, it recursively removes any 'photo_profile_path' keys from the response data
 * before sending it back to the client.
 */
class HidePhotoProfilePath
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

            // Fungsi rekursif untuk menghapus atribut 'photo_profile_path'
            $data = $this->removePhotoProfilePath($data);

            // Set ulang isi respons
            $response->setContent(json_encode($data));
        }

        return $response;
    }

    /**
     * Fungsi untuk menghapus properti 'photo_profile_path' dari array secara rekursif.
     */
    private function removePhotoProfilePath($data)
    {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                // Jika menemukan key 'photo_profile_path', hapus dari array
                if ($key === 'photo_profile_path') {
                    unset($data[$key]);
                } elseif (is_array($value)) {
                    // Jika value adalah array, lakukan proses rekursif
                    $data[$key] = $this->removePhotoProfilePath($value);
                }
            }
        }

        return $data;
    }
}
