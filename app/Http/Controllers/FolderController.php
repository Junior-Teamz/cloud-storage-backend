<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Hidehalo\Nanoid\Client;

class FolderController extends Controller
{
    /**
     * Check the user role and permission.
     */
    // private function checkPermission($role, $permission)
    // {
    //     $user = Auth::user();

    //     if ($user->hasRole($role)) {
    //         if ($user->cannot($permission)) {
    //             return response()->json([
    //                 'errors' => 'This user does not have permission to ' . str_replace('folders.', '', $permission) . '.'
    //             ], 403);
    //         }
    //     }

    //     return null;
    // }

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
                'errors' => 'An error occurred while fetching the folders and files.',
            ], 500);
        }
    }

    public function info($id)
    {
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
                'errors' => 'An error occurred while fetching the folder info.',
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
            if ($request->parent_id === null || $request->parent_id === 0 || $request->parent_id === '') {
                $parentId = $folderRootUser->id;
            } else {
                $parentId = $request->parent_id;
            }

            // Pastikan parent_id adalah integer atau null
            if (!is_null($parentId) && !is_numeric($parentId)) {
                Log::error('Invalid parent_id value: ' . $parentId);
                throw new \Exception('Invalid parent_id value.');
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
                'errors' => 'An error occurred while creating the folder.',
            ], 500);
        }
    }

    /**
     * Update the name of a folder.
     */
    public function update(Request $request, $id)
    {
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
                'message' => 'Folder name updated successfully.',
                'data' => [
                    'folder' => $folder
                ]
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'errors' => 'Folder not found.',
            ], 404);
        } catch (Exception $e) {
            Log::error('Error occurred on updating folder name: ' . $e->getMessage(), [
                'folderId' => $id,
                'name' => $request->name,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'errors' => 'An error occurred while updating the folder name.',
            ], 500);
        }
    }

    /**
     * Delete a folder.
     */
    public function delete($id)
    {
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
                'message' => 'Folder deleted successfully'
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
                'errors' => 'An error occurred while deleting the folder.',
            ], 500);
        }
    }

    /**
     * Move a folder to another parent folder.
     */
    public function move(Request $request)
    {
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
                'message' => 'Folder moved successfully.',
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
                'folderId' => $id,
                'newParentId' => $request->new_parent_id,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'errors' => 'An error occurred while moving the folder.',
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
                'errors' => 'An error occurred while fetching the folder path.',
            ], 500);
        }
    }
}
