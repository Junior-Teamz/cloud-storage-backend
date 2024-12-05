<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\User;
use App\Services\CheckFilePermissionService;
use App\Services\GenerateURLService;
use Exception;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class FileFavoriteController extends Controller
{
    protected $checkPermissionFileService;
    protected $GenerateURLService;

    public function __construct(CheckFilePermissionService $checkPermissionFileService, GenerateURLService $GenerateURLService)
    {
        // Simpan service ke dalam property
        $this->checkPermissionFileService = $checkPermissionFileService;
        $this->GenerateURLService = $GenerateURLService;
    }

    /**
     * Count all favorite files for the authenticated user.
     *
     * This method retrieves the count of all favorite files, 
     * differentiating between files owned by the user and files shared with them.
     *
     * @return \Illuminate\Http\JsonResponse A JSON response containing the counts of favorite files.
     */
    public function countAllFavoriteFiles()
    {
        $userLogin = Auth::user();

        try {
            $userInfo = User::find($userLogin->id);
            // Hitung total file yang difavoritkan oleh user saat ini
            $totalFavoriteFiles = $userInfo->favoriteFiles()->count();

            // Hitung file milik sendiri yang difavoritkan oleh user
            $ownFavoriteFiles = $userInfo->favoriteFiles()
                ->where('user_id', $userInfo->id)
                ->count();

            // Hitung file yang dibagikan (bukan milik user), namun difavoritkan oleh user
            $sharedFavoriteFiles = $userInfo->favoriteFiles()
                ->where('user_id', '!=', $userInfo->id)
                ->count();

            return response()->json([
                'total_favorite_files' => $totalFavoriteFiles,
                'own_favorite_files' => $ownFavoriteFiles,
                'shared_favorite_files' => $sharedFavoriteFiles,
            ], 200);
        } catch (Exception $e) {
            Log::error('Error counting favorite files: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred while counting favorite files.'
            ], 500);
        }
    }

    /**
     * Retrieve all favorite files for the authenticated user.
     *
     * This method retrieves a paginated list of all favorite files for the currently authenticated user.
     * It allows filtering by file name and instance name, and supports pagination.  The response includes
     * additional data such as whether the file is a video, a video URL (if applicable), and a list of users
     * the file has been shared with.
     *
     * @param  \Illuminate\Http\Request  $request The incoming request containing search parameters and pagination options.
     * @return \Illuminate\Http\JsonResponse A JSON response containing the paginated list of favorite files.
     */
    public function getAllFavoriteFile(Request $request)
    {
        $userLogin = Auth::user();

        try {
            // Ambil user yang sedang login
            $user = User::find($userLogin->id);

            // Ambil parameter pencarian dan pagination dari request
            $search = $request->input('search');
            $instanceName = $request->input('instance');
            $perPage = $request->input('per_page', 10); // Default 10 items per page

            // Query file favorit user dengan pivot data
            $favoriteFilesQuery = $user->favoriteFiles()->with(['user:id,name,email,photo_profile_url', 'folder:id', 'tags:id,name', 'instances:id,name,address', 'userPermissions.user:id,name,email,photo_profile_url',]);

            // Filter berdasarkan nama file jika ada parameter 'search'
            if ($search) {
                $favoriteFilesQuery->where('name', 'LIKE', "%{$search}%");
            }

            // Filter berdasarkan instansi jika ada parameter 'instance_id'
            if ($instanceName) {
                $favoriteFilesQuery->whereHas('instances', function ($query) use ($instanceName) {
                    $query->where('name', 'LIKE', "%{$instanceName}%");
                });
            }

            // Lakukan pagination pada hasil query
            $favoriteFiles = $favoriteFilesQuery->paginate($perPage);

            $favoriteFiles->getCollection()->transform(function ($file) use ($user) {
                $favorite = $file->favorite()->where('user_id', $user->id)->first();
                $isFavorite = !is_null($favorite);
                $favoritedAt = $isFavorite ? $favorite->pivot->created_at : null;

                $file['is_favorite'] = $isFavorite; // Otomatis menjadi true karena file yang diambil adalah file yang di favoritkan.
                $file['favorited_at'] = $favoritedAt;

                if (Str::startsWith(Storage::mimeType($file->path), 'video')) {
                    $file['video_url'] = $this->GenerateURLService->generateUrlForVideo($file->id); // URL Streaming
                }

                $file['shared_with'] = $file->userPermissions->map(function ($permission) {
                    return [
                        'id' => $permission->id,
                        'file_id' => $permission->file_id,
                        'permissions' => $permission->permissions,
                        'created_at' => $permission->created_at,
                        'user' => [
                            'id' => $permission->user->id,
                            'name' => $permission->user->name,
                            'email' => $permission->user->email,
                            'photo_profile_url' => $permission->user->photo_profile_url,
                        ]
                    ];
                });

                return $file;
            });

            // Sembunyikan kolom 'path' dan 'nanoid'
            $favoriteFiles->makeHidden(['path', 'nanoid', 'user_id', 'pivot', 'folder', 'userPermissions']);

            return response()->json([
                'favorite_files' => $favoriteFiles
            ], 200);
        } catch (Exception $e) {
            Log::error('Error occurred while fetching favorite files: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred while fetching favorite files'
            ], 500);
        }
    }

    /**
     * Add a file to the authenticated user's favorites.
     *
     * This method adds a file to the current user's favorite list.  It first validates the request,
     * checks if the user has permission to favorite the file, and then checks if the file is already
     * in the favorites list.
     *
     * @param  \Illuminate\Http\Request  $request The incoming request containing the file UUID.
     * @return \Illuminate\Http\JsonResponse A JSON response indicating success or failure.
     */
    public function addNewFavorite(Request $request)
    {
        $userLogin = Auth::user();

        // Validasi request
        $validator = Validator::make($request->all(), [
            'file_id' => 'required|exists:files,id',
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

            // Ambil file yang akan difavoritkan beserta relasi tags, instances, dan favorite status
            $file = File::with(['user:id,name,email,photo_profile_url', 'folder:id', 'tags:id,name', 'instances:id,name,address', 'userPermissions.user:id,name,email,photo_profile_url'])->where('id', $request->file_id)->first();

            // Cek apakah user memiliki izin untuk memfavoritkan file
            $checkPermission = $this->checkPermissionFileService->checkPermissionFile($file->id, ['read', 'write']);

            if (!$checkPermission) {
                return response()->json([
                    'errors' => 'You do not have permission to favorite the file.'
                ], 403);
            }

            // Cek apakah file sudah ada di daftar favorit pengguna
            $existingFavorite = $file->favorite()->where('user_id', $user->id)->first();

            if ($existingFavorite) {
                // Cek apakah mime type file adalah video
                $isVideo = Str::startsWith(Storage::mimeType($file->path), 'video');

                // Buat array response file tanpa video_url
                $fileResponse = [
                    'id' => $file->id,
                    'name' => $file->name,
                    'public_path' => $file->public_path,
                    'size' => $file->size,
                    'type' => $file->type,
                    'created_at' => $file->created_at,
                    'updated_at' => $file->updated_at,
                    'folder_id' => $file->folder->id,
                    'file_url' => $file->file_url,
                    'is_favorited' => !is_null($existingFavorite),
                    'favorited_at' => $existingFavorite->pivot->created_at ?? null,
                    'user' => $file->user, // User sudah diambil dengan select
                    'tags' => $file->tags, // Tags sudah diambil dengan select
                    'instances' => $file->instances, // Instances sudah diambil dengan select
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
                                'photo_profile_url' => $permission->user->photo_profile_url,
                            ]
                        ];
                    })
                ];

                // Jika mime type adalah video, tambahkan video_url ke response
                if ($isVideo) {
                    $fileResponse['video_url'] = $this->GenerateURLService->generateUrlForVideo($file->id); // URL Streaming
                }

                // Kembalikan response
                return response()->json([
                    'message' => 'File sudah ada di daftar favorit.',
                    'file' => $fileResponse
                ], 409);
            }

            DB::beginTransaction();

            // Tambahkan file ke favorit user
            $user->favoriteFiles()->attach($file->id);

            DB::commit();

            // Ambil ulang file setelah ditambahkan ke favorit
            $file->load(['user:id,name,email,photo_profile_url', 'folder:id', 'tags:id,name', 'instances:id,name,address', 'userPermissions.user:id,name,email,photo_profile_url']);

            $favorite = $file->favorite()->where('user_id', $user->id)->first();
            $isFavorite = !is_null($favorite);
            $favoritedAt = $isFavorite ? $favorite->pivot->created_at : null;

            $file['is_favorite'] = $isFavorite;
            $file['favorited_at'] = $favoritedAt;

            if (Str::startsWith(Storage::mimeType($file->path), 'video')) {
                $file['video_url'] = $this->GenerateURLService->generateUrlForVideo($file->id); // URL Streaming
            }

            $file['shared_with'] = $file->userPermissions->map(function ($permission) {
                return [
                    'id' => $permission->id,
                    'file_id' => $permission->file_id,
                    'permissions' => $permission->permissions,
                    'created_at' => $permission->created_at,
                    'user' => [
                        'id' => $permission->user->id,
                        'name' => $permission->user->name,
                        'email' => $permission->user->email,
                        'photo_profile_url' => $permission->user->photo_profile_url,
                    ]
                ];
            });

            // Sembunyikan kolom 'path' dan 'nanoid'
            $file->makeHidden(['path', 'nanoid', 'user_id', 'folder', 'userPermissions']);

            // Setelah berhasil ditambahkan ke favorit, kirimkan respon dengan data lengkap file
            return response()->json([
                'message' => 'File berhasil ditambahkan ke favorit.',
                'file' => $file
            ], 200);
        } catch (Exception $e) {
            // Jika terjadi error, rollback transaksi
            DB::rollBack();

            Log::error('Error occured while adding file to favorite: ' . $e->getMessage(), [
                'file_id' => $request->file_id,
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occured while adding file to favorite.'
            ], 500);
        }
    }

    /**
     * Remove a file from the authenticated user's favorites.
     *
     * This method removes a file from the current user's favorite list. It first checks if the file exists
     * and if the user has the file in their favorites.  If successful, it returns the updated file
     * information.
     * 
     * @param string $fileId The UUID of the file to remove from favorites.
     * @return \Illuminate\Http\JsonResponse A JSON response indicating success or failure.
     */
    public function deleteFavoriteFile($fileId)
    {
        $userLogin = Auth::user();

        try {
            // Ambil data user
            $user = User::with('favoriteFiles')->find($userLogin->id);

            $file = File::where('id', $fileId)->first();

            if (!$file) {
                Log::warning('Attempt to delete non-existence file delete favorite endpoint.');
                return response()->json([
                    'errors' => 'File not found.'
                ], 404);
            }

            // Periksa apakah user memiliki file favorit dengan ID yang diberikan
            $favoriteFile = $user->favoriteFiles()->where('file_id', $file->id)->first();

            if (is_null($favoriteFile)) {
                return response()->json([
                    'errors' => 'File is not in favorites'
                ], 404);
            }

            DB::beginTransaction();

            // Hapus file dari daftar favorit
            $user->favoriteFiles()->detach($file->id);

            DB::commit();

            $file->load(['user:id,name,email,photo_profile_url', 'folder:id', 'tags:id,name', 'instances:id,name,address', 'userPermissions.user:id,name,email,photo_profile_url']);

            $favorite = $file->favorite()->where('user_id', $user->id)->first();
            $isFavorite = !is_null($favorite);
            $favoritedAt = $isFavorite ? $favorite->pivot->created_at : null;

            $file['is_favorite'] = $isFavorite;
            $file['favorited_at'] = $favoritedAt;

            if (Str::startsWith(Storage::mimeType($file->path), 'video')) {
                $file['video_url'] = $this->GenerateURLService->generateUrlForVideo($file->id); // URL Streaming
            }

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
                        'photo_profile_url' => $permission->user->photo_profile_url,
                    ]
                ];
            });

            // Sembunyikan kolom 'path' dan 'nanoid'
            $file->makeHidden(['path', 'nanoid', 'user_id', 'folder', 'userPermissions']);

            return response()->json([
                'message' => 'Successfully removed file from favorite.',
                'file' => $file
            ], 200);
        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Error occurred while removing file from favorites: ' . $e->getMessage(), [
                'fileId' => $fileId,
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred while removing file from favorites'
            ], 500);
        }
    }
}