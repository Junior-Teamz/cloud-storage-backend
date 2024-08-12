<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\Folder;
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
     * Check the user role and permission.
     */
    private function checkPermission($role, $permission)
    {
        $user = Auth::user();

        if ($user->hasRole($role)) {
            if ($user->cannot($permission)) {
                return response()->json([
                    'errors' => 'This user does not have permission to ' . str_replace('folders.', '', $permission) . '.'
                ], 403);
            }
        }

        return null;
    }

    /**
     * Get the list of folders and files in a specified folder.
     */
    public function index(Request $request, $parentId = null)
    {
        $permissionCheck = $this->checkPermission('user', 'folders.info');
        if ($permissionCheck) {
            return $permissionCheck;
        }

        try {
            // Determine the query for folders based on whether parentId is provided
            if ($parentId === null) {
                // Get root folders (folders with no parent_id)
                $foldersQuery = Folder::whereNull('parent_id');
            } else {
                // Get folders with the specified parent_id
                $foldersQuery = Folder::where('parent_id', $parentId);
            }

            // Query for files in the specified parent folder
            $filesQuery = File::where('folder_id', $parentId);

            // Get the folders and files
            $folders = $foldersQuery->get();
            $files = $filesQuery->get();

            // Check if both folders and files are empty
            if ($folders->isEmpty() && $files->isEmpty()) {
                return response()->json([
                    'message' => 'No folders or files found.',
                    'data' => [
                        'folders' => [],
                        'files' => [],
                    ],
                ], 404);
            }

            return response()->json([
                'data' => [
                    'folders' => $folders,
                    'files' => $files,
                ],
            ], 200);
        } catch (Exception $e) {
            Log::error('Error occurred on getting folders and files: ' . $e->getMessage(), [
                'parentId' => $parentId,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'errors' => 'An error occurred while fetching the folders and files.',
            ], 500);
        }
    }

    /**
     * Get full path of folder and subfolder.
     */
    public function getFullPath($id)
    {
        $permissionCheck = $this->checkPermission('user', 'folders.info');
        if ($permissionCheck) {
            return $permissionCheck;
        }

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
                'folderId' => $id,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'errors' => 'An error occurred while fetching the folder path.',
            ], 500);
        }
    }

    /**
     * Get folder info, including files and subfolders.
     */
    public function info($id)
    {
        $permissionCheck = $this->checkPermission('user', 'folders.info');
        if ($permissionCheck) {
            return $permissionCheck;
        }

        try {
            // Cari folder dengan ID yang diberikan dan sertakan subfolder jika ada
            $folder = Folder::with(['subfolders'])->find($id);

            // Jika folder tidak ditemukan atau tidak ada data, kembalikan pesan kesalahan
            if (!$folder) {
                return response()->json([
                    'errors' => 'Folder not found or no data available.',
                ], 404);
            }

            return response()->json([
                'data' => $folder,
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
        $permissionCheck = $this->checkPermission('user', 'folders.create');
        if ($permissionCheck) {
            return $permissionCheck;
        }

        $validator = Validator::make(
            $request->all(),
            [
                'name' => 'required|string',
                'parent_id' => 'nullable|integer|exists:folders,id',
            ],
            [
                'parent_id.exists' => 'The selected parent folder does not exist.',
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Create folder in database
            $newFolder = Folder::create([
                'name' => $request->name,
                'user_id' => Auth::id(),
                'parent_id' => $request->parent_id,
            ]);

            // Create folder in storage
            $path = $this->getFolderPath($newFolder->parent_id);
            $fullPath = $path . '/' . $newFolder->name;
            Storage::makeDirectory($fullPath);

            return response()->json([
                'message' => $newFolder->parent_id ? 'Subfolder created successfully' : 'Folder created successfully',
                'data' => [
                    'folder' => $newFolder
                ]
            ], 201);
        } catch (Exception $e) {
            Log::error('Error occurred on creating folder: ' . $e->getMessage(), [
                'name' => $request->name,
                'parentId' => $request->parent_id,
                'userId' => Auth::id(),
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
        $permissionCheck = $this->checkPermission('user', 'folders.update');
        if ($permissionCheck) {
            return $permissionCheck;
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $folder = Folder::findOrFail($id);

            // Update folder name in database
            $oldName = $folder->name;
            $folder->name = $request->name;
            $folder->save();

            // Update folder name in storage
            $path = $this->getFolderPath($folder->parent_id);
            $oldFullPath = $path . '/' . $oldName;
            $newFullPath = $path . '/' . $folder->name;

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
        $permissionCheck = $this->checkPermission('user', 'folders.delete');
        if ($permissionCheck) {
            return $permissionCheck;
        }

        try {
            $folder = Folder::findOrFail($id);

            // Delete folder from database
            $folder->delete();

            // Delete folder from storage
            $path = $this->getFolderPath($folder->parent_id);
            $fullPath = $path . '/' . $folder->name;

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
    public function move(Request $request, $id)
    {
        $permissionCheck = $this->checkPermission('user', 'folders.update');
        if ($permissionCheck) {
            return $permissionCheck;
        }

        $validator = Validator::make(
            $request->all(),
            [
                'new_parent_id' => 'required|integer|exists:folders,id',
            ],
            [
                'new_parent_id.exists' => 'The selected parent folder does not exist.',
            ]
        );

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $folder = Folder::findOrFail($id);
            $oldParentId = $folder->parent_id;
            $folder->parent_id = $request->new_parent_id;
            $folder->save();

            // Move folder in storage
            $oldPath = $this->getFolderPath($oldParentId);
            $newPath = $this->getFolderPath($folder->parent_id);
            $oldFullPath = $oldPath . '/' . $folder->name;
            $newFullPath = $newPath . '/' . $folder->name;

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

        return $path . '/' . $parentFolder->name;
    }
}
