<?php

namespace App\Http\Middleware\Custom;

use App\Models\File;
use App\Models\Folder;
use App\Models\UserFilePermission;
use App\Models\UserFolderPermission;
use Closure;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use Tymon\JWTAuth\Exceptions\JWTException;
use Sqids\Sqids;
use Symfony\Component\HttpFoundation\Response;

class FileImageURLPermissionCheck
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        // // Ambil header Authorization secara manual
        // $authorizationHeader = $request->header('Authorization');

        // if (!$authorizationHeader) {
        //     return response()->json(['errors' => 'Authorization header not found. You probably not login first.'], 401);
        // }

        // // Pastikan header Authorization memiliki format Bearer {token}
        // if (!preg_match('/Bearer\s(\S+)/', $authorizationHeader, $matches)) {
        //     return response()->json(['errors' => 'Invalid Authorization format'], 401);
        // }

        // // Ambil token JWT dari header
        // $token = $matches[1];

        try {
            // Set token secara manual ke JWTAuth dan coba autentikasi
            JWTAuth::parseToken()->authenticate();

        } catch (Exception $e) {
            if ($e instanceof \Tymon\JWTAuth\Exceptions\TokenInvalidException) {
                return response()->json([
                    'token_valid' => false,
                    'errors' => 'Token is Invalid'
                ], 401); // HTTP 401 Unauthorized
            } else if ($e instanceof \Tymon\JWTAuth\Exceptions\TokenExpiredException) {
                return response()->json([
                    'token_valid' => false,
                    'errors' => 'Token has Expired'
                ], 401); // HTTP 401 Unauthorized
            } else if ($e instanceof \Tymon\JWTAuth\Exceptions\TokenBlacklistedException) {
                return response()->json([
                    'token_valid' => false,
                    'errors' => 'Token is Blacklisted'
                ], 403); // HTTP 403 Forbidden
            } else {
                Log::error('Terjadi kesalahan ketika memeriksa token: ' . $e->getMessage());
                return response()->json([
                    'errors' => 'Terjadi kesalahan, harap coba lagi nanti'
                ], 500); // HTTP 500 Internal Server Error
            }
        }

        // Setelah autentikasi berhasil, lanjutkan pengecekan izin file
        // Ambil file ID dari parameter route atau request
        $fileId = $request->route('hashedId');

        // Decode hashed ID ke file ID menggunakan Sqids
        $sqids = new Sqids(env('SQIDS_ALPHABET'), 20);
        $fileIdArray = $sqids->decode($fileId);

        // Jika hashed ID tidak valid atau tidak dapat didecode
        if (empty($fileIdArray) || !isset($fileIdArray[0])) {
            return response()->json(['errors' => 'Invalid or non-existent file'], 404);
        }

        $fileId = $fileIdArray[0];

        // Periksa perizinan menggunakan fungsi checkPermissionFile
        if (!$this->checkPermissionFile($fileId, ['read'])) {
            return response()->json(['errors' => 'You do not have permission to access this URL.'], 403);
        }

        return $next($request);
    }

    /**
     * Check the user's permission for the given file.
     */
    private function checkPermissionFile($fileId, $actions)
    {
        $user = JWTAuth::user(); // Mengambil user dari JWT

        $file = File::find($fileId);

        // Jika file tidak ditemukan, kembalikan 404
        if (!$file) {
            return false; // File tidak ditemukan
        }

        // Step 1: Periksa apakah file milik pengguna
        if ($file->user_id === $user->id) {
            return true; // Pemilik file memiliki semua perizinan
        }

        // Step 2: Periksa apakah user adalah SUPERADMIN
        if ($user->hasRole('admin') && $user->is_superadmin == 1) {
            return true;
        } else if ($user->hasRole('admin')) {
            return false; // Admin tanpa SUPERADMIN tidak memiliki izin khusus
        }

        // Step 3: Periksa apakah user memiliki izin khusus untuk file tersebut
        $userFilePermission = UserFilePermission::where('user_id', $user->id)->where('file_id', $file->id)->first();
        if ($userFilePermission) {
            $checkPermission = $userFilePermission->permissions;

            // Jika $actions adalah string, ubah menjadi array
            if (!is_array($actions)) {
                $actions = [$actions];
            }

            // Periksa apakah izin pengguna ada di array $actions
            if (in_array($checkPermission, $actions)) {
                return true;
            }
        }

        // Step 4: Periksa perizinan untuk folder induk secara rekursif
        return $this->checkPermissionFolderRecursive($file->folder_id, $actions);
    }

    /**
     * Recursively check folder permissions.
     */
    private function checkPermissionFolderRecursive($folderId, $actions)
    {
        $user = JWTAuth::user(); // Mengambil user dari JWT
        $folder = Folder::find($folderId);

        // Jika folder tidak ditemukan, kembalikan false
        if (!$folder) {
            return false;
        }

        // Step 1: Periksa apakah folder milik pengguna
        if ($folder->user_id === $user->id) {
            return true; // Pemilik folder memiliki semua perizinan
        }

        // Step 2: Periksa apakah user adalah admin dengan SUPERADMIN
        if ($user->hasRole('admin') && $user->is_superadmin == 1) {
            return true;
        } else if ($user->hasRole('admin')) {
            return false;
        }

        // Step 3: Periksa apakah user memiliki izin eksplisit untuk folder tersebut
        $userFolderPermission = UserFolderPermission::where('user_id', $user->id)->where('folder_id', $folder->id)->first();
        if ($userFolderPermission) {
            $checkPermission = $userFolderPermission->permissions;

            // Jika $actions adalah string, ubah menjadi array
            if (!is_array($actions)) {
                $actions = [$actions];
            }

            // Periksa apakah izin pengguna ada di array $actions
            if (in_array($checkPermission, $actions)) {
                return true;
            }
        }

        // Step 4: Periksa apakah folder memiliki folder induk
        if ($folder->parent_id) {
            return $this->checkPermissionFolderRecursive($folder->parent_id, $actions); // Panggil rekursif untuk folder induk
        }

        // Kembalikan false jika tidak ada perizinan yang ditemukan
        return false;
    }
}
