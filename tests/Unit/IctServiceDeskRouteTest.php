<?php

namespace Tests\Unit;

use App\Domain\Workflow\Routes\IctServiceDeskRoute;
use App\Domain\Workflow\Transition;
use App\Enums\BookingStageEnum;
use App\Enums\RoleEnum;
use App\Enums\StatusBookingEnum as S;
use PHPUnit\Framework\TestCase;

/** The ICT service desk route (types 20-25) on its own: statuses, stages, moves and their flags. No framework, no database. */
class IctServiceDeskRouteTest extends TestCase
{
    private IctServiceDeskRoute $route;

    protected function setUp(): void
    {
        $this->route = new IctServiceDeskRoute();
    }

    /** @return array<int, int> the target status ids of the manual transitions leaving a status */
    private function manualTargets(int $from): array
    {
        return array_values(array_map(
            fn (Transition $transition): int => $transition->to,
            array_filter($this->route->transitionsFrom($from), fn (Transition $transition): bool => $transition->isManual()),
        ));
    }

    public function test_the_shape_of_the_route(): void
    {
        $this->assertSame('ict_service_desk', $this->route->key());
        $this->assertSame(S::RECEIVED->value, $this->route->initialStatus());
        $this->assertSame([1, 111, 5], $this->route->steps());
        $this->assertSame([1, 111, 5, 7, 6], $this->route->statusIds());
        $this->assertSame([5, 7, 6], $this->route->terminalStatuses());
        $this->assertSame(S::COMPLETED->value, $this->route->successStatus());
        $this->assertSame([RoleEnum::VICE_APPROVER, RoleEnum::ADMIN_ICT], $this->route->roles());
        $this->assertSame(RoleEnum::ADMIN_ICT, $this->route->ownerRole());
        $this->assertSame(RoleEnum::USER, $this->route->requesterRole());
        $this->assertSame([], $this->route->handlerFieldStatuses(), 'the ICT forms have no handler section');
        $this->assertNull($this->route->dueFields());
    }

    public function test_the_stage_of_every_status(): void
    {
        $this->assertSame(BookingStageEnum::SERVICE_DESK_QUEUE, $this->route->stageOf(S::RECEIVED->value));
        $this->assertSame(BookingStageEnum::SERVICE_DESK_HANDLING, $this->route->stageOf(S::IN_SERVICE_DESK->value));

        foreach ([S::COMPLETED, S::CANCELED_BY_APPROVER, S::CANCELED_BY_USER, S::REJECTED] as $closed) {
            $this->assertSame(BookingStageEnum::CLOSED, $this->route->stageOf($closed->value), $closed->name);
        }

        // Older data left in a generic status waits in the queue, not stranded.
        foreach ([S::PROCESSING, S::APPROVED, S::VICE_APPROVER_APPROVED, S::VICE_APPROVER_REJECTED] as $old) {
            $this->assertSame(BookingStageEnum::SERVICE_DESK_QUEUE, $this->route->stageOf($old->value), $old->name);
        }
    }

    public function test_the_queue_offers_the_take_and_the_two_closes_and_the_automation_waits_disabled(): void
    {
        $this->assertSame([111, 5, 7], $this->manualTargets(S::RECEIVED->value));

        $take = $this->route->transitionsFrom(S::RECEIVED->value)[0];
        $this->assertSame('לקיחה לטיפול', $take->label);
        $this->assertTrue($take->assignsActor, 'whoever takes the request becomes its handler');
        $this->assertFalse($take->commentRequired);

        // The automatic move (the future automation) leaves the queue too, held by nobody.
        $automatic = array_values(array_filter($this->route->transitionsFrom(S::RECEIVED->value), fn (Transition $transition): bool => $transition->isAutomatic()));
        $this->assertCount(1, $automatic);
        $this->assertSame(Transition::AUTO_AUTOMATION, $automatic[0]->auto);
        $this->assertSame(S::IN_SERVICE_DESK->value, $automatic[0]->to);
        $this->assertSame([], $automatic[0]->roles);
    }

    public function test_handling_offers_the_closes_and_the_way_back_to_the_queue(): void
    {
        $this->assertSame([5, 7, 1], $this->manualTargets(S::IN_SERVICE_DESK->value));

        $back = array_values(array_filter($this->route->transitionsFrom(S::IN_SERVICE_DESK->value), fn (Transition $transition): bool => $transition->to === S::RECEIVED->value))[0];
        $this->assertSame(Transition::DIRECTION_BACK, $back->direction);
        $this->assertTrue($back->releasesHandler, 'back to the queue releases the handler');
        $this->assertFalse($back->commentRequired, 'the sheet: the comment is not required');
    }

    public function test_the_comment_is_required_only_on_a_close_without_doing_and_on_a_reopen(): void
    {
        foreach ($this->route->transitions() as $transition) {
            $expected = $transition->to === S::CANCELED_BY_APPROVER->value
                || ($transition->from !== S::RECEIVED->value && $transition->to === S::IN_SERVICE_DESK->value && $transition->isManual());

            $this->assertSame($expected, $transition->commentRequired, "{$transition->from} -> {$transition->to}");
        }
    }

    public function test_only_the_commander_reopens_a_closed_request(): void
    {
        foreach ([S::COMPLETED, S::CANCELED_BY_APPROVER] as $closed) {
            $reopens = $this->route->transitionsFrom($closed->value);

            $this->assertCount(1, $reopens, $closed->name);
            $this->assertSame(S::IN_SERVICE_DESK->value, $reopens[0]->to);
            $this->assertSame([RoleEnum::ADMIN_ICT], $reopens[0]->roles);
            $this->assertTrue($reopens[0]->commentRequired);
        }

        // The requester's own cancellation is final.
        $this->assertSame([], $this->route->transitionsFrom(S::CANCELED_BY_USER->value));
    }

    public function test_an_old_generic_status_behaves_like_the_queue(): void
    {
        $this->assertSame(S::RECEIVED->value, $this->route->normalize(S::APPROVED->value));
        $this->assertSame(S::RECEIVED->value, $this->route->normalize(S::PROCESSING->value));
        $this->assertSame(S::COMPLETED->value, $this->route->normalize(S::COMPLETED->value));
        $this->assertSame(S::CANCELED_BY_USER->value, $this->route->normalize(S::CANCELED_BY_USER->value));
        $this->assertSame($this->manualTargets(S::RECEIVED->value), $this->manualTargets(S::APPROVED->value));
    }

    public function test_the_assignment_rules_of_the_desk(): void
    {
        $this->assertSame([
            'statuses' => [1, 111],
            'assigners' => [RoleEnum::ADMIN_ICT],
            'candidates' => [RoleEnum::VICE_APPROVER, RoleEnum::ADMIN_ICT],
            'self' => [RoleEnum::VICE_APPROVER],
        ], $this->route->assignment());
    }
}
