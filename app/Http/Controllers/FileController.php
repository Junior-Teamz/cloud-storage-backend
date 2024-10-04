<?php

namespace App\Http\Controllers;

use Exception;
use AMWScan\Scanner;
use App\Models\File;
use App\Models\Tags;
use App\Models\User;
use App\Models\Folder;
use App\Services\CheckFilePermissionService;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use App\Services\CheckFolderPermissionService;
use App\Services\GenerateURLService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Sqids\Sqids;

class FileController extends Controller
{
    protected $checkPermissionFolderService;
    protected $GenerateURLService;
    protected $checkPermissionFileServices;

    public function __construct(CheckFolderPermissionService $checkPermissionFolderService, CheckFilePermissionService $checkFilePermissionService, GenerateURLService $GenerateURLService)
    {
        // Simpan service ke dalam property
        $this->checkPermissionFolderService = $checkPermissionFolderService;
        $this->GenerateURLService = $GenerateURLService;
        $this->checkPermissionFileServices = $checkFilePermissionService;
    }

    /**
     * Get information about a file (READ).
     */
    public function info($id)
    {
        $checkPermission = $this->checkPermissionFileServices->checkPermissionFile($id, ['read', 'write']);

        if (!$checkPermission) {
            return response()->json([
                'errors' => 'You do not have permission to view this file.',
            ]);
        }

        try {
            $file = File::with([
                'user:id,name,email',
                'tags',
                'instances:id,name,address'
            ])->find($id);

            if (!$file) {
                return response()->json([
                    'errors' => 'File not found',
                ], 404);
            }

            // Sembunyikan kolom 'path' dan 'nanoid'
            $file->makeHidden(['path', 'nanoid', 'user_id']);

            return response()->json([
                'data' => [
                    'file' => $file,
                ],
            ], 200);
        } catch (Exception $e) {
            Log::error('Error encountered while fetching file info: ', [
                'fileId' => $id,
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'errors' => 'An error occurred while fetching the file info.',
            ], 500);
        }
    }

    // dapatkan semua file dan total filenya
    public function getAllFilesAndTotalSize()
    {
        $user = Auth::user();

        try {
            // Ambil semua file dari database dengan paginasi, termasuk user, tags, dan instances
            $filesQuery = File::where('user_id', $user->id)
                ->with(['user:id,name,email', 'tags:id,name', 'instances:id,name,address']);

            // Hitung total ukuran file langsung dari query sebelum paginasi
            $totalSize = $filesQuery->sum('size');

            // Lakukan paginasi dari hasil query
            $files = $filesQuery->paginate(10);

            // Sembunyikan kolom 'path' dan 'nanoid' dari respon JSON
            $files->makeHidden(['path', 'nanoid']);

            // Return daftar file yang dipaginasi dan total ukuran
            return response()->json([
                'data' => [
                    'total_size' => $totalSize,
                    'files' => $files,
                ],
            ], 200);
        } catch (\Exception $e) {

            Log::error('An error occurred while fetching all files and total size: ' . $e->getMessage());

            return response()->json([
                'error' => 'An error occurred while fetching all files and total size.'
            ], 500);
        }
    }

    /**
     * Upload a file.
     */
    public function upload(Request $request)
    {
        $user = Auth::user();

        // Validasi input, mengubah 'file' menjadi array
        $validator = Validator::make($request->all(), [
            'file' => 'required|array',
            'file.*' => 'required|file|mimes:jpg,jpeg,png,pdf,doc,docx,xlsx,pptx,ppt,txt,mp3,ogg,wav,aac,opus,mp4,hevc,mkv,mov,h264,h265,php,js,html,css',
            'folder_id' => 'nullable|integer|exists:folders,id',
            'tag_ids' => 'required|array',
            'tag_ids.*' => ['integer', 'exists:tags,id'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Tentukan folder tujuan jika folder_id tidak null
        if ($request->has('folder_id')) {

            $folderId = $request->folder_id;

            // Periksa apakah user memiliki izin ke folder tujuan
            $permissionCheck = $this->checkPermissionFolderService->checkPermissionFolder($folderId, 'write');
            if (!$permissionCheck) {
                return response()->json([
                    'errors' => 'You do not have permission to upload files to the destination folder.',
                ], 403);
            }
        }

        try {
            // START MYSQL TRANSACTION
            DB::beginTransaction();

            $filesData = []; // Array untuk menyimpan data file yang berhasil diunggah

            $userData = User::where('id', $user->id)->first();
            $userInstances = $userData->instances->pluck('id')->toArray();  // Mengambil instance user

            foreach ($request->file('file') as $uploadedFile) {

                $originalFileName = $uploadedFile->getClientOriginalName(); // Nama asli file

                $fileExtension = $uploadedFile->getClientOriginalExtension(); // Ekstensi file

                // Generate NanoID untuk nama file
                $nanoid = (new \Hidehalo\Nanoid\Client())->generateId();
                $storageFileName = $nanoid . '.' . $fileExtension;

                // Tentukan folder tujuan
                $folderId = $request->folder_id ?? Folder::where('user_id', $user->id)->whereNull('parent_id')->first()->id;

                // Path sementara
                $tempPath = storage_path('app/temp/' . $storageFileName);
                $uploadedFile->move(storage_path('app/temp'), $storageFileName);

                // Pemindaian file dengan PHP Antimalware Scanner
                $scanner = new Scanner();
                $scanResult = $scanner->setPathScan($tempPath)->run();

                if ($scanResult->detected >= 1) {
                    // Hapus file jika terdeteksi virus
                    if (file_exists($tempPath)) {
                        unlink($tempPath);
                    }
                    DB::rollBack();
                    return response()->json(['errors' => 'File detected as malware.'], 422);
                }

                // Pindahkan file yang telah discan ke storage utama
                $path = $this->generateFilePath($folderId, $storageFileName);
                Storage::put($path, file_get_contents($tempPath));

                // Ambil ukuran file dari storage utama
                $fileSize = Storage::size($path);

                Log::info("File Temp: " . $tempPath);

                // Hapus file sementara setelah dipindahkan
                if (file_exists($tempPath)) {
                    unlink($tempPath);
                }

                // generate path for public (exposed path for frontend)
                $publicPath = $this->generateFilePublicPath($folderId, $originalFileName);

                // Buat catatan file di database
                $file = File::create([
                    'name' => $originalFileName,
                    'path' => $path,
                    'public_path' => $publicPath,
                    'size' => $fileSize, // Catatan: ukuran dalam satuan byte!
                    'type' => $fileExtension,
                    'user_id' => $user->id,
                    'folder_id' => $folderId,
                    'nanoid' => $nanoid,
                ]);

                $mimeType = Storage::mimeType($path);

                if (Str::startsWith($mimeType, 'image')) {
                    $imageUrl = $this->GenerateURLService->generateUrlForImage($file->id);

                    $file->image_url = $imageUrl;
                    $file->save();
                }

                $file->tags()->sync($request->tag_ids);

                $file->instances()->sync($userInstances);

                $file->makeHidden(['path', 'nanoid', 'user_id']);

                $file->load(['user:id,name,email', 'tags:id,name', 'instances:id,name,address']);

                // Tambahkan file ke dalam array yang akan dikembalikan
                $filesData[] = $file;
            }

            // COMMIT TRANSACTION JIKA TIDAK ADA ERROR
            DB::commit();

            Log::info('Files uploaded and scanned successfully.', [
                'userId' => $user->id,
                'folderId' => $folderId,
            ]);

            return response()->json([
                'message' => 'Files uploaded successfully.',
                'data' => [
                    'files' => $filesData,
                ],
            ], 201);
        } catch (Exception $e) {
            // ROLLBACK TRANSACTION JIKA ADA KESALAHAN
            DB::rollBack();

            // Hapus file sementara jika terjadi kesalahan
            if (isset($tempPath) && file_exists($tempPath)) {
                unlink($tempPath);
            }

            Log::error('Error occurred while uploading files: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'userId' => $user->id,
                'fileId' => $request->input('file_id', null),
            ]);

            return response()->json([
                'errors' => 'An error occurred while uploading the files.',
            ], 500);
        }
    }

    public function downloadFile(Request $request)
    {
        // Validasi input request
        $validate = Validator::make($request->all(), [
            'file_ids' => 'required|array',
            'file_ids.*' => 'required|exists:files,id',
        ]);

        if ($validate->fails()) {
            return response()->json(['error' => $validate->errors()], 422);
        }

        // Ambil file_ids dari request
        $fileIds = $request->file_ids;

        foreach ($fileIds as $fileId) {

            $checkPermission = $this->checkPermissionFileServices->checkPermissionFile($fileId, ['read']);

            if (!$checkPermission) {
                return response()->json([
                    'errors' => 'You do not have permission to download any of the files.'
                ]);
            }
        }

        try {
            // Ambil files berdasarkan file_ids
            $files = File::whereIn('id', $fileIds)->get();

            if (!$files) {
                return response()->json(['error' => 'Files not found'], 404);
            }

            if ($files->count() === 1) {
                // Single file download
                $file = $files->first();
                $filePath = Storage::path($file->path); // Menggunakan Storage::path untuk mendapatkan full path

                if (!Storage::exists($file->path)) {
                    return response()->json(['error' => 'File not found'], 404);
                }

                // Mengirimkan file tunggal untuk di-download
                return response()->download($filePath, $file->name);
            } else {

                // Mendapatkan waktu saat ini dengan zona waktu Jakarta
                $now = Carbon::now('Asia/Jakarta');

                // Membuat format nama file dengan nama dan tanggal waktu
                $zipFileName = 'files ' . $now->format('d-m-Y_His') . '.zip';
                $zipFilePath = storage_path('app/temp/' . $zipFileName);

                // Create ZipArchive
                $zip = new \ZipArchive();
                if ($zip->open($zipFilePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true) {
                    foreach ($files as $file) {
                        $filePath = Storage::path($file->path); // Menggunakan Storage::path

                        if (Storage::exists($file->path)) {
                            // Tambahkan file ke dalam arsip zip
                            $zip->addFile($filePath, $file->name);
                        }
                    }
                    $zip->close();
                } else {
                    return response()->json(['error' => 'Unable to create zip file'], 500);
                }

                // Mengirimkan file .zip untuk di-download dan menghapus file setelah dikirim
                return response()->download($zipFilePath)->deleteFileAfterSend(true);
            }
        } catch (Exception $e) {

            Log::error('Error occurred while downloading files: ' . $e->getMessage());

            return response()->json(['error' => 'An error occurred while downloading files'], 500);
        }
    }

    /**
     * Add a tag to a file.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function addTagToFile(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file_id' => 'required|integer|exists:files,id',
            'tag_id' => 'required|integer|exists:tags,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Periksa perizinan
        $permissionCheck = $this->checkPermissionFileServices->checkPermissionFile($request->file_id, 'write');
        if (!$permissionCheck) {
            return response()->json([
                'errors' => 'You do not have permission to add tag to this file.',
            ], 403);
        }

        try {
            $file = File::findOrFail($request->file_id);
            $tag = Tags::findOrFail($request->tag_id);

            // Memeriksa apakah tag sudah terkait dengan file
            if ($file->tags->contains($tag->id)) {
                return response()->json([
                    'errors' => 'Tag already exists in folder.'
                ], 409);
            }

            DB::beginTransaction();

            // Menambahkan tag ke file (tabel pivot file_has_tags)
            $file->tags()->attach($tag->id);

            DB::commit();

            $file->load(['user:id,name,email', 'tags', 'instances:id,name,address']);

            return response()->json([
                'message' => 'Successfully added tag to file.',
                'data' => [
                    'file' => $file
                ]
            ], 200);
        } catch (ModelNotFoundException $e) {

            DB::rollBack();

            return response()->json([
                'errors' => 'File or tag not found.'
            ], 404);
        } catch (Exception $e) {

            DB::rollBack();

            Log::error('Error occurred on adding tag to file: ' . $e->getMessage(), [
                'file_id' => $request->file_id,
                'tag_id' => $request->tag_id,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'errors' => 'An error occurred on adding tag to file.'
            ], 500);
        }
    }

    /**
     * Remove a tag from a file
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function removeTagFromFile(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file_id' => 'required|integer|exists:files,id',
            'tag_id' => 'required|integer|exists:tags,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Periksa apakah user memiliki perizinan pada file
        $permissionCheck = $this->checkPermissionFileServices->checkPermissionFile($request->file_id, 'write');
        if (!$permissionCheck) {
            return response()->json([
                'errors' => 'You do not have permission to remove tag from this file.',
            ], 403);
        }

        try {
            $file = File::findOrFail($request->file_id);
            $tag = Tags::findOrFail($request->tag_id);

            // Memeriksa apakah tag terkait dengan file
            if (!$file->tags->contains($tag->id)) {
                return response()->json([
                    'errors' => 'Tag not found in file.'
                ], 404);
            }

            DB::beginTransaction();

            // Menghapus tag dari file (tabel pivot file_has_tags)
            $file->tags()->detach($tag->id);

            DB::commit();

            $file->load(['user:id,name,email', 'tags', 'instances:id,name,address']);

            return response()->json([
                'message' => 'Successfully removed tag from file.',
                'data' => [
                    'file' => $file
                ]
            ], 200);
        } catch (ModelNotFoundException $e) {

            DB::rollBack();

            return response()->json([
                'errors' => 'File or tag not found.'
            ], 404);
        } catch (Exception $e) {

            DB::rollBack();

            Log::error('Error occurred on removing tag from file: ' . $e->getMessage(), [
                'file_id' => $request->file_id,
                'tag_id' => $request->tag_id,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'errors' => 'An error occurred on removing tag from file.'
            ], 500);
        }
    }

    /**
     * Update the name of a file.
     */
    public function updateFileName(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Periksa apakah user memiliki perizinan pada file
        $permissionCheck = $this->checkPermissionFileServices->checkPermissionFile($id, 'write');
        if (!$permissionCheck) {
            return response()->json([
                'errors' => 'You do not have permission to edit this file.',
            ], 403);
        }

        try {
            DB::beginTransaction();

            $file = File::findOrFail($id);

            // Dapatkan ekstensi asli dari nama file yang ada
            $currentFileNameWithExtension = $file->name;
            $originalExtension = pathinfo($currentFileNameWithExtension, PATHINFO_EXTENSION); // Ekstensi asli

            // Hapus ekstensi dari nama baru yang dimasukkan user
            $newFileNameWithoutExtension = pathinfo($request->name, PATHINFO_FILENAME);

            // Gabungkan nama baru dengan ekstensi asli
            $newFileName = $newFileNameWithoutExtension . '.' . $originalExtension;

            // Ambil Nanoid dari database
            $fileNanoid = $file->nanoid;

            // Gunakan Nanoid untuk nama file pada storage lokal laravel
            $storageFileName = $fileNanoid . '.' . $originalExtension;

            // Update file name in storage
            $oldFullPath = $file->path;
            $newPath = $this->generateFilePath($file->folder_id, $storageFileName);

            $publicPath = $this->generateFilePublicPath($file->folder_id, $newFileName);

            if (Storage::exists($oldFullPath)) {
                Storage::move($oldFullPath, $newPath);
            }

            // Update nama file di database dengan ekstensi yang tidak berubah
            $file->update([
                'nanoid' => $fileNanoid,
                'name' => $newFileName, // Nama baru dengan ekstensi asli
                'path' => $newPath,
                'public_path' => $publicPath,
            ]);

            DB::commit();

            $file->load(['user:id,name,email', 'tags', 'instances:id,name,address']);

            $file->makeHidden(['path', 'nanoid', 'user_id']);

            return response()->json([
                'message' => 'File name updated successfully.',
                'data' => $file,
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'errors' => 'File not found.',
            ], 404);
        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Error occurred while updating file name: ' . $e->getMessage(), [
                'fileId' => $id,
                'name' => $request->name,
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'errors' => 'An error occurred while updating the file name.',
            ], 500);
        }
    }

    /**
     * Move a file to another folder.
     */
    public function move(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'new_folder_id' => 'required|integer|exists:folders,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Periksa apakah user memiliki izin pada file
        $permissionCheck = $this->checkPermissionFileServices->checkPermissionFile($id, 'write');
        if (!$permissionCheck) {
            return response()->json([
                'errors' => 'You do not have permission to move this file.',
            ], 403);
        }

        // Periksa apakah user memiliki izin ke folder tujuan
        $permissionFolderCheck = $this->checkPermissionFolderService->checkPermissionFolder($request->new_folder_id, 'folder_edit');
        if (!$permissionFolderCheck) {
            return response()->json([
                'errors' => 'You do not have permission on the destination folder.',
            ], 403);
        }

        try {
            DB::beginTransaction();

            $file = File::where('id', $id)->first();

            if(!$file){
                Log::warning('Attempt to move file on non-existence folder: ' . $id);
                return response()->json([
                    'errors' => 'Folder destination to move the file was not found.'
                ], 404);
            }
            $oldPath = $file->path;

            // Intinya, kode dibawah ini adalah persiapan untuk menyiapkan path baru.
            $fileExtension = pathinfo($file->name, PATHINFO_EXTENSION);

            $fileNameWithNanoid = $file->nanoid . '.' . $fileExtension;

            // Generate new path
            $newPath = $this->generateFilePath($request->new_folder_id, $fileNameWithNanoid);

            $newPublicPath = $this->generateFilePublicPath($request->new_folder_id, $file->name);

            // Check if old file path exists
            if (!Storage::exists($oldPath)) {
                Log::error('Error occured while moving file: Old file path does not exist.', [
                    'new_folder_id' => $id,
                    'old_file_path' => $oldPath
                ]);
                return response()->json(['errors' => 'Internal server error, please contact the administrator of app.'], 500);
            }

            Storage::move($oldPath, $newPath);

            // Update file record in database
            $file->update([
                'folder_id' => $request->new_folder_id,
                'path' => $newPath,
                'public_path' => $newPublicPath,
            ]);

            DB::commit();

            $file->load(['user:id,name,email', 'tags', 'instances:id,name,address']);

            $file->makeHidden(['path', 'nanoid', 'user_id']);

            return response()->json([
                'message' => 'File moved successfully.',
                'data' => $file,
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'errors' => 'File not found.',
            ], 404);
        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Error occurred while moving file: ' . $e->getMessage(), [
                'fileId' => $id,
                'newFolderId' => $request->new_folder_id,
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'errors' => 'An error occurred while moving the file.',
            ], 500);
        }
    }

    /**
     * Delete a file (DELETE).
     * DANGEROUS! 
     */
    public function delete(Request $request)
    {
        // Validasi bahwa file_ids dikirim dalam request
        $validator = Validator::make($request->all(), [
            'file_ids' => 'required|array',
        ], [
            'file_ids.required' => 'file_ids are required.',
            'file_ids.array' => 'file_ids must be an array of file ID.',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $fileIds = $request->file_ids;

        try {
            // Periksa apakah ada file ID yang bukan integer
            $nonIntegerFileIds = array_filter($fileIds, fn($id) => !is_int($id));
            if (!empty($nonIntegerFileIds)) {
                Log::error('Invalid File IDs detected: ' . implode(', ', $nonIntegerFileIds), [
                    'context' => 'FileController.php (delete) - invalid file IDs.',
                ]);
                return response()->json([
                    'errors' => 'Invalid file IDs detected.',
                    'invalid_ids' => $nonIntegerFileIds
                ], 422);
            }

            // Periksa apakah semua file ada
            $files = File::whereIn('id', $fileIds)->get();

            $foundFileIds = $files->pluck('id')->toArray();

            // Jika ada file yang tidak ditemukan
            if (count($files) != count($fileIds)) {
                $notFoundFileIds = array_diff($fileIds, $foundFileIds);

                Log::error('File Delete: some files not found: ' . implode(', ', $notFoundFileIds));

                return response()->json([
                    'errors' => 'Some files were not found.',
                    'not_found_file_ids' => $notFoundFileIds
                ], 404);
            }

            // Periksa perizinan sekaligus
            $noPermissionFileIds = [];
            foreach ($files as $file) {
                if (!$this->checkPermissionFileServices->checkPermissionFile($file->id, 'write')) {
                    $noPermissionFileIds[] = $file->id;
                }
            }

            // Jika ada file yang tidak memiliki izin
            if (!empty($noPermissionFileIds)) {
                Log::error('User attempted to delete files without permission: ' . implode(', ', $noPermissionFileIds));
                return response()->json([
                    'errors' => 'You do not have permission to delete some of the selected files.',
                    'no_permission_file_ids' => $noPermissionFileIds
                ], 403);
            }

            DB::beginTransaction();

            // Hapus semua relasi yang berkaitan dengan file yang dihapus.
            DB::table('user_file_permissions')->whereIn('file_id', $foundFileIds)->delete();
            DB::table('file_has_tags')->whereIn('file_id', $foundFileIds)->delete();
            DB::table('file_has_instances')->whereIn('file_id', $foundFileIds)->delete();
            // TODO: tambahkan penghapusan file favorite disini

            // Hapus file secara batch
            File::whereIn('id', $foundFileIds)->delete();

            DB::commit();

            // Hapus file di storage
            foreach ($files as $file) {
                if (Storage::exists($file->path)) {
                    Storage::delete($file->path);
                }
            }

            return response()->json([
                'message' => 'File(s) deleted successfully.',
            ], 200);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Error occurred while deleting file(s): ' . $e->getMessage(), [
                'fileIds' => $fileIds,
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'errors' => 'An error occurred while deleting the file(s).',
            ], 500);
        }
    }

    public function serveFileImageByHashedId($fileId)
    {
        $user = Auth::user();

        if ($user) {
    
            // Cari file berdasarkan ID
            $file = File::find($fileId);

            if (!$file) {
                return response()->json(['errors' => 'File not found'], 404);  // File tidak ditemukan
            }

            // Cek apakah file adalah gambar
            if (!Str::startsWith(Storage::mimeType($file->path), 'image')) {
                return response()->json(['errors' => 'The file is not an image'], 415);  // 415 Unsupported Media Type
            }

            // Periksa perizinan menggunakan fungsi checkPermissionFile
            if (!$this->checkPermissionFileServices->checkPermissionFile($file->id, ['read'])) {
                return response()->json(['errors' => 'You do not have permission to access this URL.'], 403);
            }

            // Ambil path file dari storage
            $file_path = Storage::path($file->path);

            // Kembalikan file sebagai respon (mengirim file gambar)
            return response()->file($file_path);
        } else {
            return response()->json([
                'errors' => 'Unauthenticated.'
            ], 401);
        }
    }

    public function generateFilePublicPath($folderId, $fileName)
    {
        // Initialize an array to store the folder names
        $path = [];

        // If folderId is provided, build the path from the folder to the root
        while ($folderId) {
            // Find the folder by ID
            $folder = Folder::find($folderId);
            if ($folder) {
                // Prepend the folder name to the path array
                array_unshift($path, $folder->name);
                // Set the folder ID to its parent folder's ID
                $folderId = $folder->parent_id;
            } else {
                // If the folder is not found, stop the loop
                break;
            }
        }

        // Add the file name to the end of the path
        $path[] = $fileName;

        // Join the path array into a single string
        return implode('/', $path);
    }

    /**
     * Generate the file path based on folder id and file name.
     */
    private function generateFilePath($folderId, $fileNanoid)
    {
        // Initialize an array to store the folder names
        $path = [];

        // If folderId is provided, build the path from the folder to the root
        while ($folderId) {
            // Find the folder by ID
            $folder = Folder::find($folderId);
            if ($folder) {
                // Prepend the folder name to the path array
                array_unshift($path, $folder->nanoid);
                // Set the folder ID to its parent folder's ID
                $folderId = $folder->parent_id;
            } else {
                // If the folder is not found, stop the loop
                break;
            }
        }

        // Add the file name to the end of the path
        $path[] = $fileNanoid;

        // Join the path array into a single string
        return implode('/', $path);
    }
}
