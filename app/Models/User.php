<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use App\Notifications\ResetPasswordNotification;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasPermissions;
use Spatie\Permission\Traits\HasRoles;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable, HasApiTokens, HasRoles, HasUuids, HasPermissions;

    protected $guard_name = 'api';

    protected $appends = [];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'id',
        'name',
        'email',
        'password',
        'photo_profile_path',
        'photo_profile_url'
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

    // Getter untuk roles yang tidak konflik
    public function getUserRolesAttribute()
    {
        return $this->getRoleNames()->toArray(); // Mengambil nama-nama role sebagai array
    }

    // Alias agar tetap mendukung nama 'roles'
    public function toArray()
    {
        $array = parent::toArray();
        $array['roles'] = $this->user_roles; // Tambahkan roles secara manual
        return $array;
    }

    protected static function booted()
    {
        static::created(function ($user) {
            // Create root folder in database
            $folder = \App\Models\Folder::create([
                'name' => $user->name . ' Main Folder',
                'user_id' => $user->id,
                'parent_id' => null, // Root folder has no parent
            ]);

            $tag = Tags::where('name', 'Root')->first();
            $folder->tags()->attach($tag->id);

            // Get nanoid from newly created folder
            $folderNanoid = $folder->nanoid;

            // Create directory in storage/app/users/{nanoid}
            $folderPath = 'users/' . $folderNanoid;

            // Create physical directory in Laravel local storage
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

    public function section()
    {
        return $this->belongsToMany(InstanceSection::class, 'user_has_sections')->withTimestamps();
    }

    public function favoriteFolders(): BelongsToMany
    {
        return $this->belongsToMany(Folder::class, 'folder_has_favorited')->withTimestamps();
    }

    public function favoriteFiles(): BelongsToMany
    {
        return $this->belongsToMany(File::class, 'file_has_favorited')->withTimestamps();
    }

    // // Tambahkan accessor untuk mengambil instansi terkait
    // public function getInstanceDataAttribute()
    // {
    //     return $this->instances()->get();  // Mengambil semua instance yang terkait dengan user
    // }

    // // Append custom attribute `instance_data` ke model User
    // protected $appends = ['instance_data'];
}
