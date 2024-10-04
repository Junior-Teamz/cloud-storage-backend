<?php

namespace App\Services;

use App\Models\File;
use App\Models\LegalBasis;
use Sqids\Sqids;

class GenerateURLService
{
    public function generateUrlForImage($file_id)
    {
        // Cari file berdasarkan ID
        $file = File::find($file_id);

        if (!$file) {
            return null; // Jika file tidak ditemukan, kembalikan null
        }

        // Buat URL yang diobfuscate menggunakan hashed ID
        return route('image.url', ['hashedId' => $file->id]);
    }

    public function generateUrlForLegalBasis($id)
    {
        // Cari file berdasarkan ID
        $file = LegalBasis::find($id);

        if (!$file) {
            return null; // Jika file tidak ditemukan, kembalikan null
        }

        // Buat URL yang diobfuscate menggunakan hashed ID
        return route('pdf.url', ['hashedId' => $file->id]);
    }
}
