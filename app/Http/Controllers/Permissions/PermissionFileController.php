<?php

namespace App\Http\Controllers\Permissions;

use App\Http\Controllers\Controller;
use App\Http\Resources\Permission\File\UserFilePermissionResource;
use App\Http\Resources\Permission\User\UserListPermissionCollection;
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
     * Check if the authenticated user has permission to access a file.
     *
     * This method verifies if the logged-in user is the owner of the file identified by the given `$fileId`.
     * If the file is not found, it returns a 404 Not Found JSON response. If the user is the owner, it returns `true`,
     * indicating that the user has permission. Otherwise, it returns `false`.
     *
     * @param string $fileId The UUID of the file to check permissions for.
     * @return bool|\Illuminate\Http\JsonResponse Returns `true` if the user has permission, `false` otherwise, or a JSON response if the file is not found.
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

    /**
     * Get all permissions on a file.
     *
     * This method retrieves all user permissions associated with a specific file. It first checks if the
     * authenticated user has permission to view the file's permissions. If not, a 403 Forbidden response
     * is returned. If the user has permission, the method retrieves all UserFilePermission records related
     * to the file, including the associated user information. The response includes a list of users with
     * their respective permissions on the file.
     *
     * @param string $fileIdParam The UUID of the file to retrieve permissions for.
     * @return \Illuminate\Http\JsonResponse A JSON response containing the list of user permissions or an error message.
     */
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

            return response()->json([
                'message' => 'List of user with permissions on file successfully retrieved.',
                'data' => new UserListPermissionCollection($userFilePermissions)
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

    /**
     * Get specific permission on a file for a specific user.
     *
     * This method retrieves the permission of a specific user on a specific file. 
     * It checks if the authenticated user has permission to view the file's permissions. 
     * If not, a 403 Forbidden response is returned. 
     * If the user has permission, the method retrieves the UserFilePermission record 
     * related to the user and file, including the associated user and file information. 
     * The response includes the user's permission on the file.
     *
     * @param  \Illuminate\Http\Request  $request The incoming HTTP request containing the user UUID and file UUID.
     * @return \Illuminate\Http\JsonResponse A JSON response containing the user's permission on the file or an error message.
     */
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
            $userFilePermission = UserFilePermission::with(['user', 'user.instances', 'file', 'file.tags', 'file.instances'])->where('user_id', $userId)->where('file_id', $fileId)->first();

            if ($userFilePermission == null) {
                return response()->json([
                    'message' => 'User has no permissions for the specified file.',
                    'data' => []
                ], 200);
            }

            return response()->json([
                'message' => 'User ' . $userFilePermission->user->name . ' has get some permission to file: ' . $userFilePermission->file->name,
                'data' => new UserFilePermissionResource($userFilePermission)
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

    /**
     * Grant permission to a user for a specific file.
     *
     * This method grants a specific permission ('read' or 'write') to a user for a specific file. 
     * It validates the request, checks if the user is the owner of the file, and ensures the user doesn't already have permissions. 
     * If all checks pass, it creates a new permission record.
     *
     * @param  \Illuminate\Http\Request  $request The incoming HTTP request containing the user UUID, file UUID, and permissions.
     * @return \Illuminate\Http\JsonResponse A JSON response indicating success or failure.
     */
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

            $userFilePermission = UserFilePermission::where('user_id', $userId)->where('file_id', $fileId)->first();

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

            $createNewUserFilePermission->load(['user', 'user.instances', 'file', 'file.tags', 'file.instances']);

            DB::commit();

            return response()->json([
                'message' => 'User ' . $createNewUserFilePermission->user->name . ' has been granted permission ' . $createNewUserFilePermission->permissions . ' to file: ' . $createNewUserFilePermission->file->name,
                'data' => new UserFilePermissionResource($createNewUserFilePermission)
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
     * Change the permission of a user for a specific file.
     *
     * This method allows the owner of a file to change the permission of a user who has access to the file.
     * It validates the request to ensure the user UUID, file UUID, and new permissions are valid.
     * It also checks if the authenticated user is the owner of the file and if the user whose permission is being changed
     * is not the owner themselves. If all checks pass, the user's permission is updated in the database.
     *
     * @param  \Illuminate\Http\Request  $request The incoming HTTP request containing user_id, file_id, permissions.
     * @return \Illuminate\Http\JsonResponse A JSON response indicating success or failure, with appropriate status codes and messages.
     */
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

            $userFilePermission->load(['user', 'user.instances', 'file', 'file.tags', 'file.instances']);

            DB::commit();

            return response()->json([
                'message' => 'User' . $userFilePermission->user->name . ' has been successfully changed permissions on file: ' . $userFilePermission->file->name,
                'data' => new UserFilePermissionResource($userFilePermission)
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
     * Revoke all permissions for a user on a specific file.
     *
     * This method revokes all permissions a user has on a specific file. It first validates the request,
     * ensuring that the user UUID and file UUID are valid. It then checks if the authenticated user is the
     * owner of the file and if the user whose permissions are being revoked is not the owner themselves.
     * If all checks pass, the user's permission record is deleted from the database.
     *
     * @param  \Illuminate\Http\Request  $request The incoming HTTP request containing user_id and file_id.
     * @return \Illuminate\Http\JsonResponse A JSON response indicating success or failure, with appropriate status codes and messages.
     */
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
