<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class NewsTag extends Model
{
    use HasFactory;

    protected $fillable = ['name'];


    public function news(): BelongsToMany
    {
        return $this->belongsToMany(News::class, 'news_has_tags')->withTimestamps();
    }
}
