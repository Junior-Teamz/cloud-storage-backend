<?php

namespace App\Http\Middleware\Custom;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class CorsCustom
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
        // List of allowed origins (you can modify this as needed)
        $allowedOrigins = config('frontend.urls'); // Sesuaikan dengan origin yang kamu izinkan

        $origin = $request->headers->get('Origin');

        // Tambahkan log untuk memverifikasi request yang masuk
        Log::info('CORS Middleware: Handling Request from Origin: ' . $request->headers->get('Origin'));

        // Cek apakah origin yang datang ada di dalam daftar allowed origins
        if (in_array($origin, $allowedOrigins)) {

            Log::info('CORS Middleware: Origin Allowed: ' . $origin);

            $headers = [
                'Access-Control-Allow-Origin' => $origin,
                'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS',
                'Access-Control-Allow-Headers' => 'Accept, Content-Type, X-Auth-Token, Origin, Authorization',
                'Access-Control-Allow-Credentials' => 'true'
            ];

            // Untuk preflight request (OPTIONS), langsung return response dengan header yang tepat
            if ($request->getMethod() === 'OPTIONS') {
                return response()->json('CORS Preflight OK', 200, $headers);
            }

            // Lanjutkan request dengan menambahkan header ke dalam response
            $response = $next($request);

            foreach ($headers as $key => $value) {
                $response->header($key, $value);
            }

            // Tambahkan log untuk memverifikasi header yang ditambahkan
            Log::info('CORS Middleware: Adding headers to the response: ' . $response);

            return $response;
        }

        // Tambahkan log untuk memverifikasi header yang ditambahkan
        Log::info('CORS Middleware: Origin not allowed: ' . $origin);

        // Jika origin tidak diizinkan, lanjutkan request tanpa header CORS
        return $next($request);
    }
}
