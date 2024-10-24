<?php

namespace App\Http\Controllers;

use App\Models\Folder;
use App\Models\Instance;
use App\Models\User;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    // public function register(Request $request)
    // {
    //     $validator = Validator::make($request->all(), [
    //         'name' => ['required', 'string', 'max:255'],
    //         'email' => [
    //             'required',
    //             'email',
    //             'unique:users,email', // Menentukan kolom yang dicek di tabel users
    //             function ($attribute, $value, $fail) {
    //                 // Validasi format email menggunakan Laravel's 'email' rule
    //                 if (!preg_match('/^\w+([\.-]?\w+)*@\w+([\.-]?\w+)*(\.\w{2,})+$/', $value)) {
    //                     $fail('Invalid email format.');
    //                 }

    //                 // Daftar domain yang valid
    //                 $allowedDomains = [
    //                     'outlook.com',
    //                     'yahoo.com',
    //                     'aol.com',
    //                     'lycos.com',
    //                     'mail.com',
    //                     'icloud.com',
    //                     'yandex.com',
    //                     'protonmail.com',
    //                     'tutanota.com',
    //                     'zoho.com',
    //                     'gmail.com'
    //                 ];

    //                 // Ambil domain dari alamat email
    //                 $domain = strtolower(substr(strrchr($value, '@'), 1));

    //                 // Periksa apakah domain email diizinkan
    //                 if (!in_array($domain, $allowedDomains)) {
    //                     $fail('Invalid email domain.');
    //                 }
    //             },
    //         ],
    //         'password' => ['required', 'string', 'min:8', 'confirmed'],
    //         'instance_id' => ['required', 'string', 'exists:instances,id'],
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json([
    //             'errors' => $validator->errors()
    //         ], 422);
    //     }

    //     try {
    //         $instance = Instance::where('id', $request->instance_id)->first();
    
    //         // MEMULAI TRANSACTION MYSQL
    //         DB::beginTransaction();

    //         $user = User::create([
    //             'name' => $request->name,
    //             'email' => $request->email,
    //             'password' => bcrypt($request->password),
    //         ]);

    //         $role = 'user';

    //         $user->assignRole($role);

    //         $user->instances()->sync($instance->id);

    //         $user->load('instances:id,name,address');

    //         $user['role'] = $user->roles->pluck('name');

    //         // Cari folder yang terkait dengan user yang baru dibuat
    //         $userFolders = Folder::where('user_id', $user->id)->get();

    //         foreach ($userFolders as $folder) {
    //             // Perbarui relasi instance pada setiap folder terkait
    //             $folder->instances()->sync($instance->id);
    //         }

    //         // Sembunyikan relasi roles dari hasil response
    //         $user->makeHidden('roles');

    //         // COMMIT JIKA TIDAK ADA KESALAHAN
    //         DB::commit();

    //         return response()->json([
    //             'message' => 'Berhasil Mendaftarkan Akun!',
    //             'data' => $user
    //         ], 201);
    //     } catch (Exception $e) {
    //         // ROLLBACK JIKA ADA KESALAHAN
    //         DB::rollBack();

    //         Log::error('Error occurred on registering user: ' . $e->getMessage(), [
    //                'trace' => $e->getTrace()
    //            ]);
    //         return response()->json([
    //             'errors' => 'Terjadi kesalahan ketika mendaftarkan akun.',
    //         ], 500);
    //     }
    // }

    // Informasi tentang akun yang sedang login saat ini
    public function index()
    {
        $user = Auth::user();

        try {

            $userInfo = User::where('id', $user->id)->with(['instances:id,name,address'])->first();

            $userInfo['role'] = $userInfo->roles->pluck('name');

            // Sembunyikan relasi roles dari hasil response
            $userInfo->makeHidden('roles');

            return response()->json([
                'data' => $userInfo
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

    public function update(Request $request)
    {
        $user = Auth::user();

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'unique:users,email', // Menentukan kolom yang dicek di tabel users
                function ($attribute, $value, $fail) {
                    // Validasi format email menggunakan Laravel's 'email' rule
                    if (!preg_match('/^\w+([\.-]?\w+)*@\w+([\.-]?\w+)*(\.\w{2,})+$/', $value)) {
                        $fail('Format email tidak valid.');
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
                        $fail('Domain email tidak valid.');
                    }
                },
            ],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $instance = Instance::where('id', $request->instance_id)->first();

            DB::beginTransaction();

            $updatedUser = User::where('id', $user->id)->update([
                'name' => $request->name,
                'email' => $request->email,
                'password' => bcrypt($request->password),
            ]);

            DB::commit();

            $updatedUser->load('instances:id,name,address');

            return response()->json([
                'message' => 'Data user berhasil diperbarui',
                'data' => $updatedUser
            ], 200);
        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Error occurred on updating user: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);
            return response()->json([
                'errors' => 'Terjadi kesalahan ketika mengupdate data user.',
            ], 500);
        }
    }

    /**
     * Delete the current user (DELETE).
     * 
     * This function is DANGEROUS and should be used with caution.
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function delete()
    {
        $user = Auth::user();

        try {
            DB::beginTransaction();

            // Hapus folder dan file terkait dari local storage
            $folders = Folder::where('user_id', $user->id)->get();

            if (!!$folders) {
                foreach ($folders as $folder) {
                    $this->deleteFolderAndFiles($folder);
                }
            }

            // Hapus data pengguna dari database
            $userData = User::where('id', $user->id);

            $userData->instances()->detach();
            $userData->delete();

            DB::commit();

            // Kembalikan respons sukses
            return response()->json([
                'message' => 'User berhasil dihapus'
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            // Log error jika terjadi exception
            Log::error('Error occurred on deleting user: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);

            // Kembalikan respons error
            return response()->json([
                'errors' => 'Terjadi kesalahan ketika menghapus user.',
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
        DB::beginTransaction();

        try {
            // Hapus semua file dalam folder
            $files = $folder->files;
            foreach ($files as $file) {
                try {
                    // Hapus file dari storage
                    Storage::delete($file->path);
                    // Hapus data file dari database
                    $file->delete();
                    DB::commit();
                } catch (\Exception $e) {
                    DB::rollBack();
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
                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Error occurred while deleting folder record with ID ' . $folder->id . ': ' . $e->getMessage());
                // Lemparkan kembali exception agar dapat ditangani di tingkat pemanggil
                throw $e;
            }
        } catch (\Exception $e) {
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
