<?php

namespace App\Http\Resources;

use App\Enums\BookingTypeEnum;
use App\Models\BookingType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class UserWithPermissionIconResource extends JsonResource
{
    private Collection $bookingTypes;
    /** Transform the resource into an array. @param Request $request @return array<string, mixed> */
    public function toArray(Request $request): array
    {

        $this->bookingTypes = BookingType::query()
            ->select(['id', 'display_name', 'icon'])
            ->get()
            ->keyBy('id');

        return [
            'uuid' => $this->uuid,
            'full_name' => $this->full_name,
            'personal_number' => $this->personal_number,
            'phone' => $this->phone,
            'permissions' => $this->buildPermissions(),
        ];
    }


    private function buildPermissions(): Collection
    {
        return $this->getAllPermissions()
            ->unique(fn($permission) => $this->extractBookingTypeId($permission->name))
            ->filter(fn($permission) => $this->shouldDisplayPermission($permission->name))
            ->map(fn($permission) => $this->formatPermission($permission->name))
            ->filter()
            ->values();
    }

    private function shouldDisplayPermission(string $permissionName): bool
    {
        $bookingTypeId = $this->extractBookingTypeId($permissionName);

        return $bookingTypeId !== BookingTypeEnum::EQUIPMENT_BOOKING->getBookingTypeId();
    }

    private function formatPermission(string $permissionName): ?array
    {
        $bookingTypeId = $this->extractBookingTypeId($permissionName);

        $bookingType = $this->bookingTypes->get($bookingTypeId);

        if (!$bookingType) {
            return null;
        }

        return [
            'booking_type_id' => $bookingType->id,
            'role' => $this->extractRoleName($permissionName),
            'display_name' => 'ניהול ' . $bookingType->display_name,
            'icon' => $bookingType->icon,
        ];
    }

    private function extractBookingTypeId(string $permissionName): int
    {
        $parts = explode('_', $permissionName);

        return (int) end($parts);
    }

    private function extractRoleName(string $permissionName): string
    {
        $lastUnderscorePosition = strrpos($permissionName, '_');

        if ($lastUnderscorePosition === false) {
            return $permissionName;
        }

        return substr($permissionName, 0, $lastUnderscorePosition);
    }
}
