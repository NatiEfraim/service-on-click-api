<?php

namespace App\Domain\Sync;

/** Names of the r2b files sent back to the mobile app. */
final class ExportFileNames
{
    public const R2B_PREFIX = 'SrvOnClick_r2b_';

    /**
     * One cumulative snapshot per business day under a fixed name (the importer reads one file per day).
     * The prefix is configurable because the mobile app's config and importer disagree on it.
     */
    public static function bookingStatusFile(\DateTimeInterface $day, ?string $prefix = null): string
    {
        return ($prefix ?? self::R2B_PREFIX . 'booking_status_') . $day->format('Y-m-d') . '.json';
    }

    /** $uniqueId keeps two corrections made in the same second from overwriting each other in the bucket. */
    public static function bookingValuesFile(\DateTimeInterface $at, ?string $uniqueId = null): string
    {
        $suffix = $uniqueId === null ? '' : '_' . substr((string) preg_replace('/[^A-Za-z0-9]/', '', $uniqueId), 0, 8);

        return self::R2B_PREFIX . 'booking_values_' . $at->format('Y-m-d_H-i-s') . $suffix . '.json';
    }

    /** The sequence ends the name so sorted names keep the order the changes were made in. */
    public static function systemConfigFile(\DateTimeInterface $at, int $sequence): string
    {
        return self::R2B_PREFIX . 'system_config_' . $at->format('Y-m-d_H-i-s') . '_' . str_pad((string) $sequence, 9, '0', STR_PAD_LEFT) . '.json';
    }
}
