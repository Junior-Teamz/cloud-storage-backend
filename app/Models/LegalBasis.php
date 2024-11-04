<?php

namespace App\Models;


use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * This is Model for Legal Basis (IDN: Dasar Hukum).
 */
class LegalBasis extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name', 'file_name', 'file_path', 'file_url'
    ];
}
