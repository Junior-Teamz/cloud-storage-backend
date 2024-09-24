<?php

namespace App\Http\Controllers;

use App\Models\Folder;
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
            Log::error("Error occurred on getting user list: " . $e->getMessage());

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
            $userCount = User::count();

            if ($userCount->isEmpty()) {
                return response()->json([
                    'message' => 'User registered is empty.',
                    'user_count' => $userCount
                ]);
            }

            return response()->json([
                'user_count' => $userCount
            ]);
        } catch (Exception $e) {

            Log::error('Error occurred on getting user count: ' . $e->getMessage());

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

            $user['role'] = $user->roles->pluck('name');

            // Sembunyikan relasi roles dari hasil response
            $user->makeHidden('roles');

            return response()->json([
                'data' => $user
            ]);
        } catch (Exception $e) {
            Log::error('Error occurred on getting user information: ' . $e->getMessage());
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
            'name' => ['required', 'string', 'max:100'],
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
            'instance_id' => ['required', 'integer', 'exists:instances,id'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $newUser = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => bcrypt($request->password),
            ]);

            $newUser->assignRole($request->role);

            $newUser->instances()->sync($request->instance_id);

            DB::commit();

            $newUser->load('instances:id,name,address');

            $newUser['role'] = $newUser->roles->pluck('name');

            // Sembunyikan relasi roles dari hasil response
            $newUser->makeHidden('roles');

            return response()->json([
                'message' => 'User created successfully.',
                'data' => $newUser
            ], 201);
        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Error occurred on adding user: ' . $e->getMessage());
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
            'name' => ['required', 'string', 'max:100'],
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
            'instance_id' => ['required', 'integer', 'exists:instances,id'],
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
            $userToBeUpdated->instances()->sync($request->instance_id);

            // Cari folder yang terkait dengan user
            $userFolders = Folder::where('user_id', $userToBeUpdated->id)->get();

            foreach ($userFolders as $folder) {
                // Perbarui relasi instance pada setiap folder terkait
                $folder->instances()->sync($request->instance_id);
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

            Log::error('Error occurred on updating user: ' . $e->getMessage());
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

            if (!$folders->isEmpty()) {
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
            Log::error('Error occurred on deleting user: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
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
                    Log::error('Error occurred while deleting file with ID ' . $file->id . ': ' . $e->getMessage());
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
                Log::error('Error occurred while deleting folder with ID ' . $folder->id . ': ' . $e->getMessage());
                // Lemparkan kembali exception agar dapat ditangani di tingkat pemanggil
                throw $e;
            }

            // Hapus data folder dari database
            try {
                $folder->delete();
            } catch (\Exception $e) {
                Log::error('Error occurred while deleting folder record with ID ' . $folder->id . ': ' . $e->getMessage());
                // Lemparkan kembali exception agar dapat ditangani di tingkat pemanggil
                throw $e;
            }

            // Commit transaksi setelah semua operasi berhasil
            DB::commit();
        } catch (\Exception $e) {
            // Rollback transaksi jika terjadi kesalahan
            DB::rollBack();
            Log::error('Error occurred while processing folder with ID ' . $folder->id . ': ' . $e->getMessage());
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
}
