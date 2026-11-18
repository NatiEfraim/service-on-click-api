<?php

namespace App\Domain\Workflow\Routes;

use App\Domain\Workflow\TypeRoute;
use App\Enums\BookingTypeEnum;

/** The booking types that have a route of their own, and their routes. One place to add the next one. */
final class RouteRegistry
{
    /** @return array<string, TypeRoute> keyed by the BookingTypeEnum case name (no database) */
    public static function definitions(): array
    {
        return [
            BookingTypeEnum::CLEARANCE_GRANT_BOOKING->name => new ClearanceGrantRoute(),
        ];
    }

    /** @return array<int, TypeRoute> keyed by the booking type's DB id (a type missing from the DB is skipped) */
    public static function byBookingTypeId(): array
    {
        $routes = [];

        foreach (self::definitions() as $caseName => $route) {
            $id = constant(BookingTypeEnum::class . '::' . $caseName)->getBookingTypeId();

            if ($id !== null) {
                $routes[(int) $id] = $route;
            }
        }

        return $routes;
    }

    public static function forBookingType(BookingTypeEnum $type): ?TypeRoute
    {
        return self::definitions()[$type->name] ?? null;
    }
}
