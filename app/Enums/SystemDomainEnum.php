<?php

namespace App\Enums;

/**
 * The three systems the web serves, each with its OWN id space in every reference table, so adding rows to one system
 * never requires renumbering another:
 *
 *   CAMP_COMMAND (מפקדת מחנה) - the booking types the mobile app also runs. Its reference rows are seeded in lockstep
 *   with the mobile and MUST hold the exact mobile ids: the b2r/r2b files carry raw ids (status_id, field_id,
 *   option_id, role_id) and both importers resolve them one-to-one.
 *
 *   INFOSEC (ביטחון מידע) and ICT (תקשוב) - web-only systems the mobile app never sees. Each owns a reserved id block
 *   per table; their rows never enter a sync file (BookingTypeEnum::webOnlyIds() filters every export).
 *
 * The blocks per table ([floor, ceiling], inclusive):
 *
 *   table              CAMP_COMMAND   INFOSEC        ICT            created on the web at runtime
 *   roles              1-100          101-149        151-199        -
 *   statuses           1-100          101-149        151-199        -
 *   action_types       1-100          101-149 = the route engine's shared verbs (not per system)
 *   form_fields        1-1000         1001-2999      3001-4999      5001+  (custom "int_" fields)
 *   option_fields      1-10000        10001-29999    30001-49999    50001+ (the option editors)
 *   system_references  1-1000         1001-2999      3001-4999      -
 *
 * SystemDomainContractSeeder enforces all of this on every seeding run.
 */
enum SystemDomainEnum: string
{
    case CAMP_COMMAND = 'camp_command';
    case INFOSEC = 'infosec';
    case ICT = 'ict';

    public function label(): string
    {
        return match ($this) {
            self::CAMP_COMMAND => 'מפקדת מחנה',
            self::INFOSEC => 'ביטחון מידע',
            self::ICT => 'תקשוב',
        };
    }

    /** @return array{0: int, 1: int} [floor, ceiling] of this system's roles. */
    public function roleRange(): array
    {
        return match ($this) {
            self::CAMP_COMMAND => [1, 100],
            self::INFOSEC => [101, 149],
            self::ICT => [151, 199],
        };
    }

    /** @return array{0: int, 1: int} [floor, ceiling] of this system's statuses. */
    public function statusRange(): array
    {
        return match ($this) {
            self::CAMP_COMMAND => [1, 100],
            self::INFOSEC => [101, 149],
            self::ICT => [151, 199],
        };
    }

    /** @return array{0: int, 1: int} [floor, ceiling] of this system's seeded form fields. */
    public function fieldRange(): array
    {
        return match ($this) {
            self::CAMP_COMMAND => [1, 1000],
            self::INFOSEC => [1001, 2999],
            self::ICT => [3001, 4999],
        };
    }

    /** @return array{0: int, 1: int} [floor, ceiling] of this system's seeded options. */
    public function optionRange(): array
    {
        return match ($this) {
            self::CAMP_COMMAND => [1, 10000],
            self::INFOSEC => [10001, 29999],
            self::ICT => [30001, 49999],
        };
    }

    /** @return array{0: int, 1: int} [floor, ceiling] of this system's system references. */
    public function systemReferenceRange(): array
    {
        return match ($this) {
            self::CAMP_COMMAND => [1, 1000],
            self::INFOSEC => [1001, 2999],
            self::ICT => [3001, 4999],
        };
    }

    /** @return array<int, BookingTypeEnum> the booking types of this system. */
    public function bookingTypeCases(): array
    {
        return match ($this) {
            self::CAMP_COMMAND => BookingTypeEnum::campCommandCases(),
            self::INFOSEC => BookingTypeEnum::infosecCases(),
            self::ICT => BookingTypeEnum::ictCases(),
        };
    }

    /** @return array<int, int> the DB ids of this system's booking types (a type missing from the DB is skipped). */
    public function bookingTypeIds(): array
    {
        return array_values(array_filter(array_map(
            fn (BookingTypeEnum $type): ?int => $type->getBookingTypeId(),
            $this->bookingTypeCases()
        )));
    }

    /** The system a booking type belongs to; an unknown/legacy id counts as the camp command. */
    public static function ofBookingTypeId(?int $bookingTypeId): self
    {
        if ($bookingTypeId !== null) {
            foreach ([self::INFOSEC, self::ICT] as $domain) {
                if (in_array($bookingTypeId, $domain->bookingTypeIds(), true)) {
                    return $domain;
                }
            }
        }

        return self::CAMP_COMMAND;
    }
}
