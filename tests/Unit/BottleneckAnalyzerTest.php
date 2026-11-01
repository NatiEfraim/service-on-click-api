<?php

namespace Tests\Unit;

use App\Domain\Metrics\BottleneckAnalyzer;
use App\Domain\Workflow\Workflow;
use PHPUnit\Framework\TestCase;

/** Stage analysis against ONE handling target per booking type (the total time from opening). No framework, no database. */
class BottleneckAnalyzerTest extends TestCase
{
    private const NOW = 1_000_000;
    private const HOUR = 3600;

    /** The intervals: booking 1 (type 1) waited 10h at the vice approver and has been 90h at the approver; 2 is fresh; 3 is a closed stay; 9 is another type without a target. */
    private function intervals(): array
    {
        $n = self::NOW;
        $h = self::HOUR;

        return [
            ['booking_id' => 1, 'booking_type_id' => 1, 'status_id' => 1, 'entered_at' => $n - 100 * $h, 'left_at' => $n - 90 * $h, 'created_at' => $n - 100 * $h, 'handler' => 'יוסי'],
            ['booking_id' => 1, 'booking_type_id' => 1, 'status_id' => 15, 'entered_at' => $n - 90 * $h, 'left_at' => null, 'created_at' => $n - 100 * $h],
            ['booking_id' => 2, 'booking_type_id' => 1, 'status_id' => 15, 'entered_at' => $n - 2 * $h, 'left_at' => null, 'created_at' => $n - 20 * $h],
            ['booking_id' => 3, 'booking_type_id' => 1, 'status_id' => 1, 'entered_at' => $n - 30 * $h, 'left_at' => $n - 10 * $h, 'created_at' => $n - 30 * $h, 'handler' => 'יוסי'],
            ['booking_id' => 9, 'booking_type_id' => 5, 'status_id' => 15, 'entered_at' => $n - 300 * $h, 'left_at' => null, 'created_at' => $n - 300 * $h],
        ];
    }

    private function analyzer(array $targets): BottleneckAnalyzer
    {
        return new BottleneckAnalyzer(new Workflow(), $targets);
    }

    /** @return array<string, mixed> the entry of a stage */
    private function stage(array $result, string $stage): array
    {
        return array_values(array_filter($result, fn (array $row): bool => $row['stage'] === $stage))[0];
    }

    public function test_overdue_is_measured_from_the_opening_against_the_target_of_the_type(): void
    {
        $approver = $this->stage($this->analyzer([1 => ['target_hours' => 48]])->analyze($this->intervals(), self::NOW), 'approver');

        $this->assertSame(3, $approver['open_count'], 'two booking types wait in the stage');
        $this->assertSame(1, $approver['breached_open']);
        $this->assertSame([1], $approver['overdue_booking_ids']);
        $this->assertNotContains(9, $approver['overdue_booking_ids'], 'a type without a target is never overdue');
        $this->assertSame('warning', $approver['severity']);
        $this->assertNull($approver['target_hours'], 'the stage mixes booking types, so there is no single target to show');
    }

    public function test_a_stage_that_closed_stays_are_measured_in_has_a_median_and_an_average(): void
    {
        $vice = $this->stage($this->analyzer([1 => ['target_hours' => 48]])->analyze($this->intervals(), self::NOW), 'vice_approver');

        $this->assertSame(15.0, $vice['median_hours']);
        $this->assertSame(15.0, $vice['avg_hours']);
        $this->assertSame('ok', $vice['severity'], 'nothing waits there and its type has a target');
        $this->assertSame(48.0, $vice['target_hours']);
        $this->assertSame(['יוסי' => 2], $vice['top_handlers']);
    }

    public function test_without_any_target_there_is_no_severity(): void
    {
        $this->assertSame('no_target', $this->analyzer([])->analyze($this->intervals(), self::NOW)[0]['severity']);
    }

    public function test_two_overdue_of_three_waiting_is_critical(): void
    {
        $intervals = $this->intervals();
        $intervals[] = ['booking_id' => 4, 'booking_type_id' => 1, 'status_id' => 15, 'entered_at' => self::NOW - 5 * self::HOUR, 'left_at' => null, 'created_at' => self::NOW - 60 * self::HOUR];

        $approver = $this->stage($this->analyzer([1 => ['target_hours' => 48]])->analyze($intervals, self::NOW), 'approver');

        $this->assertSame('critical', $approver['severity']);
    }

    public function test_percentile(): void
    {
        $this->assertSame(9.1, BottleneckAnalyzer::percentile([1, 2, 3, 4, 5, 6, 7, 8, 9, 10], 90));
        $this->assertNull(BottleneckAnalyzer::percentile([], 50));
    }
}
