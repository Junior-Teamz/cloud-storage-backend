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

    public function serveFilePdfByHashedId($hashedId)
    {
        // Gunakan Sqids untuk memparse hashed ID kembali menjadi ID asli
        $sqids = new Sqids(env('SQIDS_ALPHABET'), env('SQIDS_LENGTH', 10));
        $fileIdArray = $sqids->decode($hashedId);

        if (empty($fileIdArray) || !isset($fileIdArray[0])) {
            return response()->json(['errors' => 'Invalid or non-existent file'], 404);  // File tidak valid
        }

        // Dapatkan file_id dari hasil decode
        $file_id = $fileIdArray[0];

        // Cari file berdasarkan ID
        $file = LegalBasis::find($file_id);

        if (!$file) {
            return response()->json(['errors' => 'Legal Basis not found'], 404);  // File tidak ditemukan
        }

        // Ambil path file dari storage
        $file_path = Storage::path($file->file_path);

        // Kembalikan file sebagai respon (mengirim file)
        return response()->file($file_path);
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

            // Pastikan folder legal_basis sudah ada, jika belum buat
            if (!Storage::exists('legal_basis')) {
                Storage::makeDirectory('legal_basis');
            }

            // Pindahkan file ke folder legal_basis
            $legalBasisPath = 'legal_basis/' . $fileName;
            Storage::move('temp/' . $fileName, $legalBasisPath);

            // Daftar kata penghubung yang tidak ingin dikapitalisasi
            $exceptions = ['dan', 'atau', 'di', 'ke', 'dari'];

            // Pisahkan name berdasarkan spasi
            $nameParts = explode(' ', strtolower($request->name));
            $nameFormatted = array_map(function ($word) use ($exceptions) {
                // Jika kata ada di daftar pengecualian, biarkan huruf kecil, jika tidak kapitalisasi
                return in_array($word, $exceptions) ? $word : ucfirst($word);
            }, $nameParts);

            // Gabungkan kembali menjadi string
            $formattedName = implode(' ', $nameFormatted);

            // Simpan data ke database
            $legalBasis = LegalBasis::create([
                'name' => $formattedName,
                'file_name' => $fileName,
                'file_path' => $legalBasisPath
            ]);

            DB::commit();

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
            $legalBasis = LegalBasis::findOrFail($id);

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

                // Simpan file baru ke storage
                $filePath = $file->storeAs('legal_basis', $fileName);

                // Perbarui nama file dan path di database
                $legalBasis->file_name = $fileName;
                $legalBasis->file_path = $filePath;
            }

            // Simpan perubahan ke database
            $legalBasis->save();

            DB::commit();

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
            $legalBasis = LegalBasis::findOrFail($id);

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
