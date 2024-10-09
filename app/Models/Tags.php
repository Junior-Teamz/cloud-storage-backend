<?php

namespace App\Models;

use App\Traits\HasUUID;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Tags extends Model
{
    use HasFactory, HasUUID;

    protected $fillable = ['name'];

    protected $hidden = ['pivot'];

    protected $table = 'tags';

    public function folders()
    {
        return $this->belongsToMany(Folder::class, 'folder_has_tags');
    }

    public function files()
    {
        return $this->belongsToMany(File::class, 'file_has_tags');
    }

    // Menghitung total penggunaan tag
    public function calculateUsageCount()
    {
        // Menghitung total penggunaan tag dari folder dan file
        return $this->folders()->count() + $this->files()->count();
    }

}
