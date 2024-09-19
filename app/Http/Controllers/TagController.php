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

            // Query dasar untuk mengambil tags
            $query = Tags::query();

            // Jika ada pencarian berdasarkan nama
            if ($keywordName) {
                $query->where('name', 'like', '%' . $keywordName . '%');
            }

            // Terapkan pengurutan berdasarkan tanggal pembuatan
            $query->orderBy('created_at', $sortOrder);

            // Ambil data dengan pagination
            $allTag = $query->paginate(10);

            // Jika data kosong, kembalikan error
            if ($allTag->isEmpty()) {
                return response()->json([
                    'errors' => 'Tag data not found.'
                ], 404);
            }

            // Tambahkan usage_count untuk setiap tag
            $allTag->getCollection()->transform(function ($tag) {
                $tag->usage_count = $tag->usage_count;
                return $tag;
            });

            return response()->json($allTag, 200);  // Kembalikan isi pagination
        } catch (\Exception $e) {
            // Tangani jika ada error
            Log::error('Error occurred while fetching tag data: ' . $e->getMessage());

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
                    'errors' => 'Tag not found.'
                ]);
            }

            $tagData['usage_count'] = $tagData->usage_count;

            return response()->json([
                'data' => $tagData
            ]);
        } catch (\Exception $e) {
            Log::error('Error occured while fetching tag data: ' . $e->getMessage());
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

            if ($countTag->isEmpty()) {
                return response()->json([
                    'message' => 'Tag is empty.',
                    'tag_count' => $countTag
                ]);
            }

            return response()->json([
                'tag_count' => $countTag
            ]);
        } catch (Exception $e) {
            Log::error('Error occurred on getting tag count: ' . $e->getMessage());

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
            // Ambil jumlah item per halaman dari request, default ke 10 jika tidak ada
            $perPage = $request->query('per_page', 10);

            // Query untuk mendapatkan tag beserta usage_count
            $tags = Tags::select('tags.*')
                ->selectRaw('(
            (SELECT COUNT(*) FROM file_has_tags WHERE file_has_tags.tags_id = tags.id) +
            (SELECT COUNT(*) FROM folder_has_tags WHERE folder_has_tags.tags_id = tags.id)
            ) as usage_count')
                ->orderByDesc('usage_count') // Urutkan berdasarkan usage_count
                ->paginate($perPage);

            return response()->json($tags, 200);
        } catch (Exception $e) {

            Log::error('Error occured while fetching tag usage statistics: ' . $e->getMessage());

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
                // Custom uniqueness validation query to make it case-insensitive
                Rule::unique('tags')->where(function ($query) {
                    return $query->whereRaw('LOWER(name) = ?', [strtolower(request('name'))]);
                }),
                'regex:/^[a-zA-Z\s]+$/',
            ],
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

            Log::error('Error occured while creating tag: ' . $e->getMessage());

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
                return response()->json([
                    'errors' => 'Example file not found.'
                ], 500);
            }

            // Mengembalikan respons untuk mendownload file
            return Storage::download($filePath, 'TagImport_Example.xlsx');
        } catch (Exception $e) {
            // Log error jika terjadi exception
            Log::error('Error occurred while downloading example file: ' . $e->getMessage());

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

        try {
            DB::beginTransaction();

            // Inisialisasi objek TagImport
            $tagImport = new TagImport;

            // Lakukan import menggunakan Laravel Excel
            Excel::import($tagImport, $request->file('file'));

            // Ambil jumlah tag yang invalid dan duplikat
            $invalidCount = $tagImport->getInvalidTagsCount();
            $duplicateCount = $tagImport->getDuplicateTagsCount();

            DB::commit();

            // Kembalikan respon sukses, dengan informasi mengenai tag yang invalid dan duplikat
            return response()->json([
                'message' => ($invalidCount || $duplicateCount) ? 'The tag was successfully imported, but there are invalid or duplicate tag names.' : 'Tags imported successfully.',
                'invalid_tags_total' => $invalidCount,
                'duplicate_tags_total' => $duplicateCount
            ], 200);
        } catch (MissingColumnException $e) {
            DB::rollBack();

            // Tangani error ketika kolom tidak ditemukan
            Log::error($e->getMessage());

            return response()->json([
                'errors' => $e->getMessage()
            ], 400);
        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Error occured while importing tags: ' . $e->getMessage());

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
                // Ignore the current tag's ID during the uniqueness check
                Rule::unique('tags')->where(function ($query) use ($request, $id) {
                    return $query->whereRaw('LOWER(name) = ?', [strtolower($request->name)])
                        ->where('id', '!=', $id); // Exclude the current tag ID
                }),
                'regex:/^[a-zA-Z\s]+$/', // Prevent mixed letters/numbers (can adjust if needed)
            ],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        try {

            $tag = Tags::find($id);

            if (!$tag) {
                return response()->json([
                    'errors' => 'Tag not found.'
                ], 404);
            }

            if ($tag->name == "Root") {
                return response()->json([
                    'errors' => 'You cannot change Root tag!'
                ]);
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

            Log::error('Error occured while updating tag: ' . $e->getMessage());

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

        // Jika tidak ada $id, validasi bahwa tag_ids dikirim dalam request
        $validator = Validator::make($request->all(), [
            'tag_ids' => 'required|array',
            'tag_ids.*' => 'integer|exists:tags,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Ambil daftar tag_ids dari request
        $tagIds = $request->tag_ids;

        try {
            $tags = Tags::whereIn('id', $tagIds)->get();

            DB::beginTransaction();

            foreach ($tags as $tag) {
                if ($tag->name == "Root") {
                    return response()->json([
                        'errors' => 'You cannot delete Root tag!',
                        'root_tag' => [
                            'id' => $tag->id,
                            'name' => $tag->name
                        ]
                    ]);
                }

                // Cek apakah ada data pivot untuk folders
                if ($tag->folders()->exists()) {
                    $tag->folders()->detach(); // Hapus relasi folder jika ada
                }

                // Cek apakah ada data pivot untuk files
                if ($tag->files()->exists()) {
                    $tag->files()->detach(); // Hapus relasi file jika ada
                }

                $tag->delete();
            }

            DB::commit();

            return response()->json([
                'message' => 'Tag deleted successfully.',
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error occured while deleting tag: ' . $e->getMessage());

            return response()->json([
                'errors' => 'An error occurred while deleting tag.'
            ], 500);
        }
    }
}
