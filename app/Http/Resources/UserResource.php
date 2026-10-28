<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /** Transform the resource into an array. @param Request $request @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->full_name,
            'personal_number' => $this->personal_number,
            'personal_id' => $this->personal_id,
            'phone' => $this->phone,
            'roles' => $this->formatRoles(),
            'permissions' => $this->formatPermissions(),
        ];
    }


    /** Format user roles. @return \Illuminate\Support\Collection */
    private function formatRoles()
    {
        return $this->roles
            ->map(fn($role): array => $this->formatRole($role))
            ->values();
    }

    /** Format single role. @param mixed $role @return array */
    private function formatRole($role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'display_name' => $role->display_name ?? $role->name,
        ];
    }

    /** Format user permissions. @return \Illuminate\Support\Collection */
    private function formatPermissions()
    {
        return $this->getAllPermissions()
            ->map(fn($permission): array => $this->formatPermission($permission))
            ->values();
    }

    /** Format single permission. @param mixed $permission @return array */
    private function formatPermission($permission): array
    {
        return [
            'id' => $permission->id,
            'name' => $permission->name,
            'display_name' => $permission->display_name ?? $permission->name,
        ];
    }
}
