<?php

namespace App\Services;

use App\Models\File;
use Sqids\Sqids;

class GenerateImageURLService
{
    public function generateUrlForImage($file_id)
    {
        // Cari file berdasarkan ID
        $file = File::find($file_id);

        if (!$file) {
            return null; // Jika file tidak ditemukan, kembalikan null
        }

        // Gunakan Sqids untuk menghasilkan hash dari ID
        $sqids = new Sqids(env('SQIDS_ALPHABET'), 20);
        $hashedId = $sqids->encode([$file->id]);

        // Buat URL yang diobfuscate menggunakan hashed ID
        return route('image.url', ['hashedId' => $hashedId]);
    }
}
