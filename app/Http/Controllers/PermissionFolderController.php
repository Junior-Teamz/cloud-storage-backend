<?php

namespace App\Http\Controllers;

use App\Models\Folder;
use App\Models\UserFolderPermission;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        $folder = Folder::find($folderId);

        // If folder not found, return 404 error and stop the process
        if (!$folder) {
            return response()->json([
                'errors' => 'Folder dengan Folder ID yang anda masukan tidak ditemukan, Silahkan periksa kembali Folder ID yang anda masukan.'
            ], 404); // Setting status code to 404 Not Found
        }

        // Step 1: Check if the folder belongs to the logged-in user
        if ($folder->user_id === $user->id) {
            return true; // The owner has all permissions
        }

        return false;
    }

    public function getPermission(Request $request)
    {
        $validator = Validator::make(
            request()->all(),
            [
                'user_id' => 'required|integer|exists:users,id',
                'folder_id' => 'required|integer|exists:folders,id',
            ],
        );

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        $permission = $this->checkPermission($request->folder_id);
        if (!$permission) {
            return response()->json([
                'errors' => 'Anda tidak memiliki wewenang untuk melihat izin pada Folder ini.'
            ], 403);
        }

        try {
            $userFolderPermission = UserFolderPermission::where('user_id', $request->user_id)->where('folder_id', $request->folder_id)->first();
            return response()->json([
                'data' => $userFolderPermission
            ]);
        } catch (Exception $e) {
            Log::error($e->getMessage());
            return response()->json([
                'errors' => 'Terjadi kesalahan saat melihat izin pada Folder ini.'
            ], 500);
        }
    }

    public function grantFolderPermission(Request $request)
    {
        $validator = Validator::make(
            request()->all(),
            [
                'user_id' => 'required|integer|exists:users,id',
                'folder_id' => 'required|integer|exists:folders,id',
                'permissions' => 'required|array|only:folder_read,folder_edit,folder_delete',
            ],
        );

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        // check if the user who owns the folder will grant permissions.
        $permission = $this->checkPermission($request->folder_id);
        if (!$permission) {
            return response()->json([
                'errors' => 'Anda tidak memiliki wewenang untuk memberikan izin pada Folder ini.'
            ], 403);
        }

        try {
            $userFolderPermission = UserFolderPermission::where('user_id', $request->user_id)->where('folder_id', $request->folder_id)->first();
            if (!$userFolderPermission) {
                $userFolderPermission = UserFolderPermission::create([
                    'user_id' => $request->user_id,
                    'folder_id' => $request->folder_id,
                    'permissions' => $request->permissions
                ]);
                
                return response()->json([
                    'message' => 'Izin diberikan kepada user.'
                ], 200);
            }
        } catch (Exception $e) {
            Log::error($e->getMessage());
            return response()->json([
                'errors' => 'Terjadi kesalahan saat memberikan izin pada Folder ini.'
            ], 500);
        }
    }

    public function changeFolderPermission(Request $request)
    {
        $validator = Validator::make(
            request()->all(),
            [
                'user_id' => 'required|integer|exists:users,id',
                'folder_id' => 'required|integer|exists:folders,id',
                'permissions' => 'required|array|only:folder_read,folder_edit,folder_delete',
            ],
        );

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        // check if the user who owns the folder will revoke permissions.
        $permission = $this->checkPermission($request->folder_id);
        if (!$permission) {
            return response()->json([
                'errors' => 'Anda tidak memiliki wewenang untuk mengubah izin pada Folder ini.'
            ], 403);
        }

        try {
            $userFolderPermission = UserFolderPermission::where('user_id', $request->user_id)->where('folder_id', $request->folder_id)->first();
            // custom what permission to be revoked
            $userFolderPermission->permissions = array_diff($userFolderPermission->permissions, $request->permissions);
            $userFolderPermission->save();
            
            return response()->json([
                'message' => 'Izin diberikan kepada user.'
            ], 200);
           
        } catch (Exception $e) {
            Log::error($e->getMessage());
            return response()->json([
                'errors' => 'Terjadi kesalahan saat mengubah izin pada Folder ini.'
            ], 500);
        }
    }

    public function revokeAllFolderPermission(Request $request)
    {
        $validator = Validator::make(
            request()->all(),
            [
                'user_id' => 'required|integer|exists:users,id',
                'folder_id' => 'required|integer|exists:folders,id',
            ],
        );

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        // check if the user who owns the folder will revoke permissions.
        $permission = $this->checkPermission($request->folder_id);
        if (!$permission) {
            return response()->json([
                'errors' => 'Anda tidak memiliki wewenang untuk menghapus semua izin pada Folder ini.'
            ], 403);
        }

        try {
            $userFolderPermission = UserFolderPermission::where('user_id', $request->user_id)->where('folder_id', $request->folder_id)->first();
            if ($userFolderPermission) {
                $userFolderPermission->delete();
            }
            
            return response()->json([
                'message' => 'Semua izin sudah dihapus.'
            ], 200);
           
        } catch (Exception $e) {
            Log::error($e->getMessage());
            return response()->json([
                'errors' => 'Terjadi kesalahan saat menghapus semua izin user.'
            ], 500);
        }
    }
}
