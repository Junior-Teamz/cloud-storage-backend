<?php

namespace App\Http\Controllers;

use App\Models\Folder;
use App\Models\User;
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

    public function __construct(CheckFolderPermissionService $checkPermissionFolderService)
    {
        // Simpan service ke dalam property
        $this->checkPermissionFolderService = $checkPermissionFolderService;
    }

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
                'error' => 'An error occurred while counting favorite folders.'
            ], 500);
        }
    }

    public function getAllFavoriteFolders(Request $request)
    {
        $userLogin = Auth::user();

        try {
            // Ambil user yang sedang login
            $user = User::find($userLogin->id);

            // Ambil parameter pencarian dan pagination dari request
            $search = $request->input('search');
            $instanceId = $request->input('instance_id');
            $perPage = $request->input('per_page', 10); // Default 10 items per page

            // Query folder favorit user dengan pivot data
            $favoriteFoldersQuery = $user->favoriteFolders()->with(['user:id,name,email', 'files', 'tags:id,name', 'instances:id,name,address', 'userPermissions',]);

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

            // Lakukan pagination pada hasil query
            $favoriteFolders = $favoriteFoldersQuery->paginate($perPage);

            // Modifikasi respons untuk menambahkan waktu ditambahkan ke favorit
            $favoriteFolders->getCollection()->transform(function ($folder) use ($user) {
                $favorite = $folder->favorite()->where('user_id', $user->id)->first();
                $isFavorite = !is_null($favorite);
                $favoritedAt = $isFavorite ? $favorite->pivot->created_at : null;

                $folder['is_favorite'] = $isFavorite; // Otomatis menjadi true karena folder yang diambil adalah folder yang di favoritkan.
                $folder['favorited_at'] = $favoritedAt;
                $checkPermission = $this->checkPermissionFolderService->checkPermissionFolder($folder->id, 'read');
                if ($checkPermission) {
                    $folder['shared_with'] = $folder->userPermissions;
                }
                return $folder;
            });

            $favoriteFolders->makeHidden(['nanoid', 'user_id', 'pivot', 'userPermissions']);

            return response()->json([
                'favorite_folders' => $favoriteFolders
            ], 200);
        } catch (Exception $e) {
            Log::error('Error occurred while fetching favorite folders: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred while fetching favorite folders'
            ], 500);
        }
    }

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
            $folder = Folder::with(['user:id,name,email', 'tags:id,name', 'instances:id,name,address', 'favorite' => function ($query) use ($userLogin) {
                $query->where('user_id', $userLogin->id);
            }])->findOrFail($request->folder_id);

            // Cek apakah user memiliki izin untuk memfavoritkan folder
            $checkPermission = $this->checkPermissionFolderService->checkPermissionFolder($folder->id, ['write']);

            if (!$checkPermission) {
                return response()->json([
                    'errors' => 'You do not have permission to favorite any of the folders.'
                ], 403);
            }

            // Cek apakah folder sudah ada di daftar favorit pengguna
            $existingFavorite = $folder->favorite->isNotEmpty();

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
                        'is_favorited' => $existingFavorite ? true : false,
                        'favorited_at' => $folder->favorite->first()->pivot->created_at,
                        'user' => $folder->user,
                        'tags' => $folder->tags,
                        'instances' => $folder->instances,
                    ]
                ], 409);
            }

            DB::beginTransaction();

            // Tambahkan folder ke favorit user
            $user->favoriteFolders()->attach($folder->id);

            DB::commit();

            // Ambil ulang folder setelah ditambahkan ke favorit
            $folder->load(['favorite' => function ($query) use ($userLogin) {
                $query->where('user_id', $userLogin->id);
            }]);

            // Setelah berhasil ditambahkan ke favorit, kirimkan respon dengan data lengkap folder
            return response()->json([
                'message' => 'Folder berhasil ditambahkan ke favorit.',
                'folder' => [
                    'id' => $folder->id,
                    'name' => $folder->name,
                    'public_path' => $folder->public_path,
                    'user' => $folder->user,
                    'tags' => $folder->tags,
                    'instances' => $folder->instances,
                    'is_favorite' => true,
                    'favorited_at' => $folder->favorite->first()->pivot->created_at // Mengambil dari pivot table setelah insert
                ]
            ], 200);
        } catch (Exception $e) {
            // Jika terjadi error, rollback transaksi
            DB::rollBack();

            Log::error('Error occured while adding folder to favorite: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'error' => 'Terjadi kesalahan saat menambahkan favorit.'
            ], 500);
        }
    }

    public function deleteFavoriteFolder($folderId)
    {
        $userLogin = Auth::user();

        try {
            // Ambil data user
            $user = User::with('favoriteFolders')->find($userLogin->id);

            // Periksa apakah user memiliki folder favorit dengan ID yang diberikan
            $favoriteFolder = $user->favoriteFolders()->where('folder_id', $folderId)->first();

            if (!$favoriteFolder) {
                return response()->json([
                    'errors' => 'Folder is not in favorites'
                ], 404);
            }

            DB::beginTransaction();

            // Hapus folder dari daftar favorit
            $user->favoriteFolders()->detach($folderId);

            DB::commit();

            return response()->json([
                'message' => 'Folder removed from favorites successfully'
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
