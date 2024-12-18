<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Role::updateOrCreate([
            'name' => 'superadmin',
            'guard_name' => 'api'
        ]);
        Role::updateOrCreate([
            'name' => 'admin',
            'guard_name' => 'api'
        ]);
        Role::updateOrCreate([
            'name' => 'user',
            'guard_name' => 'api'
        ]);
    }
}
