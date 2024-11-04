<?php

namespace App\Http\Controllers;

use App\Models\Folder;
use App\Models\User;
use App\Services\CheckFilePermissionService;
use App\Services\CheckFolderPermissionService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class FolderFavoriteController extends Controller
{
    protected $checkPermissionFolderService;
    protected $checkPermissionFileService;

    public function __construct(CheckFolderPermissionService $checkPermissionFolderService, CheckFilePermissionService $checkFilePermissionServices)
    {
        // Simpan service ke dalam property
        $this->checkPermissionFolderService = $checkPermissionFolderService;
        $this->checkPermissionFileService = $checkFilePermissionServices;
    }

    /**
     * Calculate the total size of a folder and its subfolders.
     *
     * This method recursively calculates the total size of a folder, including the sizes of all files
     * within the folder and its subfolders.
     *
     * @param Folder $folder The folder object to calculate the size of.
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
     * Count all favorite folders.
     *
     * This function counts the total number of favorite folders,
     * the number of owned favorite folders, and the number of shared favorite folders for the currently logged-in user.
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function countAllFavoriteFolders()
    {
        $userLogin = Auth::user();

        try {
            $userInfo = User::find($userLogin->id);
            // Hitung total folder yang difavoritkan oleh user saat ini
            $totalFavoriteFolders = $userInfo->favoriteFolders()->count();

            // Hitung folder milik sendiri yang difavoritkan oleh user
            $ownFavoriteFolders = $userInfo->favoriteFolders()
                ->where('user_id', $userInfo->id)
                ->count();

            // Hitung folder yang dibagikan (bukan milik user), namun difavoritkan oleh user
            $sharedFavoriteFolders = $userInfo->favoriteFolders()
                ->where('user_id', '!=', $userInfo->id)
                ->count();

            return response()->json([
                'total_favorite_folders' => $totalFavoriteFolders,
                'own_favorite_folders' => $ownFavoriteFolders,
                'shared_favorite_folders' => $sharedFavoriteFolders,
            ], 200);
        } catch (Exception $e) {
            Log::error('Error counting favorite folders: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred while counting favorite folders.'
            ], 500);
        }
    }

    // public function getAllFavoriteFolders(Request $request)
    // {
    //     $userLogin = Auth::user();

    //     try {
    //         // Ambil user yang sedang login
    //         $user = User::find($userLogin->id);

    //         // Ambil parameter pencarian dan pagination dari request
    //         $search = $request->input('search');
    //         $instanceId = $request->input('instance_id');
    //         $perPage = $request->input('per_page', 10); // Default 10 items per page

    //         // Query folder favorit user dengan pivot data
    //         $favoriteFoldersQuery = $user->favoriteFolders()->with(['user:id,name,email', 'tags:id,name', 'instances:id,name,address', 'userFolderPermissions',]);

    //         // Filter berdasarkan nama folder jika ada parameter 'search'
    //         if ($search) {
    //             $favoriteFoldersQuery->where('name', 'LIKE', "%{$search}%");
    //         }

    //         // Filter berdasarkan instansi jika ada parameter 'instance_id'
    //         if ($instanceId) {
    //             $favoriteFoldersQuery->whereHas('instances', function ($query) use ($instanceId) {
    //                 $query->where('instance_id', $instanceId);
    //             });
    //         }

    //         // Lakukan pagination pada hasil query
    //         $favoriteFolders = $favoriteFoldersQuery->paginate($perPage);

    //         // Modifikasi respons untuk menambahkan waktu ditambahkan ke favorit
    //         $favoriteFolders->getCollection()->transform(function ($folder) use ($user) {
    //             $favorite = $folder->favorite()->where('user_id', $user->id)->first();
    //             $isFavorite = !is_null($favorite);
    //             $favoritedAt = $isFavorite ? $favorite->pivot->created_at : null;

    //             $folder['is_favorite'] = $isFavorite; // Otomatis menjadi true karena folder yang diambil adalah folder yang di favoritkan.
    //             $folder['favorited_at'] = $favoritedAt;
    //             $checkPermission = $this->checkPermissionFolderService->checkPermissionFolder($folder->id, 'read');
    //             if ($checkPermission) {
    //                 $folder['shared_with'] = $folder->userFolderPermissions->map(function ($permission) {
    //                     return [
    //                         'id' => $permission->id,
    //                         'folder_id' => $permission->folder_id,
    //                         'permissions' => $permission->permissions,
    //                         'created_at' => $permission->created_at,
    //                         'updated_at' => $permission->updated_at,
    //                         'user' => [
    //                             'id' => $permission->user->id,
    //                             'name' => $permission->user->name,
    //                             'email' => $permission->user->email,
    //                         ]
    //                     ];
    //                 });
    //             }
    //             return $folder;
    //         });

    //         $favoriteFolders->makeHidden(['nanoid', 'user_id', 'pivot', 'userFolderPermissions']);

    //         return response()->json([
    //             'favorite_folders' => $favoriteFolders
    //         ], 200);
    //     } catch (Exception $e) {
    //         Log::error('Error occurred while fetching favorite folders: ' . $e->getMessage(), [
    //             'trace' => $e->getTrace()
    //         ]);

    //         return response()->json([
    //             'errors' => 'An error occurred while fetching favorite folders'
    //         ], 500);
    //     }
    // }

    /**
     * Retrieve all favorite items (folders and files) for the authenticated user.
     *
     * This method retrieves a paginated list of both favorite folders and files for the currently authenticated user.
     * It allows filtering by search query and instance UUID. The response includes separate paginated lists for folders
     * and files, along with additional data such as whether the item is favorited, favorited timestamp, and shared user details.
     *
     * @param  \Illuminate\Http\Request  $request The incoming request containing search parameters and pagination options.
     * @return \Illuminate\Http\JsonResponse A JSON response containing the paginated lists of favorite folders and files.
     */
    public function getAllFavoriteItems(Request $request)
    {
        $userLogin = Auth::user();

        try {
            // Ambil user yang sedang login
            $user = User::find($userLogin->id);

            // Ambil parameter pencarian dan pagination dari request
            $search = $request->input('search');
            $instanceId = $request->input('instance_id');
            $perPage = $request->input('per_page', 10); // Default 10 items per page

            // Parameter pagination untuk folder dan file
            $pageFolder = $request->input('page_folder', 1);
            $pageFile = $request->input('page_file', 1);

            // Query folder favorit user dengan pivot data
            $favoriteFoldersQuery = $user->favoriteFolders()->with(['user:id,name,email', 'tags:id,name', 'instances:id,name,address', 'userFolderPermissions',]);

            // Filter berdasarkan nama folder jika ada parameter 'search'
            if ($search) {
                $favoriteFoldersQuery->where('name', 'LIKE', "%{$search}%");
            }

            // Filter berdasarkan instansi jika ada parameter 'instance_id'
            if ($instanceId) {
                $favoriteFoldersQuery->whereHas('instances', function ($query) use ($instanceId) {
                    $query->where('instance_id', $instanceId);
                });
            }

            // Lakukan pagination pada hasil query folder
            $favoriteFolders = $favoriteFoldersQuery->paginate($perPage, ['*'], 'page_folder', $pageFolder);

            // Modifikasi respons untuk menambahkan waktu ditambahkan ke favorit
            $favoriteFolders->getCollection()->transform(function ($folder) use ($user) {
                $favorite = $folder->favorite()->where('user_id', $user->id)->first();
                $isFavorite = !is_null($favorite);
                $favoritedAt = $isFavorite ? $favorite->pivot->created_at : null;

                $folder['is_favorite'] = $isFavorite;
                $folder['favorited_at'] = $favoritedAt;

                $checkPermission = $this->checkPermissionFolderService->checkPermissionFolder($folder->id, 'read');
                if ($checkPermission) {
                    $folder['shared_with'] = $folder->userFolderPermissions->map(function ($permission) {
                        return [
                            'id' => $permission->id,
                            'folder_id' => $permission->folder_id,
                            'permissions' => $permission->permissions,
                            'created_at' => $permission->created_at,
                            'updated_at' => $permission->updated_at,
                            'user' => [
                                'id' => $permission->user->id,
                                'name' => $permission->user->name,
                                'email' => $permission->user->email,
                            ]
                        ];
                    });
                }
                return $folder;
            });

            $favoriteFolders->makeHidden(['nanoid', 'user_id', 'pivot', 'userFolderPermissions']);

            // Query file favorit user
            $favoriteFilesQuery = $user->favoriteFiles()->with(['user:id,name,email', 'tags:id,name', 'instances:id,name,address', 'userPermissions']);

            // Filter berdasarkan nama file jika ada parameter 'search'
            if ($search) {
                $favoriteFilesQuery->where('name', 'LIKE', "%{$search}%");
            }

            // Filter berdasarkan instansi jika ada parameter 'instance_id'
            if ($instanceId) {
                $favoriteFilesQuery->whereHas('instances', function ($query) use ($instanceId) {
                    $query->where('instance_id', $instanceId);
                });
            }

            // Lakukan pagination pada hasil query file
            $favoriteFiles = $favoriteFilesQuery->paginate($perPage, ['*'], 'page_file', $pageFile);

            // Modifikasi respons untuk menambahkan waktu ditambahkan ke favorit
            $favoriteFiles->getCollection()->transform(function ($file) use ($user) {
                $favorite = $file->favorite()->where('user_id', $user->id)->first();
                $isFavorite = !is_null($favorite);
                $favoritedAt = $isFavorite ? $favorite->pivot->created_at : null;

                $file['is_favorite'] = $isFavorite;
                $file['favorited_at'] = $favoritedAt;

                $checkPermission = $this->checkPermissionFileService->checkPermissionFile($file->id, 'read');
                if ($checkPermission) {
                    $file['shared_with'] = $file->userPermissions->map(function ($permission) {
                        return [
                            'id' => $permission->id,
                            'file_id' => $permission->file_id,
                            'permissions' => $permission->permissions,
                            'created_at' => $permission->created_at,
                            'updated_at' => $permission->updated_at,
                            'user' => [
                                'id' => $permission->user->id,
                                'name' => $permission->user->name,
                                'email' => $permission->user->email,
                            ]
                        ];
                    });
                }

                return $file;
            });

            $favoriteFiles->makeHidden(['nanoid', 'path', 'user_id', 'pivot', 'userPermissions']);

            return response()->json([
                'favorite_folders' => $favoriteFolders,
                'favorite_files' => $favoriteFiles
            ], 200);
        } catch (Exception $e) {
            Log::error('Error occurred while fetching favorite items: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred while fetching favorite items'
            ], 500);
        }
    }

    /**
     * Add a folder to the authenticated user's favorites.
     *
     * This method adds a folder to the current user's favorite list. It first validates the request
     * to ensure the `folder_id` is present and corresponds to an existing folder. Then, it checks
     * if the user has write permission for the folder. If the folder is not already in the user's
     * favorites, it adds it and returns a success response with the folder details. If the folder
     * is already a favorite, it returns a 409 Conflict response.
     *
     * @param  \Illuminate\Http\Request  $request The incoming request containing the `folder_id`.
     * @return \Illuminate\Http\JsonResponse A JSON response indicating success or failure.
     */
    public function addNewFavorite(Request $request)
    {
        $userLogin = Auth::user();

        // Validasi request
        $validator = Validator::make($request->all(), [
            'folder_id' => 'required|exists:folders,id',
        ]);

        // Jika validasi gagal, kirimkan respon error
        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Ambil data user
            $user = User::find($userLogin->id);

            // Ambil folder yang akan difavoritkan beserta relasi tags, instances, dan favorite status
            $folder = Folder::with(['user:id,name,email', 'tags:id,name', 'instances:id,name,address', 'userFolderPermissions'])->findOrFail($request->folder_id);

            // Cek apakah user memiliki izin untuk memfavoritkan folder
            $checkPermission = $this->checkPermissionFolderService->checkPermissionFolder($folder->id, ['write']);

            if (!$checkPermission) {
                return response()->json([
                    'errors' => 'You do not have permission to favorite any of the folders.'
                ], 403);
            }

            // Cek apakah folder sudah ada di daftar favorit pengguna
            $existingFavorite = $folder->favorite()->where('user_id', $user->id)->first();

            if ($existingFavorite) {
                // Jika sudah ada, kembalikan respon bahwa sudah difavoritkan
                return response()->json([
                    'message' => 'Folder sudah ada di daftar favorit.',
                    'folder' => [
                        'id' => $folder->id,
                        'name' => $folder->name,
                        'public_path' => $folder->public_path,
                        'total_size' => $this->calculateFolderSize($folder),
                        'type' => $folder->type,
                        'parent_id' => $folder->parent_id ? $folder->parentFolder->id : null,
                        'is_favorited' => !is_null($existingFavorite),
                        'favorited_at' => $existingFavorite->pivot->created_at ?? null,
                        'user' => $folder->user,
                        'tags' => $folder->tags,
                        'instances' => $folder->instances,
                        'shared_with' => $folder->userFolderPermissions->map(function ($permission) {
                            return [
                                'id' => $permission->id,
                                'folder_id' => $permission->folder_id,
                                'permissions' => $permission->permissions,
                                'created_at' => $permission->created_at,
                                'user' => [
                                    'id' => $permission->user->id,
                                    'name' => $permission->user->name,
                                    'email' => $permission->user->email,
                                ]
                            ];
                        })
                    ]
                ], 409);
            }

            DB::beginTransaction();

            // Tambahkan folder ke favorit user
            $user->favoriteFolders()->attach($folder->id);

            // Ambil ulang folder setelah ditambahkan ke favorit
            $folder->load(['user:id,name,email', 'tags:id,name', 'instances:id,name,address', 'userFolderPermissions']);

            $isFavorite = $folder->favorite()->where('user_id', $user->id)->first();

            DB::commit();

            // Setelah berhasil ditambahkan ke favorit, kirimkan respon dengan data lengkap folder
            return response()->json([
                'message' => 'Folder berhasil ditambahkan ke favorit.',
                'folder' => [
                    'id' => $folder->id,
                    'name' => $folder->name,
                    'public_path' => $folder->public_path,
                    'total_size' => $this->calculateFolderSize($folder),
                    'type' => $folder->type,
                    'parent_id' => $folder->parent_id ? $folder->parentFolder->id : null,
                    'is_favorited' => !is_null($isFavorite),
                    'favorited_at' => $isFavorite->pivot->created_at ?? null,
                    'user' => $folder->user,
                    'tags' => $folder->tags,
                    'instances' => $folder->instances,
                    'shared_with' => $folder->userFolderPermissions->map(function ($permission) {
                        return [
                            'id' => $permission->id,
                            'folder_id' => $permission->folder_id,
                            'permissions' => $permission->permissions,
                            'created_at' => $permission->created_at,
                            'user' => [
                                'id' => $permission->user->id,
                                'name' => $permission->user->name,
                                'email' => $permission->user->email,
                            ]
                        ];
                    })
                ]
            ], 200);
        } catch (Exception $e) {
            // Jika terjadi error, rollback transaksi
            DB::rollBack();

            Log::error('Error occured while adding folder to favorite: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'Terjadi kesalahan saat menambahkan favorit.'
            ], 500);
        }
    }

    /**
     * Remove a folder from the authenticated user's favorites.
     *
     * This method removes a specific folder from the authenticated user's list of favorite folders.
     * It checks if the folder exists and if it's in the user's favorites before attempting removal.
     * If successful, it returns a success message and the updated folder details.
     *
     * @param string $folderId The UUID of the folder to remove from favorites.
     * @return \Illuminate\Http\JsonResponse A JSON response indicating success or failure.
     */
    public function deleteFavoriteFolder($folderId)
    {
        $userLogin = Auth::user();

        try {
            // Ambil data user dengan folder favorit
            $user = User::with('favoriteFolders')->find($userLogin->id);

            $folder = Folder::where('id', $folderId)->first();

            if (!$folder) {
                Log::warning('Attempt to delete non-existence folder in delete favorite endpoint.');
                return response()->json([
                    'errors' => 'Folder not found.'
                ], 404);
            }

            // Periksa apakah user memiliki folder favorit dengan ID yang diberikan
            $favoriteFolder = $user->favoriteFolders()->where('folder_id', $folderId)->first();

            if (is_null($favoriteFolder)) {
                return response()->json([
                    'errors' => 'Folder is not in favorites'
                ], 404);
            }

            DB::beginTransaction();

            // Hapus folder dari daftar favorit
            $user->favoriteFolders()->detach($folderId);

            // Reload folder setelah dihapus dari favorit untuk memuat kembali datanya
            $folder->load([
                'user:id,name,email',
                'tags:id,name',
                'instances:id,name,address',
                'userFolderPermissions',
            ]);

            $loadFavorite = $folder->favorite()->where('user_id', $user->id)->first();

            DB::commit();

            return response()->json([
                'message' => 'Folder removed from favorites successfully',
                'folder' => [
                    'id' => $favoriteFolder->id,
                    'name' => $favoriteFolder->name,
                    'public_path' => $favoriteFolder->public_path,
                    'total_size' => $this->calculateFolderSize($favoriteFolder),
                    'type' => $favoriteFolder->type,
                    'parent_id' => $favoriteFolder->parent_id ? $favoriteFolder->parentFolder->id : null,
                    'is_favorited' => !is_null($loadFavorite),  // Tentukan apakah folder masih favorit
                    'favorited_at' => $loadFavorite->pivot->created_at ?? null,
                    'user' => $favoriteFolder->user,
                    'tags' => $favoriteFolder->tags,
                    'instances' => $favoriteFolder->instances,
                    'shared_with' => $favoriteFolder->userFolderPermissions->map(function ($permission) {
                        return [
                            'id' => $permission->id,
                            'folder_id' => $permission->folder_id,
                            'permissions' => $permission->permissions,
                            'created_at' => $permission->created_at,
                            'user' => [
                                'id' => $permission->user->id,
                                'name' => $permission->user->name,
                                'email' => $permission->user->email,
                            ]
                        ];
                    })
                ]
            ], 200);
        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Error occurred while removing folder from favorites: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred while removing folder from favorites'
            ], 500);
        }
    }
}
