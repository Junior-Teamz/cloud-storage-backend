<?php

namespace App\Models;

use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Instance extends Model
{
    use HasFactory;

    protected $table = 'instances';

    protected $hidden = ['pivot'];

    protected $fillable = ['uuid', 'name', 'address'];

    protected static function boot() {
        
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }
    
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_has_instances');
    }

    public function folders(): BelongsToMany
    {
        return $this->belongsToMany(Folder::class, 'folder_has_instances');
    }

    public function files(): BelongsToMany
    {
        return $this->belongsToMany(File::class, 'file_has_instances');
    }
}
