<?php

namespace App\Http\Controllers\Admin\Instances\Sections;

use App\Http\Controllers\Controller;
use App\Services\CheckAdminService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class InstanceSectionController extends Controller
{
    protected $checkAdminService;

    // Inject CheckAdminService class ke dalam constructor
    public function __construct(CheckAdminService $checkAdminService)
    {
        $this->checkAdminService = $checkAdminService;
    }

    /**
     * Get all section data from instance registered with the admin
     */
    public function getAllSections()
    {
        $userLogin = Auth::user(); // Ambil user yang sedang login

        // Periksa izin user
        $checkPermission = $this->checkAdminService->checkAdminWithPermission('instance.section.read');

        if (!$checkPermission) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        try {
            // Ambil instansi terkait user yang sedang login
            $instance = $userLogin->instances()->first(); // Asumsi 1 user hanya memiliki 1 instansi

            if (!$instance) {
                return response()->json([
                    'errors' => 'No instance is registered for the current user.'
                ], 404);
            }

            // Ambil semua section dari instansi tersebut
            $sections = $instance->sections; // Relasi sections di model Instance

            return response()->json([
                'sections' => $sections
            ], 200);
        } catch (Exception $e) {
            // Log error dan kembalikan response error
            Log::error('Error fetching sections: ' . $e->getMessage());

            return response()->json([
                'errors' => 'An error occurred while fetching sections.'
            ], 500);
        }
    }
}
