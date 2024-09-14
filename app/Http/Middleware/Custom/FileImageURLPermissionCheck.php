<?php

namespace App\Http\Middleware\Custom;

use App\Models\File;
use App\Models\Folder;
use App\Models\UserFilePermission;
use App\Models\UserFolderPermission;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Sqids\Sqids;
use Symfony\Component\HttpFoundation\Response;

class FileImageURLPermissionCheck
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string  $permission
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        // Ambil pengguna yang sedang login
        $user = auth()->guard('api')->user();

        // Jika pengguna belum login, tolak akses
        if (!$user) {
            return response()->json(['errors' => 'You cannot access this URL. Please login first.'], 401);
        }

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
        $user = Auth::user();
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
        $user = Auth::user();
        $folder = Folder::find($folderId);

        // If folder not found, return false
        if (!$folder) {
            return false;
        }

        // Step 1: Check if the folder belongs to the logged-in user
        if ($folder->user_id === $user->id) {
            return true; // The owner has all permissions
        }

        // Step 2: Check if user is admin with SUPERADMIN privilege
        if ($user->hasRole('admin') && $user->is_superadmin == 1) {
            return true;
        } else if ($user->hasRole('admin')) {
            return false;
        }

        // Step 3: Check if user has explicit permission to the folder
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

        // Step 4: Check if the folder has a parent folder
        if ($folder->parent_id) {
            return $this->checkPermissionFolderRecursive($folder->parent_id, $actions); // Recursive call to check parent folder
        }

        // Return false if no permissions are found
        return false;
    }
}
