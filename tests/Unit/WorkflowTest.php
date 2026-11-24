<?php

namespace Tests\Unit;

use App\Domain\Workflow\Routes\ClearanceGrantRoute;
use App\Domain\Workflow\Workflow;
use App\Enums\BookingStageEnum;
use App\Enums\RoleEnum;
use App\Enums\StatusBookingEnum as S;
use PHPUnit\Framework\TestCase;

/** The turn-based rules of the booking route, on their own (no framework, no database). Booking types: 1 car, 5 barber, 8 transportation, 11 food. */
class WorkflowTest extends TestCase
{
    private Workflow $workflow;

    protected function setUp(): void
    {
        // Transportation (8) starts at the control officer; barber is 5.
        $this->workflow = new Workflow(controlOfficerBookingTypeIds: [8], transportationBookingTypeId: 8, barberBookingTypeId: 5);
    }

    /**
     * @param ?S $hold the hold the booking is on, when $from is the status it was put on hold from (where it is parked)
     * @return array<int, int> the status ids the permissions allow from the status
     */
    private function targets(S $from, array $permissions, int $bookingTypeId, ?Workflow $workflow = null, ?S $hold = null): array
    {
        return array_map(fn (array $action): int => $action['status']->value, ($workflow ?? $this->workflow)->availableActions($from, $permissions, $bookingTypeId, $hold));
    }

    public function test_the_vice_approver_answers_first_and_cannot_approve_finally(): void
    {
        $this->assertSame([15, 16], $this->targets(S::RECEIVED, ['vice_approver_1'], 1));
        $this->assertFalse($this->workflow->canChangeTo(S::RECEIVED, S::APPROVED, ['vice_approver_1'], 1));
    }

    public function test_the_domain_handler_of_an_infosec_type_holds_the_whole_route(): void
    {
        foreach ([['infosec_officer_15', 15], ['infosec_officer_16', 16]] as [$permission, $typeId]) {
            // The one answer, then the closing: nothing is passed on to a stage nobody holds (15 / 16).
            $this->assertSame([3, 4], $this->targets(S::RECEIVED, [$permission], $typeId), $permission);
            // A request left at the old stages of the chain waits for them too, so none is stranded.
            $this->assertSame([3, 4], $this->targets(S::VICE_APPROVER_APPROVED, [$permission], $typeId), $permission);
            $this->assertSame([3, 4], $this->targets(S::VICE_APPROVER_REJECTED, [$permission], $typeId), $permission);
            $this->assertSame([5, 7], $this->targets(S::APPROVED, [$permission], $typeId), $permission);

            foreach ([S::REJECTED, S::COMPLETED, S::CANCELED_BY_APPROVER, S::CANCELED_BY_USER] as $closed) {
                $this->assertSame([], $this->targets($closed, [$permission], $typeId), "{$permission} on {$closed->name}");
            }

            // The stages stay the generic ones, so no screen needs a new one.
            $this->assertSame(BookingStageEnum::VICE_APPROVER, $this->workflow->stageOf(S::RECEIVED, $typeId));
            $this->assertSame(BookingStageEnum::REALIZATION, $this->workflow->stageOf(S::APPROVED, $typeId));
        }

        // Only on the type they hold the permission for; the old ICT handler permission moves nothing anywhere
        // (ICT runs on IctServiceDeskRoute now - see the ICT service desk section below).
        $this->assertSame([], $this->targets(S::RECEIVED, ['infosec_officer_15'], 17));
        $this->assertSame([], $this->targets(S::RECEIVED, ['ict_handler_20'], 1));
        $this->assertSame([], $this->targets(S::RECEIVED, ['ict_handler_20'], 20));

        // The rejection and the cancellation need a comment, as everywhere.
        $this->assertNotNull(Workflow::validateComment(S::REJECTED, ''));
        $this->assertNotNull(Workflow::validateComment(S::CANCELED_BY_APPROVER, ''));
        $this->assertNull(Workflow::validateComment(S::APPROVED, ''));

        // The admin of a type (the super admin on every type) keeps the whole chain, the passing-on moves included.
        $this->assertSame([15, 16, 3, 4], $this->targets(S::RECEIVED, ['admin_15'], 15));
        // The officer's turn is not a vice approver's or an approver's elsewhere: on a car the permission is just another name.
        $this->assertSame([15, 16], $this->targets(S::RECEIVED, ['vice_approver_1', 'infosec_officer_15'], 1));
    }

    public function test_the_approver_decides_once_the_vice_approver_has_answered(): void
    {
        $this->assertSame([3, 4], $this->targets(S::VICE_APPROVER_APPROVED, ['approver_1'], 1));
        $this->assertSame([3, 4], $this->targets(S::VICE_APPROVER_REJECTED, ['approver_1'], 1));
        $this->assertSame([5, 7], $this->targets(S::APPROVED, ['approver_1'], 1));
    }

    public function test_the_approver_and_the_admin_decide_finally_from_every_stage_below_them(): void
    {
        // The vice approver's stage on a car (received, and what the control officer left behind) and the control officer's stage on a transport.
        $stages = [[S::RECEIVED, 1], [S::PROCESSING_BY_CONTROL_OFFICER, 1], [S::RECEIVED, 8], [S::CONTROL_OFFICER_APPROVED, 8], [S::CONTROL_OFFICER_REJECTED, 8]];

        foreach ($stages as [$from, $bookingTypeId]) {
            foreach (['approver_', 'admin_'] as $role) {
                foreach ([S::APPROVED, S::REJECTED] as $decision) {
                    $this->assertTrue($this->workflow->canChangeTo($from, $decision, [$role . $bookingTypeId], $bookingTypeId), "{$role}{$bookingTypeId} from {$from->name} to {$decision->name}");
                }
            }
        }

        // Two moves, labeled as at the approver's own stage, and no status of the skipped stages in between: the approval goes to the realization
        // stage and the rejection closes the booking.
        $decision = [['status' => S::APPROVED, 'label' => 'אישור סופי'], ['status' => S::REJECTED, 'label' => 'דחייה סופית']];
        $this->assertSame($decision, $this->workflow->availableActions(S::RECEIVED, ['approver_1'], 1));
        $this->assertSame($decision, $this->workflow->availableActions(S::RECEIVED, ['approver_8'], 8));
        $this->assertSame($decision, $this->workflow->availableActions(S::CONTROL_OFFICER_APPROVED, ['approver_8'], 8));
        $this->assertSame(BookingStageEnum::REALIZATION, $this->workflow->stageOf(S::APPROVED, 1));
        $this->assertSame(BookingStageEnum::CLOSED, $this->workflow->stageOf(S::REJECTED, 1));
    }

    public function test_the_skip_is_only_the_final_decision_and_only_for_the_approver_and_the_admin(): void
    {
        foreach (['approver_1', 'admin_1'] as $permission) {
            // Not the other moves of the approver's later stages: completing and canceling need an approved booking, the barber's mark too.
            $this->assertFalse($this->workflow->canChangeTo(S::RECEIVED, S::COMPLETED, [$permission], 1));
            $this->assertFalse($this->workflow->canChangeTo(S::RECEIVED, S::CANCELED_BY_APPROVER, [$permission], 1), $permission . ': canceling is for an approved booking');
            $this->assertFalse($this->workflow->canChangeTo(S::RECEIVED, S::APPROVED_BY_BARBER, [$permission], 1));
            $this->assertFalse($this->workflow->canChangeTo(S::RECEIVED, S::CANCELED_BY_USER, [$permission], 1));
        }

        // The supplier holds of a transportation stay with the stage's own role (and the approver at its stage): skipping is the decision only.
        foreach ([S::PENDING_FILE, S::PENDING_PROCESSING, S::PENDING_APPROVED] as $hold) {
            $this->assertFalse($this->workflow->canChangeTo(S::RECEIVED, $hold, ['approver_8'], 8), 'the approver does not put it on hold from the control officer stage');
        }

        // The handlers have no final say: the vice approver decides the stage they own, the control officer the one before.
        foreach ([S::APPROVED, S::REJECTED] as $decision) {
            $this->assertFalse($this->workflow->canChangeTo(S::RECEIVED, $decision, ['vice_approver_1'], 1));
            $this->assertFalse($this->workflow->canChangeTo(S::RECEIVED, $decision, ['vice_approver_8'], 8));
            $this->assertFalse($this->workflow->canChangeTo(S::RECEIVED, $decision, ['transportation_control_officer_8'], 8));
            $this->assertFalse($this->workflow->canChangeTo(S::CONTROL_OFFICER_APPROVED, $decision, ['vice_approver_8'], 8));
        }

        // Per booking type; nothing for a person without a role in it.
        $this->assertSame([], $this->targets(S::RECEIVED, ['approver_2', 'admin_2', 'user_1'], 1));

        // No skip once the booking is past the stages below the approver: its own stage, then the realization, then nothing.
        $this->assertSame([3, 4], $this->targets(S::VICE_APPROVER_APPROVED, ['approver_1'], 1));
        $this->assertSame([5, 7], $this->targets(S::APPROVED, ['approver_1'], 1));
        $this->assertSame([], $this->targets(S::COMPLETED, ['approver_1', 'admin_1'], 1));
        $this->assertSame([], $this->targets(S::REJECTED, ['approver_1', 'admin_1'], 1));
    }

    public function test_every_rejection_a_role_is_offered_needs_a_comment(): void
    {
        $rejections = [S::REJECTED, S::VICE_APPROVER_REJECTED, S::CONTROL_OFFICER_REJECTED, S::CANCELED_BY_APPROVER, S::CANCELED_BY_BARBER];
        $rolesByType = [
            8 => ['transportation_control_officer_8', 'vice_approver_8', 'approver_8', 'admin_8'],
            5 => ['approver_5', 'admin_5'],
        ];

        foreach ($rolesByType as $bookingTypeId => $roles) {
            foreach (S::cases() as $from) {
                foreach ($roles as $permission) {
                    foreach ($this->workflow->availableActions($from, [$permission], $bookingTypeId) as $action) {
                        if (in_array($action['status'], $rejections, true)) {
                            $this->assertNotNull(Workflow::validateComment($action['status'], ''), "{$permission} from {$from->name} to {$action['status']->name}");
                        }
                    }
                }
            }
        }
    }

    public function test_a_closed_booking_has_no_actions_and_a_permission_is_per_booking_type(): void
    {
        $this->assertSame([], $this->targets(S::COMPLETED, ['approver_1', 'vice_approver_1', 'admin_1'], 1));
        $this->assertSame([], $this->targets(S::VICE_APPROVER_APPROVED, ['approver_2'], 1));
    }

    public function test_the_admin_holds_every_turn_of_the_type_they_administer(): void
    {
        // The same moves as whoever's turn it is: vice approver, approver and (in transportation) control officer, then the final decision (3, 4)
        // that skips the stages below the approver.
        $this->assertSame([15, 16, 3, 4], $this->targets(S::RECEIVED, ['admin_1'], 1));
        $this->assertSame([3, 4], $this->targets(S::VICE_APPROVER_APPROVED, ['admin_1'], 1));
        $this->assertSame([5, 7], $this->targets(S::APPROVED, ['admin_1'], 1));
        $this->assertSame([17, 18, 8, 9, 10, 3, 4], $this->targets(S::RECEIVED, ['admin_8'], 8));
        $this->assertSame([15, 16, 8, 9, 10, 3, 4], $this->targets(S::CONTROL_OFFICER_APPROVED, ['admin_8'], 8));
        $this->assertSame([3, 4, 8, 10], $this->targets(S::PENDING_PROCESSING, ['admin_8'], 8));

        // The barber route has one step, the barber's answer, and the admin holds that turn too.
        $this->assertSame([11, 12], $this->targets(S::RECEIVED, ['admin_5'], 5));
        $this->assertSame([], $this->targets(S::APPROVED_BY_BARBER, ['admin_5'], 5));
    }

    public function test_the_admin_is_strongest_in_the_roles_but_cannot_act_for_the_requester_or_on_a_closed_booking(): void
    {
        $this->assertTrue($this->workflow->canChangeTo(S::RECEIVED, S::APPROVED, ['admin_1'], 1), 'may skip the vice approver');
        $this->assertTrue($this->workflow->canChangeTo(S::RECEIVED, S::REJECTED, ['admin_1'], 1), 'and reject without waiting for the vice approver');
        $this->assertFalse($this->workflow->canChangeTo(S::RECEIVED, S::CANCELED_BY_USER, ['admin_1'], 1), 'the requester cancels in the app');

        foreach ([S::REJECTED, S::COMPLETED, S::CANCELED_BY_APPROVER, S::CANCELED_BY_USER] as $closed) {
            $this->assertSame([], $this->targets($closed, ['admin_1'], 1), $closed->name . ' is closed for the admin too');
        }

        // The permission is per booking type.
        $this->assertSame([], $this->targets(S::RECEIVED, ['admin_2'], 1));
        $this->assertSame([], $this->targets(S::RECEIVED, ['admin_1'], 2));
    }

    public function test_a_person_with_two_roles_on_one_type_acts_on_each_own_turn(): void
    {
        $permissions = ['approver_1', 'vice_approver_1'];

        // The turn's own buttons first, then the approver's final decision.
        $this->assertSame([15, 16, 3, 4], $this->targets(S::RECEIVED, $permissions, 1));
        $this->assertSame([3, 4], $this->targets(S::VICE_APPROVER_APPROVED, $permissions, 1));
    }

    public function test_transportation_starts_at_the_control_officer_who_may_also_put_it_on_hold(): void
    {
        $this->assertSame([17, 18, 8, 9, 10], $this->targets(S::RECEIVED, ['transportation_control_officer_8'], 8));
        // At that stage the approver and the admin of the type may decide finally, the vice approver may take the booking over (own test below),
        // and the control officer has no say on a car.
        $this->assertSame([3, 4], $this->targets(S::RECEIVED, ['approver_8'], 8));
        $this->assertSame([], $this->targets(S::RECEIVED, ['transportation_control_officer_1'], 1));
    }

    public function test_the_vice_approver_takes_a_transportation_booking_over_from_the_control_officer(): void
    {
        // Waiting at the control officer: the vice approver gets exactly the buttons of their own stage (answer, pass on, hold), skipping 17 / 18.
        $this->assertSame([15, 16, 8, 9, 10], $this->targets(S::RECEIVED, ['vice_approver_8'], 8));
        $this->assertSame(
            $this->workflow->availableActions(S::CONTROL_OFFICER_APPROVED, ['vice_approver_8'], 8),
            $this->workflow->availableActions(S::RECEIVED, ['vice_approver_8'], 8),
            'the same buttons, with the same labels, as at their own stage'
        );

        foreach ([S::VICE_APPROVER_APPROVED, S::VICE_APPROVER_REJECTED] as $answer) {
            $this->assertTrue($this->workflow->canChangeTo(S::RECEIVED, $answer, ['vice_approver_8'], 8));
        }

        // Not the control officer's own answer, and not the final decision: that is the approver's.
        foreach ([S::CONTROL_OFFICER_APPROVED, S::CONTROL_OFFICER_REJECTED, S::APPROVED, S::REJECTED] as $other) {
            $this->assertFalse($this->workflow->canChangeTo(S::RECEIVED, $other, ['vice_approver_8'], 8), $other->name);
        }

        // Correcting a date or a time stays with the control officer, whose turn it is.
        $this->assertFalse($this->workflow->canEditValues(S::RECEIVED, ['vice_approver_8'], 8));

        // Only where there is a control officer stage to take over, and never above their own stage.
        $this->assertSame([15, 16], $this->targets(S::RECEIVED, ['vice_approver_1'], 1), 'a car: their own stage, nothing more');
        $this->assertSame([], $this->targets(S::VICE_APPROVER_APPROVED, ['vice_approver_8'], 8));
        $this->assertSame([], $this->targets(S::PENDING_FILE, ['vice_approver_8'], 8));
        $this->assertSame([], $this->targets(S::RECEIVED, ['vice_approver_1'], 8), 'the permission is per booking type');

        // The control officer does not reach above their stage either.
        $this->assertSame([], $this->targets(S::CONTROL_OFFICER_APPROVED, ['transportation_control_officer_8'], 8));
        $this->assertSame([], $this->targets(S::VICE_APPROVER_APPROVED, ['transportation_control_officer_8'], 8));
    }

    public function test_a_move_two_roles_offer_is_listed_once(): void
    {
        // An admin who is also a vice approver: the holds are the control officer's and the vice approver's, the answers of both, the decision of the approver.
        $targets = $this->targets(S::RECEIVED, ['admin_8', 'vice_approver_8'], 8);

        $this->assertSame([17, 18, 8, 9, 10, 15, 16, 3, 4], $targets);
        $this->assertSame(array_values(array_unique($targets)), $targets);
        $this->assertSame([17, 18, 8, 9, 10, 15, 16], $this->targets(S::RECEIVED, ['transportation_control_officer_8', 'vice_approver_8'], 8));
    }

    public function test_in_transportation_the_route_goes_control_officer_then_vice_approver_then_approver(): void
    {
        $this->assertSame(BookingStageEnum::CONTROL_OFFICER, $this->workflow->stageOf(S::RECEIVED, 8));
        $this->assertSame(BookingStageEnum::VICE_APPROVER, $this->workflow->stageOf(S::CONTROL_OFFICER_APPROVED, 8));
        $this->assertSame(BookingStageEnum::VICE_APPROVER, $this->workflow->stageOf(S::CONTROL_OFFICER_REJECTED, 8));
        $this->assertSame(BookingStageEnum::APPROVER, $this->workflow->stageOf(S::VICE_APPROVER_APPROVED, 8));

        $this->assertSame([15, 16, 8, 9, 10], $this->targets(S::CONTROL_OFFICER_APPROVED, ['vice_approver_8'], 8));
        $this->assertSame([3, 4], $this->targets(S::CONTROL_OFFICER_APPROVED, ['approver_8'], 8), 'only by skipping the vice approver');
        $this->assertContains(9, $this->targets(S::VICE_APPROVER_APPROVED, ['approver_8'], 8));

        // On every other type the route starts at the vice approver.
        $this->assertSame(BookingStageEnum::VICE_APPROVER, $this->workflow->stageOf(S::RECEIVED, 11));
    }

    public function test_only_the_approver_acts_while_transportation_waits_for_the_supplier(): void
    {
        // A hold with nowhere recorded (from before the booking remembered where it was parked): its own supplier stage, the approver's.
        $this->assertSame([3, 4, 8, 10], $this->targets(S::PENDING_PROCESSING, ['approver_8'], 8));
        $this->assertSame([], $this->targets(S::PENDING_PROCESSING, ['vice_approver_8', 'transportation_control_officer_8'], 8));
        $this->assertSame([3, 4, 9, 10], $this->targets(S::PENDING_FILE, ['approver_8'], 8, hold: S::PENDING_FILE), 'the hold is its own position: the same');
        $this->assertSame(BookingStageEnum::SUPPLIER_FILES, $this->workflow->stageOf(S::PENDING_FILE, 8));
    }

    public function test_a_hold_never_moves_a_transportation_booking_it_stays_with_the_stage_it_was_put_on_hold_from(): void
    {
        // Put on hold at the control officer's stage (parked at status 1): still the control officer's, with the other two holds and the same skips.
        $this->assertSame([17, 18, 9, 10], $this->targets(S::RECEIVED, ['transportation_control_officer_8'], 8, hold: S::PENDING_FILE));
        $this->assertSame([15, 16, 9, 10], $this->targets(S::RECEIVED, ['vice_approver_8'], 8, hold: S::PENDING_FILE), 'the takeover goes on');
        $this->assertSame([3, 4], $this->targets(S::RECEIVED, ['approver_8'], 8, hold: S::PENDING_FILE));
        $this->assertSame([17, 18, 9, 10, 3, 4], $this->targets(S::RECEIVED, ['admin_8'], 8, hold: S::PENDING_FILE));
        $this->assertSame(BookingStageEnum::CONTROL_OFFICER, $this->workflow->stageOf(S::RECEIVED, 8));

        // At the vice approver's stage (parked at what the control officer left, or at the old "in handling" status).
        foreach ([S::CONTROL_OFFICER_APPROVED, S::CONTROL_OFFICER_REJECTED, S::PROCESSING_BY_CONTROL_OFFICER] as $parked) {
            $this->assertSame([15, 16, 8, 10], $this->targets($parked, ['vice_approver_8'], 8, hold: S::PENDING_PROCESSING), $parked->name);
            $this->assertSame([], $this->targets($parked, ['transportation_control_officer_8'], 8, hold: S::PENDING_PROCESSING), 'the control officer is done with it');
            $this->assertSame([3, 4], $this->targets($parked, ['approver_8'], 8, hold: S::PENDING_PROCESSING));
            $this->assertSame([15, 16, 8, 10, 3, 4], $this->targets($parked, ['admin_8'], 8, hold: S::PENDING_PROCESSING));
        }

        // At the approver's stage.
        foreach ([S::VICE_APPROVER_APPROVED, S::VICE_APPROVER_REJECTED, S::PROCESSING] as $parked) {
            $this->assertSame([3, 4, 8, 9], $this->targets($parked, ['approver_8'], 8, hold: S::PENDING_APPROVED), $parked->name);
            $this->assertSame([3, 4, 8, 9], $this->targets($parked, ['admin_8'], 8, hold: S::PENDING_APPROVED));
            $this->assertSame([], $this->targets($parked, ['vice_approver_8', 'transportation_control_officer_8'], 8, hold: S::PENDING_APPROVED));
        }
    }

    public function test_the_hold_a_booking_is_on_is_never_offered_again_and_the_other_two_always_are(): void
    {
        foreach ([S::PENDING_FILE, S::PENDING_PROCESSING, S::PENDING_APPROVED] as $hold) {
            foreach ([[S::RECEIVED, 'admin_8'], [S::CONTROL_OFFICER_APPROVED, 'vice_approver_8'], [S::VICE_APPROVER_APPROVED, 'approver_8'], [S::RECEIVED, 'transportation_control_officer_8']] as [$parked, $permission]) {
                $targets = $this->targets($parked, [$permission], 8, hold: $hold);

                $this->assertNotContains($hold->value, $targets, "{$permission} on {$hold->name}");

                foreach (array_diff([8, 9, 10], [$hold->value]) as $other) {
                    $this->assertContains($other, $targets, "{$permission} on {$hold->name}");
                }
            }
        }
    }

    public function test_a_booking_on_hold_can_still_have_its_values_corrected_by_the_role_it_is_parked_with(): void
    {
        // The position decides, so the control officer who put it on hold may still move its date; nobody gains the right by the hold alone.
        $this->assertTrue($this->workflow->canEditValues(S::RECEIVED, ['transportation_control_officer_8'], 8));
        $this->assertFalse($this->workflow->canEditValues(S::RECEIVED, ['vice_approver_8'], 8));
        $this->assertTrue($this->workflow->canEditValues(S::CONTROL_OFFICER_APPROVED, ['vice_approver_8'], 8));
        $this->assertFalse($this->workflow->canEditValues(S::CONTROL_OFFICER_APPROVED, ['transportation_control_officer_8'], 8));
        $this->assertTrue($this->workflow->canEditValues(S::VICE_APPROVER_APPROVED, ['approver_8'], 8));
    }

    public function test_which_statuses_a_booking_can_be_put_on_hold_from_and_which_are_holds(): void
    {
        $this->assertSame([8, 9, 10], S::holdIds());

        foreach ([S::RECEIVED, S::PROCESSING, S::PROCESSING_BY_CONTROL_OFFICER, S::CONTROL_OFFICER_APPROVED, S::CONTROL_OFFICER_REJECTED, S::VICE_APPROVER_APPROVED, S::VICE_APPROVER_REJECTED] as $stage) {
            $this->assertTrue($stage->canBeParked(), $stage->name);
        }

        foreach ([S::PENDING_FILE, S::PENDING_PROCESSING, S::PENDING_APPROVED, S::APPROVED, S::APPROVED_BY_BARBER, S::REJECTED, S::COMPLETED, S::CANCELED_BY_USER, S::CANCELED_BY_APPROVER, S::CANCELED_BY_BARBER, S::CANCELED_BY_CHANGING_TIME] as $other) {
            $this->assertFalse($other->canBeParked(), $other->name);
        }
    }

    public function test_the_barber_route_has_one_step_the_barber_answers(): void
    {
        // The barber is the APPROVER of the type (approver_5): it approves or rejects a received booking - and nothing else: no 15 / 16, no 3 / 4, no 5 / 7.
        $this->assertSame([11, 12], $this->targets(S::RECEIVED, ['approver_5'], 5));
        $this->assertSame(BookingStageEnum::BARBER, $this->workflow->stageOf(S::RECEIVED, 5));
        $this->assertSame(RoleEnum::APPROVER, BookingStageEnum::BARBER->actingRole());

        // The admin of the type holds the turn too; nobody else - not a vice approver (the type has none: even a leftover vice_approver_5 gets nothing),
        // not the roles of another type, not the requester.
        $this->assertSame([11, 12], $this->targets(S::RECEIVED, ['admin_5'], 5));
        $this->assertSame([], $this->targets(S::RECEIVED, ['vice_approver_5'], 5));
        $this->assertSame([], $this->targets(S::RECEIVED, ['vice_approver_1', 'approver_1', 'admin_1', 'user_5'], 5));
        $this->assertSame([11, 12], array_map(fn (array $action): int => $action['status']->value, $this->workflow->availableActions(S::RECEIVED, ['approver_5', 'admin_5'], 5)), 'listed once');

        // Once answered there is nothing left: 11 is an approved appointment (the requester may still cancel it in the app), 12, 13 and 6 are closed.
        foreach ([S::APPROVED_BY_BARBER, S::CANCELED_BY_BARBER, S::CANCELED_BY_CHANGING_TIME, S::CANCELED_BY_USER] as $done) {
            $this->assertSame(BookingStageEnum::CLOSED, $this->workflow->stageOf($done, 5), $done->name);
            $this->assertSame([], $this->targets($done, ['vice_approver_5', 'approver_5', 'admin_5'], 5), $done->name);
        }

        // 13 is never a button - the system sets it when the barber's schedule changes - nor is 6 (the requester's) or any status of the standard route.
        $notButtons = [S::CANCELED_BY_CHANGING_TIME, S::CANCELED_BY_USER, S::VICE_APPROVER_APPROVED, S::VICE_APPROVER_REJECTED, S::APPROVED, S::REJECTED, S::COMPLETED, S::CANCELED_BY_APPROVER];

        foreach ($notButtons as $never) {
            $this->assertFalse($this->workflow->canChangeTo(S::RECEIVED, $never, ['admin_5'], 5), $never->name);
        }

        // The other types do not get the barber's statuses.
        $this->assertFalse($this->workflow->canChangeTo(S::RECEIVED, S::APPROVED_BY_BARBER, ['admin_1'], 1));
        $this->assertFalse($this->workflow->canChangeTo(S::RECEIVED, S::CANCELED_BY_BARBER, ['vice_approver_1'], 1));

        // A booking left in a status of the old route waits for the barber too, so it is not stranded.
        foreach ([S::PROCESSING, S::APPROVED, S::VICE_APPROVER_APPROVED, S::VICE_APPROVER_REJECTED] as $old) {
            $this->assertSame([11, 12], $this->targets($old, ['approver_5'], 5), $old->name);
        }

        // Rejecting needs a comment; approving does not.
        $this->assertNotNull(Workflow::validateComment(S::CANCELED_BY_BARBER, ''));
        $this->assertNull(Workflow::validateComment(S::APPROVED_BY_BARBER, ''));
    }

    public function test_the_barber_corrects_a_date_or_a_time_while_answering(): void
    {
        // The turn is the approver's (and the admin's): whoever may answer may correct, until the answer.
        $this->assertTrue($this->workflow->canEditValues(S::RECEIVED, ['approver_5'], 5));
        $this->assertTrue($this->workflow->canEditValues(S::RECEIVED, ['admin_5'], 5));
        $this->assertFalse($this->workflow->canEditValues(S::RECEIVED, ['vice_approver_5'], 5), 'the barber type has no vice approver');
        $this->assertFalse($this->workflow->canEditValues(S::RECEIVED, ['approver_1'], 5), 'the approver of another type');
        $this->assertFalse($this->workflow->canEditValues(S::APPROVED_BY_BARBER, ['admin_5'], 5), 'not after the approval');
    }

    public function test_a_barber_slot_is_held_until_the_booking_is_canceled(): void
    {
        $freed = array_map(fn (S $status): int => $status->value, array_values(array_filter(S::cases(), fn (S $status): bool => $status->freesBarberSlot())));

        $this->assertSame([5, 6, 7, 12, 13], $freed);
        $this->assertFalse(S::APPROVED_BY_BARBER->freesBarberSlot(), 'an approved appointment is still ahead');
        $this->assertTrue(S::APPROVED_BY_BARBER->canBeCanceledByUser(), 'and the requester may still cancel it');
        $this->assertFalse(S::CANCELED_BY_BARBER->canBeCanceledByUser());
    }

    public function test_nobody_here_sets_the_requesters_own_cancellation(): void
    {
        $this->assertTrue(Workflow::isRequesterOnly(S::CANCELED_BY_USER));
        $this->assertFalse($this->workflow->canChangeTo(S::RECEIVED, S::CANCELED_BY_USER, ['admin_1', 'approver_1', 'vice_approver_1'], 1));
    }

    public function test_without_a_control_officer_role_nothing_waits_for_one(): void
    {
        // Nobody holds the role for transportation in this setup: the route must not start at a stage no one can act in.
        $without = new Workflow(controlOfficerBookingTypeIds: [], transportationBookingTypeId: 8, barberBookingTypeId: 5);

        $this->assertSame(BookingStageEnum::VICE_APPROVER, $without->stageOf(S::RECEIVED, 8));
        $this->assertSame([15, 16, 8, 9, 10], $this->targets(S::RECEIVED, ['vice_approver_8'], 8, $without));
    }

    public function test_the_stage_of_a_status(): void
    {
        $this->assertSame(BookingStageEnum::VICE_APPROVER, $this->workflow->stageOf(S::RECEIVED, 1));
        $this->assertSame(BookingStageEnum::CONTROL_OFFICER, $this->workflow->stageOf(S::RECEIVED, 8));
        $this->assertSame(BookingStageEnum::APPROVER, $this->workflow->stageOf(S::VICE_APPROVER_APPROVED, 1));
        $this->assertSame('supplier', $this->workflow->stageOf(S::PENDING_PROCESSING, 8)->waitKind());
        $this->assertSame(BookingStageEnum::CLOSED, $this->workflow->stageOf(S::REJECTED, 1));
        $this->assertSame(BookingStageEnum::REALIZATION, $this->workflow->stageOf(S::APPROVED, 1));
        $this->assertSame(BookingStageEnum::BARBER, $this->workflow->stageOf(S::RECEIVED, 5));
        $this->assertSame(BookingStageEnum::CLOSED, $this->workflow->stageOf(S::APPROVED_BY_BARBER, 5));
    }

    public function test_values_are_corrected_by_whoever_has_the_turn_until_the_final_approval(): void
    {
        $this->assertTrue($this->workflow->canEditValues(S::VICE_APPROVER_APPROVED, ['approver_1'], 1), 'the approver after the vice approver passed it on');
        $this->assertTrue($this->workflow->canEditValues(S::PENDING_PROCESSING, ['approver_8'], 8));
        $this->assertFalse($this->workflow->canEditValues(S::APPROVED, ['approver_1'], 1));
        $this->assertFalse($this->workflow->canEditValues(S::COMPLETED, ['approver_1'], 1));
        $this->assertTrue($this->workflow->canEditValues(S::RECEIVED, ['admin_1'], 1), 'the admin holds every turn');
        $this->assertTrue($this->workflow->canEditValues(S::VICE_APPROVER_APPROVED, ['admin_1'], 1));
        $this->assertFalse($this->workflow->canEditValues(S::APPROVED, ['admin_1'], 1), 'not after the final approval, not even for the admin');
        $this->assertFalse($this->workflow->canEditValues(S::RECEIVED, ['admin_2'], 1), 'the admin of another type');
        $this->assertFalse($this->workflow->canEditValues(S::RECEIVED, ['approver_1'], 1), 'not on the vice approver turn, even though the approver may skip it');
        $this->assertFalse($this->workflow->canEditValues(S::CONTROL_OFFICER_APPROVED, ['approver_8'], 8));
        $this->assertTrue($this->workflow->canEditValues(S::RECEIVED, ['vice_approver_1'], 1));
        $this->assertFalse($this->workflow->canEditValues(S::VICE_APPROVER_APPROVED, ['vice_approver_1'], 1));
        $this->assertTrue($this->workflow->canEditValues(S::CONTROL_OFFICER_APPROVED, ['vice_approver_8'], 8));
        $this->assertTrue($this->workflow->canEditValues(S::RECEIVED, ['transportation_control_officer_8'], 8));
        $this->assertFalse($this->workflow->canEditValues(S::CONTROL_OFFICER_APPROVED, ['transportation_control_officer_8'], 8));
        $this->assertFalse($this->workflow->canEditValues(S::RECEIVED, ['user_1', 'approver_2'], 1));
    }

    public function test_the_comment_rules(): void
    {
        $this->assertNotNull(Workflow::validateComment(S::REJECTED, ''), 'a rejection needs a comment');
        $this->assertNull(Workflow::validateComment(S::APPROVED, ''), 'an approval does not');
        $this->assertNotNull(Workflow::validateComment(S::REJECTED, 'abc'), 'Hebrew only');
        $this->assertNull(Workflow::validateComment(S::REJECTED, 'חסר מידע'));
        $this->assertNotNull(Workflow::validateComment(S::REJECTED, str_repeat('א', 101)), 'at most 100 characters');
        $this->assertNull(Workflow::validateComment(S::REJECTED, str_repeat('א', 100)));
    }

    public function test_the_food_cancellation_deadline_skips_friday_and_saturday(): void
    {
        // 2026-10-27 is a Tuesday: two business days before it is Sunday the 25th; three days before is Thursday the 22nd.
        $this->assertSame('2026-10-25', Workflow::lastAllowedCancellation(new \DateTimeImmutable('2026-10-27'), 2)->format('Y-m-d'));
        $this->assertSame('2026-10-22', Workflow::lastAllowedCancellation(new \DateTimeImmutable('2026-10-27'), 3)->format('Y-m-d'));
        $this->assertSame('23:59:59', Workflow::lastAllowedCancellation(new \DateTimeImmutable('2026-10-27'), 1)->format('H:i:s'));
    }

    public function test_the_terminal_statuses_are_the_closed_ones(): void
    {
        // 105 is the successful end of the clearance grant route (web only).
        $this->assertSame([4, 5, 6, 7, 12, 13, 105], S::terminalIds());
        $this->assertTrue(S::COMPLETED->isTerminal());
        $this->assertTrue(S::CLEARANCE_GRANTED->isTerminal());
        $this->assertFalse(S::PENDING_FILE->isTerminal());
        $this->assertFalse(S::AWAITING_CLEARANCE->isTerminal());
    }

    //----------------------------- A route of its own: the clearance grant (type 14) -----------------//

    private function withClearanceRoute(): Workflow
    {
        return new Workflow(controlOfficerBookingTypeIds: [8], transportationBookingTypeId: 8, barberBookingTypeId: 5, routes: [14 => new ClearanceGrantRoute()]);
    }

    public function test_a_type_with_a_route_of_its_own_follows_that_route_and_the_generic_types_are_untouched(): void
    {
        $workflow = $this->withClearanceRoute();

        // The route's stages, not the approver chain's.
        $this->assertSame(BookingStageEnum::INTERVIEW_ASSIGNMENT, $workflow->stageOf(S::INTERVIEW_ASSIGNMENT, 14));
        $this->assertSame(BookingStageEnum::INTERVIEW_SCHEDULED, $workflow->stageOf(S::INTERVIEW_SCHEDULED, 14));
        $this->assertSame(BookingStageEnum::INTERVIEWED, $workflow->stageOf(S::INTERVIEWED, 14));
        $this->assertSame(BookingStageEnum::AWAITING_CLEARANCE, $workflow->stageOf(S::AWAITING_CLEARANCE, 14));
        $this->assertSame(BookingStageEnum::CLOSED, $workflow->stageOf(S::CLEARANCE_GRANTED, 14));
        $this->assertSame(BookingStageEnum::CLOSED, $workflow->stageOf(S::REJECTED, 14));
        // Older data left in a generic status stands at the start of the route, not stranded.
        $this->assertSame(BookingStageEnum::INTERVIEW_ASSIGNMENT, $workflow->stageOf(S::RECEIVED, 14));
        $this->assertSame(S::INTERVIEW_ASSIGNMENT, $workflow->initialStatus(14));
        $this->assertSame(S::RECEIVED, $workflow->initialStatus(1));

        // The coordinator schedules and passes the interview on; the officer also moves back, refuses and closes; the super admin (admin_14) everything.
        $this->assertSame([102, 4], $this->targets(S::INTERVIEW_ASSIGNMENT, ['infosec_officer_14'], 14, $workflow));
        $this->assertSame([102], $this->targets(S::INTERVIEW_ASSIGNMENT, ['interview_coordinator_14'], 14, $workflow));
        $this->assertSame([101, 4], $this->targets(S::INTERVIEW_SCHEDULED, ['infosec_officer_14'], 14, $workflow), 'the interview moment is the scheduler\'s, not a button');
        $this->assertSame([], $this->targets(S::INTERVIEW_SCHEDULED, ['interview_coordinator_14'], 14, $workflow));
        $this->assertSame([104, 101, 4], $this->targets(S::INTERVIEWED, ['infosec_officer_14'], 14, $workflow));
        $this->assertSame([104], $this->targets(S::INTERVIEWED, ['interview_coordinator_14'], 14, $workflow));
        $this->assertSame([105, 101, 4], $this->targets(S::AWAITING_CLEARANCE, ['infosec_officer_14'], 14, $workflow));
        $this->assertSame([], $this->targets(S::AWAITING_CLEARANCE, ['interview_coordinator_14'], 14, $workflow));
        $this->assertSame([102, 4], $this->targets(S::INTERVIEW_ASSIGNMENT, ['admin_14'], 14, $workflow));
        $this->assertSame([], $this->targets(S::CLEARANCE_GRANTED, ['infosec_officer_14', 'admin_14'], 14, $workflow));

        // Nothing of the approver chain opens the route, and the requester has no button.
        $this->assertSame([], $this->targets(S::INTERVIEW_ASSIGNMENT, ['approver_14', 'vice_approver_14', 'department_security_officer_14', 'infosec_officer_15'], 14, $workflow));
        $this->assertFalse($workflow->canChangeTo(S::INTERVIEW_ASSIGNMENT, S::APPROVED, ['admin_14'], 14));

        // The transition carries what the move needs; a move back clears the interview and needs a comment.
        $schedule = $workflow->transitionFor(14, S::INTERVIEW_ASSIGNMENT, S::INTERVIEW_SCHEDULED);
        $this->assertTrue($schedule->requiresHandler);
        $this->assertSame(['interview_location', 'interview_date', 'interview_time'], $schedule->requiredFields);
        $back = $workflow->transitionFor(14, S::AWAITING_CLEARANCE, S::INTERVIEW_ASSIGNMENT);
        $this->assertTrue($back->commentRequired);
        $this->assertSame(['interview_location', 'interview_date', 'interview_time'], $back->clearsFields);
        $this->assertNotNull(Workflow::validateComment(S::INTERVIEW_ASSIGNMENT, '', $back->commentRequired));
        $this->assertNull(Workflow::validateComment(S::INTERVIEW_SCHEDULED, '', $schedule->commentRequired));
        $this->assertNull($workflow->transitionFor(14, S::INTERVIEW_SCHEDULED, S::INTERVIEWED), 'an automatic move is not a manual transition');

        // The handler fields are written while the request waits for the assignment or the interview, by the route's roles.
        $this->assertTrue($workflow->canEditValues(S::INTERVIEW_ASSIGNMENT, ['interview_coordinator_14'], 14));
        $this->assertTrue($workflow->canEditValues(S::INTERVIEW_SCHEDULED, ['infosec_officer_14'], 14));
        $this->assertFalse($workflow->canEditValues(S::INTERVIEWED, ['infosec_officer_14'], 14));
        $this->assertFalse($workflow->canEditValues(S::INTERVIEW_ASSIGNMENT, ['department_security_officer_14'], 14));

        // The generic types did not move: a car is still the approver chain.
        $this->assertSame([15, 16], $this->targets(S::RECEIVED, ['vice_approver_1'], 1, $workflow));
        $this->assertSame([5, 105], $workflow->completedStatusIds());
    }

    public function test_every_status_has_an_action_type_for_the_timeline(): void
    {
        foreach (S::cases() as $status) {
            $this->assertGreaterThan(0, $status->getActionType()->value, $status->name);
        }
    }

    //----------------------------- A route of its own: the ICT service desk (types 20-25) -----------------//

    private function withIctRoute(): Workflow
    {
        $ict = new \App\Domain\Workflow\Routes\IctServiceDeskRoute();

        return new Workflow(controlOfficerBookingTypeIds: [8], transportationBookingTypeId: 8, barberBookingTypeId: 5, routes: [20 => $ict, 21 => $ict]);
    }

    public function test_the_ict_service_desk_route_gives_the_desk_its_buttons(): void
    {
        $workflow = $this->withIctRoute();

        // The desk's stages, not the approver chain's; a new request starts in the queue.
        $this->assertSame(BookingStageEnum::SERVICE_DESK_QUEUE, $workflow->stageOf(S::RECEIVED, 20));
        $this->assertSame(BookingStageEnum::SERVICE_DESK_HANDLING, $workflow->stageOf(S::IN_SERVICE_DESK, 20));
        $this->assertSame(BookingStageEnum::CLOSED, $workflow->stageOf(S::COMPLETED, 20));
        $this->assertSame(BookingStageEnum::SERVICE_DESK_QUEUE, $workflow->stageOf(S::APPROVED, 20), 'older data waits in the queue');
        $this->assertSame(S::RECEIVED, $workflow->initialStatus(20));

        // The representative and the commander take, close and put back; only the commander reopens; the super admin (admin_20) everything.
        foreach (['vice_approver_20', 'admin_ict_20', 'admin_20'] as $permission) {
            $this->assertSame([151, 5, 7], $this->targets(S::RECEIVED, [$permission], 20, $workflow), $permission);
            $this->assertSame([5, 7, 1], $this->targets(S::IN_SERVICE_DESK, [$permission], 20, $workflow), $permission);
        }

        $this->assertSame([], $this->targets(S::COMPLETED, ['vice_approver_20'], 20, $workflow), 'reopening is not the representative\'s');
        $this->assertSame([151], $this->targets(S::COMPLETED, ['admin_ict_20'], 20, $workflow));
        $this->assertSame([151], $this->targets(S::CANCELED_BY_APPROVER, ['admin_ict_20'], 20, $workflow));
        $this->assertSame([151], $this->targets(S::COMPLETED, ['admin_20'], 20, $workflow), 'the super admin holds the commander\'s moves');
        $this->assertSame([], $this->targets(S::CANCELED_BY_USER, ['admin_ict_20'], 20, $workflow), 'the requester\'s own cancellation is final');

        // The permission is per type; the requester, the old ICT handler and the roles of the chain have no button.
        $this->assertSame([], $this->targets(S::RECEIVED, ['vice_approver_20'], 21, $workflow));
        $this->assertSame([], $this->targets(S::RECEIVED, ['user_20', 'ict_handler_20', 'approver_20'], 20, $workflow));

        // The take carries the assignment, the way back the release; closing without doing and reopening need a comment, the rest do not.
        $take = $workflow->transitionFor(20, S::RECEIVED, S::IN_SERVICE_DESK);
        $this->assertTrue($take->assignsActor);
        $back = $workflow->transitionFor(20, S::IN_SERVICE_DESK, S::RECEIVED);
        $this->assertTrue($back->releasesHandler);
        $this->assertNull(Workflow::validateComment(S::RECEIVED, '', $back->commentRequired), 'back to the queue: the comment is optional');
        $this->assertNotNull(Workflow::validateComment(S::CANCELED_BY_APPROVER, '', $workflow->transitionFor(20, S::IN_SERVICE_DESK, S::CANCELED_BY_APPROVER)->commentRequired));
        $this->assertNull(Workflow::validateComment(S::COMPLETED, '', $workflow->transitionFor(20, S::IN_SERVICE_DESK, S::COMPLETED)->commentRequired));
        $this->assertNotNull(Workflow::validateComment(S::IN_SERVICE_DESK, '', $workflow->transitionFor(20, S::COMPLETED, S::IN_SERVICE_DESK)->commentRequired), 'a reopen explains itself');

        // No handler section on the ICT forms: nobody edits values through the route.
        $this->assertFalse($workflow->canEditValues(S::RECEIVED, ['vice_approver_20', 'admin_ict_20'], 20));

        // The generic types did not move, and the desk's success counts as done.
        $this->assertSame([15, 16], $this->targets(S::RECEIVED, ['vice_approver_1'], 1, $workflow));
        $this->assertSame([5], $workflow->completedStatusIds());
    }
}
