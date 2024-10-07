<?php

namespace App\Models;

use App\Traits\HasUUID;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FAQ extends Model
{
    use HasFactory, HasUUID;

    protected $table = 'faq';

    protected $fillable = ['question', 'answer'];
}
