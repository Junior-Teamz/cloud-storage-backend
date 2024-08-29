<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PermissionSeeder extends Seeder
{
    protected $adminPermission = [
        'users.create',
        'users.read',
        'users.update',
        'users.delete',
    ];

    /**
     * Run the database seeds.
     */
    public function run()
    {
        $permissions = [

            // User Management
            'users.create',
            'users.read',
            'users.update',
            'users.delete',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'api']);
        }

        // Get All Role Data
        $roles = Role::all();

        foreach ($roles as $role) {
            // Check the role
            if ($role->name === 'admin') {
                $role->syncPermissions($this->adminPermission);
            }
        }
    }
}
