<?php

namespace App\Http\Controllers\Instances;

use App\Http\Controllers\Controller;
use App\Http\Resources\Instance\InstanceCollection;
use App\Http\Resources\Instance\InstanceResource;
use App\Models\Instance;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class InstanceController extends Controller
{
    public function getAllInstanceData(Request $request)
    {
        $user = Auth::user();

        $validateQueryParam = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:20'
        ]);

        $perPagePaginate = $validateQueryParam['per_page'] ?? 10;

        try {
            $instanceData = Instance::paginate($perPagePaginate);

            return response()->json([
                'data' => new InstanceCollection($instanceData),
                'pagination' => [
                    'current_page' => $instanceData->currentPage(),
                    'last_page' => $instanceData->lastPage(),
                    'per_page' => $instanceData->perPage(),
                    'total' => $instanceData->total(),
                ]
            ]);
        } catch (Exception $e) {
            Log::error('Error occured while fetching all instance data.', [
                'user_id' => $user->id,
                'trace' => $e->getTrace()
            ]);
        }
    }

    public function getInstanceWithName(Request $request)
    {
        $validateQueryParam = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:20'
        ]);

        $perPagePaginate = $validateQueryParam['per_page'] ?? 10;

        try {

            $keywordName = $request->query('name');

            // Ambil hanya kolom 'id' tanpa pagination
            $instanceData = Instance::where('name', 'like', '%' . $keywordName . '%')
                ->paginate($perPagePaginate);

            if (!$instanceData) {
                return response()->json([
                    'message' => 'Instance data not found.',
                    'data' => []
                ], 200);
            }

            // Kembalikan daftar ID
            return response()->json([
                'data' => new InstanceCollection($instanceData),
                'pagination' => [
                    'current_page' => $instanceData->currentPage(),
                    'last_page' => $instanceData->lastPage(),
                    'per_page' => $instanceData->perPage(),
                    'total' => $instanceData->total(),
                ]
            ], 200);
        } catch (Exception $e) {
            Log::error('Error occured while fetching instance data from name: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);
            return response()->json([
                'errors' => 'An error occurred while fetching instance data from name.'
            ], 500);
        }
    }

    public function getInstanceDetailFromId($instanceId)
    {
        try {
            $instance = Instance::where('id', $instanceId)->first();

            if(!$instance){
                return response()->json([
                    'message' => 'Instance not found.',
                    'data' => []
                ], 200);
            }

            return response()->json([
                'data' => new InstanceResource($instance)
            ]);
            
        } catch (Exception $e) {
            Log::error('Error occured while fetching instance data from id: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);
            return response()->json([
                'errors' => 'An error occurred while fetching instance data from id.'
            ], 500);
        }
    }
}
