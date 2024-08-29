<?php

namespace App\Http\Middleware;

use Closure;
use Hashids\Hashids;
use Illuminate\Support\Facades\Route;

class HashIdMiddleware
{
    protected $hashids;

    public function __construct()
    {
        $this->hashids = new Hashids('', 10);
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $currentRoutePrefix = Route::current()->getPrefix();

        // Decode the IDs in the request
        if (strpos($currentRoutePrefix, 'user') !== false) {
            // Decode only 'folder_root_id', 'folder_id', and 'file_id'
            $this->decodeSpecificIds($request, ['folder_root_id', 'folder_id', 'file_id']);
        } elseif (strpos($currentRoutePrefix, 'folder') !== false || strpos($currentRoutePrefix, 'file') !== false) {
            // Decode all IDs except 'user_id'
            $this->decodeExcept($request, ['user_id']);
        }

        $response = $next($request);

        // Encode the IDs in the response
        if ($response->isSuccessful() && $response->headers->get('Content-Type') === 'application/json') {
            $data = json_decode($response->getContent(), true);

            if (strpos($currentRoutePrefix, 'user') !== false) {
                $data = $this->hashSpecificIds($data, ['folder_root_id', 'folder_id', 'file_id']);
            } elseif (strpos($currentRoutePrefix, 'folder') !== false || strpos($currentRoutePrefix, 'file') !== false) {
                $data = $this->hashExcept($data, ['user_id']);
            }

            $response->setContent(json_encode($data));
        }

        return $response;
    }

    /**
     * Hash semua ID kecuali ID yang dikecualikan.
     *
     * @param  array  $data
     * @param  array  $except
     * @return array
     */
    protected function hashExcept(array $data, array $except)
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->hashExcept($value, $except);
            } elseif ((str_ends_with($key, '_id') || str_ends_with($key, 'id')) && !in_array($key, $except) && $value !== null) {
                $data[$key] = $this->hashids->encode($value);
            }
        }

        return $data;
    }

    /**
     * Hash hanya ID yang ditentukan.
     *
     * @param  array  $data
     * @param  array  $only
     * @return array
     */
    protected function hashSpecificIds(array $data, array $only)
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->hashSpecificIds($value, $only);
            } elseif (in_array($key, $only) && $value !== null) {
                $data[$key] = $this->hashids->encode($value);
            }
        }

        return $data;
    }

    /**
     * Decode semua ID kecuali ID yang dikecualikan dari request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  array  $except
     * @return void
     */
    protected function decodeExcept($request, array $except)
    {
        foreach ($request->all() as $key => $value) {
            if (str_ends_with($key, '_id') && !in_array($key, $except) && $value !== null) {
                $decoded = $this->hashids->decode($value);
                if (!empty($decoded)) {
                    $request->merge([$key => $decoded[0]]);
                }
            }
        }
    }

    /**
     * Decode hanya ID yang ditentukan dari request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  array  $only
     * @return void
     */
    protected function decodeSpecificIds($request, array $only)
    {
        foreach ($request->all() as $key => $value) {
            if (in_array($key, $only) && $value !== null) {
                $decoded = $this->hashids->decode($value);
                if (!empty($decoded)) {
                    $request->merge([$key => $decoded[0]]);
                }
            }
        }
    }
}
