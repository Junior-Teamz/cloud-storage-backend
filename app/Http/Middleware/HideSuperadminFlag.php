<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HideSuperadminFlag
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Check if response is JSON
        if ($response instanceof \Illuminate\Http\JsonResponse) {
            $data = $response->getData(true);

            // Check if the is_superadmin key exists and remove it
            if (isset($data['data']['is_superadmin'])) {
                unset($data['data']['is_superadmin']);
            }

            // Set the modified data back into the response
            $response->setData($data);
        }

        return $response;
    }
}
