<?php

namespace App\Http\Middleware\Custom;

use Closure;
use Illuminate\Http\Request;

class PreventRootTagModification
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
        // Tag Root memiliki ID 1
        $rootTagId = 1;

        // Jika tag yang dimodifikasi adalah Root
        if ($request->route('tagId') == $rootTagId || in_array($rootTagId, $request->get('tag_ids', []))) {
            // Cek apakah ada operasi update, delete, atau menambahkan tag Root ke folder atau file
            if ($request->isMethod('put') || $request->isMethod('patch') || $request->isMethod('delete')) {
                return response()->json([
                    'message' => 'Tag Root tidak dapat diubah atau dihapus.'
                ], 403);
            }

            // Cek apakah ada operasi penambahan tag Root ke folder atau file
            if ($request->isMethod('post')) {
                if ($request->routeIs('folder.*') || $request->routeIs('file.*')) {
                    return response()->json([
                        'message' => 'Tag Root tidak dapat ditambahkan ke folder atau file.'
                    ], 403);
                }
            }
        }

        return $next($request);
    }
}
