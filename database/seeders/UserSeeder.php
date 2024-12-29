<?php

namespace Database\Seeders;

use App\Models\Folder;
use App\Models\Instance;
use App\Models\Tags;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Str;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $adminSeeder = User::updateOrCreate([
            'name' => 'Administrator',
            'email' => 'administrator@gmail.com',
            'password' => bcrypt('administrator!@123'),
        ]);

        $adminRole = Role::where('name', 'superadmin')->first();
        
        // Assign role with permissions to admin user
        $adminSeeder->assignRole($adminRole);
    }
}
