<?php

namespace App\Services;

use App\Models\Folder;
use App\Models\UserFolderPermission;
use Illuminate\Support\Facades\Auth;

class CheckFolderPermissionService
{
    /**
     * Check the user permission
     */
    public function checkPermissionFolder($folderId, $actions)
    {
        $user = Auth::user();
        if(is_int($folderId)){
            $folder = Folder::find($folderId);
        } else {
            $folder = Folder::where('uuid', $folderId)->first();
        }

        // If folder not found, return 404 error and stop the process
        if (!$folder) {
            return response()->json([
                'errors' => 'Folder with Folder ID you entered not found, Please check your Folder ID and try again.'
            ], 404); // Setting status code to 404 Not Found
        }

        // Step 1: Check if the folder belongs to the logged-in user
        if ($folder->user_id === $user->id) {
            return true; // The owner has all permissions
        }

        // Step 2: Check if user is admin with SUPERADMIN privilege
        if ($user->hasRole('admin') && $user->is_superadmin == 1) {
            return true;
        } else if ($user->hasRole('admin')) {
            return false; // Regular admin without SUPERADMIN privilege
        }

        // Step 3: Check if user has explicit permission to the folder
        $userFolderPermission = $this->getUserFolderPermission($user->id, $folder->id, $actions);
        if ($userFolderPermission) {
            return true;
        }

        // Step 4: Check if user has permission for any parent folder
        if ($this->hasParentFolderPermission($folder, $user->id, $actions)) {
            return true;
        }

        return false;
    }

    /**
     * Helper function to check user folder permission
     */
    private function getUserFolderPermission($userId, $folderId, $actions)
    {
        $userFolderPermission = UserFolderPermission::where('user_id', $userId)
            ->where('folder_id', $folderId)
            ->first();

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

        return false;
    }

    /**
     * Helper function to recursively check parent folder permissions
     */
    private function hasParentFolderPermission($folder, $userId, $actions)
    {
        // Periksa apakah folder memiliki parent folder (misalnya subfolder)
        if ($folder->parent_id) {
            $parentFolder = Folder::find($folder->parent_id);

            if ($parentFolder) {
                // Cek apakah user memiliki izin pada folder induk
                $hasPermission = $this->getUserFolderPermission($userId, $parentFolder->id, $actions);

                if ($hasPermission) {
                    return true;
                }

                // Rekursi ke folder induk berikutnya
                return $this->hasParentFolderPermission($parentFolder, $userId, $actions);
            }
        }

        return false;
    }
}
