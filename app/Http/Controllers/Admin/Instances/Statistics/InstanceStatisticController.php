<?php

namespace App\Http\Controllers\Admin\Instances\Statistics;

use App\Http\Controllers\Controller;
use App\Models\Instance;
use App\Models\User;
use App\Services\CheckAdminService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InstanceStatisticController extends Controller
{
    protected $checkAdminService;

    // Inject CheckAdminService class ke dalam constructor
    // public function __construct(CheckAdminService $checkAdminService)
    // {
    //     $this->checkAdminService = $checkAdminService;
    // }

    /**
     * Get current admin instance registered statistic.
     */
    public function getCurrentInstanceUsageStatistics()
    {
        $user = Auth::user();

        // $checkAdmin = $this->checkAdminService->checkAdminWithPermission('instance.statistic.read');

        // if (!$checkAdmin) {
        //     return response()->json([
        //         'errors' => 'You are not allowed to perform this action.'
        //     ], 403);
        // }

        try {
            $userData = User::where('id', $user->id)->first();
            // Ambil semua instansi dengan pagination
            $instances = $userData->instances()->first();

            // Hitung jumlah total user yang menggunakan instansi ini
            $userTotal = DB::table('user_has_instances')
                ->where('instance_id', $instances->id)
                ->distinct('user_id')
                ->count('user_id');

            // Hitung jumlah user dengan role 'user' dalam instansi ini
            $userRoleCount = DB::table('users')
                ->join('user_has_instances', 'users.id', '=', 'user_has_instances.user_id')
                ->join('model_has_roles', 'users.id', '=', 'model_has_roles.model_uuid')
                ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                ->where('user_has_instances.instance_id', $instances->id)
                ->where('roles.name', 'user') // Gunakan role dari tabel roles
                ->distinct('users.id')
                ->count('users.id');

            // Hitung jumlah user dengan role 'admin' dalam instansi ini
            $adminRoleCount = DB::table('users')
                ->join('user_has_instances', 'users.id', '=', 'user_has_instances.user_id')
                ->join('model_has_roles', 'users.id', '=', 'model_has_roles.model_uuid')
                ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                ->where('user_has_instances.instance_id', $instances->id)
                ->where('roles.name', 'admin') // Gunakan role dari tabel roles
                ->distinct('users.id')
                ->count('users.id');

            // Hitung jumlah folder yang menggunakan instansi ini
            $folderTotal = DB::table('folder_has_instances')
                ->where('instance_id', $instances->id)
                ->distinct('folder_id')
                ->count('folder_id');

            // Hitung jumlah file yang menggunakan instansi ini
            $fileTotal = DB::table('file_has_instances')
                ->where('instance_id', $instances->id)
                ->distinct('file_id')
                ->count('file_id');

            // Masukkan data ke dalam array
            $data = [
                'id' => $instances->id,
                'name' => $instances->name,
                'address' => $instances->address,
                'user_count' => [
                    'user_total' => $userTotal,
                    'role_user_total' => $userRoleCount,
                    'role_admin_total' => $adminRoleCount
                ],
                'folder_total' => $folderTotal,
                'file_total' => $fileTotal,
            ];

            // Mengembalikan data dalam format JSON
            return response()->json([
                'data' => $data
            ], 200);
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
