<?php

namespace App\Services;

use App\Models\File;

class GenerateURLService
{
    /**
     * Generate a URL for a file.
     *
     * This method generates a URL for accessing a file based on its UUID. It retrieves the file
     * from the database using the provided file UUID. If the file is found, it constructs a URL
     * using the 'file.url' route, passing the file UUID as a parameter. This URL can be used to
     * access the file.
     *
     * @param string $file_id The UUID of the file.
     * @return string|null The generated URL for the file, or null if the file is not found.
     */
    public function generateUrlForFile($file_id)
    {
        // Cari file berdasarkan ID
        $file = File::where('id', $file_id)->first();

        if (!$file) {
            return null; // Jika file tidak ditemukan, kembalikan null
        }

        // Buat URL yang diobfuscate menggunakan hashed ID
        return route('file.url', ['id' => $file->id]);
    }

    /**
     * Generate a URL for streaming a video.
     *
     * This method generates a URL for streaming a video file based on its UUID. It retrieves the file
     * from the database using the provided file UUID. If the file is found, it constructs a URL
     * using the 'video.stream' route, passing the file UUID as a parameter. This URL can be used to
     * stream the video content.
     *
     * @param string $file_id The UUID of the video file.
     * @return string|null The generated streaming URL for the video, or null if the file is not found.
     */
    public function generateUrlForVideo($file_id)
    {
        // Cari file berdasarkan ID
        $file = File::where('id', $file_id)->first();

        if (!$file) {
            return null; // Jika file tidak ditemukan, kembalikan null
        }

        // Buat URL streaming video
        return route('video.stream', ['id' => $file->id]);
    }
}
