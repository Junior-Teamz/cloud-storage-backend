<?php

namespace App\Http\Controllers;

use AMWScan\Scanner;
use App\Models\LegalBasis;
use App\Services\CheckAdminService;
use App\Services\GenerateURLService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Sqids\Sqids;

class LegalBasisController extends Controller
{
    protected $GenerateURLService;

    public function __construct(GenerateURLService $GenerateURLService)
    {
        // Simpan service ke dalam property
        $this->GenerateURLService = $GenerateURLService;
    }

    private function checkAdmin()
    {
        $user = Auth::user();

        if ($user->hasRole('admin') && $user->is_superadmin == 1) {
            return true;
        }

        return false;
    }

    public function getAll()
    {
        try {
            // Ambil semua dasar hukum
            $allLegalBasis = LegalBasis::all();

            // Tambahkan link file ke setiap dasar hukum
            $allLegalBasis->transform(function ($legalBasis) {
                $legalBasis->file_url = $this->GenerateURLService->generateUrlForLegalBasis($legalBasis->id);
                return $legalBasis;
            });

            $allLegalBasis->makeHidden('file_path');

            return response()->json([
                'message' => 'Legal basis fetched successfully.',
                'data' => $allLegalBasis
            ], 200);
        } catch (Exception $e) {
            Log::error('Error occurred while fetching all legal basis: ' . $e->getMessage());

            return response()->json([
                'errors' => 'An error occurred while fetching all legal basis.'
            ], 500);
        }
    }

    public function getSpesificLegalBasis($id)
    {
        $checkAdmin = $this->checkAdmin();

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You do not have permission to perform this action.'
            ], 403);
        }

        try {
            $legalBasis = LegalBasis::where('uuid', $id)->first();

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
                'id' => $id
            ]);

            return response()->json([
                'errors' => 'An error occured while fetching legal basis with id.'
            ], 500);
        }
    }

    public function save(Request $request)
    {
        $checkAdmin = $this->checkAdmin();

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
            'file.max' => 'File is too large, max size is 2MB',
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

            // Path sementara
            $tempPath = storage_path('app/temp/' . $fileName);
            $file->move(storage_path('app/temp'), $fileName);

            // Pemindaian file dengan PHP Antimalware Scanner
            $scanner = new Scanner();
            $scanResult = $scanner->setPathScan($tempPath)->run();

            if ($scanResult->detected >= 1) {
                // Hapus file jika terdeteksi virus
                if (file_exists($tempPath)) {
                    unlink($tempPath);
                }
                DB::rollBack();
                return response()->json(['errors' => 'File detected as malicious.'], 422);
            }

            // Nama folder untuk menyimpan thumbnail
            $fileDirectory = 'dasar_hukum';

            // Cek apakah folder news_thumbnail ada di disk public, jika tidak, buat folder tersebut
            if (!Storage::disk('public')->exists($fileDirectory)) {
                Storage::disk('public')->makeDirectory($fileDirectory);
            }

            // Simpan file baru ke storage/app/public/dasar_hukum
            $filePath = $file->store($fileDirectory, 'public');

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

            Log::error('Error occured while saving legal basis: ' . $e->getMessage());
            return response()->json([
                'errors' => 'An error occurred while saving legal basis.'
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $checkAdmin = $this->checkAdmin();

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        // Validasi input
        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:255',
            'file' => 'nullable|file|max:2000|mimes:pdf,docx,doc'
        ], [
            'file.max' => 'File is too large, max size is 2MB',
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
            $legalBasis = LegalBasis::where('uuid', $id)->first();

            // Perbarui nama jika ada
            if ($request->has('name')) {
                $legalBasis->name = $request->name;
            }

            // Periksa apakah ada file yang diunggah
            if ($request->hasFile('file')) {
                $file = $request->file('file');
                $fileName = $file->getClientOriginalName();

                // Hapus file lama dari storage jika ada
                if ($legalBasis->file_path && Storage::exists($legalBasis->file_path)) {
                    Storage::delete($legalBasis->file_path);
                }

                // Nama folder untuk menyimpan thumbnail
                $fileDirectory = 'dasar_hukum';

                // Cek apakah folder news_thumbnail ada di disk public, jika tidak, buat folder tersebut
                if (!Storage::disk('public')->exists($fileDirectory)) {
                    Storage::disk('public')->makeDirectory($fileDirectory);
                }

                // Simpan file baru ke storage/app/public/dasar_hukum
                $filePath = $file->store($fileDirectory, 'public');

                $fileUrl = Storage::disk('public')->url($filePath);

                // Perbarui nama file dan path di database
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

            Log::error('Error occurred while updating legal basis: ' . $e->getMessage());

            return response()->json([
                'errors' => 'An error occurred while updating legal basis.'
            ], 500);
        }
    }

    public function delete($id)
    {
        $checkAdmin = $this->checkAdmin();

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        try {
            DB::beginTransaction();

            // Temukan dasar hukum berdasarkan ID
            $legalBasis = LegalBasis::where('uuid', $id)->first();

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

            Log::error('Error occurred while deleting legal basis: ' . $e->getMessage());

            return response()->json([
                'errors' => 'An error occurred while deleting the legal basis.'
            ], 500);
        }
    }
}
