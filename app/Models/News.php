<?php

namespace App\Models;


use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class News extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'created_by',
        'title',
        'thumbnail_path',
        'thumbnail_url',
        'slug',
        'content',
        'viewer',
        'status'
    ];

    // Menyembunyikan pivot dari semua query secara global
    protected $hidden = ['created_by'];

    // Relasi ke model User
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function newsTags(): BelongsToMany
    {
        return $this->belongsToMany(NewsTag::class, 'news_has_tags')->withTimestamps(); // menggunakan tabel pivot untuk menyalakan otomatisasi timestamp().
    }

    // Accessor untuk thumbnail
    public function getThumbnailAttribute($value)
    {
        // Cek apakah $value merupakan URL valid
        if (filter_var($value, FILTER_VALIDATE_URL)) {
            return $value; // Jika sudah URL, kembalikan nilai aslinya
        }

        // Jika bukan URL, tambahkan base URL atau path ke file
        return Storage::url($value);
    }
}
