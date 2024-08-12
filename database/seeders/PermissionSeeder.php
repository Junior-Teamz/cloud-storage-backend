<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PermissionSeeder extends Seeder
{
    protected $userPermission = [
        // Folder Permissions
        'folders.create',
        'folders.info',
        'folders.show',
        'folders.update',
        'folders.delete',
        'folders.move',
        'folders.create_sub',
        'folders.read_sub',

        // File Permissions
        'files.upload',
        'files.download',
        'files.read_meta',
        'files.update',
        'files.delete',
        'files.move',
        'files.share',
    ];

    /**
     * Run the database seeds.
     */
    public function run()
    {
        $permissions = [
            // Folder Permissions
            'folders.create',
            'folders.info', // permission to see information about folder (like uuid, name, parent_id, etc.)
            'folders.show', // permission to see inside the folder
            'folders.update',
            'folders.delete',
            'folders.move',
            'folders.create_sub',
            'folders.read_sub',
        
            // File Permissions
            'files.upload',
            'files.download',
            'files.read_meta',
            'files.update',
            'files.delete',
            'files.move',
            'files.share',

            // User Management
            'users.create',
            'users.read',
            'users.update',
            'users.delete',
        
            // Access Management
            'access.grant',
            'access.revoke',
            'access.view_list',
            'access.update_list',
        
            // 'read_only',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'api']);
        }

         // Get All Role Data
         $roles = Role::all();
         $allPermission = Permission::all();

         foreach ($roles as $role) {
             // Check the role
             if ($role->name === 'admin') {
                 $role->syncPermissions($allPermission);
             } elseif ($role->name === 'user') {
                 $role->syncPermissions($this->userPermission);
             }
         }
    }
}
