<?php

namespace App\Http\Resources\Permission\File;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class UserFilePermissionCollection extends ResourceCollection
{
    /**
     * Transform the resource collection into an array.
     *
     * @return array<int|string, mixed>
     */
    public function toArray(Request $request): array
    {
        return UserFilePermissionResource::collection($this->collection)->resolve();
    }
}
