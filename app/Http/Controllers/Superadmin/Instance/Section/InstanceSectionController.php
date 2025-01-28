<?php

namespace App\Http\Controllers\Superadmin\Instance\Section;

use App\Http\Controllers\Controller;
use App\Http\Resources\Instance\Section\InstanceSectionCollection;
use App\Http\Resources\Instance\Section\InstanceSectionResource;
use Illuminate\Http\Request;
use App\Models\InstanceSection;
use App\Models\Instance;
use App\Services\CheckAdminService;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class InstanceSectionController extends Controller
{
    protected $checkAdminService;

    public function __construct(CheckAdminService $checkAdminService)
    {
        $this->checkAdminService = $checkAdminService;
    }

    public function getAllSections()
    {
        $checkAdmin = $this->checkAdminService->checkSuperAdmin();

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        try {
            $instanceSection = InstanceSection::with('instance')->paginate(10);

            return response()->json([
                'data' => new InstanceSectionCollection($instanceSection),
                'pagination' => [
                    'current_page' => $instanceSection->currentPage(),
                    'last_page' => $instanceSection->lastPage(),
                    'per_page' => $instanceSection->perPage(),
                    'total' => $instanceSection->total(),
                ]
            ], 200);
        } catch (Exception $e) {
            Log::error('Error occurred while fetching sections: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred while fetching sections.'
            ], 500);
        }
    }

    public function getInstanceSectionDetail($instanceSectionId)
    {
        $checkAdmin = $this->checkAdminService->checkSuperAdmin();

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        try {
            $instanceSection = InstanceSection::with('instance')->find($instanceSectionId);

            if (!$instanceSection) {
                return response()->json([
                    'errors' => 'Instance Section not found.'
                ], 404);
            }

            return response()->json([
                'data' => new InstanceSectionResource($instanceSection)
            ], 200);
        } catch (Exception $e) {
            Log::error('Error occurred while fetching instance section: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred while fetching the instance section.'
            ], 500);
        }
    }

    public function createNewInstanceSection(Request $request)
    {
        $checkAdmin = $this->checkAdminService->checkSuperAdmin();

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'instance_id' => 'required|string|max:255|exists:instances,id',
            'name' => 'string|max:255'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 400);
        }

        try {
            $instance = Instance::find($request->instance_id);

            if (!$instance) {
                return response()->json([
                    'errors' => 'Instance not found.'
                ], 404);
            }

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

            $newSection->load('instance');

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
        $checkAdmin = $this->checkAdminService->checkSuperAdmin();

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'new_name' => 'required|string|max:255'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 400);
        }

        try {
            $instanceSection = InstanceSection::with('instance')->where('id', $instanceSectionId)->first();

            if (!$instanceSection) {
                return response()->json([
                    'errors' => 'Instance Section not found.'
                ], 404);
            }

            if (strtolower($instanceSection->name) === strtolower($request->new_name)) {
                return response()->json([
                    'errors' => 'The new name must be different from the current name.'
                ], 400);
            }

            $nameExists = $instanceSection->instance->sections
                ->where('name', $request->new_name)
                ->where('id', '!=', $instanceSectionId)
                ->isNotEmpty();

            if ($nameExists) {
                return response()->json([
                    'errors' => 'The new name already exists in the same instance.'
                ], 400);
            }

            DB::beginTransaction();

            $instanceSection->name = $request->new_name;
            $instanceSection->save();

            DB::commit();

            $instanceSection->load('instance');

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
        $checkAdmin = $this->checkAdminService->checkSuperAdmin();

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        try {
            $instanceSection = InstanceSection::where('id', $instanceSectionId)->first();

            if (!$instanceSection) {
                return response()->json([
                    'errors' => 'Instance Section not found.'
                ], 404);
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
