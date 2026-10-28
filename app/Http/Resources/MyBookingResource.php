<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Carbon\Carbon;

class MyBookingResource extends JsonResource
{
    /** Transform the resource into an array. @param Request $request @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'serial_number' => $this->serial_number,
            'status' => [
                'id' => $this->status?->id,
                'name' => $this->status?->name,
                'display_name' => $this->status?->display_name,
            ],
            'created_at' => $this->created_at
                ? Carbon::parse($this->created_at)->format('d/m/Y')
                : null,
            'updated_at' => $this->updated_at
                ? Carbon::parse($this->updated_at)->format('d/m/Y')
                : null,
            'booking_type' => [
                'id' => $this->bookingType?->id,
                'display_name' => $this->bookingType?->display_name,
                'icon' => $this->bookingType?->icon,
            ],
        ];
    }
}
