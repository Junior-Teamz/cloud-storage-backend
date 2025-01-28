<?php

namespace App\Http\Controllers\Admin\Instances\Sections;

use App\Http\Controllers\Controller;
use App\Http\Resources\Instance\Section\InstanceSectionCollection;
use App\Http\Resources\Instance\Section\InstanceSectionResource;
use App\Models\InstanceSection;
use App\Services\CheckAdminService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

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

            // Ambil semua section dari instansi tersebut dengan eager loading relasi instance
            $sections = $instance->sections()->with('instance')->get(); // Relasi sections di model Instance

            return new InstanceSectionCollection($sections);
        } catch (Exception $e) {
            // Log error dan kembalikan response error
            Log::error('Error occurred while fetching sections: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred while fetching sections.'
            ], 500);
        }
    }

    public function getInstanceSectionById($instanceSectionId)
    {
        $userLogin = Auth::user();

        $checkPermission = $this->checkAdminService->checkAdminWithPermission('instance.section.read');

        if (!$checkPermission) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        try {
            $userLoginInstance = $userLogin->instances()->first();
            $instanceSectionData = InstanceSection::with('instance')->where('id', $instanceSectionId)->first();

            if (!$instanceSectionData) {
                return response()->json([
                    'errors' => 'Instance Section not found.'
                ], 404);
            }

            if ($instanceSectionData->instance->id !== $userLoginInstance->id) {
                return response()->json([
                    'errors' => 'You are not allowed to get other instance section data.'
                ], 403);
            }

            return response()->json([
                'data' => new InstanceSectionResource($instanceSectionData)
            ], 200);
        } catch (Exception $e) {
            Log::error('Error while getting instance section by id: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);
        }
    }

    public function createNewInstanceSection(Request $request)
    {
        $userLogin = Auth::user();

        $checkPermission = $this->checkAdminService->checkAdminWithPermission('instance.section.create');

        if (!$checkPermission) {
            return response()->json([
                'errors' => 'Instance Section not found.'
            ], 404);
        }

        $validator = Validator::make([
            'name' => 'string|max:255'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 400);
        }

        try {
            // validate if instance section name is already exists with the same instance
            $instance = $userLogin->instances()->first();

            $existingSection = $instance->sections()->whereRaw('LOWER(name) = ?', [strtolower($request->name)])->first();

            if ($existingSection) {
                return response()->json([
                    'errors' => 'Instance section with the same name already exists.'
                ], 400);
            }

            DB::beginTransaction();

            $newSection = InstanceSection::create([
                'name' => $request->name,
                'instance_id' => $instance->id
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Instance section created successfully.',
                'data' => new InstanceSectionResource($newSection)
            ], 201);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Error while creating instance section: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred while creating the instance section.'
            ], 500);
        }
    }

    public function updateInstanceSection(Request $request, $instanceSectionId)
    {
        $userLogin = Auth::user();

        $checkPermission = $this->checkAdminService->checkAdminWithPermission('instance.section.update');

        if (!$checkPermission) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 400);
        }

        try {
            $userLoginInstance = $userLogin->instances()->first();
            $instanceSection = InstanceSection::with('instance')->where('id', $instanceSectionId)->first();

            if (!$instanceSection) {
                return response()->json([
                    'errors' => 'Instance Section not found.'
                ], 404);
            }

            if ($instanceSection->instance->id !== $userLoginInstance->id) {
                return response()->json([
                    'errors' => 'You are not allowed to update other instance section data.'
                ], 403);
            }

            // Check if name is same as current name
            if ($instanceSection->name === $request->name) {
                return response()->json([
                    'errors' => 'New section name must be different from current name.'
                ], 422);
            }

            // Check if name exists in other sections of same instance
            $nameExists = $instanceSection->instance->sections
                ->where('name', $request->name)
                ->where('id', '!=', $instanceSectionId)
                ->isNotEmpty();

            if ($nameExists) {
                return response()->json([
                    'errors' => 'Section name already exists in this instance.'
                ], 422);
            }

            DB::beginTransaction();

            $instanceSection->name = $request->name;
            $instanceSection->save();

            DB::commit();

            return response()->json([
                'message' => 'Instance section updated successfully.',
                'data' => new InstanceSectionResource($instanceSection)
            ], 200);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Error while updating instance section: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred while updating the instance section.'
            ], 500);
        }
    }

    public function deleteInstanceSection($instanceSectionId)
    {
        $userLogin = Auth::user();

        $checkPermission = $this->checkAdminService->checkAdminWithPermission('instance.section.delete');

        if (!$checkPermission) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        try {
            $userLoginInstance = $userLogin->instances()->first();
            $instanceSection = InstanceSection::with('instance')->where('id', $instanceSectionId)->first();

            if (!$instanceSection) {
                return response()->json([
                    'errors' => 'Instance Section not found.'
                ], 404);
            }

            if ($instanceSection->instance->id !== $userLoginInstance->id) {
                return response()->json([
                    'errors' => 'You are not allowed to delete other instance section data.'
                ], 403);
            }

            DB::beginTransaction();

            $instanceSection->delete();

            DB::commit();

            return response()->json([
                'message' => 'Instance section deleted successfully.'
            ], 200);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Error while deleting instance section: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred while deleting the instance section.'
            ], 500);
        }
    }
}
