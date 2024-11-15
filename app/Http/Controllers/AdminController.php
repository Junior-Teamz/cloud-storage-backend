<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\Folder;
use App\Models\Instance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use App\Services\CheckAdminService;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    protected $checkAdminService;

    public function __construct(CheckAdminService $checkAdminService)
    {
        $this->checkAdminService = $checkAdminService;
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

        $checkAdmin = $this->checkAdminService->checkAdmin();

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        try {
            // Cari data berdasarkan Nama User
            if ($request->query('name')) {

                $keywordName = $request->query('name');

                // Cari user berdasarkan nama dengan relasi instances, roles, dan folder root
                $allUser = User::where('name', 'like', '%' . $keywordName . '%')
                    ->with([
                        'instances:id,name,address',
                        'folders' => function ($query) {
                            $query->whereNull('parent_id')->select('id'); // Ambil folder root dan hide nanoid
                        }
                    ])
                    ->paginate(10);

                // Tambahkan informasi role dan sembunyikan relasi roles
                $allUser->getCollection()->transform(function ($user) {
                    $user['role'] = $user->roles->pluck('name');
                    $user->makeHidden('roles');
                    return $user;
                });

                return response()->json($allUser, 200);
            }
            // Cari data berdasarkan Email User
            else if ($request->query('email')) {

                $keywordEmail = $request->query('email');

                // Cari user berdasarkan email dengan relasi instances, roles, dan folder root
                $allUser = User::where('email', 'like', '%' . $keywordEmail . '%')
                    ->with([
                        'instances:id,name,address',
                        'folders' => function ($query) {
                            $query->whereNull('parent_id')->select('id'); // Ambil folder root dan hide nanoid
                        }
                    ])
                    ->paginate(10);

                // Tambahkan informasi role dan sembunyikan relasi roles
                $allUser->getCollection()->transform(function ($user) {
                    $user['role'] = $user->roles->pluck('name');
                    $user->makeHidden('roles');
                    return $user;
                });

                return response()->json($allUser, 200);
            }
            // Cari berdasarkan nama instansi yang terdaftar pada user
            else if ($request->query('instance')) {

                $keywordInstance = $request->query('instance');

                // Cari user berdasarkan nama instansi dengan relasi instances, roles, dan folder root
                $allUser = User::whereHas('instances', function ($query) use ($keywordInstance) {
                    $query->where('name', 'like', '%' . $keywordInstance . '%');
                })
                    ->with([
                        'instances:id,name,address',
                        'folders' => function ($query) {
                            $query->whereNull('parent_id')->select('id'); // Ambil folder root dan hide nanoid
                        }
                    ])
                    ->paginate(10);

                // Tambahkan informasi role dan sembunyikan relasi roles
                $allUser->getCollection()->transform(function ($user) {
                    $user['role'] = $user->roles->pluck('name');
                    $user->makeHidden('roles');
                    return $user;
                });

                return response()->json($allUser, 200);
            } else {
                // Ambil semua user dengan relasi instances, roles, dan folder root
                $allUser = User::with([
                    'instances:id,name,address',
                    'folders' => function ($query) {
                        $query->whereNull('parent_id')->select('id'); // Ambil folder root dan hide nanoid
                    }
                ])->paginate(10);

                // Iterasi setiap user dan tambahkan informasi role serta sembunyikan relasi roles
                $allUser->getCollection()->transform(function ($user) {
                    $user['role'] = $user->roles->pluck('name');
                    $user->makeHidden('roles');
                    return $user;
                });

                return response()->json($allUser, 200);
            }
        } catch (\Exception $e) {
            Log::error("Error occurred on getting user list: " . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred on getting user list.',
            ], 500);
        }
    }

    /**
     * Count all users.
     *
     * This function counts the total number of users, users with the 'user' role, and users with the 'admin' role.
     * 
     * Requires admin authentication.
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function countAllUser()
    {
        $checkAdmin = $this->checkAdminService->checkAdmin();

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        try {
            // Menghitung total user
            $totalUserCount = User::count();

            // Menghitung user dengan role 'user' dan 'admin' menggunakan Spatie Laravel Permission
            $userRoleCount = User::role('user')->count(); // Menggunakan metode role() dari Spatie
            $adminRoleCount = User::role('admin')->count(); // Menggunakan metode role() dari Spatie

            if ($totalUserCount === 0) {
                return response()->json([
                    'message' => 'No users are registered.',
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
     * Get user admin information by UUID.
     *
     * This function retrieves detailed information about a specific user, including their roles and instances.
     * 
     * Requires admin authentication.
     *
     * @param string $id The UUID of the user.
     * @return \Illuminate\Http\JsonResponse
     */
    public function user_info($id)
    {
        $user = Auth::user();

        $checkAdmin = $this->checkAdminService->checkAdmin();

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        try {

            $user = User::where('id', $id)->with('instances:id,name,address')->first();

            if (!$user) {
                return response()->json([
                    'message' => "User not found.",
                    'data' => []
                ], 200);
            }

            $user['role'] = $user->roles->pluck('name');

            // Sembunyikan relasi roles dari hasil response
            $user->makeHidden('roles');

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
        $checkAdmin = $this->checkAdminService->checkAdmin();

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
                'unique:users,email', // Menentukan kolom yang dicek di tabel users
                function ($attribute, $value, $fail) {
                    // Validasi format email menggunakan Laravel's 'email' rule
                    if (!preg_match('/^\w+([\.-]?\w+)*@\w+([\.-]?\w+)*(\.\w{2,})+$/', $value)) {
                        $fail('Invalid email format.');
                    }

                    // Daftar domain yang valid
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

                    // Ambil domain dari alamat email
                    $domain = strtolower(substr(strrchr($value, '@'), 1));

                    // Periksa apakah domain email diizinkan
                    if (!in_array($domain, $allowedDomains)) {
                        $fail('Invalid email domain.');
                    }
                },
            ],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role' => ['required', 'string', 'exists:roles,name'],
            'instance_id' => ['required', 'string', 'exists:instances,id'],
            'photo_profile' => ['nullable', 'file', 'max:3000', 'mimes:jpg,jpeg,png']
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $instance = Instance::where('id', $request->instance_id)->first();

            DB::beginTransaction();

            $newUser = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => bcrypt($request->password),
            ]);

            $newUser->assignRole($request->role);

            $newUser->instances()->sync($instance->id);

            if ($request->has('photo_profile')) {

                $photoFile = $request->file('photo_profile');

                $photoProfilePath = 'users_photo_profile';

                // Cek apakah folder users_photo_profile ada di disk public, jika tidak, buat folder tersebut
                if (!Storage::disk('public')->exists($photoProfilePath)) {
                    Storage::disk('public')->makeDirectory($photoProfilePath);
                }

                // Simpan file thumbnail ke storage/app/public/news_thumbnail
                $photoProfile = $photoFile->store($photoProfilePath, 'public');

                // Buat URL publik untuk thumbnail
                $photoProfileUrl = Storage::disk('public')->url($photoProfile);

                $newUser->photo_profile_path = $photoProfile;
                $newUser->photo_profile_url = $photoProfileUrl;
                $newUser->save();
            }

            $newUser->load('instances:id,name,address');

            $newUser['role'] = $newUser->roles->pluck('name');

            // Cari folder yang terkait dengan user yang baru dibuat
            $userFolders = Folder::where('user_id', $newUser->id)->get();

            foreach ($userFolders as $folder) {
                // Perbarui relasi instance pada setiap folder terkait
                $folder->instances()->sync($instance->id);
            }

            // Sembunyikan relasi roles dari hasil response
            $newUser->makeHidden('roles');

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
        $checkAdmin = $this->checkAdminService->checkAdmin();

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
                    // Validasi format email menggunakan Laravel's 'email' rule
                    if (!preg_match('/^\w+([\.-]?\w+)*@\w+([\.-]?\w+)*(\.\w{2,})+$/', $value)) {
                        $fail('Invalid email format.');
                    }

                    // Daftar domain yang valid
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

                    // Ambil domain dari alamat email
                    $domain = strtolower(substr(strrchr($value, '@'), 1));

                    // Periksa apakah domain email diizinkan
                    if (!in_array($domain, $allowedDomains)) {
                        $fail('Invalid email domain.');
                    }
                },
                // Validasi unique email kecuali email yang sudah ada (email saat ini)
                Rule::unique('users', 'email')->ignore($userIdToBeUpdated)
            ],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'role' => ['nullable', 'string', 'exists:roles,name'],
            'instance_id' => ['nullable', 'string', 'exists:instances,id'],
            'photo_profile' => ['nullable', 'file', 'max:3000', 'mimes:jpg,jpeg,png']
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $userToBeUpdated = User::where('id', $userIdToBeUpdated)->first();

            if (!$userToBeUpdated) {
                return response()->json([
                    'errors' => 'User not found.'
                ], 404);
            }

            if ($userToBeUpdated->is_superadmin == 1) {
                if(!Auth::user()->id === $userToBeUpdated->id){
                    return response()->json([
                        'errors' => 'You are not allowed to update superadmin user.',
                    ], 403);
                }
            }

            DB::beginTransaction();

            $userToBeUpdated->update([
                'name' => $request->name,
                'email' => $request->email,
                'password' => bcrypt($request->password),
            ]);

            if($request->role){
                $userToBeUpdated->assignRole($request->role);
            }

            if ($request->instance_id) {

                $instance = Instance::where('id', $request->instance_id)->first();

                // Perbarui instance user
                $userToBeUpdated->instances()->sync($instance->id);

                // Cari folder yang terkait dengan user
                $userFolders = Folder::where('user_id', $userToBeUpdated->id)->get();

                foreach ($userFolders as $folder) {
                    // Perbarui relasi instance pada setiap folder terkait
                    $folder->instances()->sync($instance->id);
                }
            }

            if ($request->has('photo_profile')) {
                $photoFile = $request->file('photo_profile');

                $photoProfilePath = 'users_photo_profile';

                // Cek apakah folder users_photo_profile ada di disk public, jika tidak, buat folder tersebut
                if (!Storage::disk('public')->exists($photoProfilePath)) {
                    Storage::disk('public')->makeDirectory($photoProfilePath);
                }

                // Cek apakah ada foto profil lama dan hapus jika ada
                if ($userToBeUpdated->photo_profile_path && Storage::disk('public')->exists($userToBeUpdated->photo_profile_path)) {
                    Storage::disk('public')->delete($userToBeUpdated->photo_profile_path);
                }

                // Simpan file foto profil ke storage/app/public/news_foto profil
                $photoProfile = $photoFile->store($photoProfilePath, 'public');

                // Buat URL publik untuk foto profil
                $photoProfileUrl = Storage::disk('public')->url($photoProfile);

                $userToBeUpdated->photo_profile_path = $photoProfile;
                $userToBeUpdated->photo_profile_url = $photoProfileUrl;
                $userToBeUpdated->save();
            }

            DB::commit();

            $userToBeUpdated->load('instances:id,name,address');

            $userToBeUpdated['role'] = $userToBeUpdated->roles->pluck('name');

            // Sembunyikan relasi roles dari hasil response
            $userToBeUpdated->makeHidden('roles');

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
        $checkAdmin = $this->checkAdminService->checkAdmin();

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
            $user = User::where('id', $userId)->first();

            if (!$user) {
                return response()->json([
                    'errors' => 'User not found.'
                ], 404);
            }

            // Check if the current password matches with old password
            if (password_verify($request->password, $user->password)) {
                return response()->json([
                    'errors' => 'New password cannot be the same as the old password.'
                ], 422); // Return a 422 Unprocessable Entity status code
            }

            DB::beginTransaction();

            $user->update([
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
                'errors' => 'An error occurred while updating your password.'
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
        $checkAdmin = $this->checkAdminService->checkAdmin();

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        // if ($user->id == $userIdToBeDeleted) {
        //     return response()->json([
        //         'errors' => 'Anda tidak diizinkan untuk menghapus diri sendiri.',
        //     ], 403);
        // }

        try {
            // Delete the user from the database.
            $userData = User::where('id', $userIdToBeDeleted)->first();

            if (!$userData) {
                return response()->json([
                    'errors' => 'User not found.'
                ], 404);
            }

            if ($userData->is_superadmin == 1) {
                return response()->json([
                    'errors' => 'You are not allowed to delete superadmin user.',
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
                $folderPath = $this->getFolderPath($folder->id);
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

    /**
     * Recursively get the storage path for a folder.
     *
     * This function builds the full storage path for a given folder ID by recursively traversing its parent folders.
     *
     * @param string|null $parentId The UUID of the folder.
     * @return string The storage path for the folder.
     */
    private function getFolderPath($parentId)
    {
        if ($parentId === null) {
            return ''; // Root directory, no need for 'folders' base path
        }

        $parentFolder = Folder::findOrFail($parentId);
        $path = $this->getFolderPath($parentFolder->parent_id);

        // Use the folder's NanoID in the storage path
        $folderNameWithNanoId = $parentFolder->nanoid;

        return $path . '/' . $folderNameWithNanoId;
    }


    // Dibawah ini, function endpoint untuk mendapatkan statistik semua folder dan file yang ada. HANYA DIGUNAKAN UNTUK SUPERADMIN.

    /**
     * Get total storage usage.
     *
     * This function calculates the total storage used across all folders and files in the system.
     * 
     * Requires super admin authentication.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function storageUsage()
    {
        $userInfo = Auth::user();

        $checkSuperAdmin = $this->checkAdminService->checkSuperAdmin();

        if (!$checkSuperAdmin) {
            Log::warning('Attempt to access features that can only be accessed by super admins', [
                'user_id' => $userInfo->id,
                'user_role' => $userInfo->roles->pluck('name'),
            ]);
            return response()->json([
                'errors' => 'You cannot perform this action.'
            ], 403);
        }

        try {
            // Dapatkan semua folder
            $allFolders = Folder::whereNull('parent_id')->get();

            if (!$allFolders) {
                return response()->json([
                    'message' => 'No folder was created.'
                ], 200);
            }

            // Hitung total penyimpanan
            $totalUsedStorage = $this->calculateFolderSize($allFolders);

            // Format ukuran penyimpanan ke dalam KB, MB, atau GB
            $formattedStorageSize = $this->formatSizeUnits($totalUsedStorage);

            return response()->json([
                'message' => 'Storage Usage Total: ' . $formattedStorageSize,
                'data' => [
                    'rawSize' => $totalUsedStorage,
                    'formattedSize' => $formattedStorageSize
                ]
            ]);
        } catch (Exception $e) {
            Log::error('Error occured while retrieving storage usage total: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occured while retrieving storage usage total.'
            ], 500);
        }
    }

    /**
     * Get storage usage per instance.
     *
     * This function calculates the storage usage for each instance in the system.  It retrieves all instances,
     * calculates the total storage used by their associated files, and returns the results in a formatted JSON response.
     * 
     * Requires super admin authentication.
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function storageUsagePerInstance()
    {
        $userInfo = Auth::user();

        // Pastikan hanya super admin yang dapat mengakses fitur ini
        $checkSuperAdmin = $this->checkAdminService->checkSuperAdmin();

        if (!$checkSuperAdmin) {
            Log::warning('Attempt to access features that can only be accessed by super admins', [
                'user_id' => $userInfo->id,
                'user_role' => $userInfo->roles->pluck('name'),
            ]);
            return response()->json([
                'errors' => 'You cannot perform this action.'
            ], 403);
        }

        try {
            // Ambil semua instance
            $instances = Instance::with(['files'])->get();

            $storageUsagePerInstance = [];

            foreach ($instances as $instance) {
                // Hitung total penggunaan penyimpanan dari semua file yang terkait dengan instance ini
                $totalStorageUsage = $instance->files->sum('size');

                $storageUsagePerInstance[] = [
                    'instance_id' => $instance->id,
                    'instance_name' => $instance->name,
                    'storage_usage_raw' => $totalStorageUsage, // dalam bytes
                    'storage_usage_formatted' => $this->formatSizeUnits($totalStorageUsage), // format sesuai ukuran (KB, MB, GB, dll.)
                ];
            }

            return response()->json($storageUsagePerInstance, 200);
        } catch (Exception $e) {
            Log::error('Error occurred while calculating storage usage per instance: ' . $e->getMessage(), [
                'user_id' => $userInfo->id,
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred while calculating storage usage.'
            ], 500);
        }
    }

    /**
     * Count all folders.
     *
     * This function counts the total number of folders in the system excluding root folders.
     * 
     * Requires super admin authentication.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function allFolderCount()
    {
        // variable ini hanya untuk mendapatkan user info yang mengakses endpoint ini.
        $userInfo = Auth::user();

        $checkSuperAdmin = $this->checkAdminService->checkSuperAdmin();

        if (!$checkSuperAdmin) {
            Log::warning('Attempt to access features that can only be accessed by super admins', [
                'user_id' => $userInfo->id,
                'user_role' => $userInfo->roles->pluck('name'),
            ]);
            return response()->json([
                'errors' => 'You cannot perform this action.'
            ], 403);
        }

        try {
            $countAllFolder = Folder::whereNotNull('parent_id')->count();

            if ($countAllFolder == 0) {
                return response()->json([
                    'message' => 'No folders created.',
                    'data' => [
                        'count_folder' => $countAllFolder
                    ]
                ], 200);
            }

            return response()->json([
                'count_folder' => $countAllFolder
            ], 200);
        } catch (Exception $e) {
            Log::error('Error occured while counting all folder: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);
            return response()->json([
                'errors' => 'An error occured while fetching count all folder,'
            ], 500);
        }
    }

    /**
     * Count all files.
     *
     * This function counts the total number of files and calculates their total size.
     * 
     * Requires super admin authentication.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function allFileCount()
    {
        // variable ini hanya untuk mendapatkan user info yang mengakses endpoint ini.
        $userInfo = Auth::user();

        $checkSuperAdmin = $this->checkAdminService->checkSuperAdmin();

        if (!$checkSuperAdmin) {
            Log::warning('Attempt to access features that can only be accessed by super admins', [
                'user_id' => $userInfo->id,
                'user_role' => $userInfo->roles->pluck('name'),
            ]);
            return response()->json([
                'errors' => 'You cannot perform this action.'
            ], 403);
        }

        try {
            $getAllFile = File::get();

            $countFileTotal = $getAllFile->count();
            $countFileSize = $getAllFile->sum('size');
            $formattedCountFileSize = $this->formatSizeUnits($countFileSize);

            if ($countFileTotal == 0) {
                return response()->json([
                    'message' => 'No files.',
                    'data' => [
                        'count_file' => $countFileTotal,
                        'count_size_all_files' => $countFileSize,
                        'formatted_count_size_all_files' => $formattedCountFileSize
                    ]
                ], 200);
            }

            return response()->json([
                'count_all_files' => $countFileTotal,
                'count_size_all_files' => $countFileSize,
                'formatted_count_size_all_files' => $formattedCountFileSize
            ], 200);
        } catch (Exception $e) {
            Log::error('Error occured while counting all files: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);
            return response()->json([
                'errors' => 'An error occured while fetching count all files,'
            ], 500);
        }
    }

    /**
     * Format bytes into a human-readable size unit (KB, MB, GB).
     *
     * This helper function converts a given size in bytes into a more user-friendly representation,
     * using KB, MB, or GB as appropriate.
     *
     * @param int $bytes The size in bytes.
     * @return string The formatted size string.
     */
    private function formatSizeUnits($bytes)
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } elseif ($bytes > 1) {
            return $bytes . ' bytes';
        } elseif ($bytes == 1) {
            return $bytes . ' byte';
        } else {
            return '0 bytes';
        }
    }

    /**
     * Recursively calculate the total size of a folder and its subfolders.
     *
     * This function calculates the total size of a given folder by summing the sizes of its files and
     * recursively calling itself for any subfolders.
     *
     * @param Folder $folder The folder to calculate the size of.
     * @return int The total size of the folder and its subfolders in bytes.
     */
    private function calculateFolderSize(Folder $folder)
    {
        $totalSize = 0;

        // Hitung ukuran semua file di folder
        foreach ($folder->files as $file) {
            $totalSize += $file->size;
        }

        // Rekursif menghitung ukuran semua subfolder
        foreach ($folder->subfolders as $subfolder) {
            $totalSize += $this->calculateFolderSize($subfolder);
        }

        return $totalSize;
    }
}
