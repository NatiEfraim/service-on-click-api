<?php

namespace App\Enums;

use App\Models\GroupBookingTypeHasBookingType;
use Illuminate\Support\Facades\Auth;

use App\Models\GroupBookingType;

enum GroupBookingTypeEnum
{
    case CAR;
    case WEAPON;
    case FOODֹ_BOOKING;
    case EQUIPMENT;

    case WORK_SPACES;

    /** The group's DB id, memoized per request. */
    public function getGroupBookingTypeId(): ?int
    {
        static $cache = [];

        if (array_key_exists($this->name, $cache)) {
            return $cache[$this->name];
        }

        return $cache[$this->name] = match ($this) {
            self::CAR => GroupBookingType::where('name', 'car')->value('id') ?? null,
            self::WEAPON => GroupBookingType::where('name', 'weapon')->value('id') ?? null,
            self::EQUIPMENT => GroupBookingType::where('name', 'equipment')->value('id') ?? null,
            self::WORK_SPACES => GroupBookingType::where('name', 'work_spaces')->value('id')??null,
            self::FOODֹ_BOOKING => GroupBookingType::where('name', 'food_booking')->value('id')?? null,
        };
    }

    /** Booking type ids that belong to a group. */
    public static function getBookingTypeIdsByGroupId(int $groupBookingTypeId): array
    {
        return GroupBookingTypeHasBookingType::where('group_booking_id', $groupBookingTypeId)
            ->pluck('booking_type_id')
            ->toArray();
    }

    public function getNavTitle(): ?string
    {
        return match ($this) {
            self::CAR => 'רכב',
            self::WEAPON => 'נשק',
            self::EQUIPMENT => 'ציוד',
            self::WORK_SPACES => 'מתחמי עבודה',
            self::FOODֹ_BOOKING => 'הזמנת מזון',

            default => null,
        };
    }
    /** Hebrew management nav title ("הגדרות" prefix for super admin). */
    public function getManagementNavTitle(): ?string
    {

        $user = Auth::user();
        $roleEnum = $user->getRoleEnum();
        $prefix = 'ניהול ';
        if ($roleEnum->value==RoleEnum::SUPER_ADMIN->value) {
            $prefix='הגדרות ';
        }

        return match ($this) {
            self::CAR => $prefix . 'רכב',
            self::WEAPON => $prefix . 'נשק',
            self::EQUIPMENT => $prefix . 'ציוד',
            self::WORK_SPACES => $prefix . 'מתחמי עבודה', 
            self::FOODֹ_BOOKING => $prefix . 'מזון', 

            default => null,
        };
    }

    /** Resolves the enum from the group name. */
    public static function fromGroupBookingTypeName(string $name): ?self
    {
        return match ($name) {
            'car' => self::CAR,
            'weapon' => self::WEAPON,
            'equipment' => self::EQUIPMENT,
            'work_spaces' => self::WORK_SPACES,
            'food_booking' => self::FOODֹ_BOOKING,
            default => null,
        };
    }
}
