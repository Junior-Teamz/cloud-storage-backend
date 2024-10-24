<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\User;
use App\Models\UserFilePermission;
use Illuminate\Http\Request;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class PermissionFileController extends Controller
{
    /**
     * Check the user permission
     */
    private function checkPermission($fileId)
    {
        $user = Auth::user();
        $file = File::where('id', $fileId)->first();

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

        return false;
    }

    public function getAllPermissionOnFile($fileIdParam)
    {
        $file = File::where('id', $fileIdParam)->first();
        $fileId = $file->id;

        // Periksa apakah pengguna yang meminta memiliki izin untuk melihat perizinan file ini
        $permission = $this->checkPermission($fileId);
        if (!$permission) {
            return response()->json([
                'errors' => 'You do not have the authority to view permissions on this file.'
            ], 403);
        }

        try {
            // Ambil semua pengguna dengan izin yang terkait dengan file yang diberikan
            $userFilePermissions = UserFilePermission::with('user')
                ->where('file_id', $fileId)
                ->get();

            if (!$userFilePermissions) {
                return response()->json([
                    'message' => 'No user has permission on this file.'
                ], 200);
            }

            // Siapkan data untuk response
            $responseData = [];
            foreach ($userFilePermissions as $permission) {
                $responseData[] = [
                    'user_id' => $permission->user->id,
                    'user_name' => $permission->user->name,
                    'user_email' => $permission->user->email,
                    'permissions' => $permission->permissions
                ];
            }

            return response()->json([
                'message' => 'List of user with permissions on file successfully retrieved.',
                'data' => $responseData
            ], 200);
        } catch (Exception $e) {
            Log::error('Error occurred while retrieving user with permissions for file: ' . $e->getMessage(), [
                'file_id' => $fileId,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'errors' => 'An error occurred while retrieving the list of user with permissions for this file.'
            ], 500);
        }
    }

    public function getPermission(Request $request)
    {
        $validator = Validator::make(
            request()->all(),
            [
                'user_id' => 'required|exists:users,id',
                'file_id' => 'required|exists:files,id',
            ],
        );

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        $file = File::where('id', $request->file_id)->first();
        $userInfo = User::where('id', $request->user_id)->first();

        $fileId = $file->id;
        $userId = $userInfo->id;

        $permission = $this->checkPermission($fileId);
        if (!$permission) {
            return response()->json([
                'errors' => 'You do not have the authority to view permissions on this file.'
            ], 403);
        }

        if($file->user_id == $userId){
            return response()->json([
                'message' => 'You are the owner of file.'
            ], 200);
        }

        try {
            $userFilePermission = UserFilePermission::where('user_id', $userId)->where('file_id', $fileId)->with(['user', 'file'])->first();

            if ($userFilePermission == null) {
                return response()->json([
                    'message' => 'User has no permissions for the specified file.',
                    'data' => []
                ], 200);
            }

            $userFilePermission->makeHidden(['user_id', 'file_id']);

            $userFilePermission->user->makeHidden(['email_verified_at', 'is_superadmin', 'created_at', 'updated_at']);

            $userFilePermission->file->makeHidden(['nanoid', 'path']);

            return response()->json([
                'message' => 'User ' . $userFilePermission->user->name . ' has get some permission to file: ' . $userFilePermission->file->name,
                'data' => $userFilePermission
            ]);
        } catch (Exception $e) {
            Log::error('Error occured while retrieving user permission: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);
            return response()->json([
                'errors' => 'An error occured while retrieving user permission.'
            ], 500);
        }
    }

    public function grantFilePermission(Request $request)
    {
        $validator = Validator::make(
            request()->all(),
            [
                'user_id' => 'required|exists:users,id',
                'file_id' => 'required|exists:files,id',
                'permissions' => 'required|in:read,write',
            ],
        );

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $file = File::where('id', $request->file_id)->first();
            $userInfo = User::where('id', $request->user_id)->first();

            $fileId = $file->id;
            $userId = $userInfo->id;

            if ($file->user_id == $userId) {
                return response()->json([
                    'errors' => 'You cannot modify permissions for the owner of the file.'
                ], 403);
            }

            // check if the user who owns the file will grant permissions.
            $permission = $this->checkPermission($fileId);
            if (!$permission) {
                return response()->json([
                    'errors' => 'You do not have the authority to grant permissions on this file.'
                ], 403);
            }

            $userFilePermission = UserFilePermission::where('user_id', $userId)->where('file_id', $fileId)->with(['user', 'file'])->first();

            if ($userFilePermission) {
                return response()->json([
                    'errors' => 'The user already has one of the permissions on the file.'
                ], 409); // HTTP response konflik karena data perizinan user sudah ada sebelumnya.
            }

            DB::beginTransaction();

            $createNewUserFilePermission = UserFilePermission::create([
                'user_id' => $userId,
                'file_id' => $fileId,
                'permissions' => $request->permissions
            ]);

            DB::commit();

            $createNewUserFilePermission->makeHidden(['user_id', 'file_id']);

            $createNewUserFilePermission->user->makeHidden(['email_verified_at', 'is_superadmin', 'created_at', 'updated_at']);

            $createNewUserFilePermission->file->makeHidden(['nanoid', 'path']);

            return response()->json([
                'message' => 'User ' . $createNewUserFilePermission->user->name . ' has been granted permission ' . $createNewUserFilePermission->permissions . ' to file: ' . $createNewUserFilePermission->file->name,
                'data' => $createNewUserFilePermission
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

    public function changefilePermission(Request $request)
    {
        $validator = Validator::make(
            request()->all(),
            [
                'user_id' => 'required|exists:users,id',
                'file_id' => 'required|exists:files,id',
                'permissions' => 'required|in:read,write',
            ],
        );

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Cek apakah user yang dimaksud adalah pemilik file
            $file = File::where('id', $request->file_id)->first();
            $userInfo = User::where('id', $request->user_id)->first();

            $fileId = $file->id;
            $userId = $userInfo->id;

            if ($file->user_id == $userId) {
                return response()->json([
                    'errors' => 'You cannot modify permissions for the owner of the file.'
                ], 403);
            }

            // check if the user who owns the file will revoke permissions.
            $permission = $this->checkPermission($fileId);
            if (!$permission) {
                return response()->json([
                    'errors' => 'You do not have the authority to change permissions on this file.'
                ], 403);
            }

            $userFilePermission = UserFilePermission::where('user_id', $userId)->where('file_id', $fileId)->first();

            if ($userFilePermission == null) {
                return response()->json([
                    'errors' => 'The user whose permissions you want to change is not registered in the permissions data.'
                ], 404);
            }

            DB::beginTransaction();

            // custom what permission to be revoked
            $userFilePermission->permissions = $request->permissions;
            $userFilePermission->save();

            DB::commit();

            return response()->json([
                'message' => 'User' . $userFilePermission->user->name . ' has been successfully changed permissions on file: ' . $userFilePermission->file->name,
                'data' => $userFilePermission
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

    public function revokeFilePermission(Request $request)
    {
        $validator = Validator::make(
            request()->all(),
            [
                'user_id' => 'required|exists:users,id',
                'file_id' => 'required|exists:files,id',
            ],
        );

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Cek apakah user yang dimaksud adalah pemilik file
            $file = File::where('id', $request->file_id)->first();
            $userInfo = User::where('id', $request->user_id)->first();

            $fileId = $file->id;
            $userId = $userInfo->id;

            if ($file->user_id == $userId) {
                return response()->json([
                    'errors' => 'You cannot modify permissions for the owner of the file.'
                ], 403);
            }

            // check if the user who owns the file will revoke permissions.
            $permission = $this->checkPermission($fileId);
            if (!$permission) {
                return response()->json([
                    'errors' => 'You do not have the authority to revoke permissions on this file.'
                ], 403);
            }

            $userFilePermission = UserFilePermission::where('user_id', $userId)->where('file_id', $fileId)->first();

            if ($userFilePermission == null) {
                return response()->json([
                    'errors' => 'The user whose permissions you want to revoke is not registered in the permissions data.'
                ], 404);
            }

            DB::beginTransaction();

            $userFilePermission->delete();

            DB::commit();

            return response()->json([
                'message' => 'All permission for user ' . $userFilePermission->user->name . ' on file ' . $userFilePermission->file->name . ' has been revoked.'
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
}
