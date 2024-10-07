<?php

namespace App\Services;

use App\Models\File;
use App\Models\Folder;
use App\Models\UserFilePermission;
use App\Models\UserFolderPermission;
use Illuminate\Support\Facades\Auth;

class CheckFilePermissionService
{
    /**
     * Check the user permission for file
     */
    public function checkPermissionFile($fileId, $actions)
    {
        $user = Auth::user();
        if(is_int($fileId)){
            $file = File::find($fileId);
        } else {
            $file = File::where('uuid', $fileId)->first();
        }

        // If file not found, return 404 error and stop the process
        if (!$file) {
            return response()->json([
                'errors' => 'File with File ID you entered not found, Please check your File ID and try again.'
            ], 404); // Setting status code to 404 Not Found
        }

        // Step 1: Check if the file belongs to the logged-in user
        if ($file->user_id === $user->id) {
            return true; // The owner has all permissions
        }

        // Step 2: Check if user is admin with SUPERADMIN privilege
        if ($user->hasRole('admin') && $user->is_superadmin == 1) {
            return true;
        } else if ($user->hasRole('admin')) {
            return false; // Regular admin without SUPERADMIN privilege
        }

        // Step 3: Check if user has explicit permission to the file
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

        // Step 4: Check permission for folder where file is located, including parent folders
        return $this->checkPermissionFolderRecursive($file->folder_id, $actions);
    }

    /**
     * Recursive function to check permission on parent folders
     */
    private function checkPermissionFolderRecursive($folderId, $actions)
    {
        $user = Auth::user();
        $folder = Folder::where('uuid', $folderId)->first();

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
