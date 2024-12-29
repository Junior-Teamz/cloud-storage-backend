<?php

namespace App\Http\Resources\Instance;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;

class InstanceResource extends JsonResource
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

        $instanceResponse = [
            'id' => $this->id,
            'name' => $this->name,
            'address' => $this->address,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at
        ];

        if (($user->hasRole('admin') && $user->hasPermission('instance.section.read')) || $user->hasRole('superadmin')){
            $instanceResponse += [
                'sections' => $this->sections?->map(function ($section) {
                    return [
                        'id' => $section->id,
                        'name' => $section->name,
                        'created_at' => $section->created_at,
                        'updated_at' => $section->updated_at
                    ];
                })
            ];
        }

        return $instanceResponse;
    }
}
