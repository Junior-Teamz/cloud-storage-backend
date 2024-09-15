<?php

namespace App\Http\Controllers;

use App\Models\File;
use Exception;
use App\Models\Tags;
use App\Models\User;
use App\Models\Folder;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use App\Services\CheckFolderPermissionService;
use App\Services\GenerateImageURLService;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class FolderController extends Controller
{
    protected $checkPermissionFolderService;
    protected $generateImageUrlService;

    public function __construct(CheckFolderPermissionService $checkPermissionFolderService, GenerateImageURLService $generateImageUrlService)
    {
        // Simpan service ke dalam property
        $this->checkPermissionFolderService = $checkPermissionFolderService;
        $this->generateImageUrlService = $generateImageUrlService;
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
                    'subfolders.user:id,name,email', // Ambil data user yang terkait dengan folder
                    'subfolders.tags:id,name', // Ambil tags folder
                    'subfolders.instances:id,name,address', // Ambil instances folder
                    'files.user:id,name,email', // Ambil data user yang terkait dengan file
                    'files.tags:id,name', // Ambil tags file
                    'files.instances:id,name,address' // Ambil instances file
                ])
                ->select('id', 'name', 'created_at', 'updated_at', 'user_id') // Select only necessary columns
                ->first();

            // Cek apakah parent folder ditemukan
            if (!$parentFolder) {
                return response()->json([
                    'message' => 'An error occurred. Please contact our support.'
                ], 404);
            }

            // Optimasi data subfolder
            $userFolders = $parentFolder->subfolders->map(function ($folder) {
                return [
                    'folder_id' => $folder->id,
                    'name' => $folder->name,
                    'total_size' => $this->calculateFolderSize($folder), // Hitung total ukuran folder
                    'type' => $folder->type,
                    'created_at' => $folder->created_at,
                    'updated_at' => $folder->updated_at,
                    'user' => $folder->user, // User sudah diambil dengan select
                    'tags' => $folder->tags, // Tags sudah diambil dengan select
                    'instances' => $folder->instances // Instances sudah diambil dengan select
                ];
            });

            // Optimasi data file
            $responseFile = $parentFolder->files->map(function ($file) {
                $fileResponse = [
                    'id' => $file->id,
                    'name' => $file->name,
                    'public_path' => $file->public_path,
                    'size' => $file->size,
                    'type' => $file->type,
                    'created_at' => $file->created_at,
                    'updated_at' => $file->updated_at,
                    'folder_id' => $file->folder_id,
                    'user' => $file->user, // User sudah diambil dengan select
                    'tags' => $file->tags, // Tags sudah diambil dengan select
                    'instances' => $file->instances // Instances sudah diambil dengan select
                ];

                // Jika file adalah gambar (berdasarkan MIME type), buat URL sementara
                if (Str::startsWith(Storage::mimeType($file->path), 'image')) {
                    $fileResponse['image_url'] = $this->generateImageUrlService->generateUrlForImage($file->id);
                }

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
                'parent_id' => $parentFolder->id ?? null, // Pastikan null jika parentFolder tidak ditemukan
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
        // periksa apakah user memiliki izin read.
        $permission = $this->checkPermissionFolderService->checkPermissionFolder($id, ['read']);

        if (!$permission) {
            return response()->json([
                'errors' => 'You do not have permission to access this folder.',
            ], 403);
        }

        try {
            // Cari folder dengan ID yang diberikan dan sertakan subfolder, file, tags, dan instances yang relevan
            $folder = Folder::with([
                'user:id,name,email',
                'subfolders:id,parent_id,name,type,created_at,updated_at,user_id',
                'files:id,folder_id,name,path,size,type,created_at,updated_at,user_id',
                'tags:id,name',
                'instances:id,name,address'
            ])->findOrFail($id);

            // Persiapkan respon untuk folder
            $folderResponse = [
                'folder_id' => $folder->id,
                'name' => $folder->name,
                'total_size' => $this->calculateFolderSize($folder),
                'type' => $folder->type,
                'parent_id' => $folder->parent_id ? $folder->parentFolder->id : null,
                'user' => [
                    'id' => $folder->user->id,
                    'name' => $folder->user->name,
                    'email' => $folder->user->email
                ],
                'tags' => [
                    'id' => $folder->tags->id,
                    'name' => $folder->tags->name
                ],
                'instances' => [
                    'id' => $folder->instances->id,
                    'name' => $folder->instances->name,
                    'address' => $folder->instances->address
                ]
            ];

            // Ambil subfolder dan buat hidden beberapa atribut yang tidak diperlukan
            $subfolders = $folder->subfolders->map(function ($subfolder) {
                return [
                    'id' => $subfolder->id,
                    'name' => $subfolder->name,
                    'type' => $subfolder->type,
                    'created_at' => $subfolder->created_at,
                    'updated_at' => $subfolder->updated_at,
                    'user' => [
                        'id' => $subfolder->user->id,
                        'name' => $subfolder->user->name,
                        'email' => $subfolder->user->email
                    ],
                    'tags' => [
                        'id' => $subfolder->tags->id,
                        'name' => $subfolder->tags->name
                    ],
                    'instances' => [
                        'id' => $subfolder->instances->id,
                        'name' => $subfolder->instances->name,
                        'address' => $subfolder->instances->address
                    ]
                ];
            });

            // Ambil files dan buat hidden beberapa atribut yang tidak diperlukan
            $files = $folder->files->map(function ($file) {
                $mimeType = Storage::mimeType($file->path);

                $fileData = [
                    'id' => $file->id,
                    'name' => $file->name,
                    'public_path' => $file->public_path,
                    'size' => $file->size,
                    'type' => $file->type,
                    'created_at' => $file->created_at,
                    'updated_at' => $file->updated_at,
                    'user' => [
                        'id' => $file->user->id,
                        'name' => $file->user->name,
                        'email' => $file->user->email
                    ],
                    'tags' => [
                        'id' => $file->tags->id,
                        'name' => $file->tags->name
                    ],
                    'instances' => [
                        'id' => $file->instances->id,
                        'name' => $file->instances->name,
                        'address' => $file->instances->address
                    ]
                ];

                // Tambahkan URL gambar jika file berupa gambar
                if (Str::startsWith($mimeType, 'image')) {
                    $fileData['image_url'] = $this->generateImageUrlService->generateUrlForImage($file->id);
                }

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
     * @param \Illuminate\Http\Request $request The incoming request containing 'name' and 'parent_id' and 'tags'.
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
                'parent_id' => 'nullable|integer|exists:folders,id',
                'tags' => 'nullable|array',
                'tags.*' => ['string', 'regex:/^[a-zA-Z\s]+$/'],
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

            // Dapatkan folder root pengguna, jika tidak ada parent_id yang disediakan
            $folderRootUser = Folder::where('user_id', $userId)->whereNull('parent_id')->first();

            // Periksa apakah parent_id ada pada request? , jika tidak ada maka gunakan id dari folder root user default
            // Jika ada, gunakan parent_id dari request.
            if ($request->parent_id === null) {
                $parentId = $folderRootUser->id;
            } else if ($request->parent_id) {
                // check if parent_id is another user folder, then check if user login right now have the permission to edit folder on that folder. checked with checkPermission.
                $permission = $this->checkPermissionFolderService->checkPermissionFolder($request->parent_id, 'write');
                if (!$permission) {
                    return response()->json([
                        'errors' => 'You do not have permission to create folder in this parent_id',
                    ], 403);
                } else {
                    $parentId = $request->parent_id;
                }
            }

            // Mendapatkan konfigurasi tingkat kedalaman subfolder dari .env (default=5)
            $subfolderDepth = env('SUBFOLDER_DEPTH', 5);

            // Cek kedalaman folder, batasi hingga level yang ditentukan
            $depth = $this->getFolderDepth($parentId);
            if ($depth >= $subfolderDepth) {
                return response()->json([
                    'errors' => 'You cannot create more than ' . $subfolderDepth . ' subfolder levels.',
                ], 403);
            }

            // MEMULAI TRANSACTION MYSQL
            DB::beginTransaction();

            // Create folder in database
            $newFolder = Folder::create([
                'name' => $request->name,
                'user_id' => $userId,
                'parent_id' => $parentId,
            ]);

            $userData = User::where('id', $userId)->first();

            $userInstance = $userData->instances->pluck('id')->toArray();  // Mengambil instance user
            $newFolder->instances()->sync($userInstance);  // Sinkronisasi instance ke folder baru

            if ($request->has('tags')) {
                // Proses tags
                $tagIds = [];

                foreach ($request->tags as $tagName) {
                    // Periksa apakah tag sudah ada di database (case-insensitive)
                    $tag = Tags::whereRaw('LOWER(name) = ?', [strtolower($tagName)])->first();

                    if (!$tag) {
                        // Jika tag belum ada, buat tag baru
                        $tag = Tags::create(['name' => ucfirst($tagName)]);
                    }

                    // Ambil id dari tag (baik yang sudah ada atau baru dibuat)
                    $tagIds[] = $tag->id;

                    // Masukkan id dan name dari tag ke dalam array untuk response
                    $tagsData[] = [
                        'id' => $tag->id,
                        'name' => $tag->name
                    ];
                }

                // Simpan tags ke tabel pivot folder_has_tags
                $newFolder->tags()->sync($tagIds);
            }

            // Generate public path setelah folder dibuat
            $publicPath = $this->getPublicPath($newFolder->id);

            // Simpan public path ke folder baru
            $newFolder->update(['public_path' => $publicPath]);

            // COMMIT JIKA TIDAK ADA ERROR
            DB::commit();

            // Get NanoID folder
            $folderNameWithNanoId = $newFolder->nanoid;

            // Create folder in storage
            $path = $this->getFolderPath($newFolder->parent_id);
            $fullPath = $path . '/' . $folderNameWithNanoId;
            Storage::makeDirectory($fullPath);

            $newFolder->makeHidden(['nanoid']);

            // inject data response tagsData ke response newFolder
            $newFolder['tags'] = $tagsData;

            // Load instances untuk dimasukkan ke dalam response
            $newFolder->load('instances:id,name');

            return response()->json([
                'message' => $newFolder->parent_id ? 'Subfolder created successfully' : 'Folder created successfully',
                'data' => [
                    'folder' => $newFolder
                ]
            ], 201);
        } catch (Exception $e) {
            // ROLLBACK JIKA ADA ERROR
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
            'folder_id' => 'required|integer|exists:folders,id',
            'tag_id' => 'required|integer|exists:tags,id',
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
            $folder = Folder::findOrFail($request->folder_id);
            $tag = Tags::findOrFail($request->tag_id);

            // Memeriksa apakah tag sudah terkait dengan folder
            if ($folder->tags->contains($tag->id)) {
                return response()->json([
                    'errors' => 'Tag already exists in folder.'
                ], 409);
            }

            DB::beginTransaction();

            // Menambahkan tag ke folder (tabel pivot folder_has_tags)
            $folder->tags()->attach($tag->id);

            DB::commit();

            return response()->json([
                'message' => 'Successfully added tag to folder.',
                'data' => [
                    'folder_id' => $folder->id,
                    'folder_name' => $folder->name,
                    'tag_id' => $tag->id,
                    'tag_name' => $tag->name
                ]
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
            'folder_id' => 'required|integer|exists:folders,id',
            'tag_id' => 'required|integer|exists:tags,id',
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
            $folder = Folder::findOrFail($request->folder_id);
            $tag = Tags::findOrFail($request->tag_id);

            // Memeriksa apakah tag terkait dengan folder
            if (!$folder->tags->contains($tag->id)) {
                return response()->json([
                    'errors' => 'Tag not found in folder.'
                ], 404);
            }

            DB::beginTransaction();

            // Menghapus tag dari folder (tabel pivot folder_has_tags)
            $folder->tags()->detach($tag->id);

            DB::commit();

            return response()->json([
                'message' => 'Successfully removed tag from folder.',
                'data' => []
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
     * Update folder name and tags.
     * 
     * This method handles the folder name and tags update process, including validating the request data, 
     * checking user permissions on the folder, and updating the folder in the database. 
     * It ensures transactional integrity and logs any errors that occur during the process.
     * 
     * @param \Illuminate\Http\Request $request The incoming request containing 'name' and 'tags'.
     * 
     * @return \Illuminate\Http\JsonResponse Returns a JSON response with success or error messages.
     * 
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If the folder is not found.
     * @throws \Exception For general exceptions that may occur during the transaction.
     */
    public function update(Request $request, $id)
    {
        $permissionCheck = $this->checkPermissionFolderService->checkPermissionFolder($id, 'write');
        if (!$permissionCheck) {
            return response()->json([
                'errors' => 'You do not have permission to edit this folder.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string',
            'tags' => 'nullable|array',
            'tags.*' => ['string', 'regex:/^[a-zA-Z\s]+$/'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $folder = Folder::findOrFail($id);

            $oldNanoid = $folder->nanoid;

            $publicPath = $this->getPublicPath($folder->id);

            DB::beginTransaction();

            $folder->update([
                'name' => $request->name,
                'public_path' => $publicPath
            ]);

            if ($request->has('tags')) {
                // Process tags
                $tags = $request->tags;
                $tagIds = [];

                foreach ($tags as $tagName) {
                    // Check if tag exists, case-insensitive
                    $tag = Tags::whereRaw('LOWER(name) = ?', [strtolower($tagName)])->first();

                    if (!$tag) {
                        // If tag doesn't exist, create it
                        $tag = Tags::create(['name' => ucfirst($tagName)]);
                    }

                    $tagIds[] = $tag->id;
                }

                // Sync the tags with the folder (in the pivot table)
                $folder->tags()->sync($tagIds);
            }

            DB::commit();

            // Update folder name in storage
            $path = $this->getFolderPath($folder->parent_id);
            $oldFullPath = $path . '/' . $oldNanoid;
            $newFullPath = $path . '/' . $folder->nanoid;

            if (Storage::exists($oldFullPath)) {
                Storage::move($oldFullPath, $newFullPath);
            }

            $folder->load(['user:id,name,email', 'tags', 'instances:id,name']);

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
            'folder_ids.*' => 'integer|exists:folders,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $folderIds = $request->folder_ids;

        try {
            // Ambil semua folder yang sesuai
            $folders = Folder::whereIn('id', $folderIds)->get();

            // Cek izin untuk semua folder
            $noPermissionFolders = [];

            foreach ($folders as $folder) {
                if (!$this->checkPermissionFolderService->checkPermissionFolder($folder->id, 'write')) {
                    $noPermissionFolders[] = $folder->id;
                }
            }

            // Jika ada folder yang tidak memiliki izin
            if (!empty($noPermissionFolders)) {
                Log::error('User attempted to delete folders without permission: ' . implode(', ', $noPermissionFolders));
                return response()->json([
                    'errors' => 'You do not have permission to delete some of the selected folders.',
                ], 403);
            }

            DB::beginTransaction();

            // Hapus data pivot yang terkait dengan setiap folder
            foreach ($folders as $folder) {

                if ($folder->tags()->exists()) {
                    $folder->tags()->detach();
                }

                if ($folder->instances()->exists()) {
                    $folder->instances()->detach();
                }

                // Hapus folder dari database
                $folder->delete();
            }

            DB::commit();

            // Hapus folder dari storage setelah commit ke database berhasil
            foreach ($folders as $folder) {
                $path = $this->getFolderPath($folder->parent_id);
                $fullPath = $path . '/' . $folder->nanoid;

                if (Storage::exists($fullPath)) {
                    Storage::deleteDirectory($fullPath);
                }
            }

            return response()->json([
                'message' => 'Folder(s) deleted successfully.',
            ], 200);
        } catch (ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json([
                'errors' => 'Folder not found: ' . $e->getMessage(),
            ], 404);
        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Error occurred on deleting folder(s): ' . $e->getMessage(), [
                'folderIds' => $folderIds,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'errors' => 'An error occurred on deleting folder(s).',
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

            // jika tidak ada izin pada folder tujuan
            if (!$checkIfNewParentIdIsBelongsToUser) {
                $permissionCheck = $this->checkPermissionFolderService->checkPermissionFolder($request->new_parent_id, 'write');
                if (!$permissionCheck) {
                    return response()->json([
                        'errors' => 'You do not have permission on the folder you are trying to move this folder to.',
                    ], 403);
                }
            }

            DB::beginTransaction();

            $folder->parent_id = $request->new_parent_id;
            $folder->save();

            DB::commit();

            // Move folder in storage
            $oldPath = $this->getFolderPath($oldParentId);
            $newPath = $this->getFolderPath($folder->parent_id);
            $oldFullPath = $oldPath . '/' . $folder->nanoid;
            $newFullPath = $newPath . '/' . $folder->nanoid;

            if (Storage::exists($oldFullPath)) {
                Storage::move($oldFullPath, $newFullPath);
            }

            $folder->load(['user:id,name,email', 'tags', 'instances:id,name']);

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

        $parentFolder = Folder::findOrFail($parentId);
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
