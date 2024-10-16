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
     * Check the user permission
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

            $folderId = $folder->id;
            $userId = $userInfo->id;

            if ($folder->user_id == $userId) {
                return response()->json([
                    'errors' => 'You cannot modify permissions for the owner of the folder.'
                ], 403);
            }

            // check if the user who owns the folder will grant permissions.
            $permission = $this->checkPermission($folderId);
            if (!$permission) {
                return response()->json([
                    'errors' => 'You do not have the authority to grant permissions on this Folder.'
                ], 403);
            }

            $checkUserFolderPermission = UserFolderPermission::where('user_id', $userId)->where('folder_id', $folderId)->with(['user', 'folder'])->first();

            if ($checkUserFolderPermission) {
                return response()->json([
                    'errors' => 'The user already has one of the permissions on the folder. If you want to change permission, please use endpoint "changePermission".'
                ], 409); // HTTP response konflik karena data perizinan user sudah ada sebelumnya.
            }

            DB::beginTransaction();

            // Berikan izin pada folder induk
            $createNewUserFolderPermission = UserFolderPermission::create([
                'user_id' => $userId,
                'folder_id' => $folderId,
                'permissions' => $request->permissions
            ]);

            // Terapkan izin pada subfolder dan file (kecuali file yang sudah ada izin)
            $this->applyPermissionToSubfoldersAndFiles($folder, $userId, $request->permissions);

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
