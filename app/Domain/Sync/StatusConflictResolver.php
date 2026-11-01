<?php

namespace App\Domain\Sync;

use App\Enums\StatusBookingEnum;

/**
 * Three-way merge of a status arriving from the mobile app against a decision waiting here.
 * Outcomes: noop, confirm, apply, stale (file older than our decision), conflict (a person decides).
 */
final class StatusConflictResolver
{
    public const NOOP = 'noop';
    public const CONFIRM = 'confirm';
    public const APPLY = 'apply';
    public const STALE = 'stale';
    public const CONFLICT = 'conflict';

    /**
     * @param ?int $base last status seen from the mobile app
     * @param ?int $incomingAt unix time the mobile app set the incoming status
     * @param ?int $localChangedAt unix time the local status was set
     * @param string $localSource external, internal or system
     */
    public static function resolve(?int $base, int $local, int $incoming, ?int $incomingAt, ?int $localChangedAt, string $localSource): string
    {
        if ($base !== null && $incoming === $base) {
            return self::NOOP;
        }

        if ($incoming === $local) {
            return self::CONFIRM;
        }

        // The requester's own cancellation stands whatever was decided here (see canKeepInternal).
        if ($incoming === StatusBookingEnum::CANCELED_BY_USER->value) {
            return self::APPLY;
        }

        // We approved and the mobile app carried it on to completion.
        if ($incoming === StatusBookingEnum::COMPLETED->value && in_array($local, [StatusBookingEnum::APPROVED->value, StatusBookingEnum::APPROVED_BY_BARBER->value], true)) {
            return self::APPLY;
        }

        $localMovedSinceBase = $localSource !== 'external' && $base !== $local;

        if (!$localMovedSinceBase) {
            return self::APPLY;
        }

        // The file predates our decision: ours stands, the mobile app will catch up.
        if ($incomingAt !== null && $localChangedAt !== null && $incomingAt < $localChangedAt && !self::isDecision(StatusBookingEnum::from($incoming))) {
            return self::STALE;
        }

        return self::CONFLICT;
    }

    /** An approval or closing status; dropping it would lose that decision. */
    private static function isDecision(StatusBookingEnum $status): bool
    {
        return $status->isTerminal() || in_array($status, [StatusBookingEnum::APPROVED, StatusBookingEnum::APPROVED_BY_BARBER], true);
    }

    /** Not over the requester's own cancellation: reviving it would act in the requester's name. */
    public static function canKeepInternal(int $externalStatus): bool
    {
        return $externalStatus !== StatusBookingEnum::CANCELED_BY_USER->value;
    }
}
