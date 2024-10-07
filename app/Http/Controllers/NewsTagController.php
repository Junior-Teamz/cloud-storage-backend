<?php

namespace App\Http\Controllers;

use App\Models\News;
use App\Models\NewsTag;
use App\Services\CheckAdminService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class NewsTagController extends Controller
{
    protected $checkAdminService;

    // Inject RoleService ke dalam constructor
    public function __construct(CheckAdminService $checkAdminService)
    {
        $this->checkAdminService = $checkAdminService;
    }


    public function index(Request $request)
    {
        $checkAdmin = $this->checkAdminService->checkAdmin();

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        try {
            if ($request->query('name')) {
                $keywordName = $request->query('name');

                $allTag = NewsTag::where('name', 'like', '%' . $keywordName . '%')->paginate(10);

                if ($allTag->isEmpty()) {
                    return response()->json([
                        'errors' => 'News Tag is empty.'
                    ], 404);
                }

                return response()->json($allTag, 200);  // Kembalikan isi pagination tanpa membungkus lagi
            } else {

                $allTag = NewsTag::paginate(10);

                return response()->json($allTag, 200);
            }
        } catch (\Exception $e) {
            Log::error('Error occured while fetching tag data: ' . $e->getMessage());

            return response()->json([
                'errors' => 'An error occurred while fetching tag data.'
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

        $validator = Validator::make($request->all(), [
            'name' => [
                'required',
                // Custom uniqueness validation query to make it case-insensitive
                Rule::unique('news_tags')->where(function ($query) {
                    return $query->whereRaw('LOWER(name) = ?', [strtolower(request('name'))]);
                }),
                'regex:/^[a-zA-Z\s]+$/',
                'max:50'
            ],
        ], [
            'name.unique' => 'News tag name already exists.',
            'name.regex' => 'News tag name can only contain letters and spaces.',
            'name.max' => 'Name tag name cannot exceed 50 characters.'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $uppercasedNewsTag = ucwords($request->name);

            DB::beginTransaction();

            $saveTag = NewsTag::create([
                'name' => $uppercasedNewsTag
            ]);

            DB::commit();

            return response()->json([
                'message' => 'News tag added successfully.',
                'data' => $saveTag
            ]);
        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Error occured while saving news tag: ' . $e->getMessage());

            return response()->json([
                'errors' => 'An error occured while adding news tag.'
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $checkAdmin = $this->checkAdminService->checkAdmin();

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ]);
        }

        $validator = Validator::make($request->all(), [
            'name' => [
                'required',
                // Ignore the current tag's ID during the uniqueness check
                Rule::unique('news_tags')->where(function ($query) use ($request, $id) {
                    return $query->whereRaw('LOWER(name) = ?', [strtolower($request->name)])
                        ->where('id', '!=', $id); // Exclude the current tag ID
                }),
                'regex:/^[a-zA-Z\s]+$/', // Prevent mixed letters/numbers (can adjust if needed)
            ],
        ], [
            'name.unique' => 'News tag name already exists.',
            'name.regex' => 'News tag name can only contain letters and spaces.'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $newsTag = NewsTag::where('uuid', $id)->first();

            if (!$newsTag){
                return response()->json([
                    'errors' => 'News tag not found.'
                ], 404);
            }

            $uppercasedNewsTag = ucwords($request->name);

            DB::beginTransaction();

            $newsTag->name = $uppercasedNewsTag;
            $newsTag->save();

            DB::commit();

            return response()->json([
                'message' => 'News tag updated successfully.',
                'data' => $newsTag
            ]);

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Error occured while updating news tag: ' . $e->getMessage());

            return response()->json([
                'errors' => 'An error occured while updating news tag.'
            ]);
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
            'news_tag_ids' => 'required|array',
        ], [
            'news_tag_ids.required' => 'news_tag_ids are required.',
            'news_tag_ids.array' => 'news_tag_ids must be an array of news tag ID.',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Ambil daftar news_tag_ids dari request
        $newsTagIds = $request->news_tag_ids;

        try {

            // Exclude "Root" tag dari query untuk menghindari penghapusan
            $newsTags = NewsTag::whereIn('uuid', $newsTagIds)->get();

            // Bandingkan ID yang ditemukan dengan yang diminta
            $foundNewsTagIds = $newsTags->pluck('id')->toArray();
            $notFoundNewsTagIds = array_diff($newsTagIds, $foundNewsTagIds);

            if (!empty($notFoundNewsTagIds)) {
                Log::info('Attempt to delete non-existent news tags: ' . implode(',', $notFoundNewsTagIds));

                return response()->json([
                    'errors' => 'Some news tags were not found.',
                    'missing_news_tag_ids' => $notFoundNewsTagIds,
                ], 404);
            }

            DB::beginTransaction();

            // Detach hubungan untuk semua news tag dalam satu batch operation
            DB::table('news_has_tags')->whereIn('tags_id', $foundNewsTagIds)->delete();

            // Hapus semua tag sekaligus
            $newsTags->delete();

            DB::commit();

            return response()->json([
                'message' => 'News Tags deleted successfully.',
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error occurred while deleting tags: ' . $e->getMessage());

            return response()->json([
                'errors' => 'An error occurred while deleting tags.'
            ], 500);
        }
    }
}
