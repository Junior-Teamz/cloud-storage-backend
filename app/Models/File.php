<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class File extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'nanoid',
        'path',
        'public_path',
        'file_url',
        'size',
        'type',
        'user_id',
        'folder_id'
    ];

    // // Override method toArray to hide file_url if null
    // public function toArray()
    // {
    //     $array = parent::toArray();

    //     // Only include 'file_url' if it's not null
    //     if (is_null($this->file_url)) {
    //         unset($array['file_url']);
    //     }

    //     return $array;
    // }

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

    public function favorite(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'file_has_favorited')->withTimestamps();
    }
}
