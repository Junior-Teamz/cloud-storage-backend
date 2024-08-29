<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use Exception;

class AdminController extends Controller
{

    public function index()
    {
        $user = Auth::user();

        if (!($user->hasRole('admin') && $user->is_superadmin == 1)) {
            return response()->json([
                'error' => 'Anda tidak di izinkan untuk mengupdate user.',
            ], 403);
        }

        try {

            $admin = User::where('id', $user->id)->first();

            return response()->json([
                'data' => $admin
            ]);

        } catch (Exception $e) {
            Log::error('Error occurred on getting admin information: ' . $e->getMessage());
            return response()->json([
                'errors' => 'Terjadi kesalahan ketika mengambil data tentang admin.',
            ], 500);
        }
    }

    public function user_info($id) 
    {
        $user = Auth::user();

        if (!($user->hasRole('admin') && $user->is_superadmin == 1)) {
            return response()->json([
                'error' => 'Anda tidak di izinkan untuk melakukan tindakan ini.',
            ], 403);
        }

        try {

            $user = User::where('id', $id)->first();

            return response()->json([
                'data' => $user
            ]);

        } catch (Exception $e) {
            Log::error('Error occurred on getting user information: ' . $e->getMessage());
            return response()->json([
                'errors' => 'Terjadi kesalahan ketika mengambil data user.',
            ], 500);
        }
    }

    public function createUserFromAdmin(Request $request)
    {
        $user = Auth::user();

        if (!($user->hasRole('admin') && $user->is_superadmin == 1)) {
            return response()->json([
                'error' => 'Anda tidak di izinkan untuk mengupdate user.',
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
            'role' => ['required', 'string', 'exists:roles,name'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $newUser = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => bcrypt($request->password),
            ]);

            $newUser->assignRole($request->role);

            return response()->json([
                'message' => 'User berhasil ditambahkan',
                'data' => $newUser
            ], 201);
        } catch (Exception $e) {
            Log::error('Error occurred on adding user: ' . $e->getMessage());
            return response()->json([
                'errors' => 'Terjadi kesalahan ketika menambahkan user.',
            ], 500);
        }
    }

    public function updateUserFromAdmin(Request $request, $userIdToBeUpdated)
    {
        $user = Auth::user();

        if (!($user->hasRole('admin') && $user->is_superadmin == 1)) {
            return response()->json([
                'error' => 'Anda tidak di izinkan untuk mengupdate user.',
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
            $updatedUser = User::where('id', $userIdToBeUpdated)->update([
                'name' => $request->name,
                'email' => $request->email,
                'password' => bcrypt($request->password),
            ]);
            return response()->json([
                'message' => 'User berhasil diupdate',
                'data' => $updatedUser
            ], 200);
        } catch (Exception $e) {
            Log::error('Error occurred on updating user: ' . $e->getMessage());
            return response()->json([
                'errors' => 'Terjadi kesalahan ketika mengupdate user.',
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
        $user = Auth::user();

        // Check if the user has the required permission to delete users.
        if (!($user->hasRole('admin') && $user->is_superadmin == 1)) {
            return response()->json([
                'error' => 'Anda tidak di izinkan untuk menghapus user.',
            ], 403);
        }

        try {
            // Delete the user from the database.
            User::where('id', $userIdToBeDeleted)->delete();
            return response()->json([
                'message' => 'User berhasil di hapus'
            ], 200);
        } catch (Exception $e) {
            // Log the error if an exception occurs.
            Log::error('Error occurred on deleting user: ' . $e->getMessage());
            return response()->json([
                'errors' => 'Terjadi kesalahan ketika menghapus user.',
            ], 500);
        }
    }
}
