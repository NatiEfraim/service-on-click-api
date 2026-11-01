<?php

namespace Tests\Unit;

use App\Domain\Config\FormFieldRules as R;
use PHPUnit\Framework\TestCase;

/** What may be done to the form of a booking type: the name, the type, the options, the removal. No database. */
class FormFieldRulesTest extends TestCase
{
    /** @return array<int, array{id: int, field_display_name: string}> */
    private function siblings(): array
    {
        return [['id' => 1, 'field_display_name' => 'תאריך התחלה'], ['id' => 2, 'field_display_name' => 'Reason']];
    }

    public function test_a_label_is_two_to_fifty_characters_of_plain_text(): void
    {
        $this->assertNull(R::validateLabel('הערות מיוחדות', $this->siblings()));
        $this->assertNull(R::validateLabel('כמות (ביחידות), עד 10/20', $this->siblings()));

        $this->assertStringContainsString('בין 2 ל-50', R::validateLabel('א', []));
        $this->assertStringContainsString('בין 2 ל-50', R::validateLabel(str_repeat('א', 51), []));
        $this->assertStringContainsString('בין 2 ל-50', R::validateLabel('   ', []));
        $this->assertStringContainsString('אותיות, ספרות', R::validateLabel('שם <b>עבה</b>', []));
        $this->assertStringContainsString('אותיות, ספרות', R::validateLabel('שם; DROP', []));
    }

    public function test_a_label_cannot_be_the_name_of_another_field_of_the_form_whatever_the_case(): void
    {
        $this->assertSame('כבר קיים שדה בשם זה בטופס', R::validateLabel('תאריך התחלה', $this->siblings()));
        $this->assertSame('כבר קיים שדה בשם זה בטופס', R::validateLabel(' reason ', $this->siblings()));

        // A field may keep its own name.
        $this->assertNull(R::validateLabel('תאריך התחלה', $this->siblings(), 1));
    }

    public function test_a_new_field_needs_a_supported_type(): void
    {
        $this->assertNull(R::validate(['field_display_name' => 'הערה', 'field_type_id' => 2], []));

        foreach ([12 => 'password', 15 => 'hidden', 16 => 'colour', 17 => 'range', 18 => 'month', 19 => 'week', 22 => 'asterisk', 99 => 'unknown'] as $type => $name) {
            $this->assertSame('סוג השדה אינו נתמך', R::validate(['field_display_name' => 'הערה', 'field_type_id' => $type], []), $name);
        }
    }

    public function test_only_a_date_or_a_time_can_be_made_editable(): void
    {
        $this->assertNull(R::validate(['field_display_name' => 'תאריך חדש', 'field_type_id' => 3, 'editable' => true], []));
        $this->assertNull(R::validate(['field_display_name' => 'שעה חדשה', 'field_type_id' => 4, 'editable' => true], []));
        $this->assertSame('רק שדות תאריך ושעה ניתנים לעריכה על ידי מאשר', R::validate(['field_display_name' => 'הערה', 'field_type_id' => 2, 'editable' => true], []));
        $this->assertNull(R::validate(['field_display_name' => 'הערה', 'field_type_id' => 2, 'editable' => false], []));
    }

    public function test_a_new_choice_needs_options_none_twice_and_each_a_plain_name(): void
    {
        $choice = fn (array $options): ?string => R::validate(['field_display_name' => 'בחירה', 'field_type_id' => 5, 'options' => $options], []);

        $this->assertNull($choice(['כן', 'לא']));
        $this->assertSame('שדה בחירה חייב לכלול לפחות אפשרות אחת', $choice([]));
        $this->assertSame('שדה בחירה חייב לכלול לפחות אפשרות אחת', $choice(['', '   ']));
        $this->assertSame('קיימות אפשרויות כפולות', $choice(['כן', ' כן ']));
        $this->assertSame('קיימות אפשרויות כפולות', $choice(['Yes', 'yes']));
        $this->assertStringContainsString('שם אפשרות אינו תקין', (string) $choice(['<script>']));
        $this->assertStringContainsString('שם אפשרות אינו תקין', (string) $choice([str_repeat('א', 51)]));

        foreach (R::OPTION_TYPES as $type) {
            $this->assertNotNull(R::validate(['field_display_name' => 'בחירה', 'field_type_id' => $type], []), "type {$type} is a choice");
        }
    }

    public function test_the_type_of_a_field_changes_only_inside_its_family_and_only_when_nobody_filled_it(): void
    {
        // Unchanged: nothing to say.
        $this->assertNull(R::validateTypeChange(2, 2, 10, false));

        // A text becomes a text box: fine for a field made here that nobody filled.
        $this->assertNull(R::validateTypeChange(6, 2, 0, true));
        $this->assertNull(R::validateTypeChange(8, 5, 0, true), 'a choice stays a choice');

        $this->assertStringContainsString('באפליקציה', (string) R::validateTypeChange(6, 2, 0, false), 'the code of the mobile app depends on the type of its fields');
        $this->assertStringContainsString('ערכים ב-3 קריאות', (string) R::validateTypeChange(6, 2, 3, true));
        $this->assertStringContainsString('שדה בחירה', (string) R::validateTypeChange(5, 2, 0, true), 'the options would have to appear');
        $this->assertStringContainsString('שדה בחירה', (string) R::validateTypeChange(2, 5, 0, true), 'or go');
        $this->assertSame('סוג השדה אינו נתמך', R::validateTypeChange(12, 2, 0, true));
    }

    public function test_removing_archives_what_was_filled_deletes_what_was_not_and_keeps_the_last(): void
    {
        $this->assertSame('archive', R::removalMode(4, 3));
        $this->assertSame('delete', R::removalMode(0, 3));
        $this->assertSame('blocked', R::removalMode(0, 0));
        $this->assertSame('blocked', R::removalMode(9, 0), 'even a filled field does not leave an empty form');
    }

    public function test_a_new_field_has_what_the_seeded_ones_have_for_its_type(): void
    {
        $this->assertSame(['is_input' => true, 'payload_section_id' => 1], R::defaultsFor(2));
        $this->assertSame(['is_input' => true, 'payload_section_id' => 1], R::defaultsFor(13), 'an upload travels as an input');
        $this->assertSame(['is_input' => false, 'payload_section_id' => 0], R::defaultsFor(5));
        $this->assertSame(['is_input' => false, 'payload_section_id' => 0], R::defaultsFor(20));
    }

    public function test_the_key_of_a_field_made_here_is_prefixed_and_stable(): void
    {
        $key = R::newKey('3f2a9c1d-0b7e-4c11-9a2d-5e8f6a7b1c20');

        $this->assertSame('int_3f2a9c1d0b7e', $key);
        $this->assertSame($key, R::newKey('3f2a9c1d-0b7e-4c11-9a2d-5e8f6a7b1c20'));
        $this->assertNotSame($key, R::newKey('4f2a9c1d-0b7e-4c11-9a2d-5e8f6a7b1c20'));
    }

    public function test_the_options_as_given_are_trimmed_and_the_empty_ones_dropped(): void
    {
        $this->assertSame(['כן', 'לא'], R::cleanOptions([' כן ', '', 'לא', '  ']));
        $this->assertSame([], R::cleanOptions(null));
        $this->assertSame([], R::cleanOptions('not a list'));
    }
}
