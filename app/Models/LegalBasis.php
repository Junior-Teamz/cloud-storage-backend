<?php

namespace App\Models;


use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class LegalBasis extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name', 'file_name', 'file_path', 'file_url'
    ];
}
