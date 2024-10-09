<?php

namespace App\Http\Middleware\Custom;

use Closure;
use App\Models\Folder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProtectRootFolder
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
        // Hanya jalankan logika proteksi untuk metode yang memodifikasi data
        if (in_array($request->method(), ['POST', 'PUT', 'DELETE'])) {
            
            // Cek apakah route memiliki prefix 'folder' dan memiliki parameter 'id'
            if ($request->route()->named('folder.*') && $request->route('id')) {
                // Ambil folder ID dari parameter route 'id'
                $folderId = $request->route('id');

                // Cari folder berdasarkan ID
                $folder = Folder::where('id', $folderId)->first();

                // Cek jika folder adalah root folder (parent_id = null)
                if ($folder && $folder->parent_id === null) {
                    // Blokir operasi jika folder yang sedang dimodifikasi adalah root folder itu sendiri
                    return response()->json([
                        'errors' => 'You cannot modify the root folder.'
                    ], Response::HTTP_FORBIDDEN);
                }
            }
        }

        // Lanjutkan request jika bukan operasi modifikasi atau bukan root folder
        return $next($request);
    }
}
