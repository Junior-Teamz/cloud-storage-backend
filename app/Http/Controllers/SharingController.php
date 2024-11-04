<?php

namespace App\Http\Controllers;

use App\Models\File;
use Exception;
use App\Models\Folder;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\UserFolderPermission;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use App\Services\CheckFolderPermissionService;
use App\Services\GenerateURLService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

class SharingController extends Controller
{
    protected $checkPermissionFolderService;
    protected $GenerateURLService;

    public function __construct(CheckFolderPermissionService $checkPermissionFolderService, GenerateURLService $GenerateURLService)
    {
        // Simpan service ke dalam property
        $this->checkPermissionFolderService = $checkPermissionFolderService;
        $this->GenerateURLService = $GenerateURLService;
    }

    /**
     * Get a list of users who have access to a shared folder.
     *
     * This method retrieves a list of users who have been granted access to a specific folder.
     * It first checks if the authenticated user has read permission for the folder.
     * If the user has permission, it retrieves the list of users who have access, including their ID, name, and email.
     * If the folder is not shared with anyone, it returns a message indicating that.
     *
     * @param string $id The ID of the folder.
     * @return \Illuminate\Http\JsonResponse A JSON response containing the list of shared users or an error message.
     */
    public function getListUserSharedFolder($id)
    {
        $user = Auth::user();

        // periksa apakah user memiliki izin read.
        $permission = $this->checkPermissionFolderService->checkPermissionFolder($id, ['read']);

        if (!$permission) {
            return response()->json([
                'errors' => 'You do not have permission to access this folder shared users.',
            ], 403);
        }

        try {
            // Ambil folder beserta userFolderPermissions dan user yang terkait
            $folder = Folder::where('id', $id)->first();

            // Cek apakah folder dimiliki oleh user yang sedang login
            if ($folder->user_id !== $user->id) {
                return response()->json([
                    'errors' => 'You do not have permission to view this folder.'
                ], 403);
            }

            // Ambil daftar user (id, name, email) yang memiliki akses ke folder
            $sharedUsers = UserFolderPermission::where('folder_id', $folder->id)
                ->with(['user:id,name,email', 'user.instances:id,name,address']) // Hanya memuat kolom yang dibutuhkan
                ->get()
                ->map(function ($permission) {
                    return [
                        'id' => $permission->id,
                        'file_id' => $permission->file_id,
                        'permissions' => $permission->permissions,
                        'created_at' => $permission->created_at,
                        'user' => [
                            'id' => $permission->user->id,
                            'name' => $permission->user->name,
                            'email' => $permission->user->email,
                        ]
                    ];
                });

            if (!$sharedUsers) {
                return response()->json([
                    'message' => 'This folder is not shared to anyone else.'
                ]);
            }

            return response()->json([
                'shared_users' => $sharedUsers
            ], 200);
        } catch (Exception $e) {
            Log::error('Error occurred while getting list of users shared for folder: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred while getting list of users shared for folder.'
            ], 500);
        }
    }

    /**
     * Get a paginated list of shared folders and files for the authenticated user.
     *
     * This method retrieves a list of folders and files that have been shared with the currently authenticated user.
     * It supports pagination for both folders and files separately, allowing users to navigate through the results.
     * The method also allows filtering the results by instance name.
     *
     * @param  \Illuminate\Http\Request  $request The incoming request containing pagination parameters and optional filter for instance name.
     * @return \Illuminate\Http\JsonResponse A JSON response containing the paginated list of shared folders and files, along with pagination information.
     */
    public function getSharedFolderAndFile(Request $request)
    {
        $user = Auth::user();

        // Ambil jumlah item per halaman secara universal
        $perPage = $request->get('per_page', 10);

        // Ambil halaman untuk folder dan file dari query, default ke halaman 1 jika tidak ada
        $folderPage = $request->get('folder_page', 1);
        $filePage = $request->get('file_page', 1);

        // Ambil filter instansi jika ada
        $instanceNameFilter = $request->get('instance_name');

        try {
            // Query untuk mengambil semua folder yang dibagikan ke user
            $sharedFoldersQuery = Folder::whereHas('userFolderPermissions', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })->with(['user:id,name,email', 'tags:id,name', 'instances:id,name,address', 'favorite', 'subfolders']);

            // Jika ada filter berdasarkan nama instansi, tambahkan ke query
            if ($instanceNameFilter) {
                $sharedFoldersQuery->whereHas('instances', function ($query) use ($instanceNameFilter) {
                    $query->where('name', 'like', '%' . $instanceNameFilter . '%');
                });
            }

            // Ambil folder setelah filtering instansi
            $sharedFolders = $sharedFoldersQuery->get();

            // Filter folder induk jika subfolder-nya juga dibagikan
            $filteredFolders = $sharedFolders->filter(function ($folder) use ($sharedFolders) {
                $hasSharedSubfolders = $folder->subfolders->contains(function ($subfolder) use ($sharedFolders) {
                    return $sharedFolders->contains('id', $subfolder->id);
                });
                return !$hasSharedSubfolders;
            });

            // Pagination untuk folder setelah filtering
            $paginatedFolders = new LengthAwarePaginator(
                $filteredFolders->forPage($folderPage, $perPage),
                $filteredFolders->count(),
                $perPage,
                $folderPage,
                ['path' => Paginator::resolveCurrentPath()]
            );

            // Query untuk mengambil file yang dibagikan ke user dengan pagination
            $sharedFilesQuery = File::whereHas('userPermissions', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })->with(['user:id,name,email', 'tags:id,name', 'instances:id,name,address']);

            if ($instanceNameFilter) {
                $sharedFilesQuery->whereHas('instances', function ($query) use ($instanceNameFilter) {
                    $query->where('name', 'like', '%' . $instanceNameFilter . '%');
                });
            }

            // Ambil file setelah filtering instansi
            $sharedFiles = $sharedFilesQuery->paginate($perPage, ['*'], 'file_page', $filePage);

            // Format response untuk folder
            $formattedFolders = $paginatedFolders->map(function ($folder) use ($user) {
                $favorite = $folder->favorite->where('user_id', $user->id)->first();
                $isFavorite = !is_null($favorite);
                $favoritedAt = $isFavorite ? $favorite->pivot->created_at : null;

                return [
                    'id' => $folder->id,
                    'name' => $folder->name,
                    'public_path' => $folder->public_path,
                    'type' => $folder->type,
                    'user' => $folder->user,
                    'is_favorite' => $isFavorite,
                    'favorited_at' => $favoritedAt,
                    'created_at' => $folder->created_at,
                    'updated_at' => $folder->updated_at,
                    'tags' => $folder->tags,
                    'instances' => $folder->instances,
                    'shared_with' => $folder->userFolderPermissions->map(function ($permission) {
                        return [
                            'id' => $permission->id,
                            'file_id' => $permission->file_id,
                            'permissions' => $permission->permissions,
                            'created_at' => $permission->created_at,
                            'user' => [
                                'id' => $permission->user->id,
                                'name' => $permission->user->name,
                                'email' => $permission->user->email,
                            ]
                        ];
                    })
                ];
            })->values(); // Tambahkan ->values() di sini untuk menghilangkan indeks numerik

            // Format response untuk file
            $formattedFiles = $sharedFiles->map(function ($file) {
                $fileData = [
                    'id' => $file->id,
                    'name' => $file->name,
                    'public_path' => $file->public_path,
                    'size' => $file->size,
                    'type' => $file->type,
                    'user' => $file->user,
                    'created_at' => $file->created_at,
                    'updated_at' => $file->updated_at,
                    'tags' => $file->tags,
                    'instances' => $file->instances,
                    'shared_with' => $file->userPermissions->map(function ($permission) {
                        return [
                            'id' => $permission->id,
                            'file_id' => $permission->file_id,
                            'permissions' => $permission->permissions,
                            'created_at' => $permission->created_at,
                            'user' => [
                                'id' => $permission->user->id,
                                'name' => $permission->user->name,
                                'email' => $permission->user->email,
                            ]
                        ];
                    })
                ];

                // Jika file adalah gambar, tambahkan URL gambar
                $mimeType = Storage::mimeType($file->path);
                if (Str::startsWith($mimeType, 'video')) {
                    $fileData['video_url'] = $this->GenerateURLService->generateUrlForVideo($file->id);
                }

                return $fileData;
            })->values(); // Tambahkan ->values() di sini untuk menghilangkan indeks numerik

            // Return response dengan folder dan file terpisah
            return response()->json([
                'folders' => [
                    'data' => $formattedFolders,
                    'pagination' => [
                        'current_page' => $paginatedFolders->currentPage(),
                        'last_page' => $paginatedFolders->lastPage(),
                        'per_page' => $paginatedFolders->perPage(),
                        'total' => $paginatedFolders->total(),
                    ]
                ],
                'files' => [
                    'data' => $formattedFiles,
                    'pagination' => [
                        'current_page' => $sharedFiles->currentPage(),
                        'last_page' => $sharedFiles->lastPage(),
                        'per_page' => $sharedFiles->perPage(),
                        'total' => $sharedFiles->total(),
                    ]
                ],
            ], 200);
        } catch (Exception $e) {
            Log::error('Error occurred on getting shared folders and files: ' . $e->getMessage(), [
                'userId' => $user->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'errors' => 'An error occurred while getting shared folders and files.',
            ], 500);
        }
    }

    /**
     * Generate a shareable link for a folder.
     *
     * This method generates a shareable link for a given folder ID. It retrieves the frontend URL from the configuration,
     * prefixes the folder ID with 'F' to indicate it's a folder link, base64 encodes the prefixed ID, and constructs the
     * shareable link using the frontend URL and the encoded ID.
     *
     * @param int $folderId The ID of the folder to generate a shareable link for.
     * @return string The generated shareable link for the folder.
     */
    public function generateShareableFolderLink($folderId)
    {
        // Ambil URL frontend dari konfigurasi
        $getFrontendUrl = config('frontend.url');

        $frontendUrl = $getFrontendUrl[0];

        // Prefix FOLDER diawal untuk menandakan URL adalah shared link untuk Folder.
        $build = 'F' . $folderId;

        $hashedBuild = base64_encode($build);

        // Format URL: {frontend_url}/share/{folderId}
        return "{$frontendUrl}/share/{$hashedBuild}";
    }

    /**
     * Generate a shareable link for a file.
     *
     * This method generates a shareable link for a given file ID. It retrieves the frontend URL from the configuration,
     * prefixes the file ID with 'L' to indicate it's a file link, base64 encodes the prefixed ID, and constructs the
     * shareable link using the frontend URL and the encoded ID.
     *
     * @param int $fileId The ID of the file to generate a shareable link for.
     * @return string The generated shareable link for the file.
     */
    public function generateShareableFileLink($fileId)
    {
        // Ambil URL frontend dari konfigurasi
        $getFrontendUrl = config('frontend.url');

        $frontendUrl = $getFrontendUrl[0];

        // Prefix FILE diawal untuk menandakan URL adalah shared link untuk File.
        $build = 'L' . $fileId;

        $hashedBuild = base64_encode($build);

        // Format URL: {frontend_url}/share/{folderId}
        return "{$frontendUrl}/share/{$hashedBuild}";
    }
}
