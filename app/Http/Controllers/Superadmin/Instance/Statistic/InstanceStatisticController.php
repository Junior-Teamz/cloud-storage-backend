<?php

namespace App\Http\Controllers\Superadmin\Instance\Statistic;

use App\Http\Controllers\Controller;
use App\Models\Instance;
use App\Services\CheckAdminService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InstanceStatisticController extends Controller
{
    protected $checkAdminService;

    // Inject CheckAdminService class ke dalam constructor
    public function __construct(CheckAdminService $checkAdminService)
    {
        $this->checkAdminService = $checkAdminService;
    }

    /**
     * Count all instance.
     *
     * This method retrieves the total count of instances.
     * 
     * Requires superadmin authentication.
     * 
     * @return \Illuminate\Http\JsonResponse A JSON response containing the total count of instances or an error message.
     */
    public function countAllInstance()
    {
        $checkAdmin = $this->checkAdminService->checkSuperAdmin();

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
     * Requires superadmin authentication.
     *
     * @param  \Illuminate\Http\Request  $request The incoming HTTP request, containing optional pagination parameters.
     * @return \Illuminate\Http\JsonResponse A JSON response containing the instance usage statistics or an error message.
     */
    public function getInstanceUsageStatistics(Request $request)
    {
        $checkAdmin = $this->checkAdminService->checkSuperAdmin();

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

    
}
