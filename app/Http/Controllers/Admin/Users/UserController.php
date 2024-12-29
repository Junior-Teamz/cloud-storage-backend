<?php

namespace App\Http\Controllers\Admin\Users;

use App\Http\Controllers\Controller;
use App\Models\Folder;
use App\Models\Instance;
use App\Models\User;
use App\Services\CheckAdminService;
use App\Services\GetPathService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    protected $checkAdminService;
    protected $getPathService;

    public function __construct(CheckAdminService $checkAdminService, GetPathService $getPathServiceParam)
    {
        $this->checkAdminService = $checkAdminService;
        $this->getPathService = $getPathServiceParam;
    }

    public function countAllUserSameInstance()
    {
        $checkAdmin = $this->checkAdminService->checkAdminWithPermission('users.statistic.read');

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        try {
            // Get the currently logged-in admin
            $admin = Auth::user();

            // Retrieve instance IDs associated with the admin
            $adminInstanceIds = $admin->instances()->pluck('instances.id');

            // Count total users sharing the same instances as the admin
            $totalUserCount = User::whereHas('instances', function ($query) use ($adminInstanceIds) {
                $query->whereIn('id', $adminInstanceIds);
            })->count();

            // Count users with 'user' role within the same instances
            $userRoleCount = User::role('user')->whereHas('instances', function ($query) use ($adminInstanceIds) {
                $query->whereIn('id', $adminInstanceIds);
            })->count();

            // Count users with 'admin' role within the same instances
            $adminRoleCount = User::role('admin')->whereHas('instances', function ($query) use ($adminInstanceIds) {
                $query->whereIn('id', $adminInstanceIds);
            })->count();

            if ($totalUserCount === 0) {
                return response()->json([
                    'message' => 'No users are registered under the same instances as the admin.',
                    'total_user_count' => $totalUserCount,
                    'user_role_count' => $userRoleCount,
                    'admin_role_count' => $adminRoleCount
                ], 200);
            }

            return response()->json([
                'total_user_count' => $totalUserCount,
                'user_role_count' => $userRoleCount,
                'admin_role_count' => $adminRoleCount
            ], 200);
        } catch (Exception $e) {
            Log::error('Error occurred on getting user count: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred on getting user count.',
            ], 500);
        }
    }

    /**
     * Display a listing of users.
     *
     * This function retrieves a list of users based on various search criteria.
     * It allows searching by name, email, or instance name. 
     * 
     * Admin authentication is required.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function listUser(Request $request)
    {
        $user = Auth::user();

        // Check if the user is an admin
        $checkAdmin = $this->checkAdminService->checkAdminWithPermission('users.read');

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        try {
            $userData = User::where('id', $user->id)->first();

            // Get instance IDs associated with the logged-in admin
            $adminInstanceIds = $userData->instances()->pluck('instances.id');

            // Build the user query, filtering by the same instances as the admin
            $query = User::query()
                ->whereHas('instances', function ($q) use ($adminInstanceIds) {
                    $q->whereIn('id', $adminInstanceIds);
                });

            // Apply additional filters based on query parameters
            if ($request->query('name')) {
                $query->where('name', 'like', '%' . $request->query('name') . '%');
            }
            if ($request->query('email')) {
                $query->where('email', 'like', '%' . $request->query('email') . '%');
            }
            if ($request->query('instance')) {
                $query->whereHas('instances', function ($q) use ($request) {
                    $q->where('name', 'like', '%' . $request->query('instance') . '%');
                });
            }

            // Fetch users with their associated instances and folders
            $allUser = $query->with([
                'instances:id,name,address',
                'folders' => function ($query) {
                    $query->whereNull('parent_id');
                }
            ])->paginate(10);

            // Transform data to include calculated folder details
            $allUser->getCollection()->transform(function ($user) {
                $user->folders->transform(function ($folder) {
                    $folder->total_subfolder = $folder->calculateTotalSubfolder();
                    $folder->total_file = $folder->calculateTotalFile(); // Total files in folder
                    $folder->total_size = $folder->calculateTotalSize(); // Total size of folder
                    unset($folder->user_id, $folder->created_at, $folder->updated_at, $folder->files, $folder->subfolders);
                    return $folder;
                });
                return $user;
            });

            return response()->json($allUser, 200);
        } catch (\Exception $e) {
            Log::error("Error occurred on getting user list: " . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred on getting user list.',
            ], 500);
        }
    }

    public function user_info($id)
    {
        $userLogin = Auth::user();

        $checkAdmin = $this->checkAdminService->checkAdminWithPermission('users.read');

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        try {
            $userAdminData = User::where('id', $userLogin->id)->first();
            $adminInstance = $userAdminData->instances()->first();

            $user = User::with([
                'instances:id,name,address',
                'folders' => function ($query) {
                    $query->whereNull('parent_id');
                }
            ])->where('id', $id)->first();

            $userInstance = $user->instances()->first();

            if ($userInstance->id !== $adminInstance->id) {
                return response()->json([
                    'errors' => 'You cannot read user data from a different instance.'
                ], 403);
            }

            // Transformasi data pada folders
            $user->folders->transform(function ($folder) {
                $folder->total_subfolder = $folder->calculateTotalSubfolder();
                $folder->total_file = $folder->calculateTotalFile();
                $folder->total_size = $folder->calculateTotalSize();
                unset($folder->user_id, $folder->created_at, $folder->updated_at, $folder->files, $folder->subfolders);
                return $folder;
            });

            if (!$user) {
                return response()->json([
                    'message' => "User not found.",
                    'data' => []
                ], 200);
            }

            return response()->json([
                'data' => $user
            ]);
        } catch (Exception $e) {
            Log::error('Error occurred on getting user information: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);
            return response()->json([
                'errors' => 'An error occured on getting user information.',
            ], 500);
        }
    }

    /**
     * Create a new user by admin.
     *
     * This function allows an administrator to create a new user account.  It validates the input data,
     * creates the user, assigns a role, associates the user with an instance, and handles potential errors.
     * 
     * Requires admin authentication.
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function createUserFromAdmin(Request $request)
    {
        $user = Auth::user();

        $checkAdmin = $this->checkAdminService->checkAdminWithPermission('users.create');

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'unique:users,email',
                function ($attribute, $value, $fail) {
                    if (!preg_match('/^\w+([\.-]?\w+)*@\w+([\.-]?\w+)*(\.\w{2,})+$/', $value)) {
                        $fail('Invalid email format.');
                    }

                    $allowedDomains = [
                        'outlook.com',
                        'yahoo.com',
                        'aol.com',
                        'lycos.com',
                        'mail.com',
                        'icloud.com',
                        'yandex.com',
                        'protonmail.com',
                        'tutanota.com',
                        'zoho.com',
                        'gmail.com'
                    ];

                    $domain = strtolower(substr(strrchr($value, '@'), 1));

                    if (!in_array($domain, $allowedDomains)) {
                        $fail('Invalid email domain.');
                    }
                },
            ],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'instance_section_id' => ['required', 'string', 'exists:instance_sections,id'],
            'photo_profile' => ['nullable', 'file', 'max:3000', 'mimes:jpg,jpeg,png']
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $userAdminData = User::where('id', $user->id)->first();
            $adminInstance = $userAdminData->instances()->first();
            $instance = Instance::where('id', $adminInstance->id)->first();
            $section = $instance->sections()->where('id', $request->instance_section_id)->first();

            if (!$section) {
                return response()->json([
                    'errors' => 'Section does not belong to the specified instance.'
                ], 422);
            }

            DB::beginTransaction();

            $newUser = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => bcrypt($request->password),
            ]);

            $newUser->assignRole('user');

            $newUser->instances()->sync($instance->id);
            $newUser->section()->sync($section->id);

            if ($request->has('photo_profile')) {
                $photoFile = $request->file('photo_profile');
                $photoProfilePath = 'users_photo_profile';

                if (!Storage::disk('public')->exists($photoProfilePath)) {
                    Storage::disk('public')->makeDirectory($photoProfilePath);
                }

                $photoProfile = $photoFile->store($photoProfilePath, 'public');
                $photoProfileUrl = Storage::disk('public')->url($photoProfile);

                $newUser->photo_profile_path = $photoProfile;
                $newUser->photo_profile_url = $photoProfileUrl;
                $newUser->save();
            }

            $newUser->load('instances:id,name,address');

            $userFolders = Folder::where('user_id', $newUser->id)->get();

            foreach ($userFolders as $folder) {
                $folder->instances()->sync($instance->id);
            }

            DB::commit();

            return response()->json([
                'message' => 'User created successfully.',
                'data' => $newUser
            ], 201);
        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Error occurred on adding user: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);
            return response()->json([
                'errors' => 'An error occurred on adding user.',
            ], 500);
        }
    }

    /**
     * Update existing user by admin.
     *
     * This function allows an administrator to update an existing user account. It validates the input data,
     * updates the user information, updates the user's instance association, updates associated folder instances,
     * and handles potential errors.  It prevents the update of superadmin users.
     *
     * Requires admin authentication.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $userIdToBeUpdated The UUID of the user to be updated.
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateUserFromAdmin(Request $request, $userIdToBeUpdated)
    {
        $user = Auth::user();

        $checkAdmin = $this->checkAdminService->checkAdminWithPermission('users.update');

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['nullable', 'string', 'max:255'],
            'email' => [
                'nullable',
                'email',
                function ($attribute, $value, $fail) use ($request) {
                    if (!preg_match('/^\w+([\.-]?\w+)*@\w+([\.-]?\w+)*(\.\w{2,})+$/', $value)) {
                        $fail('Invalid email format.');
                    }

                    $allowedDomains = [
                        'outlook.com',
                        'yahoo.com',
                        'aol.com',
                        'lycos.com',
                        'mail.com',
                        'icloud.com',
                        'yandex.com',
                        'protonmail.com',
                        'tutanota.com',
                        'zoho.com',
                        'gmail.com'
                    ];

                    $domain = strtolower(substr(strrchr($value, '@'), 1));

                    if (!in_array($domain, $allowedDomains)) {
                        $fail('Invalid email domain.');
                    }
                },
                Rule::unique('users', 'email')->ignore($userIdToBeUpdated)
            ],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'instance_section_id' => ['nullable', 'string', 'exists:instance_sections,id'],
            'photo_profile' => ['nullable', 'file', 'max:3000', 'mimes:jpg,jpeg,png']
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $userAdminData = User::where('id', $user->id)->first();
            $adminInstance = $userAdminData->instances()->first();

            $userToBeUpdated = User::where('id', $userIdToBeUpdated)->first();
            $userInstance = $userToBeUpdated->instances()->first();

            if ($userInstance->id !== $adminInstance->id) {
                return response()->json([
                    'errors' => 'You cannot update user data from a different instance.'
                ], 403);
            }

            if (!$userToBeUpdated) {
                return response()->json([
                    'errors' => 'User not found.'
                ], 404);
            }

            if ($userToBeUpdated->hasRole('superadmin') || ($userToBeUpdated->hasRole('admin') && $user->id !== $userToBeUpdated->id)) {
                return response()->json([
                    'errors' => 'You are not allowed to update superadmin or other admin users.',
                ], 403);
            }

            DB::beginTransaction();

            $dataToUpdate = $request->only(['name', 'email', 'password']);

            if (isset($dataToUpdate['password'])) {
                $dataToUpdate['password'] = bcrypt($dataToUpdate['password']);
            }

            $userToBeUpdated->update(array_filter($dataToUpdate));

            if ($request->role) {
                $userToBeUpdated->assignRole($request->role);
            }

            $userInstanceSectionOld = $userToBeUpdated->section()->first();

            if ($request->instance_section_id && $userInstanceSectionOld->id !== $request->instance_section_id) {
                $currentSection = $adminInstance->sections()->where('id', $request->instance_section_id)->first();

                if (!$currentSection) {
                    return response()->json([
                        'errors' => 'Section does not belong to the specified instance.'
                    ], 422);
                }

                $userToBeUpdated->section()->sync($request->instance_section_id);
            }

            if ($request->has('photo_profile')) {
                $photoFile = $request->file('photo_profile');

                $photoProfilePath = 'users_photo_profile';

                if (!Storage::disk('public')->exists($photoProfilePath)) {
                    Storage::disk('public')->makeDirectory($photoProfilePath);
                }

                if ($userToBeUpdated->photo_profile_path && Storage::disk('public')->exists($userToBeUpdated->photo_profile_path)) {
                    Storage::disk('public')->delete($userToBeUpdated->photo_profile_path);
                }

                $photoProfile = $photoFile->store($photoProfilePath, 'public');

                $photoProfileUrl = Storage::disk('public')->url($photoProfile);

                $userToBeUpdated->photo_profile_path = $photoProfile;
                $userToBeUpdated->photo_profile_url = $photoProfileUrl;
                $userToBeUpdated->save();
            }

            DB::commit();

            $userToBeUpdated->load('instances:id,name,address');

            return response()->json([
                'message' => 'User updated successfully.',
                'data' => $userToBeUpdated
            ], 200);
        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Error occurred on updating user: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);
            return response()->json([
                'errors' => 'An error occured on updating user.',
            ], 500);
        }
    }

    /**
     * Update a user's password.
     *
     * This function allows an administrator to update a user's password. It validates the new password,
     * updates the user's password in the database, and handles potential errors.  It prevents the user
     * from setting their password to the same as their old password.
     *
     * Requires admin authentication.
     * 
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $userId The UUID of the user whose password is to be updated.
     */
    public function updateUserPassword(Request $request, $userId)
    {
        $user = Auth::user();

        $checkAdmin = $this->checkAdminService->checkAdminWithPermission('users.update');

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $userAdminData = User::where('id', $user->id)->first();
            $adminInstance = $userAdminData->instances()->first();

            $userData = User::where('id', $userId)->first();
            $userInstance = $userData->instances()->first();

            if ($userInstance->id !== $adminInstance->id) {
                return response()->json([
                    'errors' => 'You cannot update user password from a different instance.'
                ], 403);
            }

            if (!$userData) {
                return response()->json([
                    'errors' => 'User not found.'
                ], 404);
            }

            if ($userData->hasRole('superadmin') || ($userData->hasRole('admin') && $user->id !== $userData->id)) {
                return response()->json([
                    'errors' => 'You are not allowed to update the password of superadmin or other admin users.'
                ], 403);
            }

            // Check if the current password matches with old password
            if (password_verify($request->password, $userData->password)) {
                return response()->json([
                    'errors' => 'New password cannot be the same as the old password.'
                ], 422); // Return a 422 Unprocessable Entity status code
            }

            DB::beginTransaction();

            $userData->update([
                'password' => bcrypt($request->password),
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Password updated successfully.'
            ], 200);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Error occurred on updating password: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred while updating the password.'
            ], 500);
        }
    }

    /**
     * Delete a user from the system.
     *
     * This function deletes a user from the database and removes associated files and folders from storage.
     * It requires admin authentication and prevents the deletion of superadmin users.  This is a dangerous
     * function and should be used with caution.
     * 
     * Requires admin authentication.
     *
     * @param string $userIdToBeDeleted The UUID of the user to be deleted.
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteUserFromAdmin($userIdToBeDeleted)
    {
        $user = Auth::user();

        $checkAdmin = $this->checkAdminService->checkAdminWithPermission('users.delete');

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        try {
            $userAdminData = User::where('id', $user->id)->first();
            $adminInstance = $userAdminData->instances()->first();

            // Delete the user from the database.
            $userData = User::where('id', $userIdToBeDeleted)->first();
            $userDataInstance = $userData->instances()->first();

            if ($userDataInstance->id !== $adminInstance->id) {
                return response()->json([
                    'errors' => 'You cannot delete user data from a different instance.'
                ], 403);
            }

            if (!$userData) {
                return response()->json([
                    'errors' => 'User not found.'
                ], 404);
            }

            if ($userData->hasRole('superadmin') || ($userData->hasRole('admin') && $user->id !== $userData->id)) {
                return response()->json([
                    'errors' => 'You are not allowed to delete superadmin or other admin users.',
                ], 403);
            }

            DB::beginTransaction();

            // Hapus folder dan file terkait dari local storage
            $folders = Folder::where('user_id', $userData->id)->get();

            if ($folders->count() > 0) {
                foreach ($folders as $folder) {
                    $this->deleteFolderAndFiles($folder->id); // Pass folder ID instead of the object
                }
            }

            // Cek apakah ada foto profil lama dan hapus jika ada
            if ($userData->photo_profile_path && Storage::disk('public')->exists($userData->photo_profile_path)) {
                Storage::disk('public')->delete($userData->photo_profile_path);
            }

            $userData->delete();

            DB::commit();

            return response()->json([
                'message' => 'User deleted successfully.',
            ], 200);
        } catch (Exception $e) {
            DB::rollBack();

            // Log the error if an exception occurs.
            Log::error('Error occurred on deleting user: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);
            return response()->json([
                'errors' => 'An error occured on deleting user.',
            ], 500);
        }
    }

    /**
     * Recursively delete a folder and its contents.
     *
     * This function recursively deletes a folder and all its files and subfolders from both the database and storage.
     * 
     * @param Folder $folder The folder to delete.
     * @throws \Exception If any error occurs during the deletion process.
     */
    private function deleteFolderAndFiles($folderId)
    {
        try {
            // Find the folder by ID
            $folder = Folder::find($folderId);

            // If the folder doesn't exist, log a warning and return
            if (!$folder) {
                Log::warning("Folder with ID {$folderId} not found during deletion.");
                return;
            }

            // Hapus semua file dalam folder
            $files = $folder->files;

            DB::beginTransaction();

            foreach ($files as $file) {
                try {
                    // Hapus file dari storage
                    Storage::delete($file->path);
                    // Hapus data file dari database
                    $file->delete();
                } catch (\Exception $e) {
                    Log::error('Error occurred while deleting file with ID ' . $file->id . ': ' . $e->getMessage(), [
                        'trace' => $e->getTrace()
                    ]);
                    // Lemparkan kembali exception agar dapat ditangani di tingkat pemanggil
                    throw $e;
                }
            }

            // Hapus subfolder dan file dalam subfolder
            $subfolders = $folder->subfolders;
            foreach ($subfolders as $subfolder) {
                $this->deleteFolderAndFiles($subfolder->id); // Recursively delete subfolders
            }

            // Hapus folder dari storage
            try {
                $folderPath = $this->getPathService->getFolderPath($folder->id);
                if (Storage::exists($folderPath)) {
                    Storage::deleteDirectory($folderPath);
                }
            } catch (\Exception $e) {
                Log::error('Error occurred while deleting folder with ID ' . $folder->id . ': ' . $e->getMessage(), [
                    'trace' => $e->getTrace()
                ]);
                // Lemparkan kembali exception agar dapat ditangani di tingkat pemanggil
                throw $e;
            }

            // Hapus data folder dari database
            try {
                $folder->delete();
            } catch (\Exception $e) {
                Log::error('Error occurred while deleting folder record with ID ' . $folder->id . ': ' . $e->getMessage(), [
                    'trace' => $e->getTrace()
                ]);
                // Lemparkan kembali exception agar dapat ditangani di tingkat pemanggil
                throw $e;
            }

            // Commit transaksi setelah semua operasi berhasil
            DB::commit();
        } catch (\Exception $e) {
            // Rollback transaksi jika terjadi kesalahan
            DB::rollBack();
            Log::error('Error occurred while processing folder with ID ' . $folderId . ': ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);
            // Lemparkan kembali exception agar dapat ditangani di tingkat pemanggil
            throw $e;
        }
    }
}
