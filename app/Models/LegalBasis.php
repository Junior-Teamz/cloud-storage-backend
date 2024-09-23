<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LegalBasis extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'legal_type', 'description', 'file_name', 'file_path'
    ];
}
