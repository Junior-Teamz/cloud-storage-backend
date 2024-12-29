<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\UserSeeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $seeders = [
            RoleSeeder::class,
            PermissionSeeder::class,
            InstanceAndInstanceSectionSeeder::class,
            TagSeeder::class,
            UserSeeder::class,
        ];

        foreach ($seeders as $seeder) {
            $this->call($seeder);
        }

        // $this->sendAppInfo();
    }

    private function sendAppInfo() {
        //
    }
}
