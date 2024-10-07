<?php

namespace App\Models;

use App\Traits\HasUUID;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Instance extends Model
{
    use HasFactory, HasUUID;

    protected $table = 'instances';

    protected $hidden = ['pivot'];

    protected $fillable = ['name', 'address'];
    
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
