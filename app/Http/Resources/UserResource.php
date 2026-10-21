<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'is_staff' => $this->whenLoaded('roles', fn () => $this->roles->isNotEmpty()),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('slug')),
            // For showing or hiding screens only. Every action is authorised again on the server.
            'permissions' => $this->whenLoaded('roles', fn () => $this->roles->isNotEmpty() ? $this->permissionSlugs() : []),
        ];
    }
}
