<?php

namespace App\Http\Middleware\Custom;

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

            // Recursively remove 'is_superadmin' from the response data
            $data = $this->removeSuperadminFlag($data);

            // Set the modified data back into the response
            $response->setData($data);
        }

        return $response;
    }

    /**
     * Recursively remove 'is_superadmin' key from an array or object.
     *
     * @param  array|object  $data
     * @return array|object
     */
    protected function removeSuperadminFlag($data)
    {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                // If 'is_superadmin' key is found, remove it
                if ($key === 'is_superadmin') {
                    unset($data[$key]);
                } else {
                    // Recursively process nested arrays and objects
                    $data[$key] = $this->removeSuperadminFlag($value);
                }
            }
        } elseif (is_object($data)) {
            foreach ($data as $key => $value) {
                // If 'is_superadmin' property is found, unset it
                if ($key === 'is_superadmin') {
                    unset($data->$key);
                } else {
                    // Recursively process nested arrays and objects
                    $data->$key = $this->removeSuperadminFlag($value);
                }
            }
        }

        return $data;
    }
}
