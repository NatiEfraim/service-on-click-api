<?php

namespace Tests\Feature;

use App\Enums\PaginationEnum;
use App\Enums\RoleEnum;
use App\Models\Booking;
use App\Models\File;
use App\Models\Image;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** What a person with only the user role may do: read their own booking history and open one booking. */
class UserBookingsTest extends ManagementTestCase
{
    private function userId(string $personalNumber): int
    {
        return (int) User::where('personal_number', $personalNumber)->value('id');
    }

    /** The first booking of this person in a booking type (13 = construction, whose seeded values are all plain). */
    private function bookingOf(string $personalNumber, int $bookingTypeId = 13): Booking
    {
        return Booking::where('user_id', $this->userId($personalNumber))
            ->where('booking_type_id', $bookingTypeId)
            ->orderBy('id')
            ->firstOrFail();
    }

    /**
     * Links an attachment to the booking exactly as RequesterService::storeFiles saves an upload: the bytes under
     * portal-uploads/<booking uuid> and the full key (with its extension) on the row. $bytes null = the row is linked
     * but its bytes never reached the bucket.
     */
    private function uploadedAttachment(Booking $booking, string $fileName, ?string $bytes = 'bytes'): File|Image
    {
        $key = 'portal-uploads/' . $booking->uuid . '/' . $fileName;

        if ($bytes !== null) {
            Storage::disk('minio')->put($key, $bytes);
        }

        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $isImage = in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
        $row = ['name' => (string) Str::uuid(), 'display_name' => $fileName, 'path' => $key, 'file_name' => $fileName];

        $attachment = $isImage ? Image::create($row) : File::create($row);

        DB::table($isImage ? 'booking_has_images' : 'booking_has_files')->insert([
            'booking_id' => $booking->id,
            ($isImage ? 'image_id' : 'file_id') => $attachment->id,
            'form_field_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $attachment;
    }

    public function test_my_bookings_lists_only_the_bookings_of_the_person_logged_in(): void
    {
        $own = Booking::where('user_id', $this->userId(self::PLAIN_USER))->pluck('uuid')->all();
        $someoneElses = Booking::where('user_id', $this->userId(self::OTHER_PLAIN_USER))->pluck('uuid')->all();

        // More bookings than a page holds, so the second page is needed too.
        $this->assertGreaterThan(PaginationEnum::PER_PAGE->value, count($own));

        $firstPage = $this->actingAsPersonalNumber(self::PLAIN_USER)->getJson('/api/bookings/my-bookings')
            ->assertOk()->json('data');

        $this->app['auth']->forgetGuards();

        $secondPage = $this->actingAsPersonalNumber(self::PLAIN_USER)->getJson('/api/bookings/my-bookings?page=2')
            ->assertOk()->json('data');

        $listed = array_column([...$firstPage, ...$secondPage], 'uuid');

        $this->assertCount(PaginationEnum::PER_PAGE->value, $firstPage);
        $this->assertEqualsCanonicalizing($own, $listed);
        $this->assertEmpty(array_intersect($someoneElses, $listed), "nobody else's booking is listed");
    }

    public function test_my_bookings_can_be_filtered_by_booking_type_status_and_serial_number(): void
    {
        $car = $this->bookingOf(self::PLAIN_USER, 1);

        $byType = $this->actingAsPersonalNumber(self::PLAIN_USER)
            ->getJson('/api/bookings/my-bookings?booking_type_id[]=1')
            ->assertOk()->json('data');

        $this->assertNotEmpty($byType);
        $this->assertSame([1], array_values(array_unique(array_column(array_column($byType, 'booking_type'), 'id'))));

        // One value works as well as a list - it must not fail.
        $this->app['auth']->forgetGuards();
        $single = $this->actingAsPersonalNumber(self::PLAIN_USER)
            ->getJson('/api/bookings/my-bookings?booking_type_id=1')
            ->assertOk()->json('data');
        $this->assertSame(array_column($byType, 'uuid'), array_column($single, 'uuid'));

        $this->app['auth']->forgetGuards();
        $byStatus = $this->actingAsPersonalNumber(self::PLAIN_USER)
            ->getJson('/api/bookings/my-bookings?booking_type_id[]=1&status_id[]=' . $car->status_id)
            ->assertOk()->json('data');
        $this->assertContains($car->uuid, array_column($byStatus, 'uuid'));
        $this->assertSame([(int) $car->status_id], array_values(array_unique(array_column(array_column($byStatus, 'status'), 'id'))));

        $this->app['auth']->forgetGuards();
        $bySerial = $this->actingAsPersonalNumber(self::PLAIN_USER)
            ->getJson('/api/bookings/my-bookings?serial_number=' . $car->serial_number)
            ->assertOk()->json('data');
        $this->assertContains($car->uuid, array_column($bySerial, 'uuid'));
    }

    public function test_a_plain_user_opens_their_own_booking_with_its_formatted_values(): void
    {
        $booking = $this->bookingOf(self::PLAIN_USER);

        $this->actingAsPersonalNumber(self::PLAIN_USER)
            ->getJson('/api/bookings/' . $booking->uuid)
            ->assertOk()
            ->assertJsonPath('data.uuid', $booking->uuid)
            ->assertJsonPath('data.serial_number', $booking->serial_number)
            ->assertJsonPath('data.booking_type.id', 13)
            ->assertJsonPath('data.user.full_name', User::where('personal_number', self::PLAIN_USER)->value('full_name'))
            ->assertJsonStructure(['data' => ['uuid', 'serial_number', 'booking_type', 'status', 'user', 'created_at', 'booking_values', 'attachments']]);

        $this->app['auth']->forgetGuards();

        $values = $this->actingAsPersonalNumber(self::PLAIN_USER)->getJson('/api/bookings/' . $booking->uuid)->json('data.booking_values');
        $this->assertNotEmpty($values, 'the values were formatted');
    }

    public function test_a_plain_user_sees_the_attachments_of_their_own_booking(): void
    {
        Storage::fake('minio');
        Storage::disk('minio')->buildTemporaryUrlsUsing(fn (string $path) => 'https://minio.test/signed/' . $path);

        $booking = $this->bookingOf(self::PLAIN_USER);
        $photo = $this->uploadedAttachment($booking, 'photo.png');
        $form = $this->uploadedAttachment($booking, 'form.pdf');
        $waiting = $this->uploadedAttachment($booking, 'late.pdf', bytes: null);

        $attachments = collect(
            $this->actingAsPersonalNumber(self::PLAIN_USER)
                ->getJson('/api/bookings/' . $booking->uuid)
                ->assertOk()
                ->json('data.attachments')
        )->keyBy('name');

        $this->assertCount(3, $attachments);

        $photoRow = $attachments[$photo->name];
        $this->assertSame('image', $photoRow['kind']);
        $this->assertSame('image/png', $photoRow['mime']);
        $this->assertTrue($photoRow['inline']);
        $this->assertTrue($photoRow['available']);
        $this->assertSame('https://minio.test/signed/portal-uploads/' . $booking->uuid . '/photo.png', $photoRow['url']);

        $formRow = $attachments[$form->name];
        $this->assertSame('file', $formRow['kind']);
        $this->assertSame('application/pdf', $formRow['mime']);
        $this->assertTrue($formRow['inline']);
        $this->assertTrue($formRow['available']);
        $this->assertSame('https://minio.test/signed/portal-uploads/' . $booking->uuid . '/form.pdf', $formRow['url']);

        // Linked, but the bytes never reached the bucket: shown as not yet available, with no URL to a missing object.
        $waitingRow = $attachments[$waiting->name];
        $this->assertFalse($waitingRow['available']);
        $this->assertNull($waitingRow['mime']);
        $this->assertNull($waitingRow['url']);
    }

    public function test_a_booking_without_attachments_answers_with_an_empty_attachments_list(): void
    {
        $booking = $this->bookingOf(self::PLAIN_USER);

        $this->actingAsPersonalNumber(self::PLAIN_USER)
            ->getJson('/api/bookings/' . $booking->uuid)
            ->assertOk()
            ->assertJsonPath('data.attachments', []);
    }

    public function test_a_plain_user_cannot_open_somebody_elses_booking(): void
    {
        $someoneElses = $this->bookingOf(self::OTHER_PLAIN_USER);

        $this->actingAsPersonalNumber(self::PLAIN_USER)
            ->getJson('/api/bookings/' . $someoneElses->uuid)
            ->assertNotFound();
    }

    public function test_a_manager_opens_any_booking_of_their_unit_but_not_of_another_unit(): void
    {
        $inTheirUnit = $this->bookingOf(self::PLAIN_USER);

        $this->actingAsPersonalNumber(self::ADMIN)
            ->getJson('/api/bookings/' . $inTheirUnit->uuid)
            ->assertOk()
            ->assertJsonPath('data.uuid', $inTheirUnit->uuid);

        $elsewhere = Booking::create([
            'unit_id' => 2,
            'booking_type_id' => 13,
            'user_id' => $this->userId(self::PLAIN_USER),
            'status_id' => 1,
        ]);

        $this->app['auth']->forgetGuards();

        $this->actingAsPersonalNumber(self::ADMIN)
            ->getJson('/api/bookings/' . $elsewhere->uuid)
            ->assertNotFound();
    }

    public function test_a_manager_has_no_bookings_of_their_own_in_my_bookings(): void
    {
        $this->actingAsPersonalNumber(self::ADMIN)
            ->getJson('/api/bookings/my-bookings')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_the_path_must_be_a_uuid(): void
    {
        $this->actingAsPersonalNumber(self::PLAIN_USER)
            ->getJson('/api/bookings/not-a-uuid')
            ->assertNotFound();
    }

    public function test_a_booking_that_does_not_exist_is_not_found(): void
    {
        $this->actingAsPersonalNumber(self::PLAIN_USER)
            ->getJson('/api/bookings/6f1b8e0a-2c4d-4e7a-9a53-0d2f4b1c7e99')
            ->assertNotFound();
    }

    public function test_a_plain_user_still_has_the_basic_endpoints(): void
    {
        $this->actingAsPersonalNumber(self::PLAIN_USER)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.roles.0.display_name', RoleEnum::USER->getPermissionDisplayName());

        $this->app['auth']->forgetGuards();

        $this->actingAsPersonalNumber(self::PLAIN_USER)->postJson('/api/logout')->assertOk();
    }

    public function test_a_plain_user_cannot_change_a_booking_or_create_one(): void
    {
        $booking = $this->bookingOf(self::PLAIN_USER);

        // Changing a status and placing a booking belong to the mobile app; the web API has no route for them
        // (404: no such URL, 405: the URL exists for another method) and a refusal carries the one vague message.
        foreach ([
            ['putJson', '/api/bookings/change-status/' . $booking->uuid, ['status_id' => 6]],
            ['postJson', '/api/bookings/1', []],
            ['putJson', '/api/bookings/' . $booking->uuid, ['status_id' => 6]],
            ['deleteJson', '/api/bookings/' . $booking->uuid, []],
        ] as [$method, $uri, $body]) {
            $this->app['auth']->forgetGuards();

            $response = $this->actingAsPersonalNumber(self::PLAIN_USER)->{$method}($uri, $body);

            $this->assertContains($response->status(), [404, 405], "$method $uri");
            $response->assertJsonStructure(['message']);
        }

        $this->assertSame(
            $booking->status_id,
            $booking->fresh()->status_id,
            'the booking is untouched'
        );
    }

    public function test_a_person_with_no_role_reaches_their_bookings_once_they_have_logged_in(): void
    {
        // Before logging in there is no role: the bookings endpoints refuse.
        $this->actingAsPersonalNumber(self::NO_ROLE)->getJson('/api/bookings/my-bookings')->assertForbidden();

        $this->app['auth']->forgetGuards();

        $cookie = $this->postJson('/api/login', ['personal_number' => self::NO_ROLE])
            ->assertCreated()
            ->getCookie(self::TOKEN_NAME, false)
            ->getValue();

        $this->app['auth']->forgetGuards();

        $this->withCookieToken($cookie)
            ->getJson('/api/bookings/my-bookings')
            ->assertOk()
            ->assertJsonPath('data', []);
    }
}
