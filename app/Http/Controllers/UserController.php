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

    /**
     * Get the authenticated user's information.
     *
     * This method retrieves the details of the currently authenticated user, including their associated instances.
     * It returns a JSON response containing the user's information or an error message if retrieval fails.
     *
     * @return \Illuminate\Http\JsonResponse A JSON response with the user's data or an error message.
     */
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

    /**
     * Update the authenticated user's information.
     *
     * This method allows the currently authenticated user to update their profile information,
     * including their name, email, and password. It validates the input data, updates the user
     * record in the database, and returns a JSON response indicating success or failure.
     *
     * @param  \Illuminate\Http\Request  $request The incoming HTTP request containing the updated user data.
     * @return \Illuminate\Http\JsonResponse A JSON response indicating success or failure, with appropriate status codes and messages.
     */
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
     * Delete the authenticated user's account.
     *
     * This method allows the currently authenticated user to delete their account.
     * It will also delete all folders and files associated with the user.
     * 
     * **Caution:** This action is irreversible. Once the account is deleted, all data associated with it will be permanently removed.
     *
     * @return \Illuminate\Http\JsonResponse A JSON response indicating success or failure, with appropriate status codes and messages.
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
     * Recursively delete a folder and its contents.
     *
     * This function recursively deletes a folder and all its files and subfolders from both the database and storage.
     *
     * @param Folder $folder The folder to delete.
     * @throws \Exception If any error occurs during the deletion process.
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
}
