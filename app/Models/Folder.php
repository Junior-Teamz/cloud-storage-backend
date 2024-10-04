<?php

namespace App\Models;

use App\Services\HashIdService;
use Exception;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class Folder extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'nanoid',
        'name',
        'type',
        'public_path',
        'user_id',
        'parent_id'
    ];

    protected static function boot()
    {
        parent::boot();

        // Automatically generate a NanoID when creating a new folder
        static::creating(function ($model) {

            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }

            if (empty($model->nanoid)) {
                $model->nanoid = self::generateNanoId();
            };

            if (empty($model->type)) {
                $model->type = 'folder';
            };

            // Generate the public_path before the folder is saved to the database
            if (empty($model->public_path)) {
                $model->public_path = $model->generatePublicPath();
            }
        });
    }

    public static function generateNanoId($size = 21)
    {
        return (new \Hidehalo\Nanoid\Client())->generateId($size);
    }

    // Generate the public path based on the folder structure (parent)
    public function generatePublicPath()
    {
        $path = [];

        // If the folder has a parent, build the path from the parent's public_path
        if ($this->parent_id) {
            $parentFolder = Folder::find($this->parent_id);

            if ($parentFolder) {
                $path[] = $parentFolder->public_path;  // Append parent's public_path
            }
        }

        // Append the current folder's name to the path
        $path[] = $this->name;

        // Return the constructed path
        return implode('/', $path);
    }

    public function files()
    {
        return $this->hasMany(File::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function parentFolder()
    {
        return $this->belongsTo(Folder::class, 'parent_id');
    }

    public function subfolders()
    {
        return $this->hasMany(Folder::class, 'parent_id');
    }

    public function userFolderPermissions()
    {
        return $this->hasMany(UserFolderPermission::class);
    }

    // Add this method to define the many-to-many relationship with tags
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tags::class, 'folder_has_tags')->withTimestamps(); // menggunakan tabel pivot untuk menyalakan otomatisasi timestamp().
    }

    // Relasi many-to-many dengan InstanceModel
    public function instances(): BelongsToMany
    {
        return $this->belongsToMany(Instance::class, 'folder_has_instances')->withTimestamps(); // menggunakan tabel pivot untuk menyalakan otomatisasi timestamp().
    }

    public function favorite(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'folder_has_favorited')->withTimestamps();
    }
}
