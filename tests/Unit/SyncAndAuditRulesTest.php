<?php

namespace Tests\Unit;

use App\Domain\Audit\HashChain;
use App\Domain\Config\FormFieldRules;
use App\Domain\Sync\ExportFileNames;
use App\Domain\Sync\StatusConflictResolver as R;
use PHPUnit\Framework\TestCase;

/** The hash chain of the audit log, the three-way merge of a status, the names of the files sent back, and the field rules - none needs a database. */
class SyncAndAuditRulesTest extends TestCase
{
    //----------------------------- Audit chain -------------------------------//

    private function chain(): array
    {
        $first = ['id' => 1, 'occurred_at' => '2026-10-26 10:00:00.000000', 'action' => 'x', 'after' => ['b' => 1, 'a' => 2], 'prev_hash' => null];
        $first['hash'] = HashChain::hash($first, null);

        $second = ['id' => 2, 'occurred_at' => '2026-10-26 10:01:00.000000', 'action' => 'y', 'before' => ['s' => 1], 'prev_hash' => $first['hash']];
        $second['hash'] = HashChain::hash($second, $first['hash']);

        return [$first, $second];
    }

    public function test_a_chain_is_valid_and_a_changed_entry_is_found(): void
    {
        [$first, $second] = $this->chain();

        $this->assertTrue(HashChain::verify([$first, $second])['valid']);

        $tampered = $second;
        $tampered['action'] = 'tampered';

        $this->assertSame(['valid' => false, 'checked' => 2, 'broken_at' => 2], HashChain::verify([$first, $tampered]));
    }

    public function test_a_missing_entry_breaks_the_chain(): void
    {
        [, $second] = $this->chain();

        $this->assertFalse(HashChain::verify([$second])['valid'], 'the second row does not follow nothing');
    }

    public function test_the_hash_does_not_depend_on_the_order_of_keys_or_on_numbers_read_back_as_text(): void
    {
        $this->assertSame(
            HashChain::hash(['after' => ['a' => 2, 'b' => 1], 'booking_serial' => 1001], null),
            HashChain::hash(['after' => ['b' => 1, 'a' => 2], 'booking_serial' => '1001'], null),
        );
    }

    public function test_json_text_read_back_from_the_database_hashes_like_the_arrays_written(): void
    {
        [$first] = $this->chain();
        $fromDatabase = [...$first, 'after' => json_encode($first['after']), 'before' => null];

        $this->assertTrue(HashChain::verify([$fromDatabase])['valid']);
    }

    public function test_secrets_are_redacted(): void
    {
        $this->assertSame(
            ['digisecret' => '[redacted]', 'n' => ['api_key' => '[redacted]', 'ok' => 1]],
            HashChain::redact(['digisecret' => 'x', 'n' => ['api_key' => 'y', 'ok' => 1]])
        );
    }

    //----------------------------- Status merge ------------------------------//

    public function test_the_three_way_merge_of_a_status(): void
    {
        $this->assertSame(R::NOOP, R::resolve(1, 15, 1, 100, 200, 'internal'), 'the mobile app has not moved');
        $this->assertSame(R::CONFIRM, R::resolve(1, 3, 3, 300, 200, 'internal'), 'both agree');
        $this->assertSame(R::APPLY, R::resolve(1, 1, 5, 300, null, 'external'), 'only the mobile app moved');
        $this->assertSame(R::CONFLICT, R::resolve(1, 3, 4, 300, 200, 'internal'), 'both moved');
        $this->assertSame(R::STALE, R::resolve(1, 3, 15, 100, 200, 'internal'), 'a file older than our decision, not closing');
        $this->assertSame(R::CONFLICT, R::resolve(1, 3, 4, 100, 200, 'internal'), 'a closing status is never stale');
        $this->assertSame(R::CONFLICT, R::resolve(1, 15, 3, 100, 200, 'internal'), 'nor is an approval: dropping it would lose a decision');
    }

    public function test_what_needs_no_decision_is_applied_and_not_held_for_a_person(): void
    {
        $this->assertSame(R::APPLY, R::resolve(1, 15, 6, 300, 200, 'internal'), 'the requester cancels: it stands');
        $this->assertSame(R::APPLY, R::resolve(1, 15, 6, 100, 200, 'internal'), 'also when the cancellation is older than our decision');
        $this->assertSame(R::APPLY, R::resolve(15, 3, 5, 300, 200, 'internal'), 'a booking approved here, carried on to completion there');
        $this->assertSame(R::APPLY, R::resolve(3, 11, 5, 300, 200, 'internal'), 'so is one the barber closed');
        $this->assertSame(R::CONFLICT, R::resolve(15, 4, 5, 300, 200, 'internal'), 'a rejection completed there is a real conflict');
    }

    public function test_the_requesters_own_cancellation_cannot_be_overridden(): void
    {
        $this->assertFalse(R::canKeepInternal(6));
        $this->assertTrue(R::canKeepInternal(4));
    }

    public function test_the_names_of_the_files_sent_back(): void
    {
        $this->assertSame('SrvOnClick_r2b_booking_status_2026-10-26.json', ExportFileNames::bookingStatusFile(new \DateTimeImmutable('2026-10-26')));
        $this->assertSame('SrvOnClick_b2r_booking_status_2026-10-26.json', ExportFileNames::bookingStatusFile(new \DateTimeImmutable('2026-10-26'), 'SrvOnClick_b2r_booking_status_'));
        $this->assertSame('SrvOnClick_r2b_booking_values_2026-10-26_10-30-00.json', ExportFileNames::bookingValuesFile(new \DateTimeImmutable('2026-10-26 10:30:00')));
        // Two corrections in the same second must not share a name - the second file would replace the first one in the bucket.
        $this->assertSame('SrvOnClick_r2b_booking_values_2026-10-26_10-30-00_a1b2c3d4.json', ExportFileNames::bookingValuesFile(new \DateTimeImmutable('2026-10-26 10:30:00'), 'a1b2c3d4-e5f6-7890'));
    }

    //----------------------------- Field rules -------------------------------//

    public function test_only_a_date_or_a_time_can_be_marked_editable(): void
    {
        $this->assertNull(FormFieldRules::validateEditable(3), 'date');
        $this->assertNull(FormFieldRules::validateEditable(4), 'time');
        $this->assertNotNull(FormFieldRules::validateEditable(2), 'text');
        $this->assertNotNull(FormFieldRules::validateEditable(1), 'number');
    }

    public function test_a_corrected_value_is_checked(): void
    {
        $this->assertNull(FormFieldRules::validateValue(3, '2026-11-02', [], '2026-10-26'));
        $this->assertNotNull(FormFieldRules::validateValue(3, '2026-10-01', [], '2026-10-26'), 'a date that passed');
        $this->assertNotNull(FormFieldRules::validateValue(3, '2026-11-02', ['2026-11-02'], '2026-10-26'), 'a blocked date');
        $this->assertNotNull(FormFieldRules::validateValue(3, '2026-02-30'), 'not a date');
        $this->assertNull(FormFieldRules::validateValue(4, '09:30'));
        $this->assertNotNull(FormFieldRules::validateValue(4, '9:30'));
        $this->assertNotNull(FormFieldRules::validateValue(2, 'x'), 'text cannot be corrected');
    }
}
