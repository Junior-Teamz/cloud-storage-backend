<?php

namespace App\Models;


use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class LegalBasis extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid', 'name', 'file_name', 'file_path'
    ];

    protected static function boot() {
        
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }
}
