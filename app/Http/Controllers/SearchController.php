<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class SearchController extends Controller
{
    public function searchFoldersAndFiles(Request $request)
    {
        // Mendapatkan user yang sedang login
        $user = Auth::user();

        // Validasi input 'name', 'per_page', 'folder_page', dan 'file_page'
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'per_page' => 'nullable|min:1', // Tentukan jumlah item per halaman jika ada
            'folder_page' => 'nullable|min:1', // Halaman spesifik untuk folder
            'file_page' => 'nullable|min:1', // Halaman spesifik untuk file
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            // Ambil jumlah item per halaman, default 10
            $perPage = $request->get('per_page', 10);
            // Ambil halaman spesifik untuk folder dan file, default ke halaman 1
            $folderPage = $request->get('folder_page', 1);
            $filePage = $request->get('file_page', 1);

            // Pencarian folder milik user
            $ownFoldersQuery = Folder::where('user_id', $user->id)
                ->where('name', 'LIKE', '%' . $request->name . '%')
                ->with(['user:id,name,email', 'tags:id,name', 'instances:id,name,address']);

            // Pencarian file milik user
            $ownFilesQuery = File::where('user_id', $user->id)
                ->where('name', 'LIKE', '%' . $request->name . '%')
                ->with(['user:id,name,email', 'tags:id,name', 'instances:id,name,address']);

            // Pencarian folder yang dibagikan kepada user
            $sharedFoldersQuery = Folder::whereHas('userFolderPermissions', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })
                ->where('name', 'LIKE', '%' . $request->name . '%')
                ->with(['user:id,name,email', 'tags:id,name', 'instances:id,name,address']);

            // Pencarian file yang dibagikan kepada user
            $sharedFilesQuery = File::whereHas('userPermissions', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })
                ->where('name', 'LIKE', '%' . $request->name . '%')
                ->with(['user:id,name,email', 'tags:id,name', 'instances:id,name,address']);

            // Paginasi untuk folder milik user dan folder yang dibagikan
            $ownFolders = $ownFoldersQuery->paginate($perPage, ['*'], 'own_folders_page', $folderPage);
            $sharedFolders = $sharedFoldersQuery->paginate($perPage, ['*'], 'shared_folders_page', $folderPage);

            // Paginasi untuk file milik user dan file yang dibagikan
            $ownFiles = $ownFilesQuery->paginate($perPage, ['*'], 'own_files_page', $filePage);
            $sharedFiles = $sharedFilesQuery->paginate($perPage, ['*'], 'shared_files_page', $filePage);

            // Menyembunyikan kolom 'path' dan 'nanoid' pada hasil pencarian
            $ownFolders->makeHidden(['path', 'nanoid']);
            $sharedFolders->makeHidden(['path', 'nanoid']);
            $ownFiles->makeHidden(['path', 'nanoid']);
            $sharedFiles->makeHidden(['path', 'nanoid']);

            // Return hasil pencarian untuk folder dan file milik sendiri dan yang dibagikan
            return response()->json([
                'data' => [
                    'own_folders' => $ownFolders,
                    'own_files' => $ownFiles,
                    'shared_folders' => $sharedFolders,
                    'shared_files' => $sharedFiles,
                ],
            ], 200);
        } catch (\Exception $e) {
            Log::error('An error occurred while searching for folders and files: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred while searching for folders and files.'
            ], 500);
        }
    }

    public function searchUser(Request $request)
    {
        try {
            // Ambil query 'name' dan 'email' jika ada
            $keywordName = $request->query('name');
            $keywordEmail = $request->query('email');

            // Buat query dasar dengan relasi dan kolom yang dipilih
            $query = User::with('instances:id,name,address')->select('id', 'name', 'email');

            // Jika ada query name, tambahkan kondisi pencarian untuk name
            if ($keywordName) {
                $query->where('name', 'like', '%' . $keywordName . '%');
            }

            // Jika ada query email, tambahkan kondisi pencarian untuk email
            if ($keywordEmail) {
                $query->where('email', 'like', '%' . $keywordEmail . '%');
            }

            // Dapatkan hasil dengan pagination
            $allUser = $query->paginate(10);

            // Sembunyikan relasi roles dari hasil response
            $allUser->makeHidden('roles');

            // Kembalikan hasil pagination tanpa membungkus lagi
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
}
