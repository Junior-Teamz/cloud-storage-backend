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
                        'id' => $permission->user->id,
                        'name' => $permission->user->name,
                        'email' => $permission->user->email,
                        'instances' => $permission->user->instances
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
            Log::error('Error occurred while getting list of users shared for folder: ' . $e->getMessage());

            return response()->json([
                'errors' => 'An error occurred while getting list of users shared for folder.'
            ], 500);
        }
    }

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
            // Ambil semua folder yang dibagikan kepada user login
            $sharedFoldersQuery = Folder::whereHas('userFolderPermissions', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })
                ->with(['user:id,name,email', 'tags:id,name', 'instances:id,name,address', 'favorite', 'subfolders']);

            // Jika ada filter berdasarkan nama instansi, tambahkan ke query
            if ($instanceNameFilter) {
                $sharedFoldersQuery->whereHas('instances', function ($query) use ($instanceNameFilter) {
                    $query->where('name', 'like', '%' . $instanceNameFilter . '%');
                });
            }

            // Ambil folder setelah filtering instansi
            $sharedFolders = $sharedFoldersQuery->get();

            // Filter folder induk yang dibagikan jika subfolder-nya juga dibagikan
            $filteredFolders = $sharedFolders->filter(function ($folder) use ($sharedFolders) {
                // Cek apakah subfolder dari folder ini dibagikan
                $hasSharedSubfolders = $folder->subfolders->contains(function ($subfolder) use ($sharedFolders) {
                    return $sharedFolders->contains('id', $subfolder->id);
                });

                // Jika ada subfolder yang dibagikan, jangan sertakan folder induk
                return !$hasSharedSubfolders;
            });

            // Lakukan pagination setelah filtering
            $paginatedFolders = new LengthAwarePaginator(
                $filteredFolders->forPage($folderPage, $perPage),
                $filteredFolders->count(),
                $perPage,
                $folderPage,
                ['path' => Paginator::resolveCurrentPath()]
            );

            // Ambil semua file yang dibagikan kepada user login dengan pagination
            $sharedFilesQuery = File::whereHas('userPermissions', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })
                ->with(['user:id,name,email', 'tags:id,name', 'instances:id,name,address']);

            // Jika ada filter berdasarkan nama instansi, tambahkan ke query untuk file juga
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
                    'instances' => $folder->instances
                ];
            });

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
                    'instances' => $file->instances
                ];

                // Jika file adalah gambar, tambahkan URL gambar
                $mimeType = Storage::mimeType($file->path);
                if (Str::startsWith($mimeType, 'image')) {
                    $fileData['image_url'] = $this->GenerateURLService->generateUrlForImage($file->id);
                }

                return $fileData;
            });

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
     * Generate shareable hashed URL for folder.
     *
     * @return string
     */
    public function generateShareableFolderLink($folderId)
    {
        // Ambil URL frontend dari konfigurasi
        $frontendUrl = config('frontend.url_for_share', 'http://localhost:3000');

        // Format URL: {frontend_url}/share/{folderId}
        return "{$frontendUrl}/share/{$folderId}";
    }

    /**
     * Generate shareable hashed URL for file.
     *
     * @return string
     */
    public function generateShareableFileLink($fileId)
    {
        // Ambil URL frontend dari konfigurasi
        $frontendUrl = config('frontend.url_for_share', 'http://localhost:3000');

        // Format URL: {frontend_url}/share/{folderId}
        return "{$frontendUrl}/share/{$fileId}";
    }
}
