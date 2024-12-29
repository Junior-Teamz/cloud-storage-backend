<?php

namespace App\Http\Resources\Instance\Section;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;

class InstanceSectionResource extends JsonResource
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
        $user = Auth::user();

        $instanceSectionResponse = [
            'id' => $this->id,
            'instance_id' => $this->instance_id,
            'nama' => $this->name,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];

        if (($user->hasRole('admin') && $user->hasPermission('instance.read')) || $user->hasRole('superadmin')) {
            $instanceSectionResponse += [
                'instance' => [
                    'id' => $this->instance->id,
                    'name' => $this->instance->name,
                    'address' => $this->instance->address,
                    'created_at' => $this->instance->created_at,
                    'updated_at' => $this->instance->updated_at,
                ]
            ];
        }

        return $instanceSectionResponse;
    }
}
