<?php

namespace App\Http\Controllers;

use App\Exceptions\MissingColumnException;
use App\Imports\TagImport;
use App\Models\Tags;
use App\Services\CheckAdminService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

class TagController extends Controller
{
    protected $checkAdminService;

    public function __construct(CheckAdminService $checkAdminService)
    {
        $this->checkAdminService = $checkAdminService;
    }

    /**
     * Get a paginated list of tags.
     * 
     * This method retrieves a list of tags, excluding the "Root" tag.
     * It supports sorting and filtering.
     *
     * @param  \Illuminate\Http\Request  $request The incoming HTTP request containing optional query parameters.
     * @return \Illuminate\Http\JsonResponse A JSON response containing the paginated list of tags or an error message.
     */
    public function index(Request $request)
    {
        try {
            // Ambil parameter untuk pengurutan, default 'desc' (paling baru)
            $sortParam = $request->query('sort_by', 'latest'); // default: 'latest'

            // Tentukan urutan berdasarkan parameter yang diberikan
            $sortOrder = 'desc'; // Default: 'latest'

            if ($sortParam === 'oldest' || $sortParam === 'asc') {
                $sortOrder = 'asc'; // Jika sort_by = 'oldest', urutkan dari yang paling lama
            } elseif ($sortParam === 'latest' || $sortParam === 'desc') {
                $sortOrder = 'desc'; // Jika sort_by = 'latest', urutkan dari yang paling baru
            } else {
                $sortOrder = 'desc'; // Jika sort_by selain 'oldest' dan 'latest', urutkan dari yang baru
            }

            // Ambil parameter pencarian nama jika ada
            $keywordName = $request->query('name');

            // Query dasar untuk mengambil tags (Kecualikan tag 'Root')
            $query = Tags::where('name', '!=', 'Root');

            // Jika ada pencarian berdasarkan nama
            if ($keywordName) {
                $query->where('name', 'like', '%' . $keywordName . '%');
            }

            // Terapkan pengurutan berdasarkan tanggal pembuatan
            $query->orderBy('created_at', $sortOrder);

            // Ambil data dengan pagination
            $allTag = $query->paginate(10);

            // Jika data kosong, kembalikan error
            if (!$allTag) {
                return response()->json([
                    'message' => 'Tag data not found.',
                    'data' => []
                ], 200);
            }

            // Tambahkan usage_count untuk setiap tag
            $allTag->getCollection()->transform(function ($tag) {
                $tag->usage_count = $tag->calculateUsageCount();
                return $tag;
            });

            return response()->json($allTag, 200);  // Kembalikan isi pagination
        } catch (\Exception $e) {
            // Tangani jika ada error
            Log::error('Error occurred while fetching tag data: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred while fetching tag data.'
            ], 500);
        }
    }

    /**
     * Get information about a specific tag.
     *
     * This method retrieves information about a tag based on its ID.
     * It checks if the tag exists and if it's not the "Root" tag.
     * If the user is an admin, it also calculates and includes usage statistics for the tag.
     *
     * @param int $id The ID of the tag to retrieve information for.
     * @return \Illuminate\Http\JsonResponse A JSON response containing the tag information or an error message.
     */
    public function getTagsInformation($id)
    {
        try {
            $tagData = Tags::where('id', $id)->first();

            if (!$tagData) {
                return response()->json([
                    'message' => 'Tag not found.',
                    'data' => []
                ], 200);
            }

            if ($tagData->name === 'Root') {
                return response()->json([
                    'errors' => 'Access to the Root tag information is restricted.'
                ], 403);
            }

            if ($this->checkAdminService->checkAdmin()) {
                $tagData['total_usage'] = $tagData->calculateUsageCount();
                $tagData['folder_usage'] = $tagData->calculateFolderUsageCount();
                $tagData['file_usage'] = $tagData->calculateFileUsageCount();
                $tagData['news_usage'] = $tagData->calculateNewsUsageCount();
            }

            return response()->json([
                'data' => $tagData
            ]);
        } catch (\Exception $e) {
            Log::error('Error occured while fetching tag data: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);
            return response()->json([
                'errors' => 'An error occurred while fetching tag data.'
            ], 500);
        }
    }

    /**
     * Count the total number of tags.
     *
     * This method retrieves the total count of tags in the database, excluding the "Root" tag.
     * It first checks if the authenticated user is an admin. If not, it returns an error message.
     * If the user is an admin, it counts the tags and returns the count in a JSON response.
     * 
     * Requires admin authentication.
     *
     * @return \Illuminate\Http\JsonResponse A JSON response containing the tag count or an error message.
     */
    public function countAllTags()
    {
        $checkAdmin = $this->checkAdminService->checkAdmin();

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ]);
        }

        try {
            $countTag = Tags::count();

            if (!$countTag) {
                return response()->json([
                    'message' => 'Tag is empty.',
                    'tag_count' => $countTag
                ]);
            }

            return response()->json([
                'tag_count' => $countTag
            ]);
        } catch (Exception $e) {
            Log::error('Error occurred on getting tag count: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred on getting tag count.'
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
        $checkAdmin = $this->checkAdminService->checkAdmin();

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

    /**
     * Create a new tag.
     *
     * This method handles the creation of a new tag. It first checks if the authenticated user
     * has admin privileges. If not, a 403 Forbidden response is returned. It then validates
     * the incoming request data to ensure the 'name' field is required, a string, unique (case-insensitive),
     * contains only letters and spaces, and does not exceed 50 characters.
     * 
     * Requires admin authentication.
     * 
     * @param  \Illuminate\Http\Request  $request The incoming HTTP request containing the tag data.
     * @return \Illuminate\Http\JsonResponse A JSON response indicating success or failure, with appropriate status codes and messages.
     */
    public function store(Request $request)
    {
        $checkAdmin = $this->checkAdminService->checkAdmin();

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        // Validator with unique rule (case-insensitive check) and regex to prevent unclear letter/number mixes
        $validator = Validator::make($request->all(), [
            'name' => [
                'required',
                'string',
                // Custom uniqueness validation query to make it case-insensitive
                Rule::unique('tags')->where(function ($query) {
                    return $query->whereRaw('LOWER(name) = ?', [strtolower(request('name'))]);
                }),
                'regex:/^[a-zA-Z\s]+$/',
                'max:50'
            ],
        ], [
            'name.unique' => 'Tag name already exists.',
            'name.regex' => 'Tag name can only contain letters and spaces.',
            'name.max' => 'Tag name cannot exceed 50 characters.'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            DB::beginTransaction();

            $uppercasedTagName = ucwords($request->name);

            $tag = Tags::create([
                'name' => $uppercasedTagName
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Tag created successfully.',
                'data' => $tag
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error occured while creating tag: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred while creating tag.'
            ], 500);
        }
    }

    /**
     * Download an example Excel file for tag imports.
     *
     * This method allows authenticated admin users to download an example Excel file that demonstrates
     * the correct format for importing tag data.
     * 
     * Requires admin authentication.
     *
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse|\Illuminate\Http\JsonResponse A file excel response for downloading the example file or a JSON response indicating an error.
     */
    public function exampleImportDownload()
    {
        // Mengecek apakah pengguna adalah admin
        $checkAdmin = $this->checkAdminService->checkAdmin();

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ]);
        }

        try {
            // Path file di folder storage/app/import_example
            $filePath = 'import_example/TagImport.xlsx';

            // Cek apakah file ada
            if (!Storage::exists($filePath)) {
                Log::critical('Example file for importing tag not found!, please add example import tag excel file in storage/app/import_example/TagImport.xlsx');
                return response()->json([
                    'errors' => 'Internal server occured. Please contact the administrator of app.'
                ], 500);
            }

            // Mengembalikan respons untuk mendownload file
            return Storage::download($filePath, 'TagImport_Example.xlsx');
        } catch (Exception $e) {
            // Log error jika terjadi exception
            Log::error('Error occurred while downloading example file: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred while downloading the example file.'
            ], 500);
        }
    }

    /**
     * Import tags from an Excel file.
     *
     * This method handles the import of tags from an uploaded Excel file.
     * It then validates the uploaded file to ensure it's an Excel file with a maximum size of 5120 KB.
     * If validation passes, the file is stored temporarily, and the import process begins within a database transaction.
     * The `TagImport` class is used to handle the actual import logic. After the import, the method retrieves the count
     * of invalid and duplicate tags. The temporary file is then deleted.
     * 
     * Requires admin authentication.
     *
     * @param  \Illuminate\Http\Request  $request The incoming HTTP request containing the uploaded Excel file.
     * @return \Illuminate\Http\JsonResponse A JSON response indicating the success or failure of the import operation.
     */
    public function import(Request $request)
    {
        $checkAdmin = $this->checkAdminService->checkAdmin();

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        // Validasi file yang diupload
        $validator = Validator::make($request->all(), [
            'file' => 'required|mimes:xlsx,xls,csv|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ]);
        }

        // Simpan file ke storage/app/temp
        $file = $request->file('file');
        $path = $file->storeAs('temp', $file->getClientOriginalName());

        try {
            DB::beginTransaction();

            // Inisialisasi objek TagImport
            $tagImport = new TagImport;

            // Lakukan import menggunakan Laravel Excel
            Excel::import($tagImport, storage_path('app/' . $path));

            // Ambil jumlah tag yang invalid dan duplikat
            $invalidCount = $tagImport->getInvalidTagsCount();
            $duplicateCount = $tagImport->getDuplicateTagsCount();

            DB::commit();

            // Hapus file setelah proses import selesai
            Storage::delete($path);

            // Kembalikan respon sukses, dengan informasi mengenai tag yang invalid dan duplikat
            return response()->json([
                'message' => ($invalidCount || $duplicateCount) ? 'The tag was successfully imported, but there are invalid or duplicate tag names.' : 'Tags imported successfully.',
                'invalid_tags_total' => $invalidCount,
                'duplicate_tags_total' => $duplicateCount
            ], 200);
        } catch (MissingColumnException $e) {
            DB::rollBack();

            // Hapus file meskipun terjadi error
            Storage::delete($path);

            // Tangani error ketika kolom tidak ditemukan
            Log::error('Column not found in file excel: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'Required Column was not found in excel file.'
            ], 422);
        } catch (Exception $e) {
            DB::rollBack();

            // Hapus file meskipun terjadi error
            Storage::delete($path);

            Log::error('Error occured while importing tags: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred while importing tags.'
            ], 500);
        }
    }

    /**
     * Update an existing tag.
     *
     * This method handles the update of an existing tag. It first checks if the authenticated user
     * has admin privileges. If not, a 403 Forbidden response is returned. It then validates
     * the incoming request data to ensure the 'name' field is required, a string, unique (case-insensitive),
     * contains only letters and spaces. The validation also excludes the current tag's ID to allow
     * renaming a tag to its existing name.
     * 
     * Requires admin authentication.
     *
     * @param  \Illuminate\Http\Request  $request The incoming HTTP request containing the updated tag data.
     * @param string $id The ID of the tag to update.
     * @return \Illuminate\Http\JsonResponse A JSON response indicating success or failure, with appropriate status codes and messages.
     */
    public function update(Request $request, $id)
    {
        $checkAdmin = $this->checkAdminService->checkAdmin();

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        // Validator with unique rule (case-insensitive check) and regex to prevent unclear letter/number mixes EXCLUDE the current tag ID
        $validator = Validator::make($request->all(), [
            'name' => [
                'required',
                'string',
                // Ignore the current tag's ID during the uniqueness check
                Rule::unique('tags')->where(function ($query) use ($request, $id) {
                    return $query->whereRaw('LOWER(name) = ?', [strtolower($request->name)])
                        ->where('id', '!=', $id); // Exclude the current tag ID
                }),
                'regex:/^[a-zA-Z\s]+$/', // Prevent mixed letters/numbers (can adjust if needed)
            ],
        ], [
            'name.unique' => 'Tag name already exists.',
            'name.regex' => 'Tag name can only contain letters and spaces.'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        try {

            $tag = Tags::where('id', $id)->first();

            if (!$tag) {
                return response()->json([
                    'errors' => 'Tag not found.'
                ], 404);
            }

            if ($tag->name === 'Root') {
                return response()->json([
                    'errors' => 'You cannot change Root tag!'
                ], 403);
            }

            DB::beginTransaction();

            // update tag
            $tag->name = ucwords($request->name);
            $tag->save();

            DB::commit();

            return response()->json([
                'message' => 'Tag updated successfully.',
                'data' => $tag
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error occured while updating tag: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred while updating tag.'
            ], 500);
        }
    }

    /**
     * Delete multiple tags.
     *
     * This method handles the deletion of multiple tags based on an array of tag IDs provided in the request.
     * The method attempts to retrieve the tags from the database, excluding the "Root" tag to prevent its deletion.
     * If any of the provided tag IDs are not found, a 404 Not Found response is returned with a list of missing tag IDs.
     * If all tags are found, the method proceeds to delete them within a database transaction. It first detaches all
     * relationships the tags have with folders, files, and news. Then, it deletes the tags themselves.
     * 
     * Requires admin authentication.
     * 
     * **Caution:** Deleting tags is a destructive action. It will remove the tags from any folders, files, or news
     * they are associated with. This action cannot be undone. Ensure that you want to delete the selected tags
     * before proceeding.
     *
     * @param  \Illuminate\Http\Request  $request The incoming HTTP request containing an array of tag IDs to delete.
     * @return \Illuminate\Http\JsonResponse A JSON response indicating success or failure, with appropriate status codes and messages.
     */
    public function destroy(Request $request)
    {
        $checkAdmin = $this->checkAdminService->checkAdmin();

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        // Validasi bahwa tag_ids dikirim dalam request
        $validator = Validator::make($request->all(), [
            'tag_ids' => 'required|array',
        ], [
            'tag_ids.required' => 'tag_ids are required.',
            'tag_ids.array' => 'tag_ids must be an array of tag ID.',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Ambil daftar tag_ids dari request
        $tagIds = $request->tag_ids;

        try {
            // Exclude "Root" tag dari query untuk menghindari penghapusan
            $tags = Tags::whereIn('id', $tagIds)->where('name', '!=', 'Root')->get();

            // Bandingkan ID yang ditemukan dengan yang diminta
            $foundTagIds = $tags->pluck('id')->toArray();
            $notFoundTagIds = array_diff($tagIds, $foundTagIds);

            if (!empty($notFoundTagIds)) {
                Log::info('Attempt to delete non-existent tags: ' . implode(',', $notFoundTagIds));

                return response()->json([
                    'errors' => 'Some tags were not found.',
                    'missing_tag_ids' => $notFoundTagIds,
                ], 404);
            }

            DB::beginTransaction();

            // Detach hubungan untuk semua tag dalam satu batch operation
            DB::table('folder_has_tags')->whereIn('tags_id', $foundTagIds)->delete(); // Detach all folder relations
            DB::table('file_has_tags')->whereIn('tags_id', $foundTagIds)->delete();   // Detach all file relations
            DB::table('news_has_tags')->whereIn('tags_id', $foundTagIds)->delete();   // Detach all news relations

            // Hapus semua tag sekaligus
            Tags::whereIn('id', $foundTagIds)->delete();

            DB::commit();

            return response()->json([
                'message' => 'Tags deleted successfully.',
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error occurred while deleting tags: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred while deleting tags.'
            ], 500);
        }
    }
}
