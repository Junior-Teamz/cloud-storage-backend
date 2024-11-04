<?php

namespace App\Services;

use Sqids\Sqids;

/**
 * This class is not used anymore and should be deleted soon.
 */
class HashIdService
{
     // Encode ID menggunakan Sqids dengan prefix
     public function encodeId($id)
     {
         // Inisialisasi Sqids
         $sqids = new Sqids(env('SQIDS_ALPHABET'), env('SQIDS_LENGTH'));
 
         // Tambahkan prefix dengan ID untuk memastikan uniqueness
         $encoded = $sqids->encode([$id]);
 
         return $encoded;
     }
 
     // Decode hash kembali ke ID asli
     public function decodeId($hash)
     {
         // Inisialisasi Sqids
         $sqids = new Sqids(env('SQIDS_ALPHABET'), env('SQIDS_LENGTH'));
 
         // Decode hash menjadi array
         $decoded = $sqids->decode($hash);
 
         // Ambil ID asli. Jika gagal atau error, kembalikan null
         return $decoded ?? null;
     }
 
}
