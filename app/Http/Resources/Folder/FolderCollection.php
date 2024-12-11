<?php

namespace App\Http\Resources\Folder;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class FolderCollection extends ResourceCollection
{
    /**
     * Transform the resource collection into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return FolderResource::collection($this->collection)->resolve();
    }
}
