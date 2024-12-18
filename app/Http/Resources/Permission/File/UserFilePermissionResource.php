<?php

namespace App\Http\Resources\Permission\File;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Services\GenerateURLService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UserFilePermissionResource extends JsonResource
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
        // Cek MIME type dengan handling null
        $mimeType = Storage::exists($this->path) ? Storage::mimeType($this->path) : null;

        $userFilePermissionResponse = [
            'permission_id' => $this->id,
            'user_id' => $this->user_id,
            'file_id' => $this->file_id,
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
            'file' => [
                    'id' => $this->file->id,
                    'folder_id' => $this->file->folder_id,
                    'name' => $this->file->name,
                    'public_path' => $this->file->public_path,
                    'size' => $this->file->size,
                    'type' => $this->file->type,
                    'file_url' => $this->file->file_url,
                    'created_at' => $this->file->created_at,
                    'updated_at' => $this->file->updated_at,
                    'tags' => $this->file->tags->map(fn($tag) => $tag->only(['id', 'name'])),
                    'instances' => $this->file->instances->map(fn($instance) => $instance->only(['id', 'name', 'address'])),
            ],
        ];

        if ($mimeType && Str::startsWith($mimeType, 'video')) {
            $userFilePermissionResponse['file']['video_url'] = app(GenerateURLService::class)->generateUrlForVideo($this->id);
        }

        return $userFilePermissionResponse;
    }
}
