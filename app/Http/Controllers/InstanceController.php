<?php

namespace App\Http\Controllers;

use App\Exceptions\MissingColumnException;
use App\Imports\InstanceImport;
use App\Models\Instance;
use App\Services\CheckAdminService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

class InstanceController extends Controller
{
    protected $checkAdminService;

    // Inject RoleService ke dalam constructor
    public function __construct(CheckAdminService $checkAdminService)
    {
        $this->checkAdminService = $checkAdminService;
    }

    /**
     * Display a paginated list of instances.
     *
     * This method retrieves a paginated list of instances, optionally filtered by name.
     * 
     * Requires admin authentication.
     *
     * @param  \Illuminate\Http\Request  $request The incoming HTTP request containing optional search parameters.
     * @return \Illuminate\Http\JsonResponse A JSON response containing the list of instances or an error message.
     */
    public function index(Request $request)
    {
        $checkAdmin = $this->checkAdminService->checkAdmin();

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        try {
            if ($request->query('name')) {

                $keywordName = $request->query('name');

                $allInstance = Instance::where('name', 'like', '%' . $keywordName . '%')->paginate(10);

                if (!$allInstance) {
                    return response()->json([
                        'message' => 'Instance data is empty.'
                    ], 200);
                }

                return response()->json($allInstance, 200);  // Kembalikan isi pagination tanpa membungkus lagi
            } else {

                $allInstance = Instance::paginate(10);

                return response()->json($allInstance, 200);
            }
        } catch (Exception $e) {

            Log::error('Error occured while fetching instance data: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred while fetching instance data.'
            ], 500);
        }
    }

    /**
     * Get a list of instance IDs and names based on a name search query.
     *
     * This method retrieves a list of instance IDs and names that match the provided search query.
     * 
     * Requires admin authentication.
     *
     * @param  \Illuminate\Http\Request  $request The incoming HTTP request containing the search query.
     * @return \Illuminate\Http\JsonResponse A JSON response containing the list of instance IDs and names or an error message.
     */
    public function getInstanceWithName(Request $request)
    {
        $checkAdmin = $this->checkAdminService->checkAdmin();

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        try {

            $keywordName = $request->query('name');

            // Ambil hanya kolom 'id' tanpa pagination
            $instanceId = Instance::where('name', 'like', '%' . $keywordName . '%')
                ->get(['id', 'name']);

            if (!$instanceId) {
                return response()->json([
                    'message' => 'Instance data not found.',
                    'data' => []
                ], 200);
            }

            // Kembalikan daftar ID
            return response()->json([
                'data' => $instanceId
            ], 200);
        } catch (Exception $e) {
            Log::error('Error occured while fetching instance data: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);
            return response()->json([
                'errors' => 'An error occurred while fetching instance data.'
            ], 500);
        }
    }

    /**
     * Count all instance.
     *
     * This method retrieves the total count of instances.
     * 
     * Requires admin authentication.
     * 
     * @return \Illuminate\Http\JsonResponse A JSON response containing the total count of instances or an error message.
     */
    public function countAllInstance()
    {
        $checkAdmin = $this->checkAdminService->checkAdmin();

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        try {
            $countInstance = Instance::count();

            if (!$countInstance) {
                return response()->json([
                    'message' => 'Instance is empty.',
                    'instance_count' => $countInstance
                ], 200);
            }

            return response()->json([
                'instance_count' => $countInstance
            ], 200);
        } catch (Exception $e) {
            Log::error('Error occured while fetching count all instance: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred while fetching count all instance.'
            ], 500);
        }
    }

    /**
     * Retrieve instance usage statistics.
     *
     * This method retrieves usage statistics for each instance, including user counts (total, user role, admin role),
     * folder counts, and file counts.  The results are paginated.
     * 
     * Requires admin authentication.
     *
     * @param  \Illuminate\Http\Request  $request The incoming HTTP request, containing optional pagination parameters.
     * @return \Illuminate\Http\JsonResponse A JSON response containing the instance usage statistics or an error message.
     */
    public function getInstanceUsageStatistics(Request $request)
    {
        $checkAdmin = $this->checkAdminService->checkAdmin();

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        try {
            // Ambil semua instansi dengan pagination
            $perPage = $request->query('per_page', 10); // Default paginate 10 item per halaman
            $instances = Instance::paginate($perPage);

            // Buat array untuk menampung data
            $data = [];

            // Loop melalui setiap instansi untuk menghitung statistik
            foreach ($instances as $instance) {
                // Hitung jumlah total user yang menggunakan instansi ini
                $userTotal = DB::table('user_has_instances')
                    ->where('instance_id', $instance->id)
                    ->distinct('user_id')
                    ->count('user_id');

                // Hitung jumlah user dengan role 'user' dalam instansi ini
                $userRoleCount = DB::table('users')
                    ->join('user_has_instances', 'users.id', '=', 'user_has_instances.user_id')
                    ->join('model_has_roles', 'users.id', '=', 'model_has_roles.model_uuid')
                    ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                    ->where('user_has_instances.instance_id', $instance->id)
                    ->where('roles.name', 'user') // Gunakan role dari tabel roles
                    ->distinct('users.id')
                    ->count('users.id');

                // Hitung jumlah user dengan role 'admin' dalam instansi ini
                $adminRoleCount = DB::table('users')
                    ->join('user_has_instances', 'users.id', '=', 'user_has_instances.user_id')
                    ->join('model_has_roles', 'users.id', '=', 'model_has_roles.model_uuid')
                    ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                    ->where('user_has_instances.instance_id', $instance->id)
                    ->where('roles.name', 'admin') // Gunakan role dari tabel roles
                    ->distinct('users.id')
                    ->count('users.id');

                // Hitung jumlah folder yang menggunakan instansi ini
                $folderTotal = DB::table('folder_has_instances')
                    ->where('instance_id', $instance->id)
                    ->distinct('folder_id')
                    ->count('folder_id');

                // Hitung jumlah file yang menggunakan instansi ini
                $fileTotal = DB::table('file_has_instances')
                    ->where('instance_id', $instance->id)
                    ->distinct('file_id')
                    ->count('file_id');

                // Masukkan data ke dalam array
                $data[] = [
                    'id' => $instance->id,
                    'name' => $instance->name,
                    'address' => $instance->address,
                    'user_count' => [
                        'user_total' => $userTotal,
                        'role_user_total' => $userRoleCount,
                        'role_admin_total' => $adminRoleCount
                    ],
                    'folder_total' => $folderTotal,
                    'file_total' => $fileTotal,
                ];
            }

            // Gunakan pagination data dan tambahkan data statistik yang dihasilkan
            $paginatedData = [
                'data' => $data,
                'pagination' => [
                    'current_page' => $instances->currentPage(),
                    'per_page' => $instances->perPage(),
                    'total' => $instances->total(),
                    'last_page' => $instances->lastPage(),
                ]
            ];

            // Mengembalikan data dalam format JSON
            return response()->json($paginatedData, 200);
        } catch (Exception $e) {
            Log::error('Error occurred while fetching instance usage statistics: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);
            return response()->json([
                'errors' => 'An error occurred while fetching instance usage statistics.'
            ], 500);
        }
    }

    /**
     * Create a new instance.
     *
     * This method creates a new instance record in the database.  It validates the incoming request data,
     * ensuring that the instance name is unique (case-insensitive), follows a specific format, and does not exceed
     * the maximum length 255 characters. Upon successful creation, a 201 (Created) response is returned with the newly created instance data.
     * 
     * Requires admin authentication.
     *
     * @param  \Illuminate\Http\Request  $request The incoming HTTP request containing the instance data.
     * @return \Illuminate\Http\JsonResponse A JSON response indicating success or failure, with appropriate status codes.
     */
    public function store(Request $request)
    {
        $checkAdmin = $this->checkAdminService->checkAdmin();

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
                    Rule::unique('instances')->where(function ($query) {
                        return $query->whereRaw('LOWER(name) = ?', [strtolower(request('name'))]);
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
            DB::beginTransaction();

            $uppercasedInstanceName = ucwords($request->name);

            $instance = Instance::create([
                'name' => $uppercasedInstanceName,
                'address' => $request->address,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Instance created successfully.',
                'data' => $instance
            ], 201);
        } catch (Exception $e) {

            DB::rollBack();

            Log::error('Error occurred while creating instance: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred while creating instance.'
            ], 500);
        }
    }

    /**
     * Download an example Excel file for instance imports.
     *
     * This method allows authenticated admin users to download an example Excel file that demonstrates
     * the correct format for importing instance data.
     * 
     * Requires admin authentication.
     *
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse|\Illuminate\Http\JsonResponse
     * A file excel response for downloading the example file or a JSON response indicating an error.
     */
    public function exampleImportDownload()
    {
        // Mengecek apakah pengguna adalah admin
        $checkAdmin = $this->checkAdminService->checkAdmin();

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ]);
        }

        try {
            // Path file di folder storage/app/import_example
            $filePath = 'import_example/InstanceImport.xlsx';

            // Cek apakah file ada
            if (!Storage::exists($filePath)) {
                Log::critical('Example file for importing instance not found!, please add example import instance excel file in storage/app/import_example/InstanceImport.xlsx');
                return response()->json([
                    'errors' => 'Internal server occured. Please contact the administrator of app.'
                ], 500);
            }

            // Mengembalikan respons untuk mendownload file
            return Storage::download($filePath, 'InstanceImport_Example.xlsx');
        } catch (Exception $e) {
            // Log error jika terjadi exception
            Log::error('Error occurred while downloading example file: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred while downloading the example file.'
            ], 500);
        }
    }

    /**
     * Import instances from an Excel file.
     *
     * This method handles the import of instances from an uploaded Excel file. It validates the uploaded file,
     * performs the import using a dedicated import class (`InstanceImport`), and returns a JSON response indicating
     * the import status. If any invalid or duplicate instances are found during the import, a message is included
     * in the response.
     * 
     * Requires admin authentication.
     *
     * @param  \Illuminate\Http\Request  $request The incoming HTTP request containing the uploaded Excel file.
     * @return \Illuminate\Http\JsonResponse A JSON response indicating the success or failure of the import operation.
     */
    public function import(Request $request)
    {
        $checkAdmin = $this->checkAdminService->checkAdmin();

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        // Validasi file yang diupload
        $validator = Validator::make($request->all(), [
            'file' => 'required|mimes:xlsx,xls,csv|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ]);
        }

        try {
            DB::beginTransaction();

            // Inisialisasi objek InstanceImport
            $instanceImport = new InstanceImport;

            // Lakukan import menggunakan Laravel Excel
            Excel::import($instanceImport, $request->file('file'));

            // Ambil jumlah instansi yang invalid dan duplikat
            $invalidCount = $instanceImport->getInvalidInstancesCount();
            $duplicateCount = $instanceImport->getDuplicateInstancesCount();

            DB::commit();

            // Kembalikan respon sukses, dengan informasi mengenai instansi yang invalid dan duplikat
            return response()->json([
                'message' => ($invalidCount || $duplicateCount) ? 'The instance was successfully imported, but there are invalid or duplicate instance names.' : 'Instances imported successfully.',
                'invalid_instances_total' => $invalidCount,
                'duplicate_instances_total' => $duplicateCount
            ], 200);
        } catch (MissingColumnException $e) {
            DB::rollBack();

            // Tangani error ketika kolom tidak ditemukan
            Log::error('Column not found: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'Required Column not found in excel file.'
            ], 422);
        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Error occurred while importing instances: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred while importing instances.'
            ], 500);
        }
    }

    /**
     * Update an existing instance.
     *
     * This method updates an existing instance record in the database. It validates the incoming request data,
     * ensuring that the instance name is unique (case-insensitive), follows a specific format, and does not exceed
     * the maximum length of 255 characters. If the validation passes, it updates the instance with the new data
     * and returns a 200 (OK) response with the updated instance data.
     * 
     * Requires admin authentication.
     *
     * @param  \Illuminate\Http\Request  $request The incoming HTTP request containing the updated instance data.
     * @param string $id The ID of the instance to update.
     * @return \Illuminate\Http\JsonResponse A JSON response indicating success or failure, with appropriate status codes.
     */
    public function update(Request $request, $id)
    {
        $checkAdmin = $this->checkAdminService->checkAdmin();

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
                    Rule::unique('instances')->where(function ($query) use ($request, $id) {
                        return $query->whereRaw('LOWER(name) = ?', [strtolower($request->name)])
                            ->where('id', '!=', $id); // Exclude the current instance ID;
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
            DB::beginTransaction();

            $instance = Instance::where('id', $id)->first();

            if (!$instance) {
                return response()->json([
                    'errors' => 'Instance not found.'
                ], 404);
            }

            $uppercasedInstanceName = ucwords($request->name);

            $instance->update([
                'name' => $uppercasedInstanceName,
                'address' => $request->address,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Instance updated successfully.',
                'data' => $instance
            ], 200);
        } catch (Exception $e) {

            DB::rollBack();

            Log::error('Error occurred while updating instance: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred while updating instance.'
            ], 500);
        }
    }

    /**
     * Delete an instance.
     *
     * This method attempts to delete an instance from the database. It first checks if the authenticated user
     * has admin privileges. If not, a 403 Forbidden response is returned. If the instance exists and has no
     * associated users, folders, or files, it is deleted, and a 200 OK response is returned. If the instance
     * still has related data, a 400 Bad Request response is returned, indicating that the related data must
     * be deleted first.
     * 
     * Requires admin authentication.
     * 
     * **Caution:** Deleting an instance is a destructive action and should be performed with caution.
     * Ensure that the instance is no longer needed and that all related data has been properly handled 
     * before proceeding with the deletion.
     *
     * @param int $instanceId The ID of the instance to delete.
     * @return \Illuminate\Http\JsonResponse A JSON response indicating success or failure, with appropriate status codes.
     */
    public function destroy($instanceId)
    {
        $checkAdmin = $this->checkAdminService;

        if (!$checkAdmin) {
            Log::info('A user attempted to delete an instance without permission.');
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        try {
            $instance = Instance::where('id', $instanceId)->first();

            if (!$instance) {
                Log::warning('Attempt to delete non-existent instance: ' . $instanceId);
                return response()->json([
                    'errors' => 'Instance not found.'
                ], 404);
            }

            // Periksa apakah instansi masih memiliki relasi, jika ya, instansi tidak boleh dihapus sampai data relasi sudah dihapus terlebih dahulu.
            if ($instance->users()->exists() || $instance->folders()->exists() || $instance->files()->exists()){
                Log::warning("Attempt to delete instance that still have related users, folders, or files!");
                return response()->json([
                    'errors' => "Instance cannot be deleted because it still has related users, folders, or files."
                ], 400);
            }

            DB::beginTransaction();

            $instance->delete();

            DB::commit();

            return response()->json([
                'message' => 'Instance deleted successfully.'
            ], 200);
        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Error occured while deleting instance data: ' . $e->getMessage(), [
                'instance_id' => $instanceId,
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occured while deleting instance data.'
            ], 500);
        }
    }
}
