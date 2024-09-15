<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\Folder;
use App\Models\UserFolderPermission;
use App\Services\CheckFolderPermissionService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Sqids\Sqids;

class SharingController extends Controller
{
    protected $checkPermissionFolderService;

    public function __construct(CheckFolderPermissionService $checkPermissionFolderService)
    {
        // Simpan service ke dalam property
        $this->checkPermissionFolderService = $checkPermissionFolderService;
    }

    public function getListUserSharedFolder($id)
    {
        $user = Auth::user();

        // periksa apakah user memiliki izin read.
        $permission = $this->checkPermissionFolderService->checkPermissionFolder($id, ['read']);

        if (!$permission) {
            return response()->json([
                'errors' => 'You do not have permission to access this folder shared users.',
            ], 403);
        }

        try {
            // Ambil folder beserta userFolderPermissions dan user yang terkait
            $folder = Folder::findOrFail($id);

            // Cek apakah folder dimiliki oleh user yang sedang login
            if ($folder->user_id !== $user->id) {
                return response()->json([
                    'errors' => 'You do not have permission to view this folder.'
                ], 403);
            }

            // Ambil daftar user (id, name, email) yang memiliki akses ke folder
            $sharedUsers = UserFolderPermission::where('folder_id', $folder->id)
                ->with('user:id,name,email') // Hanya memuat kolom yang dibutuhkan
                ->get()
                ->map(function ($permission) {
                    return [
                        'id' => $permission->user->id,
                        'name' => $permission->user->name,
                        'email' => $permission->user->email,
                    ];
                });


            return response()->json([
                'shared_users' => $sharedUsers
            ], 200);
        } catch (Exception $e) {
            Log::error('Error occurred while getting list of users shared for folder: ' . $e->getMessage());

            return response()->json([
                'errors' => 'An error occurred while getting list of users shared for folder.'
            ], 500);
        }
    }
}
