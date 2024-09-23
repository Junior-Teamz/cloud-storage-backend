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

}
