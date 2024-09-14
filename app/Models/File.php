<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;

class File extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'nanoid',
        'path',
        'public_path',
        'size',
        'type',
        'user_id',
        'folder_id'
    ];

    public function generateTemporaryUrl()
    {
        // Dapatkan token JWT dari user
        $token = JWTAuth::parseToken();

        // Dapatkan detail token JWT termasuk masa berlaku (expiry time)
        $payload = $token->getPayload();

        // Dapatkan waktu expire dari token JWT (in seconds)
        $expiryTime = $payload->get('exp'); // UNIX timestamp

        // Hitung sisa waktu sebelum token kadaluarsa
        $remainingTime = $expiryTime - now()->timestamp;

        // Jika token sudah kadaluarsa, set waktu default 0 (bisa juga 1 menit atau beri respon error)
        if ($remainingTime <= 0) {
            $remainingTime = 0;
        }

        // Generate URL yang mengikuti expiry time dari JWT
        return Storage::temporaryUrl($this->path, now()->addSeconds($remainingTime));
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function folder()
    {
        return $this->belongsTo(Folder::class);
    }

    public function userPermissions()
    {
        return $this->hasMany(UserFilePermission::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tags::class, 'file_has_tags')->withTimestamps(); // menggunakan tabel pivot untuk menyalakan otomatisasi timestamp().
    }

    public function instances(): BelongsToMany
    {
        return $this->belongsToMany(Instance::class, 'file_has_instances')->withTimestamps(); // menggunakan tabel pivot untuk menyalakan otomatisasi timestamp().
    }
}
