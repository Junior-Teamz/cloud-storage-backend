<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\Folder;
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
            'per_page' => 'nullable|integer|min:1', // Tentukan jumlah item per halaman jika ada
            'folder_page' => 'nullable|integer|min:1', // Halaman spesifik untuk folder
            'file_page' => 'nullable|integer|min:1', // Halaman spesifik untuk file
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        try {
            // Pencarian folder berdasarkan nama yang cocok (LIKE)
            $foldersQuery = Folder::where('user_id', $user->id)
                ->where('name', 'LIKE', '%' . $request->name . '%')
                ->with(['user:id,name,email', 'tags:id,name', 'instances:id,name,address']);

            // Pencarian file berdasarkan nama yang cocok (LIKE)
            $filesQuery = File::where('user_id', $user->id)
                ->where('name', 'LIKE', '%' . $request->name . '%')
                ->with(['user:id,name,email', 'tags:id,name', 'instances:id,name,address']);

            // Ambil jumlah item per halaman, default 10
            $perPage = $request->get('per_page', 10);

            // Ambil halaman spesifik untuk folder dan file, default ke halaman 1
            $folderPage = $request->get('folder_page', 1);
            $filePage = $request->get('file_page', 1);

            // Paginasi untuk folder dan file dengan halaman terpisah
            $folders = $foldersQuery->paginate($perPage, ['*'], 'folders_page', $folderPage);
            $files = $filesQuery->paginate($perPage, ['*'], 'files_page', $filePage);

            // Menyembunyikan kolom 'path' dan 'nanoid' pada hasil pencarian
            $folders->makeHidden(['path', 'nanoid']);
            $files->makeHidden(['path', 'nanoid']);

            // Return hasil pencarian untuk folder dan file
            return response()->json([
                'data' => [
                    'folders' => $folders,
                    'files' => $files,
                ],
            ], 200);
        } catch (\Exception $e) {
            Log::error('An error occurred while searching for folders and files: ' . $e->getMessage());

            return response()->json([
                'error' => 'An error occurred while searching for folders and files.'
            ], 500);
        }
    }
}
