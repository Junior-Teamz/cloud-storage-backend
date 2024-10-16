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
            $query = Tags::where('id', '!=', 1);

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

            if ($tagData->id === 1) {
                return response()->json([
                    'errors' => 'Access to the Root tag information is restricted.'
                ], 403);
            }

            if ($this->checkAdminService->checkAdmin()) {
                $tagData['total_usage'] = $tagData->calculateUsageCount();
                $tagData['folder_usage'] = $tagData->calculateFolderUsageCount();
                $tagData['file_usage'] = $tagData->calculateFileUsageCount();
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

            // Query untuk mendapatkan tag beserta statistik penggunaan di folder dan file
            $tagsQuery = Tags::select('tags.*')
                ->selectRaw('
                (SELECT COUNT(*) FROM folder_has_tags WHERE folder_has_tags.tags_id = tags.id) as folder_usage_count,
                (SELECT COUNT(*) FROM file_has_tags WHERE file_has_tags.tags_id = tags.id) as file_usage_count,
                (
                    (SELECT COUNT(*) FROM file_has_tags WHERE file_has_tags.tags_id = tags.id) +
                    (SELECT COUNT(*) FROM folder_has_tags WHERE folder_has_tags.tags_id = tags.id)
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
                Log::critical('Example file for importing tag not found!');
                return response()->json([
                    'errors' => 'Example file not found.'
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

            if ($tag->id === 1) {
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
