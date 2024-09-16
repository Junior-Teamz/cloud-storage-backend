<?php

namespace App\Http\Controllers;

use App\Models\Tags;
use App\Services\CheckAdminService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

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
            if ($request->query('name')) {
                $keywordName = $request->query('name');

                $allTag = Tags::where('name', 'like', '%' . $keywordName . '%')->paginate(10);

                if ($allTag->isEmpty()) {
                    return response()->json([
                        'errors' => 'Tag data not found.'
                    ], 404);
                }

                return response()->json($allTag, 200);  // Kembalikan isi pagination tanpa membungkus lagi
            } else {
                $allTag = Tags::paginate(10);

                return response()->json($allTag, 200);
            }
        } catch (\Exception $e) {

            Log::error('Error occured while fetching tag data: ' . $e->getMessage());

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

    public function store(Request $request)
    {
        $checkAdmin = $this->checkAdminService->checkAdmin();

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        DB::beginTransaction();
        try {
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

            $tag = Tags::create([
                'name' => $request->name
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

        DB::beginTransaction();
        try {

            $tag = Tags::find($id);

            if (!$tag) {
                return response()->json([
                    'errors' => 'Tag not found.'
                ], 404);
            }

            // Update the tag
            $tag->name = $request->name;
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

        DB::beginTransaction();

        try {
            $tags = Tags::whereIn('id', $tagIds)->get();

            foreach($tags as $tag){
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
