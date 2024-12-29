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
     * Get the instance information associated with the authenticated admin user.
     *
     * This method checks if the authenticated user has the necessary permissions
     * ('instance.read') to view an instance. If the user does not have the required permissions,
     * a 403 Forbidden response is returned. If the user has the required permissions,
     * the method attempts to fetch the instance associated with the user.
     *
     * @return \Illuminate\Http\JsonResponse A JSON response containing the instance information.
     * @throws \Exception If an error occurs during the fetch process.
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

            $instanceData = $userData->instances()->with('sections')->first();

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
     * Update the instance information associated with the authenticated admin user.
     *
     * This method checks if the authenticated user has the necessary permissions
     * ('instance.update') to update an instance. If the user does not have the required permissions,
     * a 403 Forbidden response is returned. If the user has the required permissions,
     * the method attempts to update the instance associated with the user.
     *
     * @param \Illuminate\Http\Request $request The request object containing the new instance information.
     * @return \Illuminate\Http\JsonResponse A JSON response containing a success message if the instance is updated successfully.
     * @throws \Exception If an error occurs during the update process.
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

            $userInstance = $userData->instances()->with('sections')->first();

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
    
    /**
     * Delete an instance associated with the authenticated admin user.
     *
     * This method checks if the authenticated user has the necessary permissions
     * ('instance.delete') to delete an instance. If the user does not have the required permissions,
     * a 403 Forbidden response is returned. If the user has the required permissions,
     * the method attempts to delete the instance associated with the user.
     *
     * **WARNING**: This action is dangerous and destructive. Deleting an instance is irreversible.
     *
     * @return \Illuminate\Http\JsonResponse A JSON response containing a success message if the instance is deleted successfully.
     * @throws \Exception If an error occurs during the deletion process.
     */
    public function deleteInstance()
    {
        $user = Auth::user();

        $checkAdmin = $this->checkAdminService->checkAdminWithPermission('instance.delete');

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        try {
            $userData = User::where('id', $user->id)->first();

            $userInstance = $userData->instances()->first();

            if (!$userInstance) {
                return response()->json([
                    'errors' => 'Instance not found.'
                ], 404);
            }

            // Periksa apakah instansi masih memiliki relasi, jika ya, instansi tidak boleh dihapus sampai data relasi sudah dihapus terlebih dahulu.
            if ($userInstance->users()->exists() || $userInstance->folders()->exists() || $userInstance->files()->exists()) {
                return response()->json([
                    'errors' => 'Instance cannot be deleted because it still has related users, folders, or files.'
                ], 400);
            }

            DB::beginTransaction();

            $userInstance->delete();

            DB::commit();

            return response()->json([
                'message' => 'Instance deleted successfully.'
            ], 200);
        } catch (Exception $e) {

            DB::rollBack();

            Log::error('Error occurred while deleting instance from admin: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred while deleting instance.'
            ], 500);
        }
    }
}
