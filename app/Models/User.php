<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Tymon\JWTAuth\Contracts\JWTSubject;
use Illuminate\Support\Str;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable, HasApiTokens, HasRoles;

    protected $guard_name = 'api';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_superadmin'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected static function booted()
    {
        static::created(function ($user) {
            // Buat folder root di database
            $folder = \App\Models\Folder::create([
                'name' => $user->name . ' Main Folder',
                'user_id' => $user->id,
                'parent_id' => null, // Folder root tidak memiliki parent
            ]);

            $tag = Tags::where('name', 'Root')->first();

            $folder->tags()->attach($tag->id);

            // Ambil nanoid dari folder yang baru dibuat
            $folderNanoid = $folder->nanoid;

            // Buat direktori di storage/app/ dengan nama folder adalah nanoid
            $folderPath = $folderNanoid;

            // Buat direktori fisik di penyimpanan lokal Laravel
            Storage::makeDirectory($folderPath);
        });
    }


    public function getPermissionArray()
    {
        return $this->getAllPermissions()->mapWithKeys(function ($pr) {
            return [$pr['name'] => true];
        });
    }

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }

    public function folders(): HasMany
    {
        return $this->hasMany(Folder::class);
    }

    // relasi perizinan
    public function folderPermissions(): HasMany
    {
        return $this->hasMany(UserFolderPermission::class);
    }

    public function filePermissions(): HasMany
    {
        return $this->hasMany(UserFilePermission::class);
    }

    public function news(): HasMany
    {
        return $this->hasMany(News::class);
    }

    // Relasi many-to-many dengan Instance
    public function instances(): BelongsToMany
    {
        return $this->belongsToMany(Instance::class, 'user_has_instances')->withTimestamps();
    }

    // // Tambahkan accessor untuk mengambil instansi terkait
    // public function getInstanceDataAttribute()
    // {
    //     return $this->instances()->get();  // Mengambil semua instance yang terkait dengan user
    // }

    // // Append custom attribute `instance_data` ke model User
    // protected $appends = ['instance_data'];
    
}
