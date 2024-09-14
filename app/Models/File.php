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

    // Accessor for file_path attribute
    public function getFilePathAttribute($value)
    {
        if ($value) {
            // If file_path exists, return the complete URL
            return asset('storage/' . $value);
        }
        // If file_path doesn't exist, return null or a default URL as per your requirement
        return null;
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
