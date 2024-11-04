<?php

namespace App\Http\Middleware\Custom;

use App\Models\Tags;
use Closure;
use Illuminate\Http\Request;

/**
 * This middleware prevents modification of the "Root" tag.
 * 
 * It checks if the request involves the "Root" tag (identified by the first created tag ID)
 * and blocks any attempts to update, delete, or add it to folders or files or news.
 */
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
        // Ambil UUID tag Root (tag pertama yang dibuat)
        $rootTagId = Tags::orderBy('created_at')->first()->id;

        // Jika tag yang dimodifikasi adalah Root
        if ($request->route('tagId') == $rootTagId || in_array($rootTagId, $request->get('tag_ids', []))) {
            // Cek apakah ada operasi update, delete, atau menambahkan tag Root ke folder atau file
            if ($request->isMethod('put') || $request->isMethod('patch') || $request->isMethod('delete')) {
                return response()->json([
                    'message' => 'Tag Root tidak dapat diubah atau dihapus.'
                ], 403);
            }

            // Cek apakah ada operasi penambahan tag Root ke folder atau file atau news
            if ($request->isMethod('post')) {
                if ($request->routeIs('folder.*') || $request->routeIs('file.*') || $request->routeIs('news.*')) {
                    return response()->json([
                        'message' => 'Tag Root tidak dapat ditambahkan ke folder atau file.'
                    ], 403);
                }
            }
        }

        return $next($request);
    }
}
