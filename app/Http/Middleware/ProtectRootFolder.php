<?php

namespace App\Http\Middleware;

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
            // Ambil folder ID dari request (misalnya dari route parameter)
            $folderId = $request->route('id');

            // Cari folder tersebut
            $folder = Folder::find($folderId);

            // Cek jika folder adalah root (parent_id = null) dan blokir jika ditemukan
            if ($folder && $folder->parent_id === null) {
                return response()->json([
                    'errors' => 'You cannot modify the root folder.'
                ], Response::HTTP_FORBIDDEN);
            }
        }

        // Lanjutkan request jika bukan operasi modifikasi atau bukan root folder
        return $next($request);
    }
}
