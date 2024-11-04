<?php

namespace App\Services;

use App\Models\Folder;
use App\Models\UserFolderPermission;
use Illuminate\Support\Facades\Auth;

class CheckFolderPermissionService
{
    /**
     * Check if the authenticated user has permission to perform actions on a folder.
     *
     * This method checks if the authenticated user has the necessary permissions to perform
     * the specified actions on a folder. It considers folder ownership, admin privileges,
     * explicit folder permissions, and permissions inherited from parent folders.
     *
     * @param string $folderId The UUID of the folder.
     * @param string|array $actions The action(s) to check permission for (e.g., 'read', 'write', ['read', 'write']).
     * @return bool|JsonResponse True if the user has permission, false otherwise. Returns a JsonResponse with a 404 status code if the folder is not found.
     */
    public function checkPermissionFolder($folderId, $actions)
    {
        $user = Auth::user();
        $folder = Folder::find($folderId);

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
     * Get the user's permission for a specific folder.
     *
     * This private method retrieves the user's permission record for the given folder.
     * It then checks if the user has any of the specified actions allowed in their permissions.
     *
     * @param string $userId The UUID of the user.
     * @param string $folderId The UUID of the folder.
     * @param string|array $actions The action(s) to check permission for (e.g., 'read', 'write', ['read', 'write']).
     * @return bool True if the user has permission for any of the specified actions, false otherwise.
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
     * Recursively checks if the user has permission for any parent folder.
     *
     * This private method traverses up the folder hierarchy from a given folder,
     * checking if the user has the specified permissions for any of the parent folders.
     *
     * @param Folder $folder The folder object to start checking from.
     * @param string $userId The UUID of the user.
     * @param string|array $actions The action(s) to check permission for.
     * @return bool True if the user has permission for any parent folder, false otherwise.
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
