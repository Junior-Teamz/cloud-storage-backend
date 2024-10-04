<?php

namespace App\Models;


use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class File extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'name',
        'nanoid',
        'path',
        'public_path',
        'image_url',
        'size',
        'type',
        'user_id',
        'folder_id'
    ];

    protected static function boot() {
        
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    // Override method toArray to hide image_url if null
    public function toArray()
    {
        $array = parent::toArray();

        // Only include 'image_url' if it's not null
        if (is_null($this->image_url)) {
            unset($array['image_url']);
        }

        return $array;
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

    public function favorite(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'file_has_favorited')->withTimestamps();
    }
}
