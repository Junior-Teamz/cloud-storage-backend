<?php

namespace App\Http\Resources\Permission\Folder;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class UserFolderPermissionCollection extends ResourceCollection
{
    /**
     * Transform the resource collection into an array.
     *
     * @return array<int|string, mixed>
     */
    public function toArray(Request $request): array
    {
        return UserFolderPermissionResource::collection($this->collection)->resolve();
    }
}
