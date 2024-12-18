<?php

namespace App\Http\Resources\Folder;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FolderResource extends JsonResource
{
    protected $userId; // Opsional: ID pengguna yang digunakan untuk pemeriksaan

    /**
     * Konstruktor untuk FolderResource.
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
        $currentUserId = $this->userId ?? $request->user()?->id;

        // Cek apakah folder ini difavoritkan oleh user yang relevan
        $favorite = $this->favorite()->where('user_id', $currentUserId)->first();
        $isFavorite = !is_null($favorite);
        $favoritedAt = $isFavorite ? $favorite->pivot->created_at : null;

        $folderResponse = [
            'id' => $this->id,
            'name' => $this->name,
            'parent_id' => $this->parent_id,
            'public_path' => $this->public_path,
            'total_subfolder' => $this->calculateTotalSubfolder(),
            'total_file' => $this->calculateTotalFile(),
            'total_size' => $this->calculateTotalSize(),
            'type' => $this->type,
            'is_favorite' => $isFavorite,
            'favorited_at' => $favoritedAt,
            'user' => [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'email' => $this->user->email,
                    'photo_profile_url' => $this->user->photo_profile_url,
                    'roles' => $this->user->roles->pluck('name'),
                    'instances' => $this->user->instances->map(function ($userFolderInstance) {
                        return [
                            'id' => $userFolderInstance->id,
                            'name' => $userFolderInstance->name,
                            'address' => $userFolderInstance->address
                            // TODO: Tambahkan unit kerja disini
                        ];
                    })
                ],
            'tags' => $this->tags->map(fn($tag) => $tag->only(['id', 'name'])),
            'instances' => $this->instances->map(fn($instance) => $instance->only(['id', 'name', 'address'])),
            'shared_with' => $this->userFolderPermissions->map(function ($permission) {
                return [
                    'id' => $permission->id,
                    'folder_id' => $permission->folder_id,
                    'permissions' => $permission->permissions,
                    'created_at' => $permission->created_at,
                    'user' => [
                        'id' => $permission->user->id,
                        'name' => $permission->user->name,
                        'email' => $permission->user->email,
                        'photo_profile_url' => $permission->user->photo_profile_url,
                        'roles' => $permission->user->roles->pluck('name'),
                        'instance' => $permission->user->instances->map(function ($userInstance) {
                            return [
                                'id' => $userInstance->id,
                                'name' => $userInstance->name,
                                'address' => $userInstance->address,
                                // Tambahkan unit kerja disini
                            ];
                        })
                    ]
                ];
            })
        ];

        if ($this->parent_id !== null) {
            $folderResponse['created_at'] = $this->created_at;
            $folderResponse['updated_at'] = $this->updated_at;
        }

        return $folderResponse;
    }
}
