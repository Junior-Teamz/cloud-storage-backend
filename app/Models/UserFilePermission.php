<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserFilePermission extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'file_id', 'permissions'
    ];

    protected $casts = [
        'permissions' => 'array', // Secara otomatis meng-cast JSON ke array
    ];

    public function users(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function files(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }
}
