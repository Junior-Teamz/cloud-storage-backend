<?php

namespace App\Services;

use App\Models\Folder;
use Exception;
use Illuminate\Support\Facades\Log;

/**
 * Service for getting path.
 */
class GetPathService
{
    // For folder path

    /**
     * Get full path of a folder for included in response.
     *
     * @param string $id The UUID of the folder to get the full path for.
     * 
     * @return \Illuminate\Http\JsonResponse Returns a JSON response with the full path of the folder or an error message.
     * 
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If the folder is not found.
     * @throws \Exception For general exceptions that may occur during the process.
     */
    public function getPublicPath($id)
    {
        try {
            $folder = Folder::findOrFail($id);
            $path = [];

            while ($folder) {
                array_unshift($path, $folder->name);
                $folder = $folder->parentFolder;
            }

            return implode('/', $path);
        } catch (Exception $e) {
            Log::error('Error occurred on getting folder path: ' . $e->getMessage(), [
                'folder_id' => $id,
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred on getting folder path.',
            ], 500);
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
    public function getFolderPath($parentId)
    {
        if ($parentId === null) {
            return ''; // Root Folder
        }

        $parentFolder = Folder::findOrFail($parentId);
        $path = $this->getFolderPath($parentFolder->parent_id);

        // Use the folder's NanoID in the storage path
        $folderNameWithNanoId = $parentFolder->nanoid;

        return $path . '/' . $folderNameWithNanoId;
    }

    public function getPathNanoid($id)
    {
        try {
            $folder = Folder::findOrFail($id);
            $path = [];

            while ($folder) {
                array_unshift($path, $folder->nanoid);
                $folder = $folder->parentFolder;
            }

            return implode('/', $path);
        } catch (Exception $e) {
            Log::error('Error occurred on getting folder path: ' . $e->getMessage(), [
                'folder_id' => $id,
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred on getting folder path.',
            ], 500);
        }
    }


    // For file path

    /**
     * Generate the file path for storage.
     *
     * This method constructs the file path for storage based on its folder UUID and file NanoID.
     * It uses NanoIDs for folders and files to create a unique path.
     *
     * @param string $folderId The UUID of the folder containing the file.
     * @param  string  $fileNanoid The NanoID of the file.
     * @return string The generated file path for storage.
     */
    public function generateFilePath($folderId, $fileNanoid)
    {
        // Initialize an array to store the folder names
        $path = [];

        // If folderId is provided, build the path from the folder to the root
        while ($folderId) {
            // Find the folder by ID
            $folder = Folder::findOrFail($folderId);
            if ($folder) {
                // Prepend the folder name to the path array
                array_unshift($path, $folder->nanoid);
                // Set the folder ID to its parent folder's ID
                $folderId = $folder->parent_id;
            } else {
                // If the folder is not found, stop the loop
                break;
            }
        }

        // Add the file name to the end of the path
        $path[] = $fileNanoid;

        // Join the path array into a single string
        return implode('/', $path);
    }

    /**
     * Generate the public path for a file.
     *
     * This method constructs the public path for a file based on its folder UUID and file name.
     * It traverses the folder hierarchy to build the complete path.
     *
     * @param string $folderId The UUID of the folder containing the file.
     * @param  string  $fileName The name of the file.
     * @return string The generated public path for the file.
     */
    public function generateFilePublicPath($folderId, $fileName)
    {
        // Initialize an array to store the folder names
        $path = [];

        // If folderId is provided, build the path from the folder to the root
        while ($folderId) {
            // Find the folder by ID
            $folder = Folder::findOrFail($folderId);
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
