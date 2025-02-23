<?php

namespace App\Http\Resources\File;

use App\Models\File;
use App\Services\GenerateURLService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileResource extends JsonResource
{
    protected $userId;

    /**
     * Construct the resource.
     *
     * @param mixed $resource
     * @param int|null $userId
     */
    public function __construct($resource, $userId = null)
    {
        parent::__construct($resource);
        $this->userId = $userId;
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Gunakan user ID yang diberikan atau fallback ke user yang sedang login
        $currentUserId = Auth::user()->id;

        $fileInfo = File::where('id', $this->id)->first();

        $favorite = $fileInfo->favorite()->where('user_id', $currentUserId)->first();
        $isFavorite = !is_null($favorite);
        $favoritedAt = $isFavorite ? $favorite->pivot->created_at : null;

        // Cek MIME type dengan handling null
        $mimeType = Storage::exists($this->path) ? Storage::mimeType($this->path) : null;

        $fileResponse = [
            'id' => $this->id,
            'folder_id' => $this->folder_id,
            'name' => $this->name,
            'public_path' => $this->public_path,
            'size' => $this->size,
            'type' => $this->type,
            'file_url' => $this->file_url,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'is_favorite' => $isFavorite,
            'favorited_at' => $favoritedAt,
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'photo_profile_url' => $this->user->photo_profile_url,
                'roles' => $this->user->roles->pluck('name'),
                'instances' => $this->user->instances?->map(function ($userFileInstance) {
                    return [
                        'id' => $userFileInstance->id,
                        'name' => $userFileInstance->name,
                        'address' => $userFileInstance->address,
                        'sections' => $userFileInstance->sections?->map(function ($section) {
                            return [
                                'id' => $section->id,
                                'instance_id' => $section->instance_id,
                                'name' => $section->name,
                            ];
                        })
                    ];
                })
            ],
            'tags' => $this->tags->map(fn($tag) => $tag->only(['id', 'name'])),
            'instances' => $this->instances?->map(fn($instance) => $instance->only(['id', 'name', 'address'])),
            'shared_with' => $this->userPermissions->map(function ($permission) {
                return [
                    'id' => $permission->id,
                    'file_id' => $permission->file_id,
                    'permissions' => $permission->permissions,
                    'created_at' => $permission->created_at,
                    'user' => [
                        'id' => $permission->user->id,
                        'name' => $permission->user->name,
                        'email' => $permission->user->email,
                        'photo_profile_url' => $permission->user->photo_profile_url,
                        'roles' => $permission->user->roles->pluck('name'),
                        'instance' => $permission->user->instances?->map(function ($userInstance) {
                            return [
                                'id' => $userInstance->id,
                                'name' => $userInstance->name,
                                'address' => $userInstance->address,
                                'sections' => $userInstance->sections?->map(function ($section) {
                                    return [
                                        'id' => $section->id,
                                        'instance_id' => $section->instance_id,
                                        'name' => $section->name,
                                    ];
                                }),
                            ];
                        })
                    ]
                ];
            }),
        ];

        if ($mimeType && Str::startsWith($mimeType, 'video')) {
            $fileResponse['video_url'] = app(GenerateURLService::class)->generateUrlForVideo($this->id);
        }

        return $fileResponse;
    }
}
