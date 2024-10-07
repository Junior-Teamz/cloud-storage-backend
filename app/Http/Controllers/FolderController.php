<?php

namespace App\Http\Controllers;

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
use Illuminate\Database\Eloquent\ModelNotFoundException;

class FolderController extends Controller
{
    protected $checkPermissionFolderService;

    public function __construct(CheckFolderPermissionService $checkPermissionFolderService)
    {
        // Simpan service ke dalam property
        $this->checkPermissionFolderService = $checkPermissionFolderService;
    }

    /**
     * Calculate the total size of a folder and all its subfolders and files.
     *
     * This function takes a folder object and recursively calculates the total size of the folder
     * and all its subfolders and files. It returns the total size in bytes.
     *
     * @param \App\Models\Folder $folder The folder object to calculate the size for.
     *
     * @return int The total size of the folder in bytes.
     */
    private function calculateFolderSize(Folder $folder)
    {
        $totalSize = 0;

        // Hitung ukuran semua file di folder
        foreach ($folder->files as $file) {
            $totalSize += $file->size;
        }

        // Rekursif menghitung ukuran semua subfolder
        foreach ($folder->subfolders as $subfolder) {
            $totalSize += $this->calculateFolderSize($subfolder);
        }

        return $totalSize;
    }


    /**
     * Format size units from bytes to human readable format.
     *
     * This function takes a size in bytes and returns a string in the format of
     * the largest unit of measurement that is greater than or equal to 1.
     *
     * @param int $bytes The size in bytes to format.
     *
     * @return string The formatted size string, e.g. 1.5 MB, 2.3 GB, etc.
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
            $totalUsedStorage = $this->calculateFolderSize($rootFolder);

            // Format ukuran penyimpanan ke dalam KB, MB, atau GB
            $formattedStorageSize = $this->formatSizeUnits($totalUsedStorage);

            return response()->json([
                'message' => 'Your storage usage: ' . $totalUsedStorage,
                'data' => [
                    'rawSize' => $totalUsedStorage,
                    'formattedSize' => $formattedStorageSize
                ]
            ]);
        } catch (Exception $e) {
            Log::error('Error occured while retrieving storage usage: ' . $e->getMessage());

            return response()->json([
                'errors' => 'An error occured while retrieving storage usage.'
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
                ->with([
                    'user:id,uuid,name,email',
                    'subfolders.user', // Ambil data user yang terkait dengan folder
                    'subfolders.tags:uuid,name', // Ambil tags folder
                    'subfolders.instances:uuid,name,address', // Ambil instances folder
                    'subfolders.userFolderPermissions.user:id,uuid,name,email',
                    'subfolders.favorite', // Relasi favorite untuk subfolders
                    'files.user:id,uuid,name,email', // Ambil data user yang terkait dengan file
                    'files.tags:uuid,name', // Ambil tags file
                    'files.instances:uuid,name,address', // Ambil instances file
                    'files.userPermissions.user:id,uuid,name,email',
                ])
                ->select('id', 'name', 'created_at', 'updated_at', 'user_id') // Pilih hanya kolom yang diperlukan
                ->first();

            // Cek apakah parent folder ditemukan
            if (!$parentFolder) {
                return response()->json([
                    'message' => 'An error occurred. Please contact our support.'
                ], 404);
            }

            // Optimasi data subfolder
            $userFolders = $parentFolder->subfolders->map(function ($folder) use ($user) {
                // Cek apakah folder ini difavoritkan oleh user yang sedang login
                $favorite = $folder->favorite->where('user_id', $user->id)->first();
                $isFavorite = !is_null($favorite);
                $favoritedAt = $isFavorite ? $favorite->pivot->created_at : null;

                return [
                    'folder_id' => $folder->uuid,
                    'name' => $folder->name,
                    'public_path' => $folder->public_path,
                    'total_size' => $this->calculateFolderSize($folder), // Hitung total ukuran folder
                    'type' => $folder->type,
                    'is_favorite' => $isFavorite,
                    'favorited_at' => $favoritedAt,
                    'created_at' => $folder->created_at,
                    'updated_at' => $folder->updated_at,
                    'user' => [
                        'id' => $folder->user->uuid,
                        'name' => $folder->user->name,
                        'email' => $folder->user->email
                    ],
                    'tags' => $folder->tags, // Tags sudah diambil dengan select
                    'instances' => $folder->instances, // Instances sudah diambil dengan select
                    'shared_with' => $folder->userFolderPermissions
                ];
            });

            // Optimasi data file
            $responseFile = $parentFolder->files->map(function ($file) {
                $fileResponse = [
                    'id' => $file->uuid,
                    'name' => $file->name,
                    'public_path' => $file->public_path,
                    'size' => $file->size,
                    'type' => $file->type,
                    'created_at' => $file->created_at,
                    'updated_at' => $file->updated_at,
                    'folder_id' => $file->folder_id,
                    'user' => $file->user, // User sudah diambil dengan select
                    'tags' => $file->tags, // Tags sudah diambil dengan select
                    'instances' => $file->instances, // Instances sudah diambil dengan select
                    'shared_with' => $file->userPermissions
                ];

                return $fileResponse;
            });

            return response()->json([
                'data' => [
                    'folders' => $userFolders, // Sekarang berisi array folder dan tags
                    'files' => $responseFile
                ]
            ], 200);
        } catch (Exception $e) {
            Log::error('Error occurred on getting folders and files: ' . $e->getMessage(), [
                'parent_id' => isset($parentFolder) ? $parentFolder->id : null,
                'trace' => $e->getTraceAsString(),
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
     * @param int $id The ID of the folder to retrieve.
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
            $folder = Folder::with([
                'user:id,uuid,name,email',
                'parentFolder:id,uuid',
                'tags:uuid,name',
                'instances:uuid,name,address',
                'favorite',
                'userFolderPermissions.user:id,uuid,name,email', // Menambahkan relasi untuk mengambil shared users
                'subfolders.user:id,uuid,name,email', // Ambil data user yang terkait dengan folder
                'subfolders.tags:uuid,name', // Ambil tags folder
                'subfolders.instances:uuid,name,address', // Ambil instances folder
                'subfolders.userFolderPermissions.user:id,uuid,name,email',
                'subfolders.favorite', // Relasi favorite untuk subfolders
                'files:id,folder_id,name,path,size,type,created_at,updated_at,user_id',
                'files.user:id,uuid,name,email', // Ambil data user yang terkait dengan file
                'files.tags:uuid,name', // Ambil tags file
                'files.instances:uuid,name,address', // Ambil instances file
                'files.userPermissions.user:id,uuid,name,email'
            ])->where('uuid', $id)->first();

            $favorite = $folder->favorite->where('user_id', $user->id)->first();
            $isFavorite = !is_null($favorite);
            $favoritedAt = $isFavorite ? $favorite->pivot->created_at : null;

            // Persiapkan respon untuk folder
            $folderResponse = [
                'folder_id' => $folder->uuid,
                'name' => $folder->name,
                'public_path' => $folder->public_path,
                'total_size' => $this->calculateFolderSize($folder),
                'type' => $folder->type,
                'parent_id' => $folder->parent_id ? $folder->parentFolder->uuid ?? null : null,
                'is_favorited' => $isFavorite, // Tambahkan atribut is_favorited
                'favorited_at' => $favoritedAt,
                'user' => $folder->user,
                'tags' => $folder->tags,
                'instances' => $folder->instances,
                // Map shared users untuk folder
                'shared_with' => $folder->userFolderPermissions->map(function ($permission) {
                    return [
                        'id' => $permission->user->id,
                        'name' => $permission->user->name,
                        'email' => $permission->user->email,
                    ];
                })
            ];

            // Ambil subfolder dan buat hidden beberapa atribut yang tidak diperlukan
            $subfolders = $folder->subfolders->map(function ($subfolder) use ($user) {

                $favorite = $subfolder->favorite->where('user_id', $user->id)->first();
                $isFavorite = !is_null($favorite);
                $favoritedAt = $isFavorite ? $favorite->pivot->created_at : null;

                return [
                    'id' => $subfolder->uuid,
                    'name' => $subfolder->name,
                    'public_path' => $subfolder->public_path,
                    'total_size' => $this->calculateFolderSize($subfolder), // Hitung total ukuran folder
                    'type' => $subfolder->type,
                    'created_at' => $subfolder->created_at,
                    'updated_at' => $subfolder->updated_at,
                    'is_favorited' => $isFavorite, // Tambahkan atribut is_favorited
                    'favorited_at' => $favoritedAt,
                    'user' => $subfolder->user,
                    'tags' => $subfolder->tags,
                    'instances' => $subfolder->instances,
                    // Map shared users untuk subfolder
                    'shared_with' => $subfolder->userFolderPermissions->map(function ($permission) {
                        return [
                            'id' => $permission->user->id,
                            'name' => $permission->user->name,
                            'email' => $permission->user->email,
                        ];
                    })
                ];
            });

            // Ambil files dan buat hidden beberapa atribut yang tidak diperlukan
            $files = $folder->files->map(function ($file) {
                $fileData = [
                    'id' => $file->uuid,
                    'name' => $file->name,
                    'public_path' => $file->public_path,
                    'size' => $file->size,
                    'type' => $file->type,
                    'image_url' => null,
                    'created_at' => $file->created_at,
                    'updated_at' => $file->updated_at,
                    'user' => $file->user,
                    'tags' => $file->tags,
                    'instances' => $file->instances,
                    // Map shared users untuk file
                    'shared_with' => $file->userPermissions->map(function ($permission) {
                        return [
                            'id' => $permission->user->id,
                            'name' => $permission->user->name,
                            'email' => $permission->user->email,
                        ];
                    })
                ];

                return $fileData;
            });

            return response()->json([
                'data' => [
                    'folder_info' => $folderResponse,
                    'subfolders' => $subfolders,
                    'files' => $files,
                ],
            ], 200);
        } catch (Exception $e) {
            Log::error('Error occurred on getting folder info: ' . $e->getMessage(), [
                'folderId' => $id,
                'trace' => $e->getTraceAsString(),
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
                'name' => 'required|string|unique:folders,name',
                'parent_id' => 'nullable|integer|exists:folders,uuid',
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
                $parentFolder = Folder::where('uuid', $request->parent_id)->first();
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

            // Check folder depth limit
            $subfolderDepth = env('SUBFOLDER_DEPTH', 5);
            $depth = $this->getFolderDepth($parentId);
            if ($depth >= $subfolderDepth) {
                return response()->json([
                    'errors' => 'You cannot create more than ' . $subfolderDepth . ' subfolder levels.',
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
            $userInstance = User::find($userId);
            $newFolder->instances()->sync($userInstance);

            // Sync the tags using tag_ids
            $getTagIds = Tags::whereIn('uuid', $request->tag_ids)->get();
            $tagIds = $getTagIds->pluck('id')->toArray();
            $newFolder->tags()->sync($tagIds);

            // Generate public path after folder creation
            $publicPath = $this->getPublicPath($newFolder->id);
            $newFolder->update(['public_path' => $publicPath]);

            // Get the folder's NanoID for use in storage path
            $folderNameWithNanoId = $newFolder->nanoid;
            $path = $this->getFolderPath($newFolder->parent_id);
            $fullPath = $path . '/' . $folderNameWithNanoId;
            Storage::makeDirectory($fullPath);

            // Load instances and tags for the response
            $newFolder->load('user:id,uuid,name,email', 'parentFolder:id,uuid', 'instances:uuid,name,address', 'tags:uuid,name');

            $newFolder->parent_id = $newFolder->parentFolder->uuid ?? null;

            // Hide the nanoid from the response
            $newFolder->makeHidden(['nanoid', 'user_id', 'parent_folder', 'parentFolder']);

            // Commit the transaction if no errors
            DB::commit();

            return response()->json([
                'message' => $newFolder->parent_id ? 'Subfolder created successfully' : 'Folder created successfully',
                'data' => [
                    'folder' => $newFolder
                ]
            ], 201);
        } catch (Exception $e) {
            // Rollback if any error occurs
            DB::rollBack();

            Log::error('Error occurred on creating folder: ' . $e->getMessage(), [
                'name' => $request->name,
                'parentId' => $request->parent_id,
                'userId' => $userId,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'errors' => 'An error occurred while creating folder.',
            ], 500);
        }
    }

    /**
     * Add a tag to a folder.
     * 
     * This function add a tag to a folder. It accepts a JSON request containing the folder ID and tag ID, 
     * and checks if the user has permission to add the tag to the folder. If the user does not have permission, 
     * it will return an error response. If the user has permission, it will add the tag to the folder.
     * 
     * @param \Illuminate\Http\Request $request The incoming request containing 'folder_id' and 'tag_id'.
     * 
     * @return \Illuminate\Http\JsonResponse Returns a JSON response with the tag ID or an error message.
     * 
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If the folder or tag is not found.
     * @throws \Exception For general exceptions that may occur during the process.
     */
    public function addTagToFolder(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'folder_id' => 'required|integer|exists:folders,uuid',
            'tag_id' => 'required|integer|exists:tags,uuid',
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
            $folder = Folder::where('uuid', $request->folder_id);
            $tag = Tags::where('uuid', $request->tag_id);

            // Memeriksa apakah tag sudah terkait dengan folder
            if ($folder->tags->contains($tag->id)) {
                return response()->json([
                    'errors' => 'Tag already exists in folder.'
                ], 409);
            }

            DB::beginTransaction();

            // Menambahkan tag ke folder (tabel pivot folder_has_tags)
            $folder->tags()->attach($tag->id);

            $folder->load(['user:id,uuid,name,email', 'parentFolder:id,uuid', 'tags:uuid,name', 'instances:uuid,name,address', 'favorite']);

            // Ubah response parent_id menjadi UUID dari parent folder
            $folder->parent_id = $folder->parentFolder->uuid ?? null;

            // Sembunyikan relasi parent folder pada response
            $folder->makeHidden(['nanoid', 'user_id', 'parentFolder']);

            DB::commit();

            return response()->json([
                'message' => 'Successfully added tag to folder.',
                'folder' => $folder
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
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'errors' => 'An error occurred on adding tag to folder.'
            ], 500);
        }
    }


    /**
     * Remove a tag from a folder.
     * 
     * This method removes a tag from a folder. It accepts a JSON request containing the folder ID and tag ID, 
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
        $validator = Validator::make($request->all(), [
            'folder_id' => 'required|integer|exists:folders,uuid',
            'tag_id' => 'required|integer|exists:tags,uuid',
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
            $folder = Folder::where('uuid', $request->folder_id);
            $tag = Tags::where('uuid', $request->tag_id);

            // Memeriksa apakah tag terkait dengan folder
            if (!$folder->tags->contains($tag->id)) {
                return response()->json([
                    'errors' => 'Tag not found in folder.'
                ], 404);
            }

            DB::beginTransaction();

            // Menghapus tag dari folder (tabel pivot folder_has_tags)
            $folder->tags()->detach($tag->id);

            $folder->load(['user:id,uuid,name,email', 'parentFolder:id,uuid', 'tags:uuid,name', 'instances:uuid,name,address', 'favorite']);

            $folder->parent_id = $folder->parentFolder->uuid ?? null;

            $folder->makeHidden(['nanoid', 'user_id', 'parentFolder']);

            DB::commit();

            return response()->json([
                'message' => 'Successfully removed tag from folder.',
                'folder' => $folder
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
                'trace' => $e->getTraceAsString(),
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
            $folder = Folder::where('uuid', $id)->first();

            $oldNanoid = $folder->nanoid;

            $publicPath = $this->getPublicPath($folder->id);

            DB::beginTransaction();

            $folder->update([
                'name' => $request->name,
                'public_path' => $publicPath
            ]);

            // Update folder name in storage
            $path = $this->getFolderPath($folder->parent_id);
            $oldFullPath = $path . '/' . $oldNanoid;
            $newFullPath = $path . '/' . $folder->nanoid;

            if (Storage::exists($oldFullPath)) {
                Storage::move($oldFullPath, $newFullPath);
            }

            $folder->load(['user:id,uuid,name,email', 'parentFolder:id,uuid', 'tags:uuid,name', 'instances:id,name', 'favorite']);

            $folder->parent_id = $folder->parentFolder->uuid ?? null;

            $folder->makeHidden(['nanoid', 'user_id', 'parentFolder']);

            $favorite = $folder->favorite->where('user_id', $user->id)->first();
            $isFavorite = !is_null($favorite);
            $favoritedAt = $isFavorite ? $favorite->pivot->created_at : null;

            $folder['is_favorite'] = $isFavorite;
            $folder['favorited_at'] = $favoritedAt;

            DB::commit();

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
            DB::rollBack();

            Log::error('Error occurred on updating folder name: ' . $e->getMessage(), [
                'folderId' => $id,
                'name' => $request->name,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'errors' => 'An error occurred on updating folder.',
            ], 500);
        }
    }


    /**
     * Delete multiple folder.
     * 
     * This function accepts a JSON array of folder IDs and checks if the user has permission to delete the folder.
     * If the user does not have permission, it will return an error response. If the user has permission, it will delete the folder.
     * 
     * @param \Illuminate\Http\Request $request The incoming request containing the folder IDs to delete.
     * 
     * @return \Illuminate\Http\JsonResponse Returns a JSON response with the deleted folder ID or an error message.
     * 
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If the folder is not found.
     * @throws \Exception For general exceptions that may occur during the process.
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
            $folders = Folder::whereIn('uuid', $folderIds)->get();

            // Bandingkan ID yang ditemukan dengan yang diminta
            $foundFolderIds = $folders->pluck('id')->toArray();
            $foundFolderIdsToCheck = $folders->pluck('uuid')->toArray();
            $notFoundFolderIds = array_diff($folderIds, $foundFolderIdsToCheck);

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
                $path = $this->getFolderPath($folder->parent_id);
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
                'trace' => $e->getTraceAsString(),
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
                'folder_id' => 'required',
                'new_parent_id' => 'required',
            ],
        );

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            // Periksa apakah folder yang ingin dipindahkan ada
            $folder = Folder::where('uuid', $request->folder_id)->first();

            if (!$folder) {
                return response()->json([
                    'error' => 'Folder was not found.'
                ], 404);
            }

            // Ambil parent folder id yang lama dari folder yang ingin dipindahkan
            $oldParentId = $folder->parent_id;

            // Periksa apakah folder tujuan ada
            $newParentFolder = Folder::where('uuid', $request->new_parent_id)->first();

            $newParentFolderId = $newParentFolder->pluck('id');

            if (!$newParentFolder) {
                if (!$folder) {
                    return response()->json([
                        'error' => 'New parent folder was not found.'
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
            $oldPath = $this->getFolderPath($oldParentId);
            $newPath = $this->getFolderPath($folder->parent_id);
            $oldFullPath = $oldPath . '/' . $folder->nanoid;
            $newFullPath = $newPath . '/' . $folder->nanoid;

            if (Storage::exists($oldFullPath)) {
                Storage::move($oldFullPath, $newFullPath);
            }

            $folder->load(['user:id,uuid,name,email', 'parentFolder:id,uuid', 'tags:uuid,name', 'instances:id,name', 'favorite']);

            $folder->parent_id = $folder->parentFolder->uuid ?? null;

            $folder->makeHidden(['nanoid', 'user_id', 'parentFolder']);

            $favorite = $folder->favorite->where('user_id', $user->id)->first();
            $isFavorite = !is_null($favorite);
            $favoritedAt = $isFavorite ? $favorite->pivot->created_at : null;

            $folder['is_favorite'] = $isFavorite;
            $folder['favorited_at'] = $favoritedAt;

            DB::commit();

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
            DB::rollBack();

            Log::error('Error occurred on moving folder: ' . $e->getMessage(), [
                'folderId' => $request->folder_id,
                'newParentId' => $request->new_parent_id,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'errors' => 'An error occurred on moving the folder.',
            ], 500);
        }
    }

    /**
     * Get folder path in storage for a given folder id.
     * 
     * This method takes a folder ID and returns the path of the folder in storage.
     * If the folder ID is null, it returns an empty string, which is the root directory.
     * Otherwise, it uses a recursive approach to build the path from the folder to the root.
     * It uses the folder's NanoID in the storage path.
     * 
     * @param int|null $parentId The ID of the folder to get the path for.
     * 
     * @return string The path of the folder in storage.
     */
    private function getFolderPath($parentId)
    {
        if ($parentId === null) {
            return ''; // Root directory, no need for 'folders' base path
        }

        if(is_int($parentId)){
            $parentFolder = Folder::find($parentId);
        } else {
            $parentFolder = Folder::where('uuid', $parentId)->first();
        }
        $path = $this->getFolderPath($parentFolder->parent_id);

        // Use the folder's NanoID in the storage path
        $folderNameWithNanoId = $parentFolder->nanoid;

        return $path . '/' . $folderNameWithNanoId;
    }


    /**
     * Get full path of a folder for included in response.
     *
     * @param int $id The ID of the folder to get the full path for.
     * 
     * @return \Illuminate\Http\JsonResponse Returns a JSON response with the full path of the folder or an error message.
     * 
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If the folder is not found.
     * @throws \Exception For general exceptions that may occur during the process.
     */
    public function getPublicPath($id)
    {
        try {
            if(is_int($id)){
                $folder = Folder::find($id);
            } else {
                $folder = Folder::where('uuid', $id)->first();
            }
            $path = [];

            while ($folder) {
                array_unshift($path, $folder->name);
                $folder = $folder->parentFolder;
            }

            return implode('/', $path);
        } catch (Exception $e) {
            Log::error('Error occurred on getting folder path: ' . $e->getMessage(), [
                'folder_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'errors' => 'An error occurred on getting folder path.',
            ], 500);
        }
    }

    /**
     * Get full path of a folder.
     * 
     * This method takes a folder ID and returns JSON response of full path of the folder.
     * The full path includes the folder's name and all its parents' names, separated by slashes.
     * If the folder ID is not found, it returns a JSON response with an error message.
     * If an error occurs during the process, it logs the error and returns a JSON response with an error message.
     * 
     * @param int $id The ID of the folder to get the full path for.
     * 
     * @return \Illuminate\Http\JsonResponse Returns a JSON response with the full path of the folder or an error message.
     * 
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If the folder is not found.
     * @throws \Exception For general exceptions that may occur during the process.
     */
    public function getFullPath($id)
    {
        try {
            if(is_int($id)){
                $folder = Folder::find($id);
            } else {
                $folder = Folder::where('uuid', $id)->first();
            }
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
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'errors' => 'An error occurred on getting folder path.',
            ], 500);
        }
    }

    /**
     * Hitung kedalaman folder
     * @param int|null $parentId
     * @return int
     */
    private function getFolderDepth($parentId)
    {
        $depth = 0;
        while ($parentId) {
            if(is_int($parentId)){
                $folder = Folder::find($parentId);
            } else {
                $folder = Folder::where('uuid', $parentId)->first();
            }
            if (!$folder) {
                break;
            }
            $parentId = $folder->parent_id;
            $depth++;
        }
        return $depth;
    }
}
