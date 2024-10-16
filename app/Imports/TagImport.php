<?php

namespace App\Imports;

use App\Exceptions\MissingColumnException;
use App\Models\Tags;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;

class TagImport implements ToModel, WithHeadingRow, WithChunkReading
{
    // Variabel untuk menyimpan jumlah tag yang invalid atau duplikat
    protected $invalidTagsCount = 0;
    protected $duplicateTagsCount = 0;

    /**
     * Membuat model untuk setiap baris dari file Excel
     * 
     * @param array $row
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function model(array $row)
    {
        // Memeriksa apakah kolom 'Nama Tag' ada dalam row
        if (!isset($row['Nama Tag'])) {
            // Lemparkan exception jika kolom tidak ditemukan
            throw new MissingColumnException('The required column "Nama Tag" was not found.');
        }

        // Trim whitespace dari nama tag agar filter lebih ketat
        $tagName = trim($row['Nama Tag']);

        // Jika nama tag adalah "Root", lewati proses impor
        if (strtolower($tagName) === 'root') {
            return null;
        }

        // Validasi nama tag menggunakan regex
        if (!preg_match('/^[a-zA-Z\s]+$/', $tagName)) {
            // Jika tidak valid, increment invalid count dan lewati proses impor
            $this->invalidTagsCount++;
            return null;
        }

        // Memeriksa apakah nama tag sudah ada di database
        $existingTag = Tags::whereRaw('LOWER(name) = ?', [strtolower($tagName)])->first();

        if ($existingTag) {
            // Jika tag sudah ada, increment duplicate count dan lewati proses impor
            $this->duplicateTagsCount++;
            return null;
        }

        $uppercasedtag = ucwords($tagName);

        // Jika tag valid dan belum ada, tambahkan ke database
        return new Tags([
            'name' => $uppercasedtag,
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
     * Mengembalikan jumlah tag yang invalid
     * 
     * @return int
     */
    public function getInvalidTagsCount(): int
    {
        return $this->invalidTagsCount;
    }

    /**
     * Mengembalikan jumlah tag yang duplikat
     * 
     * @return int
     */
    public function getDuplicateTagsCount(): int
    {
        return $this->duplicateTagsCount;
    }
}
