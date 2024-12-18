<?php

namespace App\Http\Controllers\Admin\Instances;

use App\Http\Controllers\Controller;
use App\Http\Resources\Instance\InstanceResource;
use App\Models\Instance;
use App\Models\User;
use App\Services\CheckAdminService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class InstanceController extends Controller
{
    protected $checkAdminService;

    // Inject CheckAdminService class ke dalam constructor
    public function __construct(CheckAdminService $checkAdminService)
    {
        $this->checkAdminService = $checkAdminService;
    }

    /**
     * Retrieving information about instances registered under the admin.
     */
    public function index()
    {
        $user = Auth::user();

        $checkAdmin = $this->checkAdminService->checkAdminWithPermission('instance.read');

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        try {
            $userData = User::where('id', $user->id)->first();

            $instanceData = $userData->instances()->first();

            return response()->json([
                'data' => new InstanceResource($instanceData)
            ], 200);
        } catch (Exception $e) {

            Log::error('Error occured while fetching admin instance data: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred while fetching admin instance data.'
            ], 500);
        }
    }

    /**
     * Update current admin registered instance.
     */
    public function updateInstance(Request $request)
    {
        $user = Auth::user();

        $checkAdmin = $this->checkAdminService->checkAdminWithPermission('instance.update');

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        $validator = Validator::make(
            $request->all(),
            [
                'name' => [
                    'required',
                    'string',
                    // Custom uniqueness validation query to make it case-insensitive
                    Rule::unique('instances')->where(function ($query) use ($request) {
                        return $query->whereRaw('LOWER(name) = ?', [strtolower($request->name)]);
                    }),
                    'regex:/^[a-zA-Z0-9\s.,()\-]+$/',
                    'max:255'
                ],
                'address' => 'required|string|max:255',
            ],
            [
                'name.required' => 'Instance name is required.',
                'name.string' => 'Instance name must be string.',
                'name.unique' => 'Instance name already exists.',
                'name.regex' => 'Instance name is not valid.',
                'name.max' => 'Instance name cannot exceed 255 characters.',
            ]
        );

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $userData = User::where('id', $user->id)->first();

            $userInstance = $userData->instances()->first();

            $uppercasedInstanceName = ucwords($request->name);

            DB::beginTransaction();

            $userInstance->update([
                'name' => $uppercasedInstanceName,
                'address' => $request->address,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Instance updated successfully.',
                'data' => new InstanceResource($userInstance)
            ], 200);
        } catch (Exception $e) {

            DB::rollBack();

            Log::error('Error occurred while updating instance from admin: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred while updating instance.'
            ], 500);
        }
    }
}
