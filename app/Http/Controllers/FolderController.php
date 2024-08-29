<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use App\Models\UserFolderPermission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class FolderController extends Controller
{
    /**
     * Check the user permission
     */
    private function checkPermissionFolder($folderId, $action)
    {
        $user = Auth::user();
        $folder = Folder::find($folderId);

        // If folder not found, return 404 error and stop the process
        if (!$folder) {
            return response()->json([
                'errors' => 'Folder dengan Folder ID yang anda masukan tidak ditemukan, Silahkan periksa kembali Folder ID yang anda masukan.'
            ], 404); // Setting status code to 404 Not Found
        }

        // Step 1: Check if the folder belongs to the logged-in user
        if ($folder->user_id === $user->id) {
            return true; // The owner has all permissions
        }

        // step ???? cek apakah user yang login adalah admin dan memiliki privilege SUPERADMIN
        if ($user->hasRole('admin') && $user->is_superadmin == 1) {
            return true;
        }
        // jika hanya admin dan tidak ada privilege SUPERADMIN, kembalikan false (tidak diizinkan) 
        else if ($user->hasRole('admin')){
            return false;
        }

        // step 2 tentang group berisi user dengan permission setiap user pada group berbeda beda, masih coming soon...

        // Step 3: Check if user has granted permission with the folder
        $userFolderPermission = UserFolderPermission::where('user_id', $user->id)->where('folder_id', $folder->id)->first();
        if ($userFolderPermission) {
            $checkPermission = $userFolderPermission->permissions;
            if (in_array($action, $checkPermission)) {
                return true;
            }
        }

        return false;
    }

    public function index(Request $request)
    {
        $user = Auth::user();

        try {
            // Mendapatkan folder root (parent) dari user
            $parentFolder = Folder::where('user_id', $user->id)->whereNull('parent_id')->first();

            /**
             * Jika folder root tidak ditemukan, kembalikan pesan error
             */
            if (!$parentFolder) {
                return response()->json([
                    'message' => 'Parent folder not found.'
                ], 404);
            }

            // Mendapatkan subfolder dan file dari folder root
            $userFolders = $parentFolder->subfolders;
            $files = $parentFolder->files;

            // Jika tidak ada subfolder dan file, kembalikan pesan bahwa folder atau file tidak ditemukan
            if ($userFolders->isEmpty() && $files->isEmpty()) {
                return response()->json([
                    'message' => 'No folders or files found',
                    'data' => [
                        'folders' => [],
                        'files' => $files
                    ]
                ], 200);
            }

            // Iterasi setiap folder dalam subfolders untuk menyiapkan respons
            $respondFolders = $userFolders->map(function ($folder) {
                return [
                    'folder_id' => $folder->id,
                    'name' => $folder->name,
                    'user_id' => $folder->user->id,
                ];
            });

            return response()->json([
                'data' => [
                    'folders' => $respondFolders, // Sekarang berisi array folder
                    'files' => $files
                ]
            ], 200);
        } catch (Exception $e) {
            Log::error('Error occurred on getting folders and files: ' . $e->getMessage(), [
                'parent_id' => $parentFolder->id ?? null, // Pastikan null jika parentFolder tidak ditemukan
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'errors' => 'Terjadi kesalahan saat mendapatkan folder dan file.',
            ], 500);
        }
    }

    public function info($id)
    {
        $permission = $this->checkPermissionFolder($id, 'folder_read');
        if (!$permission) {
            return response()->json([
                'errors' => 'Anda tidak memiliki izin untuk melihat folder  ini.',
            ], 403);
        }

        try {
            // Cari folder dengan ID yang diberikan dan sertakan subfolder jika ada
            $folder = Folder::with(['subfolders', 'files'])->find($id);

            // Jika folder tidak ditemukan, kembalikan pesan kesalahan
            if (!$folder) {
                return response()->json([
                    'errors' => 'Folder not found.',
                ], 404);
            }

            // Persiapkan respon untuk folder
            $folderResponse = [
                'folder_id' => $folder->id,
                'name' => $folder->name ?? '-',
                'parent_id' => $folder->parent_id ? $folder->parentFolder->id : null,
            ];

            // Persiapkan respon untuk files
            $files = $folder->files;
            $fileResponse = [];

            if ($files->isEmpty()) {
                $fileResponse = []; // Jika tidak ada file, kembalikan array kosong
            } else {
                foreach ($files as $file) {
                    $fileResponse[] = [
                        'id' => $file->id,
                        'name' => $file->name,
                        'folder_id' => $folder->id,
                    ];
                }
            }

            return response()->json([
                'data' => [
                    'folder_root_info' => $folderResponse,
                    'subfolders' => $folder->subfolders,
                    'files' => $fileResponse,
                ],
            ], 200);
        } catch (Exception $e) {
            Log::error('Error occurred on getting folder info: ' . $e->getMessage(), [
                'folderId' => $id,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'errors' => 'Terjadi kesalahan saat mendapatkan informasi folder.',
            ], 500);
        }
    }

    /**
     * Create a new folder.
     */
    public function create(Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            [
                'name' => 'required|string',
                'parent_id' => 'nullable|integer|exists:folders,id',
            ],
        );

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $userId = Auth::id();

            // Dapatkan folder root pengguna, jika tidak ada parent_id yang disediakan
            $folderRootUser = Folder::where('user_id', $userId)->whereNull('parent_id')->first();

            // Periksa apakah parent_id ada pada request? , jika tidak ada maka gunakan id dari folder root user default
            // Jika ada, gunakan parent_id dari request.
            if ($request->parent_id === null) {
                $parentId = $folderRootUser->id;
            } else if ($request->parent_id) {
                // check if parent_id is another user folder, then check if user login right now have the permission to edit folder on that folder. checked with checkPermission.
                $permission = $this->checkPermissionFolder($request->parent_id, 'folder_edit');
                if (!$permission) {
                    return response()->json([
                        'errors' => 'Anda tidak memiliki izin untuk membuat folder pada parent_folder yang anda masukan.',
                    ], 403);
                } else {
                    $parentId = $request->parent_id;
                }
            }

            // Create folder in database
            $newFolder = Folder::create([
                'name' => $request->name,
                'user_id' => $userId,
                'parent_id' => $parentId,
            ]);

            // Get NanoID folder
            $folderNameWithNanoId = $newFolder->nanoid;

            // Create folder in storage
            $path = $this->getFolderPath($newFolder->parent_id);
            $fullPath = $path . '/' . $folderNameWithNanoId;
            Storage::makeDirectory($fullPath);

            return response()->json([
                'message' => $newFolder->parent_id ? 'Subfolder created successfully' : 'Folder created successfully',
                'data' => [
                    'folder' => $newFolder,
                    'storage_path' => $fullPath,
                ]
            ], 201);
        } catch (Exception $e) {
            Log::error('Error occurred on creating folder: ' . $e->getMessage(), [
                'name' => $request->name,
                'parentId' => $request->parent_id,
                'userId' => $userId,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'errors' => 'Terjadi kesalahan saat membuat folder.',
            ], 500);
        }
    }

    /**
     * Update the name of a folder.
     */
    public function update(Request $request, $id)
    {
        $permissionCheck = $this->checkPermissionFolder($id, 'folder_edit');
        if (!$permissionCheck) {
            return response()->json([
                'errors' => 'Anda tidak memiliki izin untuk mengubah nama folder ini.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $folder = Folder::findOrFail($id);

            // Update folder name in the database, but keep the same NanoID
            $oldNanoid = $folder->nanoid;
            $folder->name = $request->name;
            $folder->save();

            // Update folder name in storage
            $path = $this->getFolderPath($folder->parent_id);
            $oldFullPath = $path . '/' . $oldNanoid;
            $newFullPath = $path . '/' . $folder->nanoid;

            if (Storage::exists($oldFullPath)) {
                Storage::move($oldFullPath, $newFullPath);
            }

            return response()->json([
                'message' => 'Nama folder berhasil diubah.',
                'data' => [
                    'folder' => $folder
                ]
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'errors' => 'Folder tidak ditemukan.',
            ], 404);
        } catch (Exception $e) {
            Log::error('Error occurred on updating folder name: ' . $e->getMessage(), [
                'folderId' => $id,
                'name' => $request->name,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'errors' => 'Terjadi kesalahan saat mengubah nama folder.',
            ], 500);
        }
    }

    /**
     * Delete a folder.
     */
    public function delete($id)
    {
        $permissionCheck = $this->checkPermissionFolder($id, 'folder_delete');
        if (!$permissionCheck) {
            return response()->json([
                'errors' => 'Anda tidak mempunyai izin untuk menghapus folder ini.',
            ], 403);
        }

        try {
            $folder = Folder::findOrFail($id);

            // Delete folder from database
            $folder->delete();

            // Delete folder from storage
            $path = $this->getFolderPath($folder->parent_id);
            $fullPath = $path . '/' . $folder->nanoid;

            if (Storage::exists($fullPath)) {
                Storage::deleteDirectory($fullPath);
            }

            return response()->json([
                'message' => 'Folder berhasil di hapus.',
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'errors' => 'Folder not found.',
            ], 404);
        } catch (Exception $e) {
            Log::error('Error occurred on deleting folder: ' . $e->getMessage(), [
                'folderId' => $id,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'errors' => 'Terjadi kesalahan saat menghapus folder.',
            ], 500);
        }
    }

    /**
     * Move a folder to another folder with parent_id folder technique.
     */
    public function move(Request $request)
    {
        $user = Auth::user();

        $permissionCheck = $this->checkPermissionFolder($request->folder_id, 'folder_edit');
        if (!$permissionCheck) {
            return response()->json([
                'errors' => 'Anda tidak mempunyai izin untuk memindahkan folder ini.',
            ], 403);
        }

        $validator = Validator::make(
            $request->all(),
            [
                'folder_id' => 'required|integer|exists:folders,id',
                'new_parent_id' => 'required|integer|exists:folders,id',
            ],
        );

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $folder = Folder::findOrFail($request->folder_id);
            $oldParentId = $folder->parent_id;
            //periksa apakah new_parent_id (folder tujuan yang dipilih) pada request adalah milik user sendiri atau milik user lain. jika dimiliki oleh user lain, periksa apakah user saat ini memiliki izin untuk memindahkan ke folder milik orang lain itu.
            $checkIfNewParentIdIsBelongsToUser = Folder::where('id', $request->new_parent_id)->where('user_id', $user->id)->exists();
            if (!$checkIfNewParentIdIsBelongsToUser) {
                $permissionCheck = $this->checkPermissionFolder($request->new_parent_id, 'folder_edit');
                if (!$permissionCheck) {
                    return response()->json([
                        'errors' => 'Anda tidak mendapatkan izin pada folder yang anda tuju untuk memindahkan folder ini.',
                    ], 403);
                }
            }
            $folder->parent_id = $request->new_parent_id;
            $folder->save();

            // Move folder in storage
            $oldPath = $this->getFolderPath($oldParentId);
            $newPath = $this->getFolderPath($folder->parent_id);
            $oldFullPath = $oldPath . '/' . $folder->nanoid;
            $newFullPath = $newPath . '/' . $folder->nanoid;

            if (Storage::exists($oldFullPath)) {
                Storage::move($oldFullPath, $newFullPath);
            }

            return response()->json([
                'message' => 'Folder berhasil di pindahkan.',
                'data' => [
                    'folder' => $folder
                ]
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'errors' => 'Folder not found.',
            ], 404);
        } catch (Exception $e) {
            Log::error('Error occurred on moving folder: ' . $e->getMessage(), [
                'folderId' => $request->folder_id,
                'newParentId' => $request->new_parent_id,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'errors' => 'Terjadi kesalahan saat memindahkan folder.',
            ], 500);
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
     * Get full path of folder and subfolder.
     * return json
     */
    public function getFullPath($id)
    {
        try {
            $folder = Folder::findOrFail($id);
            $path = [];

            while ($folder) {
                array_unshift($path, $folder->name);
                $folder = $folder->parentFolder;
            }

            return response()->json([
                'data' => [
                    'folder_path' => implode('/', $path)
                ]
            ], 200);
        } catch (Exception $e) {
            Log::error('Error occurred on getting folder path: ' . $e->getMessage(), [
                'folder_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'errors' => 'Terjadi kesalahan saat mendapatkan path folder.',
            ], 500);
        }
    }
}
