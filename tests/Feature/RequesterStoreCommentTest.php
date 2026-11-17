<?php

namespace Tests\Feature;

use App\Enums\FieldTypeEnum;
use App\Enums\UnitEnum;
use App\Http\Resources\RequesterFormResource;
use App\Models\Booking;
use App\Models\BookingValue;
use App\Models\Comment;
use App\Models\FormField;
use App\Models\OptionField;

/**
 * The requester's comment on a web form submission (POST /bookings/{bookingTypeId}): saved as a booking comment when
 * given, under the same rule every booking comment follows (Hebrew letters and spaces, up to 100), and shown back on
 * both the requester's and the management's booking view.
 */
class RequesterStoreCommentTest extends ManagementTestCase
{
    /** An ICT portal type (email fault), as the catalog offers it. */
    private const BOOKING_TYPE = 22;

    /** A valid values map for the type's required root fields: plain text for text fields, a plain option for selects. */
    private function requiredValues(int $bookingTypeId = self::BOOKING_TYPE): array
    {
        $values = [];

        $fields = FormField::where('booking_type_id', $bookingTypeId)
            ->where('unit_id', UnitEnum::H->value)
            ->whereNull('form_field_id')
            ->where('is_required', true)
            ->where('is_active', '!=', false)
            ->get();

        $this->assertNotEmpty($fields, 'the seeded form has required fields to fill');

        foreach ($fields as $field) {
            if ((int) $field->field_type_id === FieldTypeEnum::SELECT->value) {
                $plainOption = OptionField::where('form_field_id', $field->id)
                    ->where('is_active', true)
                    ->get()
                    ->first(fn (OptionField $option): bool => !RequesterFormResource::asksForValue($option)
                        && !OptionField::where('option_field_id', $option->id)->where('is_active', true)->exists());

                $this->assertNotNull($plainOption, "field {$field->field_name} has a plain option to choose");
                $values[$field->id] = $plainOption->id;
            } else {
                $values[$field->id] = 'בדיקת תקלה';
            }
        }

        return $values;
    }

    public function test_the_comment_is_saved_with_the_booking_and_both_sides_see_it(): void
    {
        $text = 'אני גם כן רוצה לראות את ההערה שהוא רשם';

        $created = $this->actingAsPersonalNumber(self::PLAIN_USER)
            ->postJson('/api/bookings/' . self::BOOKING_TYPE, ['values' => $this->requiredValues(), 'comment' => "  {$text} "])
            ->assertCreated()
            ->json('data');

        $booking = Booking::where('uuid', $created['uuid'])->firstOrFail();
        $comment = Comment::where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame(1, Comment::where('booking_id', $booking->id)->count());

        $this->assertSame($text, $comment->comment, 'saved trimmed');
        $this->assertSame($booking->user_id, $comment->user_id, 'the requester is the author');

        // The requester sees it on their own booking view.
        $this->app['auth']->forgetGuards();
        $shown = $this->actingAsPersonalNumber(self::PLAIN_USER)
            ->getJson('/api/bookings/' . $booking->uuid)
            ->assertOk()
            ->json('data.comments');

        $this->assertCount(1, $shown);
        $this->assertSame($text, $shown[0]['comment']);

        // The management detail (the הערות panel) sees the same comment.
        $this->app['auth']->forgetGuards();
        $detail = $this->actingAsPersonalNumber(self::SUPER_ADMIN)
            ->getJson('/api/bookings-approver-management/bookings/' . $booking->serial_number)
            ->assertOk()
            ->json('data.comments');

        $this->assertContains($text, array_column($detail, 'comment'));
    }

    /** The values of a web submission used to vanish from both views: the formatter choked on the wrapped shape and answered nothing. */
    public function test_the_submitted_values_are_shown_back_on_both_views(): void
    {
        $created = $this->actingAsPersonalNumber(self::PLAIN_USER)
            ->postJson('/api/bookings/' . self::BOOKING_TYPE, ['values' => $this->requiredValues()])
            ->assertCreated()
            ->json('data');

        $booking = Booking::where('uuid', $created['uuid'])->firstOrFail();

        $this->app['auth']->forgetGuards();
        $shown = $this->actingAsPersonalNumber(self::PLAIN_USER)
            ->getJson('/api/bookings/' . $booking->uuid)
            ->assertOk()
            ->json('data');

        $this->assertArrayHasKey('booking_values', $shown, 'the formatter answered values');
        $this->assertContains('בדיקת תקלה', array_column($shown['booking_values'], 'value'));

        $this->app['auth']->forgetGuards();
        $detail = $this->actingAsPersonalNumber(self::SUPER_ADMIN)
            ->getJson('/api/bookings-approver-management/bookings/' . $booking->serial_number)
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($detail['booking_values'] ?? []);
        $this->assertContains('בדיקת תקלה', array_column($detail['booking_values'], 'value'));
    }

    /** Bookings the portal stored before the fix keep the one-element-list shape in the DB: they must still display. */
    public function test_a_value_stored_in_the_old_wrapped_shape_is_still_shown(): void
    {
        $created = $this->actingAsPersonalNumber(self::PLAIN_USER)
            ->postJson('/api/bookings/' . self::BOOKING_TYPE, ['values' => $this->requiredValues()])
            ->assertCreated()
            ->json('data');

        $booking = Booking::where('uuid', $created['uuid'])->firstOrFail();

        foreach (BookingValue::where('booking_id', $booking->id)->whereNull('option_field_id')->get() as $row) {
            if ($row->value !== null) {
                $row->forceFill(['value' => [$row->value]])->save();
            }
        }

        $this->app['auth']->forgetGuards();
        $shown = $this->actingAsPersonalNumber(self::PLAIN_USER)
            ->getJson('/api/bookings/' . $booking->uuid)
            ->assertOk()
            ->json('data');

        $this->assertArrayHasKey('booking_values', $shown);
        $this->assertContains('בדיקת תקלה', array_column($shown['booking_values'], 'value'));
    }

    public function test_without_a_comment_or_with_only_spaces_nothing_is_saved(): void
    {
        $commentsBefore = Comment::count();

        $this->actingAsPersonalNumber(self::PLAIN_USER)
            ->postJson('/api/bookings/' . self::BOOKING_TYPE, ['values' => $this->requiredValues()])
            ->assertCreated();

        $this->app['auth']->forgetGuards();

        $this->actingAsPersonalNumber(self::PLAIN_USER)
            ->postJson('/api/bookings/' . self::BOOKING_TYPE, ['values' => $this->requiredValues(), 'comment' => '   '])
            ->assertCreated();

        $this->assertSame($commentsBefore, Comment::count());
    }

    public function test_a_comment_that_breaks_the_comment_rule_is_refused_and_no_booking_is_made(): void
    {
        $bookingsBefore = Booking::count();
        $commentsBefore = Comment::count();

        foreach ([
            'English comment',                 // not Hebrew letters and spaces
            'הערה עם מספר 42',                 // digits are not allowed either
            str_repeat('א', 101),              // longer than 100
        ] as $badComment) {
            $this->app['auth']->forgetGuards();

            $this->actingAsPersonalNumber(self::PLAIN_USER)
                ->postJson('/api/bookings/' . self::BOOKING_TYPE, ['values' => $this->requiredValues(), 'comment' => $badComment])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('comment');
        }

        $this->assertSame($bookingsBefore, Booking::count(), 'a refused submission creates nothing');
        $this->assertSame($commentsBefore, Comment::count());
    }
}
