<?php

namespace Database\Seeders\TestSeeders;

use App\Enums\FieldTypeEnum;
use App\Enums\RoleEnum;
use App\Enums\UnitEnum;
use App\Models\BarberBooking;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\BookingTypeHasStatus;
use App\Models\BookingValue;
use App\Models\Comment;
use App\Models\FormField;
use App\Models\OptionField;
use App\Models\User;
use App\Models\ViceApproverHasBooking;
use Illuminate\Database\Seeder;

/**
 * Synthetic bookings for local development: the bookings are placed in the mobile app, so the web system
 * has none of its own. For each booking type there is one booking in each status the type can reach.
 */
class DevBookingSeeder extends Seeder
{
    /** At most this many bookings per booking type. */
    private const BOOKINGS_PER_TYPE = 6;

    /** Run the database seeds. */
    public function run(): void
    {
        // Whoever has the user role (they log in and see their own bookings) and the people the mobile app knows by ID number.
        $owners = User::query()
            ->whereNotNull('personal_id')
            ->orWhereHas('roles', fn($query) => $query->where('name', RoleEnum::USER->toSpatieRoleName()))
            ->orderBy('id')
            ->get();

        if ($owners->isEmpty()) {
            return;
        }

        // Optional: the vice approver of every booking, and whoever writes the comment.
        $viceApprover = $this->firstUserWithRole(RoleEnum::VICE_APPROVER);
        $commenter = $viceApprover
            ?? $this->firstUserWithRole(RoleEnum::APPROVER)
            ?? $this->firstUserWithRole(RoleEnum::ADMIN);

        foreach (BookingType::query()->orderBy('id')->get() as $bookingType) {
            $statusIds = BookingTypeHasStatus::query()
                ->where('booking_type_id', $bookingType->id)
                ->orderBy('status_id')
                ->pluck('status_id')
                ->take(self::BOOKINGS_PER_TYPE);

            foreach ($statusIds as $index => $statusId) {
                $owner = $owners[$index % $owners->count()];

                $booking = Booking::create([
                    'unit_id' => UnitEnum::H->value,
                    'booking_type_id' => $bookingType->id,
                    'user_id' => $owner->id,
                    'status_id' => $statusId,
                ]);

                $this->createValues($booking);

                if ($viceApprover) {
                    ViceApproverHasBooking::create([
                        'user_id' => $viceApprover->id,
                        'booking_id' => $booking->id,
                        'approved_by_vice_approver' => false,
                        'is_deleted' => false,
                    ]);
                }

                if ($index === 0 && $commenter) {
                    Comment::create([
                        'booking_id' => $booking->id,
                        'user_id' => $commenter->id,
                        'comment' => 'הערת בדיקה',
                    ]);
                }

                if ($bookingType->name === 'barber_booking') {
                    BarberBooking::create([
                        'date' => now()->addDays($index + 1)->toDateString(),
                        'start_time' => sprintf('%02d:00:00', 8 + $index),
                        'booking_id' => $booking->id,
                        'user_id' => $owner->id,
                        'is_deleted' => false,
                    ]);
                }
            }
        }
    }

    /** The first user (by id) that holds the role, if there is one. @param RoleEnum $role @return ?User */
    private function firstUserWithRole(RoleEnum $role): ?User
    {
        return User::query()
            ->whereHas('roles', fn($query) => $query->where('name', $role->toSpatieRoleName()))
            ->orderBy('id')
            ->first();
    }

    /** A value for every top-level form field of the booking's type: the first option for lists, a sample for the rest. @param Booking $booking */
    private function createValues(Booking $booking): void
    {
        $formFields = FormField::query()
            ->where('booking_type_id', $booking->booking_type_id)
            ->where('unit_id', $booking->unit_id)
            ->whereNull('form_field_id')
            ->get();

        foreach ($formFields as $formField) {
            $fieldType = FieldTypeEnum::tryFrom((int) $formField->field_type_id);

            if (in_array($fieldType, [FieldTypeEnum::SELECT, FieldTypeEnum::RADIO, FieldTypeEnum::CHECKBOX, FieldTypeEnum::MULTI_SELECT], true)) {
                $option = OptionField::query()
                    ->where('form_field_id', $formField->id)
                    ->whereNull('option_field_id')
                    ->orderBy('id')
                    ->first();

                if ($option) {
                    BookingValue::create([
                        'booking_id' => $booking->id,
                        'form_field_id' => $formField->id,
                        'option_field_id' => $option->id,
                        'value' => null,
                    ]);
                }

                continue;
            }

            // Uploads are not seeded: they need files in the bucket.
            if (in_array($fieldType, [FieldTypeEnum::FILE, FieldTypeEnum::IMAGE], true)) {
                continue;
            }

            // Plain fields are stored as a JSON scalar (the model's array cast turns the string into "…").
            BookingValue::create([
                'booking_id' => $booking->id,
                'form_field_id' => $formField->id,
                'value' => match ($fieldType) {
                    FieldTypeEnum::DATE => now()->addDays(3)->toDateString(),
                    FieldTypeEnum::TIME => '10:00',
                    FieldTypeEnum::NUMBER, FieldTypeEnum::NUMBER_ASTERISK => '2',
                    FieldTypeEnum::TEL => '0500000001',
                    default => 'ערך בדיקה',
                },
            ]);
        }
    }
}
