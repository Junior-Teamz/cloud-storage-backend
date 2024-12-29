<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PermissionSeeder extends Seeder
{
    protected $superadminPermission = [
        // Superadmin has all permission.

        'instance.read',
        'instance.create',
        'instance.update',
        'instance.delete',
        'instance.statistic.read',

        'instance.section.read',
        'instance.section.create',
        'instance.section.update',
        'instance.section.delete',
        'instance.section.statistic.read',

        'users.read',
        'users.create',
        'users.update',
        'users.delete',
        'users.statistic.read',

        'tag.read',
        'tag.create',
        'tag.update',
        'tag.delete',
        'tag.statistic.read',

        'news.read',
        'news.create',
        'news.update',
        'news.delete',
        'news.status.update',
    ];

    /**
     * Run the database seeds.
     */
    public function run()
    {
        $permissions = [

            // Instance Management
            'instance.read',
            'instance.create',
            'instance.update',
            'instance.delete',
            // Instance Statistic Management
            'instance.statistic.read',

            // Instance Section Management
            'instance.section.read',
            'instance.section.create',
            'instance.section.update',
            'instance.section.delete',
            // Instance Section Statistic Management
            'instance.section.statistic.read',

            // User Management
            'users.read',
            'users.create',
            'users.update',
            'users.delete',
            // User Statistic Management
            'users.statistic.read',

            // Tag Management
            'tag.read',
            'tag.create',
            'tag.update',
            'tag.delete',
            // Tag Statistic Management
            'tag.statistic.read',

            // News Management
            'news.read',
            'news.create',
            'news.update',
            'news.delete',
            // News Status Management
            'news.status.update',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'api']);
        }

        // Get All Role Data
        $roles = Role::all();

        foreach ($roles as $role) {
            // Check the role
            if ($role->name === 'superadmin') {
                $role->syncPermissions($this->superadminPermission);
            }
        }
    }
}
