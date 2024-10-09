<?php

namespace App\Models;


use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class NewsTag extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['name'];

    // Menyembunyikan pivot dari semua query secara global
    protected $hidden = ['pivot'];

    protected $table = 'news_tags';

    public function news(): BelongsToMany
    {
        return $this->belongsToMany(News::class, 'news_has_tags')->withTimestamps();
    }
}
