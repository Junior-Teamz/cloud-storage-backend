<?php

namespace App\Http\Resources\Instance\Section;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class InstanceSectionCollection extends ResourceCollection
{
    /**
     * Transform the resource collection into an array.
     *
     * @return array<int|string, mixed>
     */
    public function toArray(Request $request): array
    {
        return InstanceSectionResource::collection($this->collection)->resolve();
    }
}
