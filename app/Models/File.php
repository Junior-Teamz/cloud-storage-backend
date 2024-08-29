<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class File extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'nanoid', 'path', 'size', 'mime_type', 'user_id', 'folder_id'];

    protected static function boot()
    {
        parent::boot();

        // Automatically generate a NanoID when creating a new folder
        static::creating(function ($model) {
            if (empty($model->nanoid)) {
                $model->nanoid = self::generateNanoId();
            }
        });
    }

    public static function generateNanoId($size = 21)
    {
        return (new \Hidehalo\Nanoid\Client())->generateId($size);
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
}
