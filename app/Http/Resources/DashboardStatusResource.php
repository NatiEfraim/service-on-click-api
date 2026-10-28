<?php

namespace App\Http\Resources;

use App\Enums\StatusBookingEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A single dashboard status row; the caller attaches a `count` attribute to the Status model before wrapping. */
class DashboardStatusResource extends JsonResource
{
    /** Transform the resource into an array. @param Request $request @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'status_id' => $this->id,
            'status_display_name' => StatusBookingEnum::tryFrom($this->id)?->getDashboardDisplayName(),
            'status_icon' => StatusBookingEnum::tryFrom($this->id)?->getStatusIconDashBoard(),
            'count' => (int) $this->count,
        ];
    }
}
