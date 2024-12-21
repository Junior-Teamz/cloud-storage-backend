<?php

namespace Database\Seeders;

use App\Models\InstanceSection;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class InstanceSectionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        InstanceSection::updateOrCreate([
            'name' => '',
            'instance_id' => 1, // First created instance from InstanceSeeder.
        ]);
    }
}
