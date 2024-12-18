<?php

namespace Database\Seeders;

use App\Models\Instance;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class InstanceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Instance::updateOrCreate([
            'name' => 'KemenkopUKM',
            'address' => 'Jl. H. R. Rasuna Said No.Kav. 3-4, RT.6/RW.7, Kuningan, Karet Kuningan, Kecamatan Setiabudi, Kota Jakarta Selatan, Daerah Khusus Ibukota Jakarta 12940',
        ]);
    }
}
