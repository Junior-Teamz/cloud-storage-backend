<?php

namespace App\Http\Controllers;

use App\Http\Resources\File\FileCollection;
use App\Http\Resources\Folder\FolderCollection;
use App\Http\Resources\Folder\FolderResource;
use Exception;
use App\Models\Tags;
use App\Models\User;
use App\Models\Folder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use App\Services\CheckFolderPermissionService;
use App\Services\GenerateURLService;
use App\Services\GetPathService;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class FolderController extends Controller
{
    protected $checkPermissionFolderService;
    protected $GenerateURLService;
    protected $getPathService;

    public function __construct(CheckFolderPermissionService $checkPermissionFolderService, GenerateURLService $GenerateURLService, GetPathService $getPathServices)
    {
        // Simpan service ke dalam property
        $this->checkPermissionFolderService = $checkPermissionFolderService;
        $this->GenerateURLService = $GenerateURLService;
        $this->getPathService = $getPathServices;
    }

    /**
     * Format bytes into a human-readable size unit (KB, MB, GB).
     *
     * This helper function converts a given size in bytes into a more user-friendly representation,
     * using KB, MB, or GB as appropriate.
     *
     * @param int $bytes The size in bytes.
     * @return string The formatted size string.
     */
    private function formatSizeUnits($bytes)
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } elseif ($bytes > 1) {
            return $bytes . ' bytes';
        } elseif ($bytes == 1) {
            return $bytes . ' byte';
        } else {
            return '0 bytes';
        }
    }

    /**
     * Get the total size of all files in a folder and its subfolders.
     * 
     * This method calculates the total size of all files in a folder and its subfolders
     * by recursively calling itself on each subfolder. It returns the total size in bytes.
     * 
     * @param \App\Models\Folder $folder The folder to calculate the size of.
     * 
     * @return int The total size of all files in the folder and its subfolders.
     */
    public function storageSizeUsage()
    {
        $user = Auth::user();

        try {
            // Dapatkan folder root milik user
            $rootFolder = Folder::where('user_id', $user->id)->whereNull('parent_id')->first();

            if (!$rootFolder) {
                return response()->json([
                    'errors' => 'An error was occured. Please contact our support.'
                ]);
            }

            // Hitung total penyimpanan yang digunakan user
            $totalUsedStorage = $rootFolder->calculateTotalSize();

            // Format ukuran penyimpanan ke dalam KB, MB, atau GB
            $formattedStorageSize = $this->formatSizeUnits($totalUsedStorage);

            return response()->json([
                'message' => 'Your storage usage: ' . $formattedStorageSize,
                'data' => [
                    'rawSize' => $totalUsedStorage,
                    'formattedSize' => $formattedStorageSize
                ]
            ]);
        } catch (Exception $e) {
            Log::error('Error occured while retrieving storage usage: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occured while retrieving storage usage.'
            ], 500);
        }
    }

    /**
     * Count the total number of folders for the authenticated user.
     *
     * This method retrieves and counts all folders belonging to the authenticated user,
     * excluding the root folder. It returns the total folder count in the response.
     *
     * @return \Illuminate\Http\JsonResponse A JSON response containing the total folder count or an error message.
     */
    public function countTotalFolderUser()
    {
        $user = Auth::user();

        try {
            // mendapatkan semua folder user (KECUALI FOLDER ROOT) lalu hitung totalnya
            $countAllFolder = Folder::where('user_id', $user->id)->whereNotNull('parent_id')->count();

            return response()->json([
                'data' => [
                    'total_folder' => $countAllFolder
                ]
            ]);
        } catch (Exception $e) {
            Log::error('Error occured while counting all user folder: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occured while counting all user folder.'
            ], 500);
        }
    }

    /**
     * Get all folders and files for the current user.
     * 
     * This method retrieves all folders and files for the current user, including 
     * subfolders and tags. It also includes the user information and instance address 
     * for each folder and file.
     * 
     * @return \Illuminate\Http\JsonResponse Returns a JSON response with an array of folders and files.
     * 
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If the parent folder is not found.
     * @throws \Exception For general exceptions that may occur during the process.
     */
    public function index()
    {
        $user = Auth::user();

        try {
            // Ambil folder root user dengan eager loading untuk subfolders dan files
            $parentFolder = Folder::where('user_id', $user->id)
                ->whereNull('parent_id')
                ->with(['user', 'tags', 'instances', 'userFolderPermissions', 'userFolderPermissions.user', 'userFolderPermissions.user.instances', 'favorite'])
                ->first();

            // Cek apakah parent folder ditemukan
            if (!$parentFolder) {
                return response()->json([
                    'message' => 'An error occurred. Please contact our support.'
                ], 500);
            }

            $subfolders = $parentFolder->subfolders()->with(['user', 'user.instances', 'tags', 'instances', 'userFolderPermissions', 'userFolderPermissions.user', 'userFolderPermissions.user.instances', 'favorite'])->paginate(10);
            $files = $parentFolder->files()->with(['user', 'user.instances', 'tags', 'instances', 'userPermissions', 'userPermissions.user', 'userPermissions.user.instances', 'favorite'])->paginate(10);

            return response()->json([
                'data' => [
                    'subfolders' => new FolderCollection($subfolders),
                    'files' => new FileCollection($files)
                ],
                'pagination' => [
                    'subfolders' => [
                        'current_page' => $subfolders->currentPage(),
                        'last_page' => $subfolders->lastPage(),
                        'per_page' => $subfolders->perPage(),
                        'total' => $subfolders->total(),
                    ],
                    'files' => [
                        'current_page' => $files->currentPage(),
                        'last_page' => $files->lastPage(),
                        'per_page' => $files->perPage(),
                        'total' => $files->total(),
                    ]
                ]
            ], 200);
        } catch (Exception $e) {
            Log::error('Error occurred on getting folders and files: ' . $e->getMessage(), [
                'parent_id' => isset($parentFolder) ? $parentFolder->id : null,
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred while getting folders and files.',
            ], 500);
        }
    }

    /**
     * Get detailed information about a folder.
     * 
     * This method retrieves all the information about a folder, including its subfolders, files, tags, and instances.
     * It also checks if the user has permission to access the folder and logs any errors that occur during the process.
     * 
     * @param string $id The UUID of the folder to retrieve.
     * 
     * @return \Illuminate\Http\JsonResponse Returns a JSON response with the folder information or an error message.
     * 
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If the folder is not found.
     * @throws \Exception For general exceptions that may occur during the process.
     */
    public function info($id)
    {
        $user = Auth::user();

        // Periksa apakah user memiliki izin read.
        $permission = $this->checkPermissionFolderService->checkPermissionFolder($id, ['read', 'write']);

        if (!$permission) {
            return response()->json([
                'errors' => 'You do not have permission to access this folder.',
            ], 403);
        }

        try {
            // Cari folder dengan ID yang diberikan dan sertakan subfolder, file, tags, instances, dan userFolderPermissions yang relevan
            $folder = Folder::with(['user', 'tags', 'instances', 'userFolderPermissions', 'userFolderPermissions.user', 'userFolderPermissions.user.instances', 'favorite'])->where('id', $id)->first();

            if (!$folder) {
                Log::warning('Attempt to get folder on non-existence folder id: ' . $id);
                return response()->json([
                    'message' => 'Folder not found.',
                    'data' => []
                ], 200);
            }

            $subfolders = $folder->subfolders()->with(['user', 'user.instances', 'tags', 'instances', 'userFolderPermissions', 'userFolderPermissions.user', 'userFolderPermissions.user.instances', 'favorite'])->paginate(10);
            $files = $folder->files()->with(['user', 'user.instances', 'tags', 'instances', 'userPermissions', 'userPermissions.user', 'userPermissions.user.instances', 'favorite'])->paginate(10);

            return response()->json([
                'data' => [
                    'folder_info' => new FolderResource($folder, $user->id),
                    'subfolders' => new FolderCollection($subfolders),
                    'files' => new FileCollection($files),
                ],
                'pagination' => [
                    'subfolders' => [
                        'current_page' => $subfolders->currentPage(),
                        'last_page' => $subfolders->lastPage(),
                        'per_page' => $subfolders->perPage(),
                        'total' => $subfolders->total(),
                    ],
                    'files' => [
                        'current_page' => $files->currentPage(),
                        'last_page' => $files->lastPage(),
                        'per_page' => $files->perPage(),
                        'total' => $files->total(),
                    ]
                ]
            ], 200);
        } catch (Exception $e) {
            Log::error('Error occurred on getting folder info: ' . $e->getMessage(), [
                'folderId' => $id,
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred while getting folder info.',
            ], 500);
        }
    }

    /**
     * Create a new folder.
     *
     * This controller method will create a new folder based on the request data.
     * It will check if the user has permission to create a folder in the specified parent folder.
     *
     * @param \Illuminate\Http\Request $request The incoming request containing 'name' and 'parent_id' and 'tag_ids'.
     *
     * @return \Illuminate\Http\JsonResponse Returns a JSON response with the new folder information or an error message.
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If the parent folder is not found.
     * @throws \Exception For general exceptions that may occur during the transaction.
     */
    public function create(Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            [
                'name' => 'required|string',
                'parent_id' => 'nullable|exists:folders,id',
                'tag_ids' => 'required|array',
            ],
        );

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $userLogin = Auth::user();
            $userId = $userLogin->id;

            // Get the user's root folder if no parent_id is provided
            $folderRootUser = Folder::where('user_id', $userId)->whereNull('parent_id')->first();

            // Check if parent_id is provided, else use the root folder's ID
            if ($request->parent_id === null) {
                $parentId = $folderRootUser->id;
            } else {
                $parentFolder = Folder::where('id', $request->parent_id)->first();
                // Check user permission for the provided parent_id
                $permission = $this->checkPermissionFolderService->checkPermissionFolder($parentFolder->id, 'write');
                if (!$permission) {
                    return response()->json([
                        'errors' => 'You do not have permission to create a folder in this parent_id',
                    ], 403);
                } else {
                    $parentId = $parentFolder->id;
                }
            }

            // Cek apakah nama folder sudah ada dalam parent folder yang sama
            $existingFolder = Folder::where('parent_id', $parentId)
                ->where('name', $request->name)
                ->exists();

            if ($existingFolder) {
                return response()->json([
                    'errors' => 'Folder with the same name already exists in this parent folder.'
                ], 422);
            }

            // Check folder depth limit
            $subfolderDepth = config('storage-config.max_subfolder_depth');
            $depth = $this->getFolderDepth($parentId);
            if ($depth >= $subfolderDepth) {
                return response()->json([
                    'errors' => 'You cannot create more than ' . $subfolderDepth . ' subfolder levels.',
                ], 403);
            }

            // Periksa apakah terdapat tag bernama "Root" pada array tag.
            $getTagIds = Tags::whereIn('id', $request->tag_ids)->get();
            $tagNames = $getTagIds->pluck('name')->toArray();

            if (in_array('Root', $tagNames)) {
                return response()->json([
                    'errors' => 'You are not allowed to add the Root tag.'
                ], 403);
            }

            // Start database transaction
            DB::beginTransaction();

            // Create the folder
            $newFolder = Folder::create([
                'name' => $request->name,
                'user_id' => $userId,
                'parent_id' => $parentId,
            ]);

            // Sync the user's instances to the folder
            $userInstance = User::with('instances')->find($userId);
            $userInstanceId = $userInstance->instances->pluck('id');
            $newFolder->instances()->sync($userInstanceId);

            // Sync the tags using tag_ids
            $tagIds = $getTagIds->pluck('id')->toArray();
            $newFolder->tags()->sync($tagIds);

            // Generate public path after folder creation
            $publicPath = $this->getPathService->getPublicPath($newFolder->id);
            $newFolder->update(['public_path' => $publicPath]);

            // Get the folder's NanoID for use in storage path
            $folderNameWithNanoId = $newFolder->nanoid;
            $path = $this->getPathService->getFolderPath($newFolder->parent_id);
            $fullPath = $path . '/' . $folderNameWithNanoId;
            Storage::makeDirectory($fullPath);

            // Load instances and tags for the response
            $newFolder->load(['user', 'tags', 'instances', 'userFolderPermissions', 'userFolderPermissions.user', 'userFolderPermissions.user.instances', 'favorite']);

            // Commit the transaction if no errors
            DB::commit();

            return response()->json([
                'message' => ($newFolder->parent_id === $folderRootUser->id) ? 'Folder created successfully' : 'Subfolder created successfully',
                'data' => [
                    'folder' => new FolderResource($newFolder, $userLogin->id)
                ]
            ], 201);
        } catch (Exception $e) {
            // Rollback if any error occurs
            DB::rollBack();

            Log::error('Error occurred on creating folder: ' . $e->getMessage(), [
                'name' => $request->name,
                'parentId' => $request->parent_id,
                'userId' => $userId,
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred while creating folder.',
            ], 500);
        }
    }

    /**
     * Add a tag to a folder.
     * 
     * This function add a tag to a folder. It accepts a JSON request containing the folder UUID and tag UUID, 
     * and checks if the user has permission to add the tag to the folder. If the user does not have permission, 
     * it will return an error response. If the user has permission, it will add the tag to the folder.
     * 
     * @param \Illuminate\Http\Request $request The incoming request containing 'folder_id' and 'tag_id'.
     * 
     * @return \Illuminate\Http\JsonResponse Returns a JSON response with the tag UUID or an error message.
     * 
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If the folder or tag is not found.
     * @throws \Exception For general exceptions that may occur during the process.
     */
    public function addTagToFolder(Request $request)
    {
        $userLogin = Auth::user();

        $validator = Validator::make($request->all(), [
            'folder_id' => 'required|exists:folders,id',
            'tag_id' => 'required|exists:tags,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Periksa perizinan
        $permissionCheck = $this->checkPermissionFolderService->checkPermissionFolder($request->folder_id, 'write');
        if (!$permissionCheck) {
            return response()->json([
                'errors' => 'You do not have permission to add tag to this folder.',
            ], 403);
        }

        try {
            $folder = Folder::where('id', $request->folder_id)->first();
            $tag = Tags::where('id', $request->tag_id)->first();

            if ($folder->parent_id === null) {
                Log::warning('Attempt to adding tag to root folder', [
                    'user_id' => $userLogin->id,
                    'folder_id' => $folder->id,
                    'tag_id' => $tag->id
                ]);
                return response()->json([
                    'errors' => 'Cannot add tag to this folder.'
                ], 403);
            }

            // Memeriksa apakah tag sudah terkait dengan folder
            if ($folder->tags->contains($tag->id)) {
                return response()->json([
                    'errors' => 'Tag already exists in folder.'
                ], 409);
            }

            if ($tag->name == "Root") {
                return response()->json([
                    'errors' => "You cannot add 'Root' tag."
                ], 403);
            }

            DB::beginTransaction();

            // Menambahkan tag ke folder (tabel pivot folder_has_tags)
            $folder->tags()->attach($tag->id);

            $folder->load(['user', 'tags', 'instances', 'userFolderPermissions', 'userFolderPermissions.user', 'userFolderPermissions.user.instances', 'favorite']);

            DB::commit();

            return response()->json([
                'message' => 'Successfully added tag to folder.',
                'folder' => new FolderResource($folder, $userLogin->id)
            ], 200);
        } catch (ModelNotFoundException $e) {

            DB::rollBack();

            return response()->json([
                'errors' => 'Folder or tag not found.'
            ], 404);
        } catch (Exception $e) {

            DB::rollBack();

            Log::error('Error occurred on adding tag to folder: ' . $e->getMessage(), [
                'folder_id' => $request->folder_id,
                'tag_id' => $request->tag_id,
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred on adding tag to folder.'
            ], 500);
        }
    }


    /**
     * Remove a tag from a folder.
     * 
     * This method removes a tag from a folder. It accepts a JSON request containing the folder UUID and tag UUID, 
     * and checks if the user has permission to remove the tag from the folder. If the user does not have permission, 
     * it will return an error response. If the user has permission, it will remove the tag from the folder.
     * 
     * @param \Illuminate\Http\Request $request The incoming request containing 'folder_id' and 'tag_id'.
     * 
     * @return \Illuminate\Http\JsonResponse Returns a JSON response with success or error messages.
     * 
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If the folder or tag is not found.
     * @throws \Exception For general exceptions that may occur during the process.
     */
    public function removeTagFromFolder(Request $request)
    {
        $userLogin = Auth::user();

        $validator = Validator::make($request->all(), [
            'folder_id' => 'required|exists:folders,id',
            'tag_id' => 'required|exists:tags,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Periksa perizinan
        $permissionCheck = $this->checkPermissionFolderService->checkPermissionFolder($request->folder_id, 'write'); // misalnya folder_edit adalah action untuk edit atau modifikasi
        if (!$permissionCheck) {
            return response()->json([
                'errors' => 'You do not have permission to remove tag from this folder.',
            ], 403);
        }

        try {
            $folder = Folder::where('id', $request->folder_id)->first();
            $tag = Tags::where('id', $request->tag_id)->first();

            // Memeriksa apakah tag terkait dengan folder
            if (!$folder->tags->contains($tag->id)) {
                return response()->json([
                    'errors' => 'Tag not found in folder.'
                ], 404);
            }

            if ($folder->parent_id === null) {
                if ($tag->name == "Root") {
                    return response()->json([
                        'errors' => "You cannot remove 'Root' tag on root folder."
                    ]);
                }
            }

            DB::beginTransaction();

            // Menghapus tag dari folder (tabel pivot folder_has_tags)
            $folder->tags()->detach($tag->id);

            $folder->load(['user', 'tags', 'instances', 'userFolderPermissions', 'userFolderPermissions.user', 'userFolderPermissions.user.instances', 'favorite']);

            DB::commit();

            return response()->json([
                'message' => 'Successfully removed tag from folder.',
                'folder' => new FolderResource($folder, $userLogin->id)
            ], 200);
        } catch (ModelNotFoundException $e) {

            DB::rollBack();

            return response()->json([
                'errors' => 'Folder or tag not found.'
            ], 404);
        } catch (Exception $e) {

            DB::rollBack();

            Log::error('Error occurred on removing tag from folder: ' . $e->getMessage(), [
                'folder_id' => $request->folder_id,
                'tag_id' => $request->tag_id,
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred on removing tag from folder.'
            ], 500);
        }
    }


    /**
     * Update folder name.
     * 
     * This method handles the folder name update process, including validating the request data, 
     * checking user permissions on the folder, and updating the folder in the database. 
     * It ensures transactional integrity and logs any errors that occur during the process.
     * 
     * @param \Illuminate\Http\Request $request The incoming request containing 'name' and 'tag_ids array'.
     * 
     * @return \Illuminate\Http\JsonResponse Returns a JSON response with success or error messages.
     * 
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If the folder is not found.
     * @throws \Exception For general exceptions that may occur during the transaction.
     */
    public function update(Request $request, $id)
    {
        $user = Auth::user();

        $permissionCheck = $this->checkPermissionFolderService->checkPermissionFolder($id, 'write');
        if (!$permissionCheck) {
            return response()->json([
                'errors' => 'You do not have permission to edit this folder.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $folder = Folder::find($id);

            if (!$folder) {
                Log::warning('Attempt to update folder on non-existence folder id: ' . $id);
                return response()->json([
                    'errors' => 'Folder not found.'
                ], 404);
            }

            if ($folder->parent_id === null) {
                return response()->json([
                    'errors' => "You cannot change name of root folder."
                ], 403);
            }

            $oldNanoid = $folder->nanoid;

            $publicPath = $this->getPathService->getPublicPath($folder->id);

            DB::beginTransaction();

            $folder->update([
                'name' => $request->name,
                'public_path' => $publicPath
            ]);

            // Update folder name in storage
            $path = $this->getPathService->getFolderPath($folder->parent_id);
            $oldFullPath = $path . '/' . $oldNanoid;
            $newFullPath = $path . '/' . $folder->nanoid;

            if (Storage::exists($oldFullPath)) {
                Storage::move($oldFullPath, $newFullPath);
            }

            $folder->load(['user', 'tags', 'instances', 'userFolderPermissions', 'userFolderPermissions.user', 'userFolderPermissions.user.instances', 'favorite']);

            DB::commit();

            return response()->json([
                'message' => 'Folder name updated successfully.',
                'data' => [
                    'folder' => new FolderResource($folder, $user->id)
                ]
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'errors' => 'Folder not found.',
            ], 404);
        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Error occurred on updating folder name: ' . $e->getMessage(), [
                'folderId' => $id,
                'name' => $request->name,
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred on updating folder.',
            ], 500);
        }
    }

    /**
     * Delete multiple folders.
     *
     * This method handles the deletion of multiple folders based on the provided folder IDs in the request.
     * It validates the request to ensure that an array of folder IDs is provided. It then retrieves the folders
     * from the database, checks if the user has permission to delete each folder, and performs the deletion.
     * The method also handles the deletion of related data in other tables and the removal of the folders
     * from storage.
     * 
     * **Caution:** Deleting folders is a destructive action that also deletes all subfolders and files within 
     * those folders. This action cannot be undone. Ensure that the folders and their contents are no longer 
     * needed before proceeding with the deletion.
     *
     * @param  \Illuminate\Http\Request  $request The incoming HTTP request containing an array of folder IDs to delete.
     * @return \Illuminate\Http\JsonResponse A JSON response indicating success or failure, with appropriate status codes.
     */
    public function delete(Request $request)
    {
        // Validasi bahwa folder_ids dikirim dalam request
        $validator = Validator::make($request->all(), [
            'folder_ids' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $folderIds = $request->folder_ids;

        try {
            // Ambil semua folder yang sesuai
            $folders = Folder::whereIn('id', $folderIds)->get();

            $getFolderParentId = $folders->pluck('parent_id')->toArray();
            if (in_array(null, $getFolderParentId)) {
                return response()->json([
                    'errors' => "You cannot delete root folder."
                ], 403);
            }

            // Bandingkan ID yang ditemukan dengan yang diminta
            $foundFolderIds = $folders->pluck('id')->toArray();
            $notFoundFolderIds = array_diff($folderIds, $foundFolderIds);

            if (!empty($notFoundFolderIds)) {
                Log::info('Attempt to delete non-existent folders: ' . implode(',', $notFoundFolderIds));
                return response()->json([
                    'errors' => 'Some folders were not found.',
                    'missing_folder_ids' => $notFoundFolderIds,
                ], 404);
            }

            // Periksa izin pengguna terhadap folder secara batch
            $folderIdNoPermission = [];
            foreach ($folderIds as $folderId) {
                if (!$this->checkPermissionFolderService->checkPermissionFolder($folderId, 'write')) {
                    $folderIdNoPermission[] = $folderId;
                }
            }

            // Jika ada folder yang tidak memiliki izin, kembalikan error
            if (!empty($folderIdNoPermission)) {
                Log::info('Attempt to delete folders without permission: ' . implode(',', $folderIdNoPermission));
                return response()->json([
                    'errors' => 'You do not have permission to delete some folders.',
                    'folder_ids_no_permission' => $folderIdNoPermission
                ], 403);
            }

            DB::beginTransaction();

            // Hapus semua relasi yang berkaitan dengan folder yang dihapus.
            DB::table('user_folder_permissions')->whereIn('folder_id', $foundFolderIds)->delete();
            DB::table('folder_has_tags')->whereIn('folder_id', $foundFolderIds)->delete();
            DB::table('folder_has_instances')->whereIn('folder_id', $foundFolderIds)->delete();
            DB::table('folder_has_favorited')->whereIn('folder_id', $foundFolderIds)->delete();

            // Hapus folder dari database
            Folder::whereIn('id', $foundFolderIds)->delete();

            // Hapus folder dari storage setelah commit ke database berhasil
            foreach ($folders as $folder) {
                $path = $this->getPathService->getFolderPath($folder->parent_id);
                $fullPath = $path . '/' . $folder->nanoid;

                if (Storage::exists($fullPath)) {
                    Storage::deleteDirectory($fullPath);
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Folder(s) deleted successfully.',
            ], 200);
        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Error occurred while deleting folder(s): ' . $e->getMessage(), [
                'folderIds' => $folderIds,
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred while deleting folder(s).',
            ], 500);
        }
    }

    /**
     * Move a folder to a new parent folder.
     * 
     * @param \Illuminate\Http\Request $request The incoming request containing 'folder_id' and 'new_parent_id'.
     * 
     * @return \Illuminate\Http\JsonResponse Returns a JSON response with success or error messages.
     * 
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If the folder is not found.
     * @throws \Exception For general exceptions that may occur during the transaction.
     */
    public function move(Request $request)
    {
        $user = Auth::user();

        $permissionCheck = $this->checkPermissionFolderService->checkPermissionFolder($request->folder_id, 'write');
        if (!$permissionCheck) {
            return response()->json([
                'errors' => 'You do not have permission to move this folder.',
            ], 403);
        }

        $validator = Validator::make(
            $request->all(),
            [
                'folder_id' => 'required,exists:folders,id',
                'new_parent_id' => 'required,exists:folders,id',
            ],
        );

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            // Periksa apakah folder yang ingin dipindahkan ada
            $folder = Folder::where('id', $request->folder_id);

            if ($folder->parent_id === null) {
                return response()->json([
                    'errors' => "You cannot move root folder."
                ], 403);
            }

            // Ambil parent folder id yang lama dari folder yang ingin dipindahkan
            $oldParentId = $folder->parent_id;

            // Periksa apakah folder tujuan ada
            $newParentFolder = Folder::where('id', $request->new_parent_id)->first();

            $newParentFolderId = $newParentFolder->id;

            if (!$newParentFolder) {
                if (!$folder) {
                    return response()->json([
                        'errors' => 'New parent folder was not found.'
                    ], 404);
                }
            }

            // Periksa apakah user yang sedang login memiliki perizinan untuk memindahkan folder ke folder tujuan
            $permissionCheck = $this->checkPermissionFolderService->checkPermissionFolder($newParentFolderId, 'write');
            if (!$permissionCheck) {
                return response()->json([
                    'errors' => 'You do not have permission on the folder you are trying to move this folder to.',
                ], 403);
            }

            DB::beginTransaction();

            $folder->parent_id = $newParentFolderId;
            $folder->save();

            // Move folder in storage
            $oldPath = $this->getPathService->getFolderPath($oldParentId);
            $newPath = $this->getPathService->getFolderPath($folder->parent_id);
            $oldFullPath = $oldPath . '/' . $folder->nanoid;
            $newFullPath = $newPath . '/' . $folder->nanoid;

            if (Storage::exists($oldFullPath)) {
                Storage::move($oldFullPath, $newFullPath);
            }

            $folder->load(['user', 'tags', 'instances', 'userFolderPermissions', 'userFolderPermissions.user', 'userFolderPermissions.user.instances', 'favorite']);

            DB::commit();

            return response()->json([
                'message' => 'Folder moved successfully.',
                'data' => [
                    'folder' => new FolderResource($folder, $user->id)
                ]
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'errors' => 'Folder not found.',
            ], 404);
        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Error occurred on moving folder: ' . $e->getMessage(), [
                'folderId' => $request->folder_id,
                'newParentId' => $request->new_parent_id,
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred on moving the folder.',
            ], 500);
        }
    }

    /**
     * Get full path of a folder.
     * 
     * This method takes a folder UUID and returns JSON response of full path of the folder.
     * The full path includes the folder's name and all its parents' names, separated by slashes.
     * If the folder UUID is not found, it returns a JSON response with an error message.
     * If an error occurs during the process, it logs the error and returns a JSON response with an error message.
     * 
     * @param string $id The UUID of the folder to get the full path for.
     * 
     * @return \Illuminate\Http\JsonResponse Returns a JSON response with the full path of the folder or an error message.
     * 
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If the folder is not found.
     * @throws \Exception For general exceptions that may occur during the process.
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

            $pathReady = implode('/', $path);

            return response()->json([
                'full_path' => $pathReady
            ], 200);
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
     * Get the depth of a folder in the folder hierarchy.
     *
     * This method recursively traverses the folder hierarchy starting from the given parent UUID
     * and calculates the depth of the folder. The root folder has a depth of 0.
     *
     * @param string $parentId The UUID of the parent folder.
     *
     * @return int The depth of the folder.
     */
    private function getFolderDepth($parentId)
    {
        $depth = 0;
        while ($parentId) {
            $folder = Folder::find($parentId);
            if (!$folder) {
                break;
            }
            $parentId = $folder->parent_id;
            $depth++;
        }
        return $depth;
    }
}
