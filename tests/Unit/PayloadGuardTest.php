<?php

namespace Tests\Unit;

use App\Domain\Sync\ExportFileNames;
use App\Domain\Sync\PayloadGuard;
use PHPUnit\Framework\TestCase;

/** What must never leave for the mobile app: the display name of a unit, the name of a department, a reference to a department. No database. */
class PayloadGuardTest extends TestCase
{
    public function test_a_clean_payload_passes(): void
    {
        $this->assertSame([], PayloadGuard::violations(['changes' => [['entity' => 'statement', 'unit_id' => 1, 'data' => ['statement' => 'יש לאשר את התנאים']]]], ['NASA']));
    }

    public function test_a_name_is_found_as_a_whole_word_anywhere_in_the_payload(): void
    {
        $payload = ['changes' => [['data' => ['statement' => 'ההזמנה היא ליחידת NASA בלבד']]]];

        $this->assertSame(['$.changes.0.data.statement: internal name'], PayloadGuard::violations($payload, ['NASA']));
    }

    public function test_it_ignores_the_case_and_finds_hebrew_names_too(): void
    {
        $this->assertNotSame([], PayloadGuard::violations(['t' => 'nasa'], ['NASA']));
        $this->assertNotSame([], PayloadGuard::violations(['t' => 'יחידת כנף צפון, קומה 2'], ['כנף צפון']));
    }

    public function test_a_longer_word_that_contains_the_name_is_not_a_hit(): void
    {
        $this->assertSame([], PayloadGuard::violations(['t' => 'NASAL spray, ANASA, NASA2'], ['NASA']));
        $this->assertSame([], PayloadGuard::violations(['t' => 'מחלקתית'], ['מחלקה']));
        $this->assertNotSame([], PayloadGuard::violations(['t' => '(NASA)'], ['NASA']), 'punctuation around the name does not hide it');
    }

    public function test_a_key_that_carries_a_unit_name_or_a_department_is_found_at_any_depth(): void
    {
        foreach (['unit_name', 'unit_display_name', 'internal_name', 'department', 'department_id', 'DEPARTMENT_NAME'] as $key) {
            $this->assertSame(["$.a.b.{$key}"], PayloadGuard::violations(['a' => ['b' => [$key => 5]]], []), $key);
        }

        $this->assertSame([], PayloadGuard::violations(['unit_id' => 3, 'code' => 'H'], []), 'the unit id and its letter are what the mobile app knows');
    }

    public function test_the_names_to_look_for_leave_out_short_ones_the_letters_and_repeats(): void
    {
        $this->assertSame(['כנף צפון', 'NASA'], PayloadGuard::usableNames(['כנף צפון', null, '', 'N', 'ab', 'H', 'nasa', 'NASA', 'M'], ['H', 'N', 'M', 'R']));
        $this->assertSame([], PayloadGuard::usableNames(['H', 'Mmm'], ['H', 'mmm']), 'a unit that has no name of its own shows its letter');
    }

    public function test_the_config_files_sort_in_the_order_they_were_queued_even_within_one_second(): void
    {
        $at = new \DateTimeImmutable('2026-11-01 10:00:00');

        $names = [ExportFileNames::systemConfigFile($at, 9), ExportFileNames::systemConfigFile($at, 10), ExportFileNames::systemConfigFile($at, 100)];
        $sorted = $names;
        sort($sorted, SORT_STRING);

        $this->assertSame($names, $sorted);
        $this->assertSame('SrvOnClick_r2b_system_config_2026-11-01_10-00-00_000000009.json', $names[0]);
    }
}
