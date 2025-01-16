<?php

namespace App\Http\Controllers\Superadmin\Users;

use App\Http\Controllers\Controller;
use App\Models\Folder;
use App\Models\Instance;
use App\Models\User;
use App\Services\CheckAdminService;
use App\Services\GetPathService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    protected $checkAdminService;
    protected $getPathService;

    public function __construct(CheckAdminService $checkAdminService, GetPathService $getPathServiceParam)
    {
        $this->checkAdminService = $checkAdminService;
        $this->getPathService = $getPathServiceParam;
    }

    /**
     * Count all users.
     *
     * This function counts the total number of users, users with the 'user' role, and users with the 'admin' role.
     * 
     * Requires admin authentication.
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function countAllUser()
    {
        $checkAdmin = $this->checkAdminService->checkSuperAdmin();

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        try {
            // Menghitung total user
            $totalUserCount = User::count();

            // Menghitung user dengan role 'user' dan 'admin' menggunakan Spatie Laravel Permission
            $userRoleCount = User::role('user')->count(); // Menggunakan metode role() dari Spatie
            $adminRoleCount = User::role('admin')->count(); // Menggunakan metode role() dari Spatie

            if ($totalUserCount === 0) {
                return response()->json([
                    'message' => 'No users are registered.',
                    'total_user_count' => $totalUserCount,
                    'user_role_count' => $userRoleCount,
                    'admin_role_count' => $adminRoleCount
                ], 200);
            }

            return response()->json([
                'total_user_count' => $totalUserCount,
                'user_role_count' => $userRoleCount,
                'admin_role_count' => $adminRoleCount
            ], 200);
        } catch (Exception $e) {
            Log::error('Error occurred on getting user count: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred on getting user count.',
            ], 500);
        }
    }

    public function listUser(Request $request)
    {
        $checkAdmin = $this->checkAdminService->checkSuperAdmin();

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        try {
            // Cari data berdasarkan query parameter
            $query = User::query();

            if ($request->query('name')) {
                $query->where('name', 'like', '%' . $request->query('name') . '%');
            } elseif ($request->query('email')) {
                $query->where('email', 'like', '%' . $request->query('email') . '%');
            } elseif ($request->query('instance')) {
                $query->whereHas('instances', function ($q) use ($request) {
                    $q->where('name', 'like', '%' . $request->query('instance') . '%');
                });
            }

            $allUser = $query->with([
                'instances:id,name,address',
                'folders' => function ($query) {
                    $query->whereNull('parent_id');
                }
            ])->paginate(10);

            // Proses untuk menambahkan total_subfolder
            $allUser->getCollection()->transform(function ($user) {
                $user->folders->transform(function ($folder) {
                    $folder->total_subfolder = $folder->calculateTotalSubfolder();
                    $folder->total_file = $folder->calculateTotalFile(); // Menampilkan total file di dalam folder
                    $folder->total_size = $folder->calculateTotalSize(); // Hitung total ukuran folder
                    unset($folder->user_id, $folder->created_at, $folder->updated_at, $folder->files, $folder->subfolders);
                    return $folder;
                });
                return $user;
            });

            return response()->json($allUser, 200);
        } catch (\Exception $e) {
            Log::error("Error occurred on getting user list: " . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred on getting user list.',
            ], 500);
        }
    }

    public function user_info($id)
    {
        $user = Auth::user();

        $checkAdmin = $this->checkAdminService->checkSuperAdmin();

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        try {

            $user = User::with([
                'instances:id,name,address',
                'folders' => function ($query) {
                    $query->whereNull('parent_id');
                }
            ])->where('id', $id)->first();

            // Transformasi data pada folders
            $user->folders->transform(function ($folder) {
                $folder->total_subfolder = $folder->calculateTotalSubfolder();
                $folder->total_file = $folder->calculateTotalFile();
                $folder->total_size = $folder->calculateTotalSize();
                unset($folder->user_id, $folder->created_at, $folder->updated_at, $folder->files, $folder->subfolders);
                return $folder;
            });

            if (!$user) {
                return response()->json([
                    'message' => "User not found.",
                    'data' => []
                ], 200);
            }

            return response()->json([
                'data' => $user
            ]);
        } catch (Exception $e) {
            Log::error('Error occurred on getting user information: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);
            return response()->json([
                'errors' => 'An error occured on getting user information.',
            ], 500);
        }
    }

    /**
     * Get all permissions for admin.
     *
     * This function retrieves all permissions assigned to the admin role.
     * 
     * Requires admin authentication.
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAdminPermissions()
    {
        $checkAdmin = $this->checkAdminService->checkSuperAdmin();

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        try {
            $permissions = Permission::all();

            return response()->json([
                'permissions' => $permissions
            ], 200);
        } catch (Exception $e) {
            Log::error('Error occurred on getting admin permissions: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred on getting admin permissions.',
            ], 500);
        }
    }

    /**
     * Create a new user by admin.
     *
     * This function allows an administrator to create a new user account.  It validates the input data,
     * creates the user, assigns a role, associates the user with an instance, and handles potential errors.
     * 
     * Requires admin authentication.
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function createUserFromAdmin(Request $request)
    {
        $checkAdmin = $this->checkAdminService->checkSuperAdmin();

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'unique:users,email',
                function ($attribute, $value, $fail) {
                    if (!preg_match('/^\w+([\.-]?\w+)*@\w+([\.-]?\w+)*(\.\w{2,})+$/', $value)) {
                        $fail('Invalid email format.');
                    }

                    $allowedDomains = [
                        'outlook.com',
                        'yahoo.com',
                        'aol.com',
                        'lycos.com',
                        'mail.com',
                        'icloud.com',
                        'yandex.com',
                        'protonmail.com',
                        'tutanota.com',
                        'zoho.com',
                        'gmail.com'
                    ];

                    $domain = strtolower(substr(strrchr($value, '@'), 1));

                    if (!in_array($domain, $allowedDomains)) {
                        $fail('Invalid email domain.');
                    }
                },
            ],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role' => ['required', 'string', 'exists:roles,name', 'not_in:superadmin'],
            'instance_id' => ['required', 'string', 'exists:instances,id'],
            'instance_section_id' => ['nullable', 'string', 'exists:instance_sections,id'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
            'photo_profile' => ['nullable', 'file', 'max:3000', 'mimes:jpg,jpeg,png']
        ], [
            'role.not_in' => "Creating new user with role 'superadmin' is not allowed."
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        if ($request->role === 'admin' && !$request->has('permissions')) {
            return response()->json([
                'errors' => 'Permissions are required for admin role.'
            ], 422);
        }

        if ($request->role === 'admin' && $request->has('instance_section_id')) {
            return response()->json([
                'errors' => 'Instance section ID should not be provided for admin role.'
            ], 422);
        }

        if ($request->role === 'user' && !$request->has('instance_section_id')) {
            return response()->json([
                'errors' => 'Instance section ID is required for user role.'
            ], 422);
        }

        if ($request->role === 'user' && $request->has('permissions')) {
            return response()->json([
                'errors' => 'Permissions should not be provided for user role.'
            ], 422);
        }

        try {
            $instance = Instance::where('id', $request->instance_id)->first();
            $section = $instance->sections()->where('id', $request->instance_section_id)->first();

            if ($request->role === 'user' && !$section) {
                return response()->json([
                    'errors' => 'Section does not belong to the specified instance.'
                ], 422);
            }

            DB::beginTransaction();

            $newUser = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => bcrypt($request->password),
            ]);

            $newUser->assignRole($request->role);

            $newUser->instances()->sync($instance->id);

            if ($request->role === 'user') {
                $newUser->section()->sync($section->id);
            }

            if ($request->role === 'admin' && $request->has('permissions')) {
                $newUser->syncPermissions($request->permissions);
            }

            if ($request->has('photo_profile')) {
                $photoFile = $request->file('photo_profile');
                $photoProfilePath = 'users_photo_profile';

                if (!Storage::disk('public')->exists($photoProfilePath)) {
                    Storage::disk('public')->makeDirectory($photoProfilePath);
                }

                $photoProfile = $photoFile->store($photoProfilePath, 'public');
                $photoProfileUrl = Storage::url($photoProfile);

                $newUser->photo_profile_path = $photoProfile;
                $newUser->photo_profile_url = $photoProfileUrl;
                $newUser->save();
            }

            $newUser->load(['instances:id,name,address', 'section:id,name']);

            if($request->role === 'admin'){
                $permissions = $newUser->getAllPermissions()->map(function ($permission) {
                    return [
                        'id' => $permission->id,
                        'name' => $permission->name,
                        'guard_name' => $permission->guard_name,
                        'created_at' => $permission->created_at,
                        'updated_at' => $permission->updated_at,
                    ];
                });
    
                $newUser["permissions"] = $permissions;
            }

            DB::commit();

            return response()->json([
                'message' => 'User created successfully.',
                'data' => $newUser
            ], 201);
        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Error occurred on adding user: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);
            return response()->json([
                'errors' => 'An error occurred on adding user.',
            ], 500);
        }
    }

    /**
     * Update existing user with role "user" by superadmin.
     *
     * This function allows an administrator to update an existing user account with 'user' role.
     * It validates the input data, updates the user information, updates the user's instance association,
     * updates associated folder instances, and handles potential errors.
     *
     * Requires admin authentication.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $userIdToBeUpdated The UUID of the user to be updated.
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateUserRoleUser(Request $request, $userIdToBeUpdated)
    {
        $checkAdmin = $this->checkAdminService->checkSuperAdmin();

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['nullable', 'string', 'max:255'],
            'email' => [
                'nullable',
                'email',
                function ($attribute, $value, $fail) {
                    if (!preg_match('/^\w+([\.-]?\w+)*@\w+([\.-]?\w+)*(\.\w{2,})+$/', $value)) {
                        $fail('Invalid email format.');
                    }

                    $allowedDomains = [
                        'outlook.com',
                        'yahoo.com',
                        'aol.com',
                        'lycos.com',
                        'mail.com',
                        'icloud.com',
                        'yandex.com',
                        'protonmail.com',
                        'tutanota.com',
                        'zoho.com',
                        'gmail.com'
                    ];

                    $domain = strtolower(substr(strrchr($value, '@'), 1));

                    if (!in_array($domain, $allowedDomains)) {
                        $fail('Invalid email domain.');
                    }
                },
                Rule::unique('users', 'email')->ignore($userIdToBeUpdated)
            ],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'instance_id' => ['nullable', 'string', 'exists:instances,id'],
            'instance_section_id' => ['nullable', 'string', 'exists:instance_sections,id'],
            'photo_profile' => ['nullable', 'file', 'max:3000', 'mimes:jpg,jpeg,png']
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $userToBeUpdated = User::where('id', $userIdToBeUpdated)->first();

            if (!$userToBeUpdated) {
                return response()->json([
                    'errors' => 'User not found.'
                ], 404);
            }

            if ($userToBeUpdated->hasRole('superadmin')) {
                return response()->json([
                    'errors' => 'You are not allowed to update superadmin user.',
                ], 403);
            }

            DB::beginTransaction();

            $dataToUpdate = $request->only(['name', 'email', 'password']);

            if (isset($dataToUpdate['password'])) {
                $dataToUpdate['password'] = bcrypt($dataToUpdate['password']);
            }

            $userToBeUpdated->update(array_filter($dataToUpdate));

            $currentInstance = $userToBeUpdated->instances()->first();
            $currentSection = $userToBeUpdated->section()->first();

            if ($request->instance_id && $request->instance_id != $currentInstance->id) {
                $instance = Instance::where('id', $request->instance_id)->first();
                $section = $instance->sections()->where('id', $request->instance_section_id)->first();

                if (!$section) {
                    return response()->json([
                        'errors' => 'Section does not belong to the specified instance.'
                    ], 422);
                }

                $userToBeUpdated->instances()->sync($instance->id);
                $userToBeUpdated->section()->sync($section->id);

                $userFolders = Folder::where('user_id', $userToBeUpdated->id)->get();

                foreach ($userFolders as $folder) {
                    $folder->instances()->sync($instance->id);
                }
            } elseif ($request->instance_section_id && $request->instance_section_id != $currentSection->id) {
                $section = $currentInstance->sections()->where('id', $request->instance_section_id)->first();

                if (!$section) {
                    return response()->json([
                        'errors' => 'Section does not belong to the specified instance.'
                    ], 422);
                }

                $userToBeUpdated->section()->sync($section->id);
            }

            if ($request->has('photo_profile')) {
                $photoFile = $request->file('photo_profile');

                $photoProfilePath = 'users_photo_profile';

                if (!Storage::disk('public')->exists($photoProfilePath)) {
                    Storage::disk('public')->makeDirectory($photoProfilePath);
                }

                if ($userToBeUpdated->photo_profile_path && Storage::disk('public')->exists($userToBeUpdated->photo_profile_path)) {
                    Storage::disk('public')->delete($userToBeUpdated->photo_profile_path);
                }

                $photoProfile = $photoFile->store($photoProfilePath, 'public');
                $photoProfileUrl = Storage::disk('public')->url($photoProfile);

                $userToBeUpdated->photo_profile_path = $photoProfile;
                $userToBeUpdated->photo_profile_url = $photoProfileUrl;
                $userToBeUpdated->save();
            }

            DB::commit();

            $userToBeUpdated->load(['instances:id,name,address', 'section:id,name']);

            return response()->json([
                'message' => 'User updated successfully.',
                'data' => $userToBeUpdated
            ], 200);
        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Error occurred on updating user: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);
            return response()->json([
                'errors' => 'An error occured on updating user.',
            ], 500);
        }
    }

    /**
     * Update existing user role "admin" by superadmin
     *
     * This function allows an administrator to update an existing user account with 'admin' role.
     * It validates the input data, updates the user information, updates the user's instance association,
     * updates associated folder instances, and handles potential errors.
     *
     * Requires admin authentication.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $userIdToBeUpdated The UUID of the user to be updated.
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateUserRoleAdmin(Request $request, $userIdToBeUpdated)
    {
        $checkAdmin = $this->checkAdminService->checkSuperAdmin();

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['nullable', 'string', 'max:255'],
            'email' => [
                'nullable',
                'email',
                function ($attribute, $value, $fail) {
                    if (!preg_match('/^\w+([\.-]?\w+)*@\w+([\.-]?\w+)*(\.\w{2,})+$/', $value)) {
                        $fail('Invalid email format.');
                    }

                    $allowedDomains = [
                        'outlook.com',
                        'yahoo.com',
                        'aol.com',
                        'lycos.com',
                        'mail.com',
                        'icloud.com',
                        'yandex.com',
                        'protonmail.com',
                        'tutanota.com',
                        'zoho.com',
                        'gmail.com'
                    ];

                    $domain = strtolower(substr(strrchr($value, '@'), 1));

                    if (!in_array($domain, $allowedDomains)) {
                        $fail('Invalid email domain.');
                    }
                },
                Rule::unique('users', 'email')->ignore($userIdToBeUpdated)
            ],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'instance_id' => ['nullable', 'string', 'exists:instances,id'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
            'photo_profile' => ['nullable', 'file', 'max:3000', 'mimes:jpg,jpeg,png']
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        if (!$request->has('permissions')) {
            return response()->json([
                'errors' => 'Permissions are required for admin role.'
            ], 422);
        }

        try {
            $userToBeUpdated = User::where('id', $userIdToBeUpdated)->first();

            if (!$userToBeUpdated) {
                return response()->json([
                    'errors' => 'User not found.'
                ], 404);
            }

            if ($userToBeUpdated->hasRole('superadmin')) {
                return response()->json([
                    'errors' => 'You cannot update superadmin in this endpoint. Please use /api/superadmin/user/update/update_superadmin instead.',
                ], 403);
            }

            DB::beginTransaction();

            $dataToUpdate = $request->only(['name', 'email', 'password']);

            if (isset($dataToUpdate['password'])) {
                $dataToUpdate['password'] = bcrypt($dataToUpdate['password']);
            }

            $userToBeUpdated->update(array_filter($dataToUpdate));

            $currentInstance = $userToBeUpdated->instances()->first();

            if ($request->instance_id && $request->instance_id != $currentInstance->id) {
                $instance = Instance::where('id', $request->instance_id)->first();
                $userToBeUpdated->instances()->sync($instance->id);

                $userFolders = Folder::where('user_id', $userToBeUpdated->id)->get();

                foreach ($userFolders as $folder) {
                    $folder->instances()->sync($instance->id);
                }
            }

            $userToBeUpdated->syncPermissions($request->permissions);

            if ($request->has('photo_profile')) {
                $photoFile = $request->file('photo_profile');

                $photoProfilePath = 'users_photo_profile';

                if (!Storage::disk('public')->exists($photoProfilePath)) {
                    Storage::disk('public')->makeDirectory($photoProfilePath);
                }

                if ($userToBeUpdated->photo_profile_path && Storage::disk('public')->exists($userToBeUpdated->photo_profile_path)) {
                    Storage::disk('public')->delete($userToBeUpdated->photo_profile_path);
                }

                $photoProfile = $photoFile->store($photoProfilePath, 'public');
                $photoProfileUrl = Storage::disk('public')->url($photoProfile);

                $userToBeUpdated->photo_profile_path = $photoProfile;
                $userToBeUpdated->photo_profile_url = $photoProfileUrl;
                $userToBeUpdated->save();
            }

            DB::commit();

            $userToBeUpdated->load(['instances:id,name,address', 'section:id,name']);

            if($userToBeUpdated->role === 'admin'){
                $permissions = $userToBeUpdated->getAllPermissions()->map(function ($permission) {
                    return [
                        'id' => $permission->id,
                        'name' => $permission->name,
                        'guard_name' => $permission->guard_name,
                        'created_at' => $permission->created_at,
                        'updated_at' => $permission->updated_at,
                    ];
                });
    
                $userToBeUpdated["permissions"] = $permissions;
            }

            return response()->json([
                'message' => 'User updated successfully.',
                'data' => $userToBeUpdated
            ], 200);
        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Error occurred on updating user: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);
            return response()->json([
                'errors' => 'An error occured on updating user.',
            ], 500);
        }
    }

    /**
     * NOTE: This function is currently disabled.
     * Update existing user by admin.
     *
     * This function allows an administrator to update an existing user account. It validates the input data,
     * updates the user information, updates the user's instance association, updates associated folder instances,
     * and handles potential errors.  It prevents the update of superadmin users.
     *
     * Requires admin authentication.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $userIdToBeUpdated The UUID of the user to be updated.
     * @return \Illuminate\Http\JsonResponse
     */
    // public function updateUserFromAdmin(Request $request, $userIdToBeUpdated)
    // {
    //     $checkAdmin = $this->checkAdminService->checkSuperAdmin();

    //     if (!$checkAdmin) {
    //         return response()->json([
    //             'errors' => 'You are not allowed to perform this action.'
    //         ], 403);
    //     }

    //     $validator = Validator::make($request->all(), [
    //         'name' => ['nullable', 'string', 'max:255'],
    //         'email' => [
    //             'nullable',
    //             'email',
    //             function ($attribute, $value, $fail) {
    //                 if (!preg_match('/^\w+([\.-]?\w+)*@\w+([\.-]?\w+)*(\.\w{2,})+$/', $value)) {
    //                     $fail('Invalid email format.');
    //                 }

    //                 $allowedDomains = [
    //                     'outlook.com',
    //                     'yahoo.com',
    //                     'aol.com',
    //                     'lycos.com',
    //                     'mail.com',
    //                     'icloud.com',
    //                     'yandex.com',
    //                     'protonmail.com',
    //                     'tutanota.com',
    //                     'zoho.com',
    //                     'gmail.com'
    //                 ];

    //                 $domain = strtolower(substr(strrchr($value, '@'), 1));

    //                 if (!in_array($domain, $allowedDomains)) {
    //                     $fail('Invalid email domain.');
    //                 }
    //             },
    //             Rule::unique('users', 'email')->ignore($userIdToBeUpdated)
    //         ],
    //         'password' => ['nullable', 'string', 'min:8', 'confirmed'],
    //         'role' => ['nullable', 'string', 'exists:roles,name', 'not_in:superadmin'],
    //         'instance_id' => ['nullable', 'string', 'exists:instances,id'],
    //         'instance_section_id' => ['nullable', 'string', 'exists:instance_sections,id'],
    //         'permissions' => ['nullable', 'array'],
    //         'permissions.*' => ['string', 'exists:permissions,name'],
    //         'photo_profile' => ['nullable', 'file', 'max:3000', 'mimes:jpg,jpeg,png']
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json([
    //             'errors' => $validator->errors()
    //         ], 422);
    //     }

    //     try {
    //         $userToBeUpdated = User::where('id', $userIdToBeUpdated)->first();

    //         if (!$userToBeUpdated) {
    //             return response()->json([
    //                 'errors' => 'User not found.'
    //             ], 404);
    //         }

    //         if ($userToBeUpdated->hasRole('superadmin')) {
    //             return response()->json([
    //                 'errors' => 'You are not allowed to update superadmin user.',
    //             ], 403);
    //         }

    //         DB::beginTransaction();

    //         $dataToUpdate = $request->only(['name', 'email', 'password']);

    //         if (isset($dataToUpdate['password'])) {
    //             $dataToUpdate['password'] = bcrypt($dataToUpdate['password']);
    //         }

    //         $userToBeUpdated->update(array_filter($dataToUpdate));

    //         if ($request->role) {
    //             if ($request->role === 'admin') {
    //                 if (!$request->has('permissions')) {
    //                     return response()->json([
    //                         'errors' => 'Permissions are required for admin role.'
    //                     ], 422);
    //                 }
    //                 $userToBeUpdated->syncPermissions($request->permissions);
    //             } elseif ($request->role === 'user') {
    //                 if ($request->has('permissions')) {
    //                     return response()->json([
    //                         'errors' => 'Permissions should not be provided for user role.'
    //                     ], 422);
    //                 }
    //                 $userToBeUpdated->revokePermissionTo($userToBeUpdated->permissions);
    //             }
    //             $userToBeUpdated->assignRole($request->role);
    //         }

    //         $currentInstance = $userToBeUpdated->instances()->first();
    //         $currentSection = $userToBeUpdated->section()->first();

    //         if ($request->role === 'admin') {
    //             if ($request->instance_id && $request->instance_id != $currentInstance->id) {
    //                 $instance = Instance::where('id', $request->instance_id)->first();
    //                 $userToBeUpdated->instances()->sync($instance->id);

    //                 $userFolders = Folder::where('user_id', $userToBeUpdated->id)->get();

    //                 foreach ($userFolders as $folder) {
    //                     $folder->instances()->sync($instance->id);
    //                 }
    //             }
    //         } else {
    //             if ($request->instance_id && $request->instance_id != $currentInstance->id) {
    //                 $instance = Instance::where('id', $request->instance_id)->first();
    //                 $section = $instance->sections()->where('id', $request->instance_section_id)->first();

    //                 if (!$section) {
    //                     return response()->json([
    //                         'errors' => 'Section does not belong to the specified instance.'
    //                     ], 422);
    //                 }

    //                 $userToBeUpdated->instances()->sync($instance->id);
    //                 $userToBeUpdated->section()->sync($section->id);

    //                 $userFolders = Folder::where('user_id', $userToBeUpdated->id)->get();

    //                 foreach ($userFolders as $folder) {
    //                     $folder->instances()->sync($instance->id);
    //                 }
    //             } elseif ($request->instance_section_id && $request->instance_section_id != $currentSection->id) {
    //                 $section = $currentInstance->sections()->where('id', $request->instance_section_id)->first();

    //                 if (!$section) {
    //                     return response()->json([
    //                         'errors' => 'Section does not belong to the specified instance.'
    //                     ], 422);
    //                 }

    //                 $userToBeUpdated->section()->sync($section->id);
    //             }
    //         }

    //         if ($request->has('photo_profile')) {
    //             $photoFile = $request->file('photo_profile');

    //             $photoProfilePath = 'users_photo_profile';

    //             if (!Storage::disk('public')->exists($photoProfilePath)) {
    //                 Storage::disk('public')->makeDirectory($photoProfilePath);
    //             }

    //             if ($userToBeUpdated->photo_profile_path && Storage::disk('public')->exists($userToBeUpdated->photo_profile_path)) {
    //                 Storage::disk('public')->delete($userToBeUpdated->photo_profile_path);
    //             }

    //             $photoProfile = $photoFile->store($photoProfilePath, 'public');
    //             $photoProfileUrl = Storage::disk('public')->url($photoProfile);

    //             $userToBeUpdated->photo_profile_path = $photoProfile;
    //             $userToBeUpdated->photo_profile_url = $photoProfileUrl;
    //             $userToBeUpdated->save();
    //         }

    //         DB::commit();

    //         $userToBeUpdated->load('instances:id,name,address');

    //         return response()->json([
    //             'message' => 'User updated successfully.',
    //             'data' => $userToBeUpdated
    //         ], 200);
    //     } catch (Exception $e) {
    //         DB::rollBack();

    //         Log::error('Error occurred on updating user: ' . $e->getMessage(), [
    //             'trace' => $e->getTrace()
    //         ]);
    //         return response()->json([
    //             'errors' => 'An error occured on updating user.',
    //         ], 500);
    //     }
    // }

    /**
     * Update a user's password.
     *
     * This function allows an administrator to update a user's password. It validates the new password,
     * updates the user's password in the database, and handles potential errors.  It prevents the user
     * from setting their password to the same as their old password.
     *
     * Requires admin authentication.
     * 
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $userId The UUID of the user whose password is to be updated.
     */
    public function updateUserPassword(Request $request, $userId)
    {
        $checkAdmin = $this->checkAdminService->checkSuperAdmin();

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = User::where('id', $userId)->first();

            if (!$user) {
                return response()->json([
                    'errors' => 'User not found.'
                ], 404);
            }

            // Check if the current password matches with old password
            if (password_verify($request->password, $user->password)) {
                return response()->json([
                    'errors' => 'New password cannot be the same as the old password.'
                ], 422); // Return a 422 Unprocessable Entity status code
            }

            DB::beginTransaction();

            $user->update([
                'password' => bcrypt($request->password),
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Password updated successfully.'
            ], 200);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Error occurred on updating password: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);

            return response()->json([
                'errors' => 'An error occurred while updating your password.'
            ], 500);
        }
    }

    /**
     * Delete a user from the system.
     *
     * This function deletes a user from the database and removes associated files and folders from storage.
     * It requires admin authentication and prevents the deletion of superadmin users.  This is a dangerous
     * function and should be used with caution.
     * 
     * Requires admin authentication.
     *
     * @param string $userIdToBeDeleted The UUID of the user to be deleted.
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteUserFromAdmin($userIdToBeDeleted)
    {
        $checkAdmin = $this->checkAdminService->checkSuperAdmin();

        if (!$checkAdmin) {
            return response()->json([
                'errors' => 'You are not allowed to perform this action.'
            ], 403);
        }

        // if ($user->id == $userIdToBeDeleted) {
        //     return response()->json([
        //         'errors' => 'Anda tidak diizinkan untuk menghapus diri sendiri.',
        //     ], 403);
        // }

        try {
            // Delete the user from the database.
            $userData = User::where('id', $userIdToBeDeleted)->first();

            if (!$userData) {
                return response()->json([
                    'errors' => 'User not found.'
                ], 404);
            }

            if ($userData->hasRole('superadmin')) {
                return response()->json([
                    'errors' => 'You are not allowed to delete superadmin user.',
                ], 403);
            }

            DB::beginTransaction();

            // Hapus folder dan file terkait dari local storage
            $folders = Folder::where('user_id', $userData->id)->get();

            if ($folders->count() > 0) {
                foreach ($folders as $folder) {
                    $this->deleteFolderAndFiles($folder->id); // Pass folder ID instead of the object
                }
            }

            // Cek apakah ada foto profil lama dan hapus jika ada
            if ($userData->photo_profile_path && Storage::disk('public')->exists($userData->photo_profile_path)) {
                Storage::disk('public')->delete($userData->photo_profile_path);
            }

            $userData->delete();

            DB::commit();

            return response()->json([
                'message' => 'User deleted successfully.',
            ], 200);
        } catch (Exception $e) {
            DB::rollBack();

            // Log the error if an exception occurs.
            Log::error('Error occurred on deleting user: ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);
            return response()->json([
                'errors' => 'An error occured on deleting user.',
            ], 500);
        }
    }

    /**
     * Recursively delete a folder and its contents.
     *
     * This function recursively deletes a folder and all its files and subfolders from both the database and storage.
     * 
     * @param Folder $folder The folder to delete.
     * @throws \Exception If any error occurs during the deletion process.
     */
    private function deleteFolderAndFiles($folderId)
    {
        try {
            // Find the folder by ID
            $folder = Folder::find($folderId);

            // If the folder doesn't exist, log a warning and return
            if (!$folder) {
                Log::warning("Folder with ID {$folderId} not found during deletion.");
                return;
            }

            // Hapus semua file dalam folder
            $files = $folder->files;

            DB::beginTransaction();

            foreach ($files as $file) {
                try {
                    // Hapus file dari storage
                    Storage::delete($file->path);
                    // Hapus data file dari database
                    $file->delete();
                } catch (\Exception $e) {
                    Log::error('Error occurred while deleting file with ID ' . $file->id . ': ' . $e->getMessage(), [
                        'trace' => $e->getTrace()
                    ]);
                    // Lemparkan kembali exception agar dapat ditangani di tingkat pemanggil
                    throw $e;
                }
            }

            // Hapus subfolder dan file dalam subfolder
            $subfolders = $folder->subfolders;
            foreach ($subfolders as $subfolder) {
                $this->deleteFolderAndFiles($subfolder->id); // Recursively delete subfolders
            }

            // Hapus folder dari storage
            try {
                $folderPath = $this->getPathService->getFolderPath($folder->id);
                if (Storage::exists($folderPath)) {
                    Storage::deleteDirectory($folderPath);
                }
            } catch (\Exception $e) {
                Log::error('Error occurred while deleting folder with ID ' . $folder->id . ': ' . $e->getMessage(), [
                    'trace' => $e->getTrace()
                ]);
                // Lemparkan kembali exception agar dapat ditangani di tingkat pemanggil
                throw $e;
            }

            // Hapus data folder dari database
            try {
                $folder->delete();
            } catch (\Exception $e) {
                Log::error('Error occurred while deleting folder record with ID ' . $folder->id . ': ' . $e->getMessage(), [
                    'trace' => $e->getTrace()
                ]);
                // Lemparkan kembali exception agar dapat ditangani di tingkat pemanggil
                throw $e;
            }

            // Commit transaksi setelah semua operasi berhasil
            DB::commit();
        } catch (\Exception $e) {
            // Rollback transaksi jika terjadi kesalahan
            DB::rollBack();
            Log::error('Error occurred while processing folder with ID ' . $folderId . ': ' . $e->getMessage(), [
                'trace' => $e->getTrace()
            ]);
            // Lemparkan kembali exception agar dapat ditangani di tingkat pemanggil
            throw $e;
        }
    }
}
