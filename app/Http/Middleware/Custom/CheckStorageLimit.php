<?php

namespace App\Http\Middleware\Custom;

use App\Models\Folder;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * This middleware checks if the authenticated user has exceeded their storage limit.
 * 
 * It calculates the total size of the user's root folder and compares it to the defined storage limit.
 * If the limit is exceeded, a 403 Forbidden response is returned.
 */
class CheckStorageLimit
{
    protected $storageLimit;

    public function __construct()
    {
        // Mengambil nilai dari config dan mengubahnya ke byte (GB -> Byte)
        $this->storageLimit = config('storage-config.storage_limit') * 1024 * 1024 * 1024;
    }

    /**
     * Calculate the total size of a folder, including all files and subfolders.
     *
     * This function recursively iterates through all files and subfolders within
     * the given folder and calculates the total size in bytes.
     *
     * @param Folder $folder The folder object to calculate the size of.
     * @return int The total size of the folder in bytes.
     */
    private function calculateFolderSize(Folder $folder)
    {
        $totalSize = 0;

        // Hitung ukuran semua file di folder
        foreach ($folder->files as $file) {
            $totalSize += $file->size; // Asumsi kolom 'size' ada di model File
        }

        // Rekursif menghitung ukuran semua subfolder
        foreach ($folder->subfolders as $subfolder) {
            $totalSize += $this->calculateFolderSize($subfolder);
        }

        return $totalSize;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();

        if ($user) {
            // Dapatkan folder root milik user
            $rootFolder = Folder::where('user_id', $user->id)->whereNull('parent_id')->first();

            if ($rootFolder) {
                // Hitung total penyimpanan yang digunakan user
                $totalUsedStorage = $this->calculateFolderSize($rootFolder);

                // Jika total penyimpanan melebihi batas yang ditentukan
                if ($totalUsedStorage >= $this->storageLimit) {
                    return response()->json([
                        'errors' => 'You have exceeded your storage limit of ' . ($this->storageLimit / (1024 * 1024 * 1024)) . 'GB.',
                    ], 403); // Kirimkan respons forbidden
                }
            }
        }

        return $next($request);
    }
}
