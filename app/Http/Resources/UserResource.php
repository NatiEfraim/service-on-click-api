<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->full_name,
            'personal_number' => $this->personal_number,
            'personal_id' => $this->personal_id,
            'phone' => $this->phone,
            'unit_id' => $this->unit_id,
            // Only when unit / department were loaded: the resource queries nothing itself.
            'unit_name' => $this->relationLoaded('unit') ? $this->unit?->displayName() : null,
            'department_id' => $this->department_id,
            'department_name' => $this->relationLoaded('department') ? $this->department?->name : null,
            'roles' => $this->formatRoles(),
            'permissions' => $this->formatPermissions(),
        ];
    }


    /** @return \Illuminate\Support\Collection */
    private function formatRoles()
    {
        return $this->roles
            ->map(fn($role): array => $this->formatRole($role))
            ->values();
    }

    private function formatRole($role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'display_name' => $role->display_name ?? $role->name,
        ];
    }

    /** @return \Illuminate\Support\Collection */
    private function formatPermissions()
    {
        return $this->getAllPermissions()
            ->map(fn($permission): array => $this->formatPermission($permission))
            ->values();
    }

    private function formatPermission($permission): array
    {
        return [
            'id' => $permission->id,
            'name' => $permission->name,
            'display_name' => $permission->display_name ?? $permission->name,
        ];
    }
}
