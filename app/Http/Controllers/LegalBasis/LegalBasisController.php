<?php

namespace App\Http\Controllers\LegalBasis;

use App\Http\Controllers\Controller;
use App\Models\LegalBasis;
use App\Services\CheckAdminService;
use App\Services\GetPathService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * This is Legal Basis (IDN: Dasar Hukum) Controller.
 */
class LegalBasisController extends Controller
{
    protected $checkAdminService;
    protected $getPathService;

    public function __construct(CheckAdminService $checkAdminService, GetPathService $getPathServiceParam)
    {
        $this->checkAdminService = $checkAdminService;
        $this->getPathService = $getPathServiceParam;
    }

    /**
     * Get all legal basis documents.
     *
     * This method retrieves all legal basis documents from the database and returns them as a JSON response.
     * 
     * Requires superadmin authentication.
     *
     * @return \Illuminate\Http\JsonResponse A JSON response containing all legal basis documents or an error message.
     */
    public function getAll()
    {
        try {
            // Ambil semua dasar hukum
            $allLegalBasis = LegalBasis::all();

            $allLegalBasis->makeHidden('file_path');

            return response()->json([
                'message' => 'Legal basis fetched successfully.',
                'data' => $allLegalBasis
            ], 200);
        } catch (Exception $e) {
            Log::error('Error occurred while fetching all legal basis: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred while fetching all legal basis.'
            ], 500);
        }
    }

    /**
     * Get a specific legal basis document.
     *
     * This method retrieves a single legal basis document from the database based on the provided UUID.
     * It checks if the authenticated user is a superadmin and returns a 403 Forbidden response if not.
     * If the legal basis document is found, it is returned as a JSON response. If the document is not found, a 200 OK response is returned
     * with an empty data array and a message indicating that the legal basis was not found.
     * 
     * @param string $id The UUID of the legal basis document to retrieve.
     * @return \Illuminate\Http\JsonResponse A JSON response containing the legal basis document or an error message.
     */
    public function getSpesificLegalBasis($id)
    {

        try {
            $legalBasis = LegalBasis::where('id', $id)->first();

            if (!$legalBasis) {
                Log::warning('Attempt to get legal basis with not found id: ' . $id);

                return response()->json([
                    'message' => 'Legal Basis not found.',
                    'data' => []
                ], 200);
            }

            $legalBasis->makeHidden('file_path');

            return response()->json([
                'message' => 'Legal basis fetched successfully',
                'data' => $legalBasis
            ], 200);
        } catch (Exception $e) {
            Log::error('Error occurred while fetching legal basis with id: ' . $e->getMessage(), [
                'id' => $id,
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occured while fetching legal basis with id.'
            ], 500);
        }
    }

    /**
     * Save a new legal basis document.
     *
     * This method handles the upload and storage of a new legal basis document. It validates the incoming request,
     * ensuring that a name is provided and a file is uploaded with the correct format and size. The uploaded file
     * is stored in the 'dasar_hukum' directory within the public storage disk. The method then creates a new
     * LegalBasis record in the database, associating it with the uploaded file.
     * 
     * Requires superadmin authentication.
     *
     * @param  \Illuminate\Http\Request  $request The incoming HTTP request containing the legal basis document data.
     * @return \Illuminate\Http\JsonResponse A JSON response indicating success or failure, with appropriate status codes.
     */
    public function save(Request $request)
    {
        $checkAdmin = $this->checkAdminService->checkSuperAdmin();

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'file' => 'required|file|max:5120|mimes:pdf,docx,doc'
        ], [
            'name.required' => 'Name is required',
            'file.max' => 'File is too large, max size is 5MB',
            'file.mimes' => 'File must be type of pdf, docx, or doc'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $file = $request->file('file');
            $fileName = $file->getClientOriginalName();

            // Nama folder untuk menyimpan thumbnail
            $fileDirectory = 'dasar_hukum';

            // Cek apakah folder dasar_hukum ada di disk public, jika tidak, buat folder tersebut
            if (!Storage::disk('public')->exists($fileDirectory)) {
                Storage::disk('public')->makeDirectory($fileDirectory);
            }

            // Simpan file thumbnail ke storage/app/public/dasar_hukum
            $filePath = $file->store($fileDirectory, 'public');

            // Buat URL publik untuk thumbnail
            $fileUrl = Storage::disk('public')->url($filePath);

            // Daftar kata penghubung yang tidak ingin dikapitalisasi
            $exceptions = ['dan', 'atau', 'di', 'ke', 'dari'];

            // Format nama
            $nameParts = explode(' ', strtolower($request->name));
            $nameFormatted = array_map(function ($word) use ($exceptions) {
                return in_array($word, $exceptions) ? $word : ucfirst($word);
            }, $nameParts);
            $formattedName = implode(' ', $nameFormatted);

            // Simpan data ke database
            $legalBasis = LegalBasis::create([
                'name' => $formattedName,
                'file_name' => $fileName,
                'file_path' => $filePath,
                'file_url' => $fileUrl
            ]);

            DB::commit();

            $legalBasis->makeHidden('file_path');

            return response()->json([
                'message' => 'Legal basis successfully saved.',
                'data' => $legalBasis
            ], 201);
        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Error occurred while saving legal basis: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);
            return response()->json([
                'errors' => 'An error occurred while saving legal basis.'
            ], 500);
        }
    }

    /**
     * Update an existing legal basis document.
     *
     * This method handles the update of an existing legal basis document. It validates the incoming request,
     * ensuring that the provided name is a string with a maximum length of 255 characters and that the uploaded
     * file, if any, has the correct format and size. If a new file is uploaded, the old file is deleted from
     * storage. The method then updates the LegalBasis record in the database with the new data.
     * 
     * Requires superadmin authentication.
     *
     * @param  \Illuminate\Http\Request  $request The incoming HTTP request containing the updated legal basis document data.
     * @param string $id The UUID of the legal basis document to update.
     * @return \Illuminate\Http\JsonResponse A JSON response indicating success or failure, with appropriate status codes.
     */
    public function update(Request $request, $id)
    {
        $checkAdmin = $this->checkAdminService->checkSuperAdmin();

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        // Validasi input
        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:255',
            'file' => 'nullable|file|max:5120|mimes:pdf,docx,doc'
        ], [
            'file.max' => 'File is too large, max size is 5MB',
            'file.mimes' => 'File must be type of pdf, docx, or doc'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Temukan dasar hukum berdasarkan ID
            $legalBasis = LegalBasis::where('id', $id)->first();

            // Perbarui nama jika ada
            if ($request->filled('name')) {
                $legalBasis->name = $request->name;
            }    

            // Periksa apakah ada file yang diunggah
            if ($request->hasFile('file')) {
                $file = $request->file('file');
                $fileName = $file->getClientOriginalName();

                // Nama folder untuk menyimpan file
                $fileDirectory = 'dasar_hukum';

                // Cek apakah ada file lama dan hapus jika ada
                if ($legalBasis->file_path && Storage::disk('public')->exists($legalBasis->file_path)) {
                    Storage::disk('public')->delete($legalBasis->file_path);
                }

                // Simpan file file ke storage/app/public/dasar_hukum
                $filePath = $file->store($fileDirectory, 'public');

                // Buat URL publik untuk file
                $fileUrl = Storage::disk('public')->url($filePath);

                // Perbarui nama file, path, dan URL di database
                $legalBasis->file_name = $fileName;
                $legalBasis->file_path = $filePath;
                $legalBasis->file_url = $fileUrl;
            }

            // Simpan perubahan ke database
            $legalBasis->save();

            DB::commit();

            $legalBasis->makeHidden('file_path');

            return response()->json([
                'message' => 'Legal basis updated successfully.',
                'data' => $legalBasis
            ], 200);
        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Error occurred while updating legal basis: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred while updating legal basis.'
            ], 500);
        }
    }

    /**
     * Delete a legal basis document.
     *
     * This method deletes a legal basis document from the database and storage. It first checks if the authenticated
     * user is a superadmin. If not, a 403 Forbidden response is returned. If the legal basis document exists,
     * it is deleted from both the database and storage.
     * 
     * Requires superadmin authentication.
     * 
     * **Caution:** Deleting a legal basis document is a destructive action and cannot be undone. Ensure that the 
     * document is no longer needed before proceeding with the deletion.
     *
     * @param string $id The UUID of the legal basis document to delete.
     * @return \Illuminate\Http\JsonResponse A JSON response indicating success or failure, with appropriate status codes.
     */
    public function delete($id)
    {
        $checkAdmin = $this->checkAdminService->checkSuperAdmin();

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        try {
            DB::beginTransaction();

            // Temukan dasar hukum berdasarkan ID
            $legalBasis = LegalBasis::where('id', $id)->first();

            // Hapus file dari storage jika ada
            if ($legalBasis->file_path && Storage::exists($legalBasis->file_path)) {
                Storage::delete($legalBasis->file_path);
            }

            // Hapus data dasar hukum dari database
            $legalBasis->delete();

            DB::commit();

            return response()->json([
                'message' => 'Legal basis deleted successfully.'
            ], 200);
        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Error occurred while deleting legal basis: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred while deleting the legal basis.'
            ], 500);
        }
    }
}
