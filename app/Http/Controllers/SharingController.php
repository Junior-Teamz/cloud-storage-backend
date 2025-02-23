<?php

namespace App\Http\Controllers;

use App\Http\Resources\File\FileCollection;
use App\Http\Resources\Folder\FolderCollection;
use App\Models\File;
use Exception;
use App\Models\Folder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\UserFolderPermission;
use Illuminate\Support\Facades\Auth;
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
            })->with(['user', 'user.instances', 'tags', 'instances', 'userFolderPermissions', 'userFolderPermissions.user', 'userFolderPermissions.user.instances', 'favorite', 'subfolders']);

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
            })->with(['user', 'user.instances', 'tags', 'instances', 'userPermissions', 'userPermissions.user', 'userPermissions.user.instances', 'favorite']);

            if ($instanceNameFilter) {
                $sharedFilesQuery->whereHas('instances', function ($query) use ($instanceNameFilter) {
                    $query->where('name', 'like', '%' . $instanceNameFilter . '%');
                });
            }

            // Ambil file setelah filtering instansi
            $sharedFiles = $sharedFilesQuery->paginate($perPage, ['*'], 'file_page', $filePage);

            // Return response dengan folder dan file terpisah
            return response()->json([
                'data' => [
                    'folders' => new FolderCollection($paginatedFolders),
                    'files' => new FileCollection($sharedFiles)
                ],
                'pagination' => [
                    'folders' => [
                        'current_page' => $paginatedFolders->currentPage(),
                        'last_page' => $paginatedFolders->lastPage(),
                        'per_page' => $paginatedFolders->perPage(),
                        'total' => $paginatedFolders->total(),
                    ],
                    'files' => [
                        'current_page' => $sharedFiles->currentPage(),
                        'last_page' => $sharedFiles->lastPage(),
                        'per_page' => $sharedFiles->perPage(),
                        'total' => $sharedFiles->total(),
                    ]
                ]
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
     * This method generates a shareable link for a given folder UUID. It retrieves the frontend URL from the configuration,
     * prefixes the folder UUID with 'F' to indicate it's a folder link, base64 encodes the prefixed UUID, and constructs the
     * shareable link using the frontend URL and the encoded UUID.
     *
     * @param string $folderId The UUID of the folder to generate a shareable link for.
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
     * This method generates a shareable link for a given file UUID. It retrieves the frontend URL from the configuration,
     * prefixes the file UUID with 'L' to indicate it's a file link, base64 encodes the prefixed UUID, and constructs the
     * shareable link using the frontend URL and the encoded UUID.
     *
     * @param string $fileId The UUID of the file to generate a shareable link for.
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
