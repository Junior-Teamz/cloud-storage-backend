<?php

namespace App\Http\Resources\Instance;

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
        $getCurrentAdminInstanceId = $user->instances->id;

        $instanceResponse = [
            'id' => $this->id,
            'name' => $this->name,
            'address' => $this->address
        ];

        if($user->hasRole('superadmin') || ($user->hasRole('admin') && $getCurrentAdminInstanceId === $this->id)){
            $instanceResponse += [
                'created_at' => $this->created_at,
                'updated_at' => $this->updated_at
            ];
        }

        return $instanceResponse;
    }
}
