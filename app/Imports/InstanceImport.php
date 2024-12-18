<?php

namespace App\Imports;

use App\Exceptions\MissingColumnException;
use App\Models\Instance;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;

class InstanceImport implements ToModel, WithHeadingRow, WithChunkReading
{
    // Variabel untuk menyimpan jumlah instansi yang invalid atau duplikat
    protected $invalidInstancesCount = 0;
    protected $duplicateInstancesCount = 0;

    /**
     * Membuat model untuk setiap baris dari file Excel
     * 
     * @param array $row
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function model(array $row)
    {
        // Memeriksa apakah kolom 'Nama Instansi' dan 'Alamat Instansi' ada dalam row
        if (! (isset($row['Nama Instansi']) && isset($row['Alamat Instansi'])) ) {
            // Lemparkan exception jika kolom tidak ditemukan
            throw new MissingColumnException('The required column "Nama Instansi" and "Alamat Instansi" was not found.');
        }

        // Trim whitespace dari nama dan alamat instansi agar filter lebih ketat
        $instanceName = trim($row['Nama Instansi']);
        $instanceAddress = trim($row['Alamat Instansi']);

        // Validasi nama instansi menggunakan regex (hanya huruf dan spasi)
        if (!preg_match('/^[a-zA-Z0-9\s.,()\-]+$/', $instanceName)) {
            // Jika tidak valid, increment invalid count dan lewati proses impor
            $this->invalidInstancesCount++;
            return null;
        }

        // Memeriksa apakah nama instansi sudah ada di database
        $existingInstance = Instance::whereRaw('LOWER(name) = ?', [strtolower($instanceName)])->first();

        if ($existingInstance) {
            // Jika instansi sudah ada, increment duplicate count dan lewati proses impor
            $this->duplicateInstancesCount++;
            return null;
        }

        $uppercasedInstanceName = ucwords($instanceName);

        // Jika instansi valid dan belum ada, tambahkan ke database
        return new Instance([
            'name' => $uppercasedInstanceName,
            'address' => $instanceAddress,
        ]);
    }

    /**
     * Menggunakan chunk reading agar dapat menangani file besar
     * 
     * @return int
     */
    public function chunkSize(): int
    {
        return 1000; // Memproses 1000 baris per batch
    }

    /**
     * Mengembalikan jumlah instansi yang invalid
     * 
     * @return int
     */
    public function getInvalidInstancesCount(): int
    {
        return $this->invalidInstancesCount;
    }

    /**
     * Mengembalikan jumlah instansi yang duplikat
     * 
     * @return int
     */
    public function getDuplicateInstancesCount(): int
    {
        return $this->duplicateInstancesCount;
    }
}
