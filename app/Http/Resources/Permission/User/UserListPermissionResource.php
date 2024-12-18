<?php

namespace App\Http\Resources\Permission\User;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserListPermissionResource extends JsonResource
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
        $userListResponse = [
            'permission_id' => $this->id,
            'permissions' => $this->permissions,
            'user_id' => $this->user_id,
        ];

        if ($this->folder_id) {
            $userListResponse['folder_id'] = $this->folder_id;
        } else if ($this->file_id) {
            $userListResponse['file_id'] = $this->file_id;
        }

        $userListResponse += [
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
                        // Tambahkan unit kerja disini
                    ];
                }),
            ]
        ];

        return $userListResponse;
    }
}
