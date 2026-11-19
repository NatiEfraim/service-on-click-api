<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The logged-in user as the website's requester home reads it: the name and the role label. Not the ID number, phone, unit,
 * department or permission list (those are in GET me of the management API, for the people who act on them); the users
 * list of the admins keeps the full UserResource.
 */
class CurrentUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->full_name,
            'personal_number' => $this->personal_number,
            'roles' => $this->roles
                ->map(fn ($role): array => ['display_name' => $role->display_name ?? $role->name])
                ->values(),
        ];
    }
}
