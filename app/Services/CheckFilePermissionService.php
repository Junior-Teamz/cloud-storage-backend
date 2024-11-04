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
     * Check if the authenticated user has permission to perform actions on a file.
     *
     * This method checks if the authenticated user has the necessary permissions to perform
     * the specified actions on a file. It considers file ownership, admin privileges,
     * explicit file permissions, and permissions on the folder where the file is located.
     *
     * @param string $fileId The UUID of the file.
     * @param string|array $actions The action(s) to check permission for (e.g., 'read', 'write', ['read', 'write']).
     * @return bool|JsonResponse True if the user has permission, false otherwise. Returns a JsonResponse with a 404 status code if the file is not found.
     */
    public function checkPermissionFile($fileId, $actions)
    {
        $user = Auth::user();
        $file = File::find($fileId);

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
     * Recursively checks folder permissions up the folder tree.
     *
     * This private method is used to recursively check if a user has permission to perform
     * certain actions on a folder, by traversing up the folder tree from a starting folder ID.
     * It considers folder ownership, admin privileges, and explicit folder permissions.
     *
     * @param string $folderId The UUID of the folder to start checking permissions from.
     * @param string|array $actions The action(s) to check permission for (e.g., 'read', 'write', ['read', 'write']).
     * @return bool True if the user has permission, false otherwise.
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
