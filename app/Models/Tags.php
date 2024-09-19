<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tags extends Model
{
    use HasFactory;

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

    public function getUsageCountAttribute()
    {
        // Hitung jumlah penggunaan tag pada files
        $fileCount = $this->files()->count();

        // Hitung jumlah penggunaan tag pada folders
        $folderCount = $this->folders()->count();

        // Gabungkan keduanya
        return $fileCount + $folderCount;
    }
}
