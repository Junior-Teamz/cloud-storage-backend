<?php

namespace App\Http\Controllers;

use App\Models\FAQ;
use App\Services\CheckAdminService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class FAQController extends Controller
{
    protected $checkAdminService;

    // Inject RoleService ke dalam constructor
    public function __construct(CheckAdminService $checkAdminService)
    {
        $this->checkAdminService = $checkAdminService;
    }

    /**
     * Display a listing of the FAQs.
     *
     * This method retrieves a paginated list of FAQs, optionally filtered by a search query.
     * 
     * Requires admin authentication.
     *
     * @param  \Illuminate\Http\Request  $request The incoming HTTP request containing optional search parameters.
     * @return \Illuminate\Http\JsonResponse A JSON response containing the list of FAQs or an error message.
     */
    public function index(Request $request)
    {
        // Mengecek apakah pengguna adalah admin
        $checkAdmin = $this->checkAdminService->checkAdmin();

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You do not have permission to fetch FAQs.'
            ], 403);
        }

        try {
            // Ambil query pencarian dari request jika ada
            $searchQuery = $request->query('name');

            $faqQuery = FAQ::query();

            // Jika ada query pencarian, filter berdasarkan nama
            if ($searchQuery) {
                $faqs = $faqQuery->where('name', 'like', '%' . $searchQuery . '%')->paginate(10); // Filter by name with pagination
            } else {
                // Jika tidak ada query pencarian, tampilkan semua FAQ dengan pagination
                $faqs = $faqQuery->paginate(10);
            }

            // Jika tidak ada FAQ yang ditemukan
            if (!$faqs) {
                return response()->json([
                    'message' => 'No FAQs found.',
                    'data' => []
                ], 200);
            }

            // Kembalikan response JSON dengan data FAQ yang ditemukan
            return response()->json([
                'data' => $faqs
            ], 200);
        } catch (\Exception $e) {
            // Log error jika terjadi exception
            Log::error('An error occurred while fetching FAQs: ' . $e->getMessage(), [
                'trace' => $e->getTrace() 
            ]);

            return response()->json([
                'errors' => 'An error occurred while fetching FAQs.'
            ], 500);
        }
    }

    /**
     * Display a specific FAQ.
     *
     * This method retrieves a single FAQ by its ID.
     * 
     * Requires admin authentication.
     *
     * @param  int  $id The ID of the FAQ to retrieve.
     * @return \Illuminate\Http\JsonResponse A JSON response containing the FAQ or an error message.
     */
    public function showSpesificFAQ($id)
    {
        $checkAdmin = $this->checkAdminService->checkAdmin();

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You do not have permission to fetch FAQs.'
            ], 403);
        }

        try {

            $faq = FAQ::where('id', $id)->first();
            if (!$faq) {
                return response()->json([
                    'message' => 'FAQ not found.',
                    'data' => []
                ], 200);
            }

            return response()->json([
                'data' => $faq
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error occured while fetching spesific FAQ: ' . $e->getMessage(), [
                'trace' => $e->getTrace() 
            ]);

            return response()->json([
                'errors' => 'An error occurred while fetching spesific FAQ.'
            ], 500);
        }
    }

    /**
     * Create a new FAQ.
     *
     * This method creates a new FAQ entry in the database.
     * 
     * Requires admin authentication.
     *
     * @param  \Illuminate\Http\Request  $request The incoming HTTP request containing the FAQ data.
     * @return \Illuminate\Http\JsonResponse A JSON response indicating success or failure.
     */
    public function store(Request $request)
    {
        $checkAdmin = $this->checkAdminService->checkAdmin();

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You do not have permission to create FAQs.'
            ], 403);
        }

        $validator = Validator::make(
            $request->all(),
            [
                'question' => 'required|string',
                'answer' => 'required|string',
            ],
        );

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $faq = FAQ::create([
                'question' => $request->question,
                'answer' => $request->answer
            ]);

            DB::commit();

            return response()->json([
                'message' => 'FAQ created successfully',
                'data' => $faq
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('An error occurred while creating FAQ: ' . $e->getMessage(), [
                'trace' => $e->getTrace() 
            ]);

            return response()->json([
                'errors' => 'An error occurred while creating FAQ.'
            ], 500);
        }
    }

    /**
     * Update an existing FAQ.
     *
     * This method updates an existing FAQ entry in the database.
     * 
     * Requires admin authentication.
     * 
     * @param  \Illuminate\Http\Request  $request The incoming HTTP request containing the updated FAQ data.
     * @param  int  $id The ID of the FAQ to update.
     * @return \Illuminate\Http\JsonResponse A JSON response indicating success or failure.
     */
    public function update(Request $request, $id)
    {
        $checkAdmin = $this->checkAdminService->checkAdmin();

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You do not have permission to update FAQs.'
            ], 403);
        }

        $faq = FAQ::where('id', $id)->first();

        if (!$faq) {
            return response()->json([
                'errors' => 'FAQ not found'
            ], 404);
        }

        $validator = Validator::make(
            $request->all(),
            [
                'question' => 'required|string',
                'answer' => 'required|string',
            ],
        );

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $faq->question = $request->question;
            $faq->answer = $request->answer;
            $faq->save();

            DB::commit();

            return response()->json([
                'message' => 'FAQ updated successfully',
                'data' => $faq
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('An error occurred while updating FAQ: ' . $e->getMessage(), [
                'trace' => $e->getTrace() 
            ]);

            return response()->json([
                'errors' => 'An error occurred while updating FAQ.'
            ], 500);
        }
    }

    /**
     * Delete an existing FAQ.
     *
     * This method deletes an existing FAQ entry from the database.
     * 
     * Requires admin authentication.
     * 
     * @param  int  $id The ID of the FAQ to delete.
     * @return \Illuminate\Http\JsonResponse A JSON response indicating success or failure.
     */
    public function destroy($id)
    {
        $checkAdmin = $this->checkAdminService->checkAdmin();

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You do not have permission to delete FAQs.'
            ], 403);
        }

        $faq = FAQ::where('id', $id)->first();

        if (!$faq) {
            return response()->json([
                'errors' => 'FAQ not found'
            ], 404);
        }

        try {
            DB::beginTransaction();

            $faq->delete();
            
            DB::commit();

            return response()->json([
                'message' => 'FAQ deleted successfully',
                'data' => $faq
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('An error occurred while deleting FAQ: ' . $e->getMessage(), [
                'trace' => $e->getTrace() 
            ]);

            return response()->json([
                'errors' => 'An error occurred while deleting FAQ.'
            ], 500);
        }
    }
}
