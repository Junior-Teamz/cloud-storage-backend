<?php

namespace App\Services;

use App\Models\File;
use App\Models\LegalBasis;
use Sqids\Sqids;

class GenerateURLService
{
    public function generateUrlForFile($file_id)
    {
        // Cari file berdasarkan ID
        $file = File::where('id', $file_id)->first();

        if (!$file) {
            return null; // Jika file tidak ditemukan, kembalikan null
        }

        // Buat URL yang diobfuscate menggunakan hashed ID
        return route('file.url', ['hashedId' => $file->id]);
    }

    public function generateUrlForVideo($file_id)
    {
        // Cari file berdasarkan ID
        $file = File::where('id', $file_id)->first();

        if (!$file) {
            return null; // Jika file tidak ditemukan, kembalikan null
        }

        // Buat URL streaming video
        return route('video.stream', ['hashedId' => $file->id]);
    }

    public function generateUrlForLegalBasis($id)
    {
        // Cari file berdasarkan ID
        $file = LegalBasis::where('id', $id)->first();

        if (!$file) {
            return null; // Jika file tidak ditemukan, kembalikan null
        }

        // Buat URL yang diobfuscate menggunakan hashed ID
        return route('pdf.url', ['hashedId' => $file->id]);
    }
}
