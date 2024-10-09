<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\User;
use App\Services\CheckFilePermissionService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class FileFavoriteController extends Controller
{
    protected $checkPermissionFileService;

    public function __construct(CheckFilePermissionService $checkPermissionFileService)
    {
        // Simpan service ke dalam property
        $this->checkPermissionFileService = $checkPermissionFileService;
    }

    private function checkPermissionFile2($fileId) {
        $user = Auth::user();
        $file = File::where('id', $fileId)->first();

        // If file not found, return 404 error and stop the process
        if (!$file) {
            return response()->json([
                'errors' => 'File with File ID you entered not found, Please check your File ID and try again.'
            ], 404); // Setting status code to 404 Not Found
        }

        // Step 1: Check if the file belongs to the logged-in user
        if ($file->user_id === $user->id) {
            return true; // The owner has all permissions
        }

        // Step 2: Check if user is admin with SUPERADMIN privilege
        if ($user->hasRole('admin') && $user->is_superadmin == 1) {
            return true;
        } else if ($user->hasRole('admin')) {
            return false; // Regular admin without SUPERADMIN privilege
        }
        
        return false;
    }

    // TODO: new function count all favorite files

    public function getAllFavoriteFile(Request $request)
    {
        $userLogin = Auth::user();

        try {
            // Ambil user yang sedang login
            $user = User::find($userLogin->id);

            if (!$user) {
                return response()->json([
                    'errors' => 'User not found'
                ], 404);
            }

            // Ambil parameter pencarian dan pagination dari request
            $search = $request->input('search');
            $instanceName = $request->input('instance');
            $perPage = $request->input('per_page', 10); // Default 10 items per page

            // Query file favorit user dengan pivot data
            $favoriteFilesQuery = $user->favoriteFiles()->with(['user:id,name,email', 'folder:id', 'tags:id,name', 'instances:id,name,address', 'userPermissions.user:id,name,email',]);

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
                $file['is_favorite'] = true; // Otomatis menjadi true karena file yang diambil adalah file yang di favoritkan.
                $file['favorited_at'] = $file->pivot->created_at;
                $checkPermission = $this->checkPermissionFile2($file->id, 'read');
                if($checkPermission){
                    $file['shared_with'] = $file->userPermissions->user;
                }
                return $file;
            });

            // Sembunyikan kolom 'path' dan 'nanoid'
            $favoriteFiles->makeHidden(['path', 'nanoid', 'user_id']);

            return response()->json([
                'favorite_files' => $favoriteFiles
            ], 200);
        } catch (Exception $e) {
            Log::error('Error occurred while fetching favorite files: ' . $e->getMessage());

            return response()->json([
                'errors' => 'An error occurred while fetching favorite files'
            ], 500);
        }
    }

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

            if (!$user) {
                Log::error('Error occured: user not found: ' . $userLogin->id);

                return response()->json([
                    'errors' => 'Internal error occured, Please try again later.'
                ], 500);
            }

            // Ambil file yang akan difavoritkan beserta relasi tags, instances, dan favorite status
            $file = File::with(['user:id,name,email', 'folder:id', 'tags:id,name', 'instances:id,name,address', 'favorite' => function ($query) use ($userLogin) {
                $query->where('user_id', $userLogin->id);
            }])->where('id', $request->file_id)->first();

            // Cek apakah user memiliki izin untuk memfavoritkan file
            $checkPermission = $this->checkPermissionFileService->checkPermissionFile($file->id, ['write']);

            if (!$checkPermission) {
                return response()->json([
                    'errors' => 'You do not have permission to favorite the file.'
                ], 403);
            }

            // Cek apakah file sudah ada di daftar favorit pengguna
            $existingFavorite = $file->favorite->where('user_id', $user->id)->first();

            if ($existingFavorite) {
                // Jika sudah ada, kembalikan respon bahwa sudah difavoritkan
                return response()->json([
                    'message' => 'File sudah ada di daftar favorit.',
                    'file' => [
                        'id' => $file->id,
                        'name' => $file->name,
                        'public_path' => $file->public_path,
                        'size' => $file->size,
                        'type' => $file->type,
                        'created_at' => $file->created_at,
                        'updated_at' => $file->updated_at,
                        'folder_id' => $file->folder->id,
                        'is_favorited' => $existingFavorite ? true : false,
                        'favorited_at' => $existingFavorite->pivot->created_at ?? null,
                        'user' => $file->user, // User sudah diambil dengan select
                        'tags' => $file->tags, // Tags sudah diambil dengan select
                        'instances' => $file->instances, // Instances sudah diambil dengan select
                    ]
                ], 409);
            }

            DB::beginTransaction();

            // Tambahkan file ke favorit user
            $user->favoriteFiles()->attach($file->id);

            DB::commit();

            // Ambil ulang file setelah ditambahkan ke favorit
            $file->load(['favorite' => function ($query) use ($userLogin) {
                $query->where('user_id', $userLogin->id);
            }]);

            // Setelah berhasil ditambahkan ke favorit, kirimkan respon dengan data lengkap file
            return response()->json([
                'message' => 'File berhasil ditambahkan ke favorit.',
                'file' => [
                    'id' => $file->id,
                    'name' => $file->name,
                    'public_path' => $file->public_path,
                    'size' => $file->size,
                    'type' => $file->type,
                    'created_at' => $file->created_at,
                    'updated_at' => $file->updated_at,
                    'folder_id' => $file->folder->id,
                    'is_favorited' => $file->favorite->where('user_id', $user->id)->first() ? true : false,
                    'favorited_at' => $file->favorite->where('user_id', $user->id)->first()->pivot->created_at ?? null,
                    'user' => $file->user, // User sudah diambil dengan select
                    'tags' => $file->tags, // Tags sudah diambil dengan select
                    'instances' => $file->instances, // Instances sudah diambil dengan select
                ]
            ], 200);
        } catch (Exception $e) {
            // Jika terjadi error, rollback transaksi
            DB::rollBack();

            Log::error('Error occured while adding file to favorite.', [
                'file_id' => $request->file_id,
                'message' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'An error occured while adding file to favorite.'
            ], 500);
        }
    }

    public function deleteFavoriteFile($fileId)
    {
        $userLogin = Auth::user();

        try {
            // Ambil data user
            $user = User::with('favoriteFiles')->find($userLogin->id);

            $file = File::where('id', $fileId)->first();

            if(!$file){
                Log::warning('Attempt to delete non-existence file favorite.');
                return response()->json([
                    'errors' => 'File not found.'
                ], 404);
            }

            // Periksa apakah user memiliki file favorit dengan ID yang diberikan
            $favoriteFile = $user->favoriteFiles()->where('file_id', $file->id)->first();

            if (!$favoriteFile) {
                return response()->json([
                    'errors' => 'File is not in favorites'
                ], 404);
            }

            DB::beginTransaction();

            // Hapus file dari daftar favorit
            $user->favoriteFiles()->detach($file->id);

            DB::commit();

            $file->load(['user:id,name,email', 'folder:id', 'tags:id,name', 'instances:id,name,address', 'favorite']);

            return response()->json([
                'message' => 'Successfully removed file from favorite.',
                'file' => [
                    'id' => $file->id,
                    'name' => $file->name,
                    'public_path' => $file->public_path,
                    'size' => $file->size,
                    'type' => $file->type,
                    'created_at' => $file->created_at,
                    'updated_at' => $file->updated_at,
                    'folder_id' => $file->folder->id,
                    'is_favorited' => $file->favorite->isNotEmpty() ? true : false,
                    'favorited_at' => null,
                    'user' => $file->user, // User sudah diambil dengan select
                    'tags' => $file->tags, // Tags sudah diambil dengan select
                    'instances' => $file->instances, // Instances sudah diambil dengan select
                ]
            ], 200);
        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Error occurred while removing file from favorites', [
                'fileId' => $fileId,
                'message' => $e->getMessage()
            ]);

            return response()->json([
                'errors' => 'An error occurred while removing file from favorites'
            ], 500);
        }
    }
}
