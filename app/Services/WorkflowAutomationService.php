<?php

namespace App\Services;

use App\Domain\Clearance\ClearanceLevel;
use App\Domain\Clearance\ClearanceRegistry;
use App\Domain\Workflow\Routes\ClearanceGrantFields;
use App\Domain\Workflow\Transition;
use App\Domain\Workflow\Workflow;
use App\Enums\ActionTypeEnum;
use App\Enums\StatusBookingEnum;
use App\Models\Booking;
use App\Models\BookingValue;
use App\Models\FormField;
use App\Models\OptionField;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The moves the system makes on its own on the route types: a request whose interview moment has passed goes on (Transition::AUTO_DUE), and -
 * when workflow.clearance.auto_close is on - a request waiting for its clearance is closed once the registry (ותיקן) shows the requested level
 * (Transition::AUTO_REGISTRY). Each move is its own transaction on a locked row, re-checked inside the lock, with no human actor: the timeline
 * row and the audit row say "system". Run by `php artisan workflow:advance`.
 */
class WorkflowAutomationService
{
    public function __construct(
        private readonly Workflow $workflow,
        private readonly BookingStatusRecorder $recorder,
        private readonly AuditService $audit,
        private readonly ClearanceRegistry $registry,
    ) {
    }

    /** Moves on every request whose stage_due_at has passed. @return array{moved: int, checked: int} */
    public function advanceDue(): array
    {
        $moved = 0;
        $checked = 0;

        foreach ($this->workflow->routeTypeIds() as $bookingTypeId) {
            foreach ($this->workflow->routeFor($bookingTypeId)->transitions() as $transition) {
                if ($transition->auto !== Transition::AUTO_DUE) {
                    continue;
                }

                $ids = Booking::query()
                    ->where('booking_type_id', $bookingTypeId)
                    ->where('status_id', $transition->from)
                    ->whereNotNull('stage_due_at')
                    ->where('stage_due_at', '<=', now())
                    ->orderBy('id')
                    ->pluck('id');

                foreach ($ids as $id) {
                    $checked++;

                    if ($this->move((int) $id, $transition, fn (Booking $booking): bool => $booking->stage_due_at !== null && $booking->stage_due_at->lte(now()), 'מועד התחקיר חלף')) {
                        $moved++;
                    }
                }
            }
        }

        return ['moved' => $moved, 'checked' => $checked];
    }

    /**
     * Closes the requests waiting for their clearance whose candidate the registry shows at (or above) the requested level. A registry failure
     * stops the round: nothing moves on a guess.
     * @return array{moved: int, checked: int, skipped?: string}
     */
    public function closeGranted(): array
    {
        if (!config('workflow.clearance.auto_close', false)) {
            return ['moved' => 0, 'checked' => 0, 'skipped' => 'auto_close_off'];
        }

        $moved = 0;
        $checked = 0;

        foreach ($this->workflow->routeTypeIds() as $bookingTypeId) {
            foreach ($this->workflow->routeFor($bookingTypeId)->transitions() as $transition) {
                if ($transition->auto !== Transition::AUTO_REGISTRY) {
                    continue;
                }

                $bookings = Booking::query()
                    ->where('booking_type_id', $bookingTypeId)
                    ->where('status_id', $transition->from)
                    ->orderBy('id')
                    ->get(['id', 'unit_id', 'booking_type_id', 'status_id']);

                foreach ($bookings as $booking) {
                    $checked++;
                    ['personal_id' => $personalId, 'requested_code' => $requestedCode] = $this->candidate($booking);

                    if ($personalId === null || $requestedCode === null) {
                        continue;
                    }

                    try {
                        $granted = $this->registry->levelCodeFor($personalId);
                    } catch (\Throwable $e) {
                        Log::warning('WorkflowAutomationService: the clearance registry could not be reached: ' . $e->getMessage());

                        return ['moved' => $moved, 'checked' => $checked, 'skipped' => 'registry_unavailable'];
                    }

                    if (!ClearanceLevel::satisfies($granted, $requestedCode)) {
                        continue;
                    }

                    if ($this->move((int) $booking->id, $transition, fn (): bool => true, 'הסיווג נקלט בוותיקן')) {
                        $moved++;
                    }
                }
            }
        }

        return ['moved' => $moved, 'checked' => $checked];
    }

    /** The candidate's ID number and the code of the requested level, from the request's values. @return array{personal_id: ?string, requested_code: ?string} */
    private function candidate(Booking $booking): array
    {
        $fields = FormField::query()
            ->where('booking_type_id', $booking->booking_type_id)
            ->where('unit_id', $booking->unit_id)
            ->whereIn('field_name', [ClearanceGrantFields::PERSONAL_ID, ClearanceGrantFields::REQUESTED_LEVEL])
            ->get()
            ->keyBy('field_name');

        $rows = BookingValue::query()
            ->where('booking_id', $booking->id)
            ->whereIn('form_field_id', $fields->pluck('id')->all())
            ->get()
            ->keyBy('form_field_id');

        $personalId = null;
        $requestedCode = null;

        if (($field = $fields->get(ClearanceGrantFields::PERSONAL_ID)) !== null && ($row = $rows->get($field->id)) !== null) {
            $value = $row->value;
            $value = is_array($value) && array_is_list($value) && count($value) === 1 ? $value[0] : $value;
            $personalId = is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
        }

        if (($field = $fields->get(ClearanceGrantFields::REQUESTED_LEVEL)) !== null && ($row = $rows->get($field->id)) !== null && $row->option_field_id !== null) {
            $requestedCode = OptionField::query()->whereKey($row->option_field_id)->value('code');
        }

        return ['personal_id' => $personalId, 'requested_code' => $requestedCode === null ? null : (string) $requestedCode];
    }

    /** One automatic move, in its own transaction on the locked row; false when the request moved on meanwhile or no longer qualifies. */
    private function move(int $bookingId, Transition $transition, callable $stillApplies, string $reason): bool
    {
        return (bool) DB::transaction(function () use ($bookingId, $transition, $stillApplies, $reason): bool {
            $booking = Booking::query()->whereKey($bookingId)->lockForUpdate()->first();

            if ($booking === null || (int) $booking->status_id !== $transition->from || !$stillApplies($booking)) {
                return false;
            }

            $before = ['status_id' => (int) $booking->status_id, 'version' => (int) $booking->version];
            $target = StatusBookingEnum::from($transition->to);

            $correlationId = $this->recorder->apply($booking, $target, null, 'system', null, ActionTypeEnum::ADVANCE_STAGE, ['stage_due_at' => null]);

            $this->audit->record([
                'action' => 'booking.status_change',
                'entity_type' => 'booking',
                'entity_id' => $booking->serial_number,
                'booking_serial' => $booking->serial_number,
                'unit_id' => $booking->unit_id,
                'booking_type_id' => $booking->booking_type_id,
                'before' => $before,
                'after' => ['status_id' => $target->value, 'version' => (int) $booking->version],
                'reason' => $reason,
                'correlation_id' => $correlationId,
                'source' => 'system',
            ], null);

            return true;
        });
    }
}
