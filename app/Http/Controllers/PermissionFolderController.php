<?php

namespace App\Http\Controllers;

use App\Models\Folder;
use App\Models\User;
use App\Models\UserFilePermission;
use App\Models\UserFolderPermission;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class PermissionFolderController extends Controller
{

    
    /**
     * Check if the authenticated user has permission to access the specified folder.
     *
     * @param string $folderId The UUID of the folder to check.
     * @return bool|Illuminate\Http\JsonResponse True if the user has permission, false otherwise.
     *  Returns a 404 JSON response if the folder is not found.
     */
    private function checkPermission($folderId)
    {
        $user = Auth::user();
        $folder = Folder::where('id', $folderId)->first();

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

        return false;
    }

    /**
     * Get all permissions on a specific folder.
     *
     * Retrieves all users with their respective permissions on a given folder.
     * Requires authentication and authorization: only the folder owner can access this information.
     *
     * @param string $folderIdParam The UUID of the folder.
     * @return Illuminate\Http\JsonResponse A JSON response containing the list of users with permissions or an error message.
     */
    public function getAllPermissionOnFolder($folderIdParam)
    {
        $folder = Folder::where('id', $folderIdParam)->first();
        $folderId = $folder->id;

        // Periksa apakah pengguna yang meminta memiliki izin untuk melihat perizinan folder ini
        $permission = $this->checkPermission($folderId);
        if (!$permission) {
            return response()->json([
                'errors' => 'You do not have the authority to view permissions on this Folder.'
            ], 403);
        }

        try {
            // Ambil semua pengguna dengan izin yang terkait dengan folder yang diberikan
            $userFolderPermissions = UserFolderPermission::with('user')
                ->where('folder_id', $folderId)
                ->get();

            if (!$userFolderPermissions) {
                return response()->json([
                    'message' => 'No user has permission on this folder.'
                ], 200);
            }

            // Siapkan data untuk response
            $responseData = [];
            foreach ($userFolderPermissions as $permission) {
                $responseData[] = [
                    'user_id' => $permission->user->id,
                    'user_name' => $permission->user->name,
                    'user_email' => $permission->user->email,
                    'photo_profile_url' => $permission->user->photo_profile_url,
                    'permissions' => $permission->permissions
                ];
            }

            return response()->json([
                'message' => 'List of users with permissions on folder successfully retrieved.',
                'data' => $responseData
            ], 200);
        } catch (Exception $e) {
            Log::error('Error occurred while retrieving users with permissions for folder: ' . $e->getMessage(), [
                'folder_id' => $folderId,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'errors' => 'An error occurred while retrieving the list of users with permissions for this folder.'
            ], 500);
        }
    }

    /**
     * Get permission of a user on a specific folder.
     *
     * Retrieves the permission of a specific user on a given folder.
     * Requires authentication and authorization: only the folder owner can access this information.
     *
     * @param Request $request The request containing the user UUID and folder UUID.
     * @return Illuminate\Http\JsonResponse A JSON response containing the user's permission or an error message.
     * @throws Exception If an error occurs during the process.
     */
    public function getPermission(Request $request)
    {
        // Validasi input request
        $validator = Validator::make(
            request()->all(),
            [
                'user_id' => 'required|exists:users,id',
                'folder_id' => 'required|exists:folders,id',
            ],
        );

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        $folder = Folder::where('id', $request->folder_id)->first();
        $userInfo = User::where('id', $request->user_id)->first();

        $folderId = $folder->id;
        $userId = $userInfo->id;

        // Cek permission user pada folder
        $permission = $this->checkPermission($folderId);
        if (!$permission) {
            return response()->json([
                'errors' => 'You do not have the authority to view permissions on this Folder.'
            ], 403);
        }

        if ($folder->user_id == $userId) {
            return response()->json([
                'message' => 'You are the owner of folder.'
            ], 200);
        }

        try {
            // Cek apakah userFolderPermission ada
            $userFolderPermission = UserFolderPermission::where('user_id', $request->user_id)
                ->where('folder_id', $request->folder_id)->with(['user', 'folder'])
                ->first();

            if (!$userFolderPermission) {
                return response()->json([
                    'message' => 'User has no permissions for the specified folder.',
                    'data' => []
                ], 200);
            }

            $userFolderPermission->makeHidden(['user_id', 'folder_id']);

            $userFolderPermission->user->makeHidden(['email_verified_at', 'is_superadmin', 'created_at', 'updated_at']);

            $userFolderPermission->file->makeHidden(['nanoid']);

            return response()->json([
                'message' => 'User ' . $userFolderPermission->user->name . ' has permission for folder: ' . $userFolderPermission->folder->name,
                'data' => $userFolderPermission
            ]);
        } catch (Exception $e) {
            Log::error('Error occured while retrieving user permission: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);
            return response()->json([
                'errors' => 'An error occurred while retrieving user permission.'
            ], 500);
        }
    }

    /**
     * Grant permission to a user on a specific folder.
     *
     * Grants the specified permission (read or write) to a user on a given folder.
     * This also applies the permission recursively to subfolders and files within that folder.
     * Requires authentication and authorization: only the folder owner can grant permissions.
     *
     * @param Request $request The request containing the user UUID, folder UUID, and permission type.
     * @return Illuminate\Http\JsonResponse A JSON response indicating success or failure.
     * @throws Exception If an error occurs during the process.
     */
    public function grantFolderPermission(Request $request)
    {
        $validator = Validator::make(
            request()->all(),
            [
                'user_id' => 'required|exists:users,id',
                'folder_id' => 'required|exists:folders,id',
                'permissions' => 'required|in:read,write',
            ],
        );

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $folder = Folder::where('id', $request->folder_id)->first();
            $userInfo = User::where('id', $request->user_id)->first();

            if($folder->parent_id === null){
                Log::warning('Attempt to grant permission on root folder.', [
                    'folder_id' => $folder->id,
                    'user_id' => Auth::user()->id,
                    'user_id_to_be_granted' => $userInfo->id,
                    'permissions' => $request->permissions
                ]);
                return response()->json([
                    'errors' => 'Cannot grant permission on this folder.'
                ], 403);
            }

            if ($folder->user_id == $userInfo->id) {
                return response()->json([
                    'errors' => 'You cannot modify permissions for the owner of the folder.'
                ], 403);
            }

            // check if the user who owns the folder will grant permissions.
            $permission = $this->checkPermission($folder->id);
            if (!$permission) {
                return response()->json([
                    'errors' => 'You do not have the authority to grant permissions on this Folder.'
                ], 403);
            }

            $checkUserFolderPermission = UserFolderPermission::where('user_id', $userInfo->id)->where('folder_id', $folder->id)->with(['user', 'folder'])->first();

            if ($checkUserFolderPermission) {
                return response()->json([
                    'errors' => 'The user already has one of the permissions on the folder. If you want to change permission, please use endpoint "changePermission".'
                ], 409); // HTTP response konflik karena data perizinan user sudah ada sebelumnya.
            }

            DB::beginTransaction();

            // Berikan izin pada folder induk
            $createNewUserFolderPermission = UserFolderPermission::create([
                'user_id' => $userInfo->id,
                'folder_id' => $folder->id,
                'permissions' => $request->permissions
            ]);

            // Terapkan izin pada subfolder dan file (kecuali file yang sudah ada izin)
            $this->applyPermissionToSubfoldersAndFiles($folder, $userInfo->id, $request->permissions);

            DB::commit();

            $createNewUserFolderPermission->makeHidden(['user_id']);

            $createNewUserFolderPermission->user->makeHidden(['email_verified_at', 'is_superadmin', 'created_at', 'updated_at']);

            $createNewUserFolderPermission->folder->makeHidden(['nanoid']);

            return response()->json([
                'message' => 'User ' . $createNewUserFolderPermission->user->name . ' has been granted permission ' . $createNewUserFolderPermission->permissions . ' to folder: ' . $createNewUserFolderPermission->folder->name,
                'data' => $createNewUserFolderPermission
            ], 200);
        } catch (Exception $e) {
            DB::rollBack();

            Log::error('An error occured while granting user permission: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);
            return response()->json([
                'errors' => 'An error occured while granting user permission.'
            ], 500);
        }
    }

    /**
     * Apply permission to subfolders and files recursively.
     *
     * This function recursively applies the given permission to all subfolders and files within a specified folder.
     * It checks for existing permissions before creating new ones to avoid duplicates.
     * 
     * @param Folder $folder The parent folder.
     * @param string $userId The UUID of the user.
     * @param string $permissions The permission to apply ('read' or 'write').
     */
    private function applyPermissionToSubfoldersAndFiles($folder, $userId, $permissions)
    {
        // Terapkan izin pada subfolder
        foreach ($folder->subfolders as $subfolder) {
            // Cek apakah user sudah memiliki izin pada subfolder
            $existingPermission = UserFolderPermission::where('user_id', $userId)->where('folder_id', $subfolder->id)->first();
            if (!$existingPermission) {
                UserFolderPermission::create([
                    'user_id' => $userId,
                    'folder_id' => $subfolder->id,
                    'permissions' => $permissions
                ]);
            }

            // Rekursif untuk sub-subfolder
            $this->applyPermissionToSubfoldersAndFiles($subfolder, $userId, $permissions);
        }

        // Terapkan izin pada file, kecuali file yang sudah ada izin
        foreach ($folder->files as $file) {
            $existingFilePermission = UserFilePermission::where('user_id', $userId)->where('file_id', $file->id)->first();
            if (!$existingFilePermission) {
                UserFilePermission::create([
                    'user_id' => $userId,
                    'file_id' => $file->id,
                    'permissions' => $permissions
                ]);
            }
        }
    }

    /**
     * Change permission of a user on a specific folder.
     *
     * Changes the permission (read or write) of a user on a given folder.
     * This also applies the permission recursively to subfolders and files within that folder.
     * Requires authentication and authorization: only the folder owner can change permissions.
     *
     * @param Request $request The request containing the user UUID, folder UUID, and permission type.
     * @return Illuminate\Http\JsonResponse A JSON response indicating success or failure.
     * @throws Exception If an error occurs during the process.
     */
    public function changeFolderPermission(Request $request)
    {
        $validator = Validator::make(
            request()->all(),
            [
                'user_id' => 'required|exists:users,id',
                'folder_id' => 'required|exists:folders,id',
                'permissions' => 'required|in:read,write',
            ],
        );

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $folder = Folder::where('id', $request->folder_id)->first();
            $userInfo = User::where('id', $request->user_id)->first();

            $folderId = $folder->id;
            $userId = $userInfo->id;

            if ($folder->user_id == $userId) {
                return response()->json([
                    'errors' => 'You cannot modify permissions for the owner of the folder.'
                ], 403);
            }

            // check if the user who owns the folder will revoke permissions.
            $permission = $this->checkPermission($folderId);
            if (!$permission) {
                return response()->json([
                    'errors' => 'You do not have the authority to change permissions on this Folder.'
                ], 403);
            }

            $userFolderPermission = UserFolderPermission::where('user_id', $userId)->where('folder_id', $folderId)->first();

            if (!$userFolderPermission) {
                return response()->json([
                    'errors' => 'The user whose permissions you want to change is not registered in the permissions data. Please use endpoint "grantPermission" first.'
                ], 404);
            }

            DB::beginTransaction();

            $userFolderPermission->permissions = $request->permissions;
            $userFolderPermission->save();

            // Terapkan perubahan izin pada subfolder dan file (kecuali file yang sudah ada izin)
            $this->applyPermissionToSubfoldersAndFiles($folder, $request->user_id, $request->permissions);

            DB::commit();

            return response()->json([
                'message' => 'Successfully change permission for user ' . $userFolderPermission->user->name . ' to ' . $userFolderPermission->permissions . ' on folder: ' . $userFolderPermission->folder->name,
                'data' => $userFolderPermission
            ], 200);
        } catch (Exception $e) {
            DB::rollBack();

            Log::error('An error occured while changing user permission: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);
            return response()->json([
                'errors' => 'An error occured while changing user permission.'
            ], 500);
        }
    }

    /**
     * Revoke permission of a user on a specific folder.
     *
     * Revokes all permissions of a user on a given folder and its subfolders and files.
     * Requires authentication and authorization: only the folder owner can revoke permissions.
     *
     * @param Request $request The request containing the user UUID and folder UUID.
     * @return Illuminate\Http\JsonResponse A JSON response indicating success or failure.
     * @throws Exception If an error occurs during the process.
     */
    public function revokeFolderPermission(Request $request)
    {
        $validator = Validator::make(
            request()->all(),
            [
                'user_id' => 'required|exists:users,id',
                'folder_id' => 'required|exists:folders,id',
            ],
        );

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }


        try {
            $folder = Folder::where('id', $request->folder_id)->first();
            $userInfo = User::where('id', $request->user_id)->first();

            $folderId = $folder->id;
            $userId = $userInfo->id;

            if ($folder->user_id == $userId) {
                return response()->json([
                    'errors' => 'You cannot modify permissions for the owner of the folder.'
                ], 403);
            }

            // check if the user who owns the folder will revoke permissions.
            $permission = $this->checkPermission($folderId);
            if (!$permission) {
                return response()->json([
                    'errors' => 'You do not have the authority to revoke permissions on this Folder.'
                ], 403);
            }

            $userFolderPermission = UserFolderPermission::where('user_id', $userId)->where('folder_id', $folderId)->first();

            if (!$userFolderPermission) {
                return response()->json([
                    'errors' => 'The user whose permissions you want to revoke is not registered in the permissions data.'
                ], 404);
            }

            DB::beginTransaction();

            $userFolderPermission->delete();

            // Cabut izin dari subfolder dan file (kecuali file yang sudah ada izin)
            $this->removePermissionFromSubfoldersAndFiles($folder, $request->user_id);

            DB::commit();

            return response()->json([
                'message' => 'All permission for user ' . $userFolderPermission->user->name . ' on folder ' . $userFolderPermission->folder->name . ' has been revoked.'
            ], 200);
        } catch (Exception $e) {
            DB::rollBack();

            Log::error('An error occured while revoking user permission: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);
            return response()->json([
                'errors' => 'An error occured while revoking user permission.'
            ], 500);
        }
    }


    /**
     * Recursively remove permissions from subfolders and files.
     *
     * @param Folder $folder The parent folder.
     * @param string $userId The UUID of the user whose permissions to remove.
     */
    private function removePermissionFromSubfoldersAndFiles($folder, $userId)
    {
        // Hapus izin dari semua subfolder
        foreach ($folder->subfolders as $subfolder) {
            UserFolderPermission::where('user_id', $userId)->where('folder_id', $folder->id)->delete();
            $this->removePermissionFromSubfoldersAndFiles($subfolder, $userId);
        }

        // Hapus izin dari file
        foreach ($folder->files as $file) {
            UserFilePermission::where('user_id', $userId)->where('file_id', $file->id)->delete();
        }
    }
}
