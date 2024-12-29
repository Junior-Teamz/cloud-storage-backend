<?php

namespace Database\Seeders;

use App\Models\Instance;
use App\Models\InstanceSection;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class InstanceAndInstanceSectionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $instance = Instance::updateOrCreate([
            'name' => 'PT. Example',
            'address' => 'Jl. Contoh No. 1',
        ]);

        InstanceSection::updateOrCreate([
            'name' => 'Direktur',
            'instance_id' => $instance->id,
        ]);
    }
}
