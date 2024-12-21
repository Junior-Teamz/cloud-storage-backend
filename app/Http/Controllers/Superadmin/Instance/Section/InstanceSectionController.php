<?php

namespace App\Http\Controllers\Superadmin\Instance\Section;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\InstanceSection;
use App\Models\Instance;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class InstanceSectionController extends Controller
{
    public function getAllSections($instanceId)
    {
        try {
            $instance = Instance::with('instance')->find($instanceId);

            if (!$instance) {
                return response()->json([
                    'errors' => 'Instance not found.'
                ], 404);
            }

            $sections = $instance->sections;

            return response()->json([
                'sections' => $sections
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

    public function getInstanceSection($instanceSectionId)
    {
        try {
            $instanceSection = InstanceSection::with('instance')->find($instanceSectionId);

            if (!$instanceSection) {
                return response()->json([
                    'errors' => 'Instance Section not found.'
                ], 404);
            }

            return response()->json([
                'data' => $instanceSection
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

            return response()->json([
                'message' => 'Instance section created successfully.',
                'data' => $newSection
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

    public function updateInstanceSection(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'instance_id' => 'required|string|max:255|exists:instances,id',
            'instance_section_id' => 'required|string|max:255|exists:instance_sections,id',
            'name' => 'string|max:255'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 400);
        }

        try {
            $instanceSection = InstanceSection::where('id', $request->instance_section_id)
                ->where('instance_id', $request->instance_id)
                ->first();

            if (!$instanceSection) {
                return response()->json([
                    'errors' => 'Instance Section not found.'
                ], 404);
            }

            DB::beginTransaction();

            $instanceSection->name = $request->name;
            $instanceSection->save();

            DB::commit();

            return response()->json([
                'message' => 'Instance section updated successfully.',
                'data' => $instanceSection
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

    public function deleteInstanceSection($instanceId, $instanceSectionId)
    {
        try {
            $instanceSection = InstanceSection::where('id', $instanceSectionId)
                ->where('instance_id', $instanceId)
                ->first();

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
