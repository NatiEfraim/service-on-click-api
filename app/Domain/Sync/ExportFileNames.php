<?php

namespace App\Domain\Sync;

/**
 * The names of the r2b files this system sends back to the mobile app (b2r is the other way: JsonModelTypeEnum::EXPORT_FILE_PREFIX).
 * No database access.
 */
final class ExportFileNames
{
    public const R2B_PREFIX = 'SrvOnClick_r2b_';

    /**
     * The decisions made here on one business day. The mobile app's importer reads ONE file per day by its exact name and skips the rows that
     * did not change, so this system writes a cumulative snapshot of the day (the latest decision per booking) under the same name again and again.
     * The prefix is configurable because the mobile app's own configuration and importer disagree about it (r2b_booking_status / b2r_booking_status).
     */
    public static function bookingStatusFile(\DateTimeInterface $day, ?string $prefix = null): string
    {
        return ($prefix ?? self::R2B_PREFIX . 'booking_status_') . $day->format('Y-m-d') . '.json';
    }

    /**
     * The corrections of date/time values made while bookings were handled. A message type of its own: the mobile side needs an importer for it.
     * The time is to the second, so $uniqueId (the correction's id) keeps two corrections made in the same second apart: a file with the same
     * name would replace the first one in the bucket before the mobile app read it.
     */
    public static function bookingValuesFile(\DateTimeInterface $at, ?string $uniqueId = null): string
    {
        $suffix = $uniqueId === null ? '' : '_' . substr((string) preg_replace('/[^A-Za-z0-9]/', '', $uniqueId), 0, 8);

        return self::R2B_PREFIX . 'booking_values_' . $at->format('Y-m-d_H-i-s') . $suffix . '.json';
    }

    /**
     * One change of the configuration (form structure, options, statements, settings). The changes must reach the mobile app in the order they
     * were made, and a reader that sorts the names gets the order of the names: the sequence (the id of the queued file, padded) is the last part
     * of the name, so two changes made in the same second still sort the way they were made.
     */
    public static function systemConfigFile(\DateTimeInterface $at, int $sequence): string
    {
        return self::R2B_PREFIX . 'system_config_' . $at->format('Y-m-d_H-i-s') . '_' . str_pad((string) $sequence, 9, '0', STR_PAD_LEFT) . '.json';
    }
}
