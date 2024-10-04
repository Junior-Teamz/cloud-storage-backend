<?php

namespace App\Models;


use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Tags extends Model
{
    use HasFactory;

    protected $fillable = ['uuid', 'name'];

    protected $hidden = ['pivot'];

    protected $table = 'tags';

    protected static function boot() {
        
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function folders()
    {
        return $this->belongsToMany(Folder::class, 'folder_has_tags');
    }

    public function files()
    {
        return $this->belongsToMany(File::class, 'file_has_tags');
    }

}
