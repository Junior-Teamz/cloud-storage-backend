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

        // Gunakan Sqids untuk menghasilkan hash dari ID
        $sqids = new Sqids(env('SQIDS_ALPHABET'), env('SQIDS_LENGTH', 10));
        $hashedId = $sqids->encode([$file->id]);

        // Buat URL yang diobfuscate menggunakan hashed ID
        return route('image.url', ['hashedId' => $hashedId]);
    }

    public function generateUrlForLegalBasis($id)
    {
        // Cari file berdasarkan ID
        $file = LegalBasis::find($id);

        if (!$file) {
            return null; // Jika file tidak ditemukan, kembalikan null
        }

        // Gunakan Sqids untuk menghasilkan hash dari ID
        $sqids = new Sqids(env('SQIDS_ALPHABET'), env('SQIDS_LENGTH', 10));
        $hashedId = $sqids->encode([$file->id]);

        // Buat URL yang diobfuscate menggunakan hashed ID
        return route('pdf.url', ['hashedId' => $hashedId]);
    }
}
