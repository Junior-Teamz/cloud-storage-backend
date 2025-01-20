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
            'name' => 'KemenkopUKM',
            'address' => 'Jalan Rasuna Said',
        ]);

        InstanceSection::updateOrCreate([
            'name' => 'Direktur',
            'instance_id' => $instance->id,
        ]);
    }
}
