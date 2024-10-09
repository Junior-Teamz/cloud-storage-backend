<?php

namespace App\Models;


use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Tags extends Model
{
    use HasFactory, HasUuids;

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

    public function calculateFolderUsageCount()
    {
        return $this->folders()->count();
    }

    public function calculateFileUsageCount()
    {
        return $this->files()->count();
    }

}
