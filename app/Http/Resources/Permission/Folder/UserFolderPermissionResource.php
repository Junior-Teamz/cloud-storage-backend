<?php

namespace App\Http\Resources\Permission\Folder;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserFolderPermissionResource extends JsonResource
{
    public function __construct($resource)
    {
        parent::__construct($resource);
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'permission_id' => $this->id,
            'user_id' => $this->user_id,
            'folder_id' => $this->folder_id,
            'permissions' => $this->permissions,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
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
            'folder' => [
                'id' => $this->folder->id,
                'name' => $this->folder->name,
                'parent_id' => $this->folder->parent_id,
                'public_path' => $this->folder->public_path,
                'total_subfolder' => $this->folder->calculateTotalSubfolder(),
                'total_file' => $this->folder->calculateTotalFile(),
                'total_size' => $this->folder->calculateTotalSize(),
                'type' => $this->folder->type,
                'tags' => $this->folder->tags->map(fn($tag) => $tag->only(['id', 'name'])),
                'instances' => $this->folder->instances->map(fn($instance) => $instance->only(['id', 'name', 'address'])),
                'created_at' => $this->folder->created_at,
                'updated_at' => $this->folder->updated_at
            ],
        ];
    }
}
