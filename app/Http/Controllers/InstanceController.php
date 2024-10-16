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
     * Mendapatkan data instansi berdasarkan query parameter `name`.
     * Jika query parameter `name` tidak diberikan, maka akan mengembalikan semua data instansi.
     * 
     * @param Request $request
     * 
     * @return \Illuminate\Http\JsonResponse
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
     * Mendapatkan daftar ID instansi berdasarkan nama.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
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
     * Membuat instansi baru.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
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
     * Update data spesifik instansi.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
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
     * Menghapus data instansi.
     * 
     * PERINGATAN: FUNCTION INI DAPAT MENGHAPUS DATA SECARA 
     * PERMANEN. GUNAKAN DENGAN HATI-HATI
     * 
     * @param  $instanceId
     * @return \Illuminate\Http\JsonResponse
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
