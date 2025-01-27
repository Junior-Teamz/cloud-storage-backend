<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\File;
use App\Models\Folder;
use App\Models\Instance;
use App\Models\Tags;
use App\Services\CheckAdminService;
use App\Services\GetPathService;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;

class AppStatisticController extends Controller
{
    protected $checkAdminService;
    protected $getPathService;

    public function __construct(CheckAdminService $checkAdminService, GetPathService $getPathServiceParam)
    {
        $this->checkAdminService = $checkAdminService;
        $this->getPathService = $getPathServiceParam;
    }

    /**
     * Get total storage usage.
     *
     * This function calculates the total storage used across all folders and files in the system.
     * 
     * Requires super admin authentication.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function storageUsage()
    {
        $userInfo = Auth::user();

        $checkSuperAdmin = $this->checkAdminService->checkSuperAdmin();

        if (!$checkSuperAdmin) {
            Log::warning('Attempt to access features that can only be accessed by super admins', [
                'user_id' => $userInfo->id,
                'user_role' => $userInfo->roles->pluck('name'),
            ]);
            return response()->json([
                'errors' => 'You cannot perform this action.'
            ], 403);
        }

        try {
            // Dapatkan semua folder
            $allFolders = Folder::whereNull('parent_id')->get();

            if (!$allFolders) {
                return response()->json([
                    'message' => 'No folder was created.'
                ], 200);
            }

            // Hitung total penyimpanan
            $totalUsedStorage = $this->calculateFolderSize($allFolders);

            // Format ukuran penyimpanan ke dalam KB, MB, atau GB
            $formattedStorageSize = $this->formatSizeUnits($totalUsedStorage);

            return response()->json([
                'message' => 'Storage Usage Total: ' . $formattedStorageSize,
                'data' => [
                    'rawSize' => $totalUsedStorage,
                    'formattedSize' => $formattedStorageSize
                ]
            ]);
        } catch (Exception $e) {
            Log::error('Error occured while retrieving storage usage total: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occured while retrieving storage usage total.'
            ], 500);
        }
    }

    /**
     * Get storage usage per instance.
     *
     * This endpoint calculates the storage usage for each instance in the system. It retrieves all instances along with their associated files and folders.
     * For each instance, it calculates the total storage used by its files, the total number of folders, and the total number of files.
     * The results are returned in a JSON response, with storage usage presented in both raw bytes and a human-readable format (KB, MB, GB).
     * 
     * Requires super admin authentication.
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function storageUsagePerInstance()
    {
        $userInfo = Auth::user();

        // Pastikan hanya super admin yang dapat mengakses fitur ini
        $checkSuperAdmin = $this->checkAdminService->checkSuperAdmin();

        if (!$checkSuperAdmin) {
            Log::warning('Attempt to access features that can only be accessed by super admins', [
                'user_id' => $userInfo->id,
                'user_role' => $userInfo->roles->pluck('name'),
            ]);
            return response()->json([
                'errors' => 'You cannot perform this action.'
            ], 403);
        }

        try {
            // Ambil semua instance beserta folder dan file-nya
            $instances = Instance::with(['files', 'folders'])->get();

            $storageUsagePerInstance = [];

            foreach ($instances as $instance) {
                // Hitung total penggunaan penyimpanan dari semua file yang terkait dengan instance ini
                $totalStorageUsage = $instance->files->sum('size');

                // Hitung jumlah folder dan file
                $totalFolders = $instance->folders->count();
                $totalFiles = $instance->files->count();

                $storageUsagePerInstance[] = [
                    'instance_id' => $instance->id,
                    'instance_name' => $instance->name,
                    'instance_address' => $instance->address,
                    'storage_usage_raw' => $totalStorageUsage, // dalam bytes
                    'storage_usage_formatted' => $this->formatSizeUnits($totalStorageUsage), // format sesuai ukuran (KB, MB, GB, dll.)
                    'total_folders' => $totalFolders,
                    'total_files' => $totalFiles,
                ];
            }

            return response()->json($storageUsagePerInstance, 200);
        } catch (Exception $e) {
            Log::error('Error occurred while calculating storage usage per instance: ' . $e->getMessage(), [
                'user_id' => $userInfo->id,
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred while calculating storage usage.'
            ], 500);
        }
    }


    /**
     * Get tag usage statistics.
     * 
     * This method retrieves tag usage statistics, including the number of times each tag is used in folders, files, and news.
     * It supports pagination and filtering by tag name.
     *
     * @param  \Illuminate\Http\Request  $request The incoming HTTP request containing optional query parameters.
     * @return \Illuminate\Http\JsonResponse A JSON response containing the paginated list of tags with usage statistics or an error message.
     */
    public function getTagUsageStatistics(Request $request)
    {
        $checkAdmin = $this->checkAdminService->checkSuperAdmin();

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        try {
            $name = $request->query('name');
            // Ambil jumlah item per halaman dari request, default ke 10 jika tidak ada
            $perPage = $request->query('per_page', 10);

            // Query untuk mendapatkan tag beserta statistik penggunaan di folder, file, dan news
            $tagsQuery = Tags::select('tags.*')
                ->selectRaw('
            (SELECT COUNT(*) FROM folder_has_tags WHERE folder_has_tags.tags_id = tags.id) as folder_usage_count,
            (SELECT COUNT(*) FROM file_has_tags WHERE file_has_tags.tags_id = tags.id) as file_usage_count,
            (SELECT COUNT(*) FROM news_has_tags WHERE news_has_tags.tags_id = tags.id) as news_usage_count,
            (
                (SELECT COUNT(*) FROM file_has_tags WHERE file_has_tags.tags_id = tags.id) +
                (SELECT COUNT(*) FROM folder_has_tags WHERE folder_has_tags.tags_id = tags.id) +
                (SELECT COUNT(*) FROM news_has_tags WHERE news_has_tags.tags_id = tags.id)
            ) as total_usage_count
        ')
                ->orderByDesc('total_usage_count'); // Urutkan berdasarkan total penggunaan

            // Jika query name diberikan, tambahkan kondisi pencarian berdasarkan nama
            if ($name) {
                $tagsQuery->where('tags.name', 'like', '%' . $name . '%');
            }

            // Paginasi hasil
            $tags = $tagsQuery->paginate($perPage);

            // Menampilkan hasil dalam format JSON
            return response()->json([
                'data' => $tags->items(), // Isi data tag
                'pagination' => [
                    'current_page' => $tags->currentPage(),
                    'per_page' => $tags->perPage(),
                    'total' => $tags->total(),
                    'last_page' => $tags->lastPage(),
                ]
            ], 200);
        } catch (Exception $e) {
            Log::error('Error occurred while fetching tag usage statistics: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred while fetching tag usage statistics.'
            ], 500);
        }
    }

    public function tagsUsedByInstance()
    {
        $userInfo = Auth::user();

        // Pastikan hanya super admin yang dapat mengakses fitur ini
        $checkSuperAdmin = $this->checkAdminService->checkSuperAdmin();

        if (!$checkSuperAdmin) {
            Log::warning('Attempt to access features that can only be accessed by super admins', [
                'user_id' => $userInfo->id,
                'user_role' => $userInfo->roles->pluck('name'),
            ]);
            return response()->json([
                'errors' => 'You cannot perform this action.'
            ], 403);
        }

        try {
            // Ambil semua tag beserta hubungannya
            $tags = Tags::with(['folders.instances', 'files.instances'])->get();

            $tagsUsedByInstance = [];

            foreach ($tags as $tag) {
                $instances = collect();

                // Mengumpulkan semua instansi yang menggunakan tag melalui folder
                foreach ($tag->folders as $folder) {
                    $instances = $instances->merge($folder->instances);
                }

                // Mengumpulkan semua instansi yang menggunakan tag melalui file
                foreach ($tag->files as $file) {
                    $instances = $instances->merge($file->instances);
                }

                // Hapus duplikasi instansi
                $instances = $instances->unique('id');

                // Format data untuk masing-masing instansi
                $tagsUsedByInstance[] = [
                    'tag_id' => $tag->id,
                    'tag_name' => $tag->name,
                    'instances' => $instances?->map(function ($instance) use ($tag) {
                        $folderUsage = $tag->folders()->whereHas('instances', function ($query) use ($instance) {
                            $query->where('instances.id', $instance->id); // Tambahkan prefix tabel
                        })->count();

                        $fileUsage = $tag->files()->whereHas('instances', function ($query) use ($instance) {
                            $query->where('instances.id', $instance->id); // Tambahkan prefix tabel
                        })->count();

                        return [
                            'instance_id' => $instance->id,
                            'instance_name' => $instance->name,
                            'instance_address' => $instance->address,
                            'tag_use_count' => $folderUsage + $fileUsage // gabungan total dari tag digunakan pada folder dan file per instansi. TODO: penggunaan tag pada file harus dihapus.
                        ];
                    }),
                ];
            }

            return response()->json($tagsUsedByInstance, 200);
        } catch (Exception $e) {
            Log::error('Error occurred while fetching tags used by instance: ' . $e->getMessage(), [
                'user_id' => $userInfo->id,
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred while fetching tag usage information.'
            ], 500);
        }
    }

    /**
     * Count all folders.
     *
     * This function counts the total number of folders in the system excluding root folders.
     * 
     * Requires super admin authentication.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function allFolderCount()
    {
        // variable ini hanya untuk mendapatkan user info yang mengakses endpoint ini.
        $userInfo = Auth::user();

        $checkSuperAdmin = $this->checkAdminService->checkSuperAdmin();

        if (!$checkSuperAdmin) {
            Log::warning('Attempt to access features that can only be accessed by super admins', [
                'user_id' => $userInfo->id,
                'user_role' => $userInfo->roles->pluck('name'),
            ]);
            return response()->json([
                'errors' => 'You cannot perform this action.'
            ], 403);
        }

        try {
            $countAllFolder = Folder::whereNotNull('parent_id')->count();
            $totalSizeAllFolders = $this->calculateTotalSizeAllFolders();
            $formattedTotalSizeAllFolders = $this->formatSizeUnits($totalSizeAllFolders);

            if ($countAllFolder == 0) {
                return response()->json([
                    'message' => 'No folders created.',
                    'data' => [
                        'count_folder' => $countAllFolder,
                        'total_size_all_folders' => $totalSizeAllFolders,
                        'formatted_total_size_all_folders' => $formattedTotalSizeAllFolders
                    ]
                ], 200);
            }

            return response()->json([
                'count_folder' => $countAllFolder,
                'total_size_all_folders' => $totalSizeAllFolders,
                'formatted_total_size_all_folders' => $formattedTotalSizeAllFolders
            ], 200);
        } catch (Exception $e) {
            Log::error('Error occured while counting all folder: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);
            return response()->json([
                'errors' => 'An error occured while fetching count all folder,'
            ], 500);
        }
    }

    /**
     * Count all files.
     *
     * This function counts the total number of files and calculates their total size.
     * 
     * Requires super admin authentication.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function allFileCount()
    {
        // variable ini hanya untuk mendapatkan user info yang mengakses endpoint ini.
        $userInfo = Auth::user();

        $checkSuperAdmin = $this->checkAdminService->checkSuperAdmin();

        if (!$checkSuperAdmin) {
            Log::warning('Attempt to access features that can only be accessed by super admins', [
                'user_id' => $userInfo->id,
                'user_role' => $userInfo->roles->pluck('name'),
            ]);
            return response()->json([
                'errors' => 'You cannot perform this action.'
            ], 403);
        }

        try {
            $getAllFile = File::get();

            $countFileTotal = $getAllFile->count();
            $countFileSize = $getAllFile->sum('size');
            $formattedCountFileSize = $this->formatSizeUnits($countFileSize);

            if ($countFileTotal == 0) {
                return response()->json([
                    'message' => 'No files.',
                    'data' => [
                        'count_file' => $countFileTotal,
                        'count_size_all_files' => $countFileSize,
                        'formatted_count_size_all_files' => $formattedCountFileSize
                    ]
                ], 200);
            }

            return response()->json([
                'count_all_files' => $countFileTotal,
                'count_size_all_files' => $countFileSize,
                'formatted_count_size_all_files' => $formattedCountFileSize
            ], 200);
        } catch (Exception $e) {
            Log::error('Error occured while counting all files: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);
            return response()->json([
                'errors' => 'An error occured while fetching count all files,'
            ], 500);
        }
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
     * Recursively calculate the total size of a folder and its subfolders.
     *
     * This function calculates the total size of a given folder by summing the sizes of its files and
     * recursively calling itself for any subfolders.
     *
     * @param Folder $folder The folder to calculate the size of.
     * @return int The total size of the folder and its subfolders in bytes.
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

    private function calculateTotalSizeAllFolders()
    {
        $totalSize = 0;

        $folders = Folder::all();
        foreach ($folders as $folder) {
            $totalSize += $folder->calculateTotalSize();
        }

        return $totalSize;
    }
}
