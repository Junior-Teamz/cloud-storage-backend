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

    // informasi akun user spesifik
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

            if (!$user){
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

    public function updateUserFromAdmin(Request $request, $userIdToBeUpdated)
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
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'instance_id' => ['required', 'string', 'exists:instances,id'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $userToBeUpdated = User::where('id', $userIdToBeUpdated)->first();
            $instance = Instance::where('id', $request->instance_id)->first();

            if (!$userToBeUpdated) {
                return response()->json([
                    'errors' => 'User not found.'
                ], 404);
            }

            if ($userToBeUpdated->is_superadmin == 1) {
                return response()->json([
                    'errors' => 'You are not allowed to update superadmin user.',
                ], 403);
            }

            DB::beginTransaction();

            $userToBeUpdated->update([
                'name' => $request->name,
                'email' => $request->email,
                'password' => bcrypt($request->password),
            ]);

            // Perbarui instance user
            $userToBeUpdated->instances()->sync($instance->id);

            // Cari folder yang terkait dengan user
            $userFolders = Folder::where('user_id', $userToBeUpdated->id)->get();

            foreach ($userFolders as $folder) {
                // Perbarui relasi instance pada setiap folder terkait
                $folder->instances()->sync($instance->id);
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
     * Delete a user from admin (DELETE).
     * 
     * This function is DANGEROUS and should be used with caution.
     * 
     * @param int $userIdToBeDeleted The ID of the user to be deleted.
     * 
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

            // Hapus folder dan file terkait dari local storage
            $folders = Folder::where('user_id', $userData->id)->get();

            DB::beginTransaction();

            if (!!$folders) {
                foreach ($folders as $folder) {
                    $this->deleteFolderAndFiles($folder);
                }
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
     * Menghapus folder beserta file-file di dalamnya dari local storage
     *
     * @throws \Exception
     */
    private function deleteFolderAndFiles(Folder $folder)
    {
        try {
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
                $this->deleteFolderAndFiles($subfolder);
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
            Log::error('Error occurred while processing folder with ID ' . $folder->id . ': ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);
            // Lemparkan kembali exception agar dapat ditangani di tingkat pemanggil
            throw $e;
        }
    }

    /**
     * Get folder path based on parent folder id.
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



    /**
     * Dibawah ini, function endpoint untuk mendapatkan statistik semua
     * folder dan file yang ada. HANYA DIGUNAKAN UNTUK SUPERADMIN.
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
