<?php

namespace App\Http\Resources;

use App\Enums\StatusBookingEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A dashboard status row; the caller sets a count attribute on the Status first. */
class DashboardStatusResource extends JsonResource
{
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
