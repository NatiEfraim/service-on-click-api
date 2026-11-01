<?php

namespace App\Domain\Sync;

use App\Enums\StatusBookingEnum;

/**
 * Three-way merge of a booking's status arriving from the mobile app while a decision made here may be waiting to be sent.
 *   base     = the last status seen from the mobile app (bookings.ext_status_id)
 *   local    = the status here
 *   incoming = the status in the file
 * Outcomes: noop (the mobile app has not moved), confirm (both agree), apply (only the mobile app moved - or it moved where there is nothing to
 * decide: the requester canceled, or the booking we approved was carried on to completion), stale (the file is older than our decision and
 * decides nothing), conflict (both moved - a person decides). No database access.
 */
final class StatusConflictResolver
{
    public const NOOP = 'noop';
    public const CONFIRM = 'confirm';
    public const APPLY = 'apply';
    public const STALE = 'stale';
    public const CONFLICT = 'conflict';

    /**
     * @param ?int $base the last status seen from the mobile app
     * @param ?int $incomingAt when the mobile app set the incoming status (unix time)
     * @param ?int $localChangedAt when the status here was set (unix time)
     * @param string $localSource who set the status here: external, internal or system
     */
    public static function resolve(?int $base, int $local, int $incoming, ?int $incomingAt, ?int $localChangedAt, string $localSource): string
    {
        if ($base !== null && $incoming === $base) {
            return self::NOOP;
        }

        if ($incoming === $local) {
            return self::CONFIRM;
        }

        // The requester's own cancellation stands whatever was decided here, and a person could not keep our decision over it anyway
        // (canKeepInternal): there is nothing to decide.
        if ($incoming === StatusBookingEnum::CANCELED_BY_USER->value) {
            return self::APPLY;
        }

        // We approved, and the mobile app took the approval and went on to complete it.
        if ($incoming === StatusBookingEnum::COMPLETED->value && in_array($local, [StatusBookingEnum::APPROVED->value, StatusBookingEnum::APPROVED_BY_BARBER->value], true)) {
            return self::APPLY;
        }

        $localMovedSinceBase = $localSource !== 'external' && $base !== $local;

        if (!$localMovedSinceBase) {
            return self::APPLY;
        }

        // The file was written before our decision and decides nothing: our decision stands, the mobile app will catch up.
        if ($incomingAt !== null && $localChangedAt !== null && $incomingAt < $localChangedAt && !self::isDecision(StatusBookingEnum::from($incoming))) {
            return self::STALE;
        }

        return self::CONFLICT;
    }

    /** A status somebody decided on - an approval or a closing one - not a step on the way: dropping it would lose that decision. */
    private static function isDecision(StatusBookingEnum $status): bool
    {
        return $status->isTerminal() || in_array($status, [StatusBookingEnum::APPROVED, StatusBookingEnum::APPROVED_BY_BARBER], true);
    }

    /** May a person keep our decision over the status that arrived? Not over the requester's own cancellation: reviving it would act in the requester's name. */
    public static function canKeepInternal(int $externalStatus): bool
    {
        return $externalStatus !== StatusBookingEnum::CANCELED_BY_USER->value;
    }
}
