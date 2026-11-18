<?php

namespace App\Domain\Clearance;

/** The clearance levels by their option codes (option_fields.code of the requested_level field), ranked low to high. */
final class ClearanceLevel
{
    public const RESTRICTED = 'restricted';
    public const SECRET = 'secret';
    public const TOP_SECRET = 'top_secret';

    private const RANKS = [
        self::RESTRICTED => 1,
        self::SECRET => 2,
        self::TOP_SECRET => 3,
    ];

    public static function rank(?string $code): ?int
    {
        return $code === null ? null : (self::RANKS[strtolower(trim($code))] ?? null);
    }

    /** Whether a granted level covers the requested one (unknown levels never do). */
    public static function satisfies(?string $granted, ?string $requested): bool
    {
        $have = self::rank($granted);
        $want = self::rank($requested);

        return $have !== null && $want !== null && $have >= $want;
    }
}
