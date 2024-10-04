<?php

namespace App\Models;


use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class NewsTag extends Model
{
    use HasFactory;

    protected $fillable = ['uuid', 'name'];

    // Menyembunyikan pivot dari semua query secara global
    protected $hidden = ['pivot'];

    protected $table = 'news_tags';

    protected static function boot() {
        
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function news(): BelongsToMany
    {
        return $this->belongsToMany(News::class, 'news_has_tags')->withTimestamps();
    }
}
