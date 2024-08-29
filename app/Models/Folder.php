<?php

namespace App\Models;

use App\Casts\HashId;
use App\Exceptions\ForbiddenActionException;
use App\Models\Scopes\ProtectRootFolderScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class Folder extends Model
{
    use HasFactory;

    protected $fillable = [
        'nanoid', 'name', 'user_id', 'parent_id'
    ];

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
}