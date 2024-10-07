<?php

namespace App\Models;

use App\Traits\HasUUID;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class LegalBasis extends Model
{
    use HasFactory, HasUUID;

    protected $fillable = [
        'name', 'file_name', 'file_path'
    ];
}
