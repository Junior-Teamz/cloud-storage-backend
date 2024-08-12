<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\Folder;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class FileController extends Controller
{
    /**
     * Get information about a file (READ).
     */
    public function info($id)
    {
        try {
            $file = File::findOrFail($id);

            // Generate file URL if it exists in public disk
            $fileUrl = Storage::url($file->path);

            return response()->json([
                'data' => [
                    'file' => $file,
                    'fileUrl' => $fileUrl, // Add file URL to the response
                ],
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'errors' => 'File not found.',
            ], 404);
        } catch (Exception $e) {
            return response()->json([
                'errors' => 'An error occurred while fetching the file info.',
            ], 500);
        }
    }

    /**
     * Create a new text file (CREATE).
     */
    public function create(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string',  // Nama file yang akan dibuat
            'content' => 'nullable|string', // Konten file, bisa kosong
            'folder_id' => 'nullable|integer|exists:folders,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            // Generate file path berdasarkan folder_id (jika ada)
            $path = $this->generateFilePath($request->folder_id, $request->name);

            // Simpan file teks ke storage dengan konten yang diberikan (jika ada)
            Storage::put($path, $request->input('content', ''));

            // Dapatkan ukuran file yang baru disimpan
            $size = Storage::size($path);

            // Simpan informasi file ke database
            $file = File::create([
                'name' => $request->name,
                'path' => $path,
                'size' => $size,
                'mime_type' => 'text/plain', // Set tipe MIME untuk file teks
                'user_id' => $request->user()->id,
                'folder_id' => $request->folder_id,
            ]);

            return response()->json([
                'message' => 'File created and saved to storage successfully.',
                'data' => $file,
            ], 201);
        } catch (Exception $e) {
            Log::error('Error occurred on creating a file: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'errors' => 'An error occurred while creating the file.',
            ], 500);
        }
    }

    /**
 * Upload a file (CREATE).
 */
public function upload(Request $request)
{
    $validator = Validator::make($request->all(), [
        'file' => 'required|file',
        'folder_id' => 'nullable|integer|exists:folders,id',
    ]);

    if ($validator->fails()) {
        return response()->json(['errors' => $validator->errors()], 422);
    }

    try {
        $uploadedFile = $request->file('file');
        $fileName = $uploadedFile->getClientOriginalName();

        // Handle case where folder_id is not provided
        $folderId = $request->input('folder_id', null);
        $path = $this->generateFilePath($folderId, $fileName);

        // Save the file to storage
        Storage::put($path, file_get_contents($uploadedFile));

        // Create file record in database
        $file = File::create([
            'name' => $fileName,
            'path' => $path,
            'size' => $uploadedFile->getSize(),
            'mime_type' => $uploadedFile->getMimeType(),
            'user_id' => $request->user()->id,
            'folder_id' => $folderId,
        ]);

        // Generate file URL
        $fileUrl = Storage::url($path);

        Log::info('File uploaded successfully.', [
            'fileName' => $fileName,
            'path' => $path,
            'userId' => $request->user()->id,
            'folderId' => $folderId,
        ]);

        return response()->json([
            'message' => 'File uploaded successfully.',
            'data' => [
                'file' => $file,
                'fileUrl' => $fileUrl, // Add file URL to the response
            ],
        ], 201);
    } catch (Exception $e) {
        Log::error('Error occurred while uploading file: ' . $e->getMessage(), [
            'trace' => $e->getTraceAsString(),
            'userId' => $request->user()->id,
            'folderId' => $request->input('folder_id', null),
        ]);

        return response()->json([
            'errors' => 'An error occurred while uploading the file.',
        ], 500);
    }
}

    /**
     * Update the name of a file.
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
            $file = File::findOrFail($id);

            // Update file name in storage
            $oldFullPath = $file->path;
            $newPath = $this->generateFilePath($file->folder_id, $request->name);

            if (Storage::exists($oldFullPath)) {
                Storage::move($oldFullPath, $newPath);
            }

            // Update file name in database
            $file->name = $request->name;
            $file->path = $newPath;
            $file->save();

            return response()->json([
                'message' => 'File name updated successfully.',
                'data' => $file,
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'errors' => 'File not found.',
            ], 404);
        } catch (Exception $e) {
            return response()->json([
                'errors' => 'An error occurred while updating the file name.',
            ], 500);
        }
    }

    /**
     * Delete a file (DELETE).
     */
    public function delete($id)
    {
        try {
            $file = File::findOrFail($id);

            // Delete file from storage
            if (Storage::exists($file->path)) {
                Storage::delete($file->path);
            }

            // Delete file from database
            $file->delete();

            return response()->json([
                'message' => 'File deleted successfully.',
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'errors' => 'File not found.',
            ], 404);
        } catch (Exception $e) {
            return response()->json([
                'errors' => 'An error occurred while deleting the file.',
            ], 500);
        }
    }

    /**
     * Move a file to another folder or to the root (MOVE).
     */
    public function move(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'new_folder_id' => 'nullable|integer|exists:folders,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $file = File::findOrFail($id);
            $oldPath = $file->path;

            // Generate new path
            $newPath = $this->generateFilePath($request->new_folder_id, $file->name);

            // Check if old file path exists
            if (!Storage::exists($oldPath)) {
                return response()->json(['errors' => 'Old file path does not exist.'], 404);
            }

            // Move file in storage
            if (Storage::exists($oldPath)) {
                // Ensure the new directory exists
                $newDirectory = dirname($newPath);
                if (!Storage::exists($newDirectory)) {
                    Storage::makeDirectory($newDirectory);
                }

                Storage::move($oldPath, $newPath);
            }

            // Update file record in database
            $file->folder_id = $request->new_folder_id;
            $file->path = $newPath;
            $file->save();

            return response()->json([
                'message' => 'File moved successfully.',
                'data' => $file,
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'errors' => 'File not found.',
            ], 404);
        } catch (Exception $e) {
            return response()->json([
                'errors' => 'An error occurred while moving the file.',
            ], 500);
        }
    }

    /**
     * Generate the file path based on folder id and file name.
     */
    private function generateFilePath($folderId, $fileName)
    {
        // Initialize an array to store the folder names
        $path = [];

        // If folderId is provided, build the path from the folder to the root
        while ($folderId) {
            // Find the folder by ID
            $folder = Folder::find($folderId);
            if ($folder) {
                // Prepend the folder name to the path array
                array_unshift($path, $folder->name);
                // Set the folder ID to its parent folder's ID
                $folderId = $folder->parent_id;
            } else {
                // If the folder is not found, stop the loop
                break;
            }
        }

        // Add the file name to the end of the path
        $path[] = $fileName;

        // Join the path array into a single string
        return implode('/', $path);
    }
}
