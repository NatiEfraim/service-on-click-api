<?php

namespace Tests\Unit;

use App\Domain\Workflow\Workflow;
use App\Enums\BookingStageEnum;
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

    /** @return array<int, int> the status ids the permissions allow from the status */
    private function targets(S $from, array $permissions, int $bookingTypeId, ?Workflow $workflow = null): array
    {
        return array_map(fn (array $action): int => $action['status']->value, ($workflow ?? $this->workflow)->availableActions($from, $permissions, $bookingTypeId));
    }

    public function test_the_vice_approver_answers_first_and_cannot_approve_finally(): void
    {
        $this->assertSame([15, 16], $this->targets(S::RECEIVED, ['vice_approver_1'], 1));
        $this->assertFalse($this->workflow->canChangeTo(S::RECEIVED, S::APPROVED, ['vice_approver_1'], 1));
    }

    public function test_the_approver_decides_once_the_vice_approver_has_answered(): void
    {
        $this->assertSame([3, 4], $this->targets(S::VICE_APPROVER_APPROVED, ['approver_1'], 1));
        $this->assertSame([3, 4], $this->targets(S::VICE_APPROVER_REJECTED, ['approver_1'], 1));
        $this->assertSame([5, 7], $this->targets(S::APPROVED, ['approver_1'], 1));
    }

    public function test_the_approver_and_the_admin_may_approve_finally_from_every_stage_below_them(): void
    {
        // The vice approver's stage on a car (received, and what the control officer left behind) and the control officer's stage on a transport.
        $stages = [[S::RECEIVED, 1], [S::PROCESSING_BY_CONTROL_OFFICER, 1], [S::RECEIVED, 8], [S::CONTROL_OFFICER_APPROVED, 8], [S::CONTROL_OFFICER_REJECTED, 8]];

        foreach ($stages as [$from, $bookingTypeId]) {
            foreach (['approver_', 'admin_'] as $role) {
                $this->assertTrue($this->workflow->canChangeTo($from, S::APPROVED, [$role . $bookingTypeId], $bookingTypeId), "{$role}{$bookingTypeId} from {$from->name}");
            }
        }

        // One move, labeled as the final approval, and the next stage is the realization: no status of the skipped stages in between.
        $this->assertSame([['status' => S::APPROVED, 'label' => 'אישור סופי']], $this->workflow->availableActions(S::RECEIVED, ['approver_1'], 1));
        $this->assertSame([['status' => S::APPROVED, 'label' => 'אישור סופי']], $this->workflow->availableActions(S::RECEIVED, ['approver_8'], 8));
        $this->assertSame(BookingStageEnum::REALIZATION, $this->workflow->stageOf(S::APPROVED, 1));
    }

    public function test_the_shortcut_is_only_the_approval_and_only_for_the_approver_and_the_admin(): void
    {
        foreach (['approver_1', 'admin_1'] as $permission) {
            $this->assertFalse($this->workflow->canChangeTo(S::RECEIVED, S::REJECTED, [$permission], 1), $permission . ' rejects only after the stages below');
            $this->assertFalse($this->workflow->canChangeTo(S::RECEIVED, S::COMPLETED, [$permission], 1));
            $this->assertFalse($this->workflow->canChangeTo(S::RECEIVED, S::APPROVED_BY_BARBER, [$permission], 1));
        }

        // The handlers below the approver have nothing to skip to.
        $this->assertFalse($this->workflow->canChangeTo(S::RECEIVED, S::APPROVED, ['vice_approver_1'], 1));
        $this->assertFalse($this->workflow->canChangeTo(S::RECEIVED, S::APPROVED, ['transportation_control_officer_8'], 8));
        $this->assertFalse($this->workflow->canChangeTo(S::CONTROL_OFFICER_APPROVED, S::APPROVED, ['vice_approver_8'], 8));

        // Per booking type; nothing for a person without a role in it.
        $this->assertSame([], $this->targets(S::RECEIVED, ['approver_2', 'admin_2', 'user_1'], 1));

        // No shortcut once the booking is past the stages below the approver.
        $this->assertSame([3, 4], $this->targets(S::VICE_APPROVER_APPROVED, ['approver_1'], 1));
        $this->assertSame([5, 7], $this->targets(S::APPROVED, ['approver_1'], 1));
        $this->assertSame([], $this->targets(S::COMPLETED, ['approver_1', 'admin_1'], 1));
    }

    public function test_a_closed_booking_has_no_actions_and_a_permission_is_per_booking_type(): void
    {
        $this->assertSame([], $this->targets(S::COMPLETED, ['approver_1', 'vice_approver_1', 'admin_1'], 1));
        $this->assertSame([], $this->targets(S::VICE_APPROVER_APPROVED, ['approver_2'], 1));
    }

    public function test_the_admin_holds_every_turn_of_the_type_they_administer(): void
    {
        // The same moves as whoever's turn it is: vice approver, approver and (in transportation) control officer, then the shortcut (3).
        $this->assertSame([15, 16, 3], $this->targets(S::RECEIVED, ['admin_1'], 1));
        $this->assertSame([3, 4], $this->targets(S::VICE_APPROVER_APPROVED, ['admin_1'], 1));
        $this->assertSame([5, 7], $this->targets(S::APPROVED, ['admin_1'], 1));
        $this->assertSame([17, 18, 8, 9, 10, 3], $this->targets(S::RECEIVED, ['admin_8'], 8));
        $this->assertSame([15, 16, 8, 9, 10, 3], $this->targets(S::CONTROL_OFFICER_APPROVED, ['admin_8'], 8));
        $this->assertSame([3, 4, 8, 10], $this->targets(S::PENDING_PROCESSING, ['admin_8'], 8));

        // The barber route: done by the barber (vice approver's move) first, then completed by the approver's move.
        $this->assertTrue($this->workflow->canChangeTo(S::APPROVED, S::APPROVED_BY_BARBER, ['admin_5'], 5));
        $this->assertFalse($this->workflow->canChangeTo(S::APPROVED, S::COMPLETED, ['admin_5'], 5));
        $this->assertTrue($this->workflow->canChangeTo(S::APPROVED_BY_BARBER, S::COMPLETED, ['admin_5'], 5));
    }

    public function test_the_admin_is_strongest_in_the_roles_but_cannot_act_for_the_requester_or_on_a_closed_booking(): void
    {
        $this->assertTrue($this->workflow->canChangeTo(S::RECEIVED, S::APPROVED, ['admin_1'], 1), 'may skip the vice approver');
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

        // The turn's own buttons first, then the approver's shortcut.
        $this->assertSame([15, 16, 3], $this->targets(S::RECEIVED, $permissions, 1));
        $this->assertSame([3, 4], $this->targets(S::VICE_APPROVER_APPROVED, $permissions, 1));
    }

    public function test_transportation_starts_at_the_control_officer_who_may_also_put_it_on_hold(): void
    {
        $this->assertSame([17, 18, 8, 9, 10], $this->targets(S::RECEIVED, ['transportation_control_officer_8'], 8));
        // At that stage the vice approver waits, the approver can only skip ahead (and so can the admin of the type), and the control officer has no say on a car.
        $this->assertSame([], $this->targets(S::RECEIVED, ['vice_approver_8'], 8));
        $this->assertSame([3], $this->targets(S::RECEIVED, ['approver_8'], 8));
        $this->assertSame([], $this->targets(S::RECEIVED, ['transportation_control_officer_1'], 1));
    }

    public function test_in_transportation_the_route_goes_control_officer_then_vice_approver_then_approver(): void
    {
        $this->assertSame(BookingStageEnum::CONTROL_OFFICER, $this->workflow->stageOf(S::RECEIVED, 8));
        $this->assertSame(BookingStageEnum::VICE_APPROVER, $this->workflow->stageOf(S::CONTROL_OFFICER_APPROVED, 8));
        $this->assertSame(BookingStageEnum::VICE_APPROVER, $this->workflow->stageOf(S::CONTROL_OFFICER_REJECTED, 8));
        $this->assertSame(BookingStageEnum::APPROVER, $this->workflow->stageOf(S::VICE_APPROVER_APPROVED, 8));

        $this->assertSame([15, 16, 8, 9, 10], $this->targets(S::CONTROL_OFFICER_APPROVED, ['vice_approver_8'], 8));
        $this->assertSame([3], $this->targets(S::CONTROL_OFFICER_APPROVED, ['approver_8'], 8), 'only by skipping the vice approver');
        $this->assertContains(9, $this->targets(S::VICE_APPROVER_APPROVED, ['approver_8'], 8));

        // On every other type the route starts at the vice approver.
        $this->assertSame(BookingStageEnum::VICE_APPROVER, $this->workflow->stageOf(S::RECEIVED, 11));
    }

    public function test_only_the_approver_acts_while_transportation_waits_for_the_supplier(): void
    {
        $this->assertSame([3, 4, 8, 10], $this->targets(S::PENDING_PROCESSING, ['approver_8'], 8));
        $this->assertSame([], $this->targets(S::PENDING_PROCESSING, ['vice_approver_8', 'transportation_control_officer_8'], 8));
    }

    public function test_the_barber_marks_done_after_the_approval_and_the_approver_completes_only_after_that(): void
    {
        $this->assertTrue($this->workflow->canChangeTo(S::APPROVED, S::APPROVED_BY_BARBER, ['vice_approver_5'], 5));
        $this->assertFalse($this->workflow->canChangeTo(S::VICE_APPROVER_APPROVED, S::APPROVED_BY_BARBER, ['vice_approver_5'], 5), 'the barber cannot skip the approver');
        $this->assertFalse($this->workflow->canChangeTo(S::APPROVED, S::COMPLETED, ['approver_5'], 5));
        $this->assertTrue($this->workflow->canChangeTo(S::APPROVED_BY_BARBER, S::COMPLETED, ['approver_5'], 5));
        $this->assertTrue($this->workflow->canChangeTo(S::APPROVED, S::CANCELED_BY_APPROVER, ['approver_5'], 5));
        $this->assertFalse($this->workflow->canChangeTo(S::APPROVED, S::APPROVED_BY_BARBER, ['vice_approver_1'], 1), 'not on a car');
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
        $this->assertSame(BookingStageEnum::BARBER_DONE, $this->workflow->stageOf(S::APPROVED_BY_BARBER, 5));
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
        $this->assertSame([4, 5, 6, 7, 12, 13], S::terminalIds());
        $this->assertTrue(S::COMPLETED->isTerminal());
        $this->assertFalse(S::PENDING_FILE->isTerminal());
    }

    public function test_every_status_has_an_action_type_for_the_timeline(): void
    {
        foreach (S::cases() as $status) {
            $this->assertGreaterThan(0, $status->getActionType()->value, $status->name);
        }
    }
}
