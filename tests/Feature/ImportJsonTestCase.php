<?php

namespace Tests\Feature;

use App\Models\Booking;
use Illuminate\Support\Facades\Storage;

/**
 * The production structure (form fields, options, booking types ... with the ids the mobile app exports) and a bucket that is a folder in memory:
 * no test of the import ever reaches the real bucket. The development bookings of ManagementTestCase are removed so every count is exact.
 *
 * Form fields used by the fixtures (booking type: ids): 3 equipment 33 > 35 > 40 (options 84 > 93), 43, 34 > 49; 5 barber 83 date, 84 time;
 * 10 out-unit food 18 > 31 (options 13-16); 11 in-unit food 6 name, 8 amount, 9 date, 10 time, 7 select (option 3).
 */
abstract class ImportJsonTestCase extends ManagementTestCase
{
    protected const BOOKING_DIRECTORY = 'import-json/booking-data';
    protected const LOG_DIRECTORY = 'import-json/application-log';
    protected const LOADED_DIRECTORY = 'loaded-json';

    protected const BOOKINGS_FILE = 'SrvOnClick_b2r_export_bookings_2026-10-29.json';
    protected const LOGS_FILE = 'SrvOnClick_b2r_application_logs_2026-10-29.json';

    /** 2026-10-29 07:06:11 UTC - when the fixtures were "made" in the mobile app. */
    protected const CREATED_AT = 1793257571;

    /** ID numbers of the people in the fixtures (none of them is a seeded user). */
    protected const OWNER = '900000001';
    protected const OTHER_OWNER = '900000002';
    protected const ACTOR = '900000003';

    protected function setUp(): void
    {
        parent::setUp();

        // What the tests below count would be blurred by the synthetic bookings of the parent class.
        Booking::query()->delete();

        Storage::fake('minio');

        config([
            'filesystems.storage_service' => 'minio',
            'app.prod_stage' => true,
            'app.import_json.enabled' => true,
            // Warnings about skipped rows are expected here; they do not belong in the developer's log file.
            'logging.default' => 'null',
        ]);
    }

    //----------------------------- Fixtures ------------------------//

    /** One node of "booking_values" as the export writes it. */
    protected function node(int $fieldId, ?int $parentFieldId, int $fieldTypeId, mixed $value = null, mixed $options = null): array
    {
        return [
            'field_id' => $fieldId,
            'form_field_id' => $parentFieldId,
            'field_display_name' => 'field ' . $fieldId,
            'field_type_id' => $fieldTypeId,
            'value' => $value,
            'option_fields' => $options,
        ];
    }

    /** One entry of a node's "option_fields". */
    protected function option(int $optionId, int $formFieldId, ?int $parentOptionId = null, mixed $value = null): array
    {
        return [
            'form_field_id' => $formFieldId,
            'option_id' => $optionId,
            'display_name' => 'option ' . $optionId,
            'field_type_id' => 2,
            'parent_option_id' => $parentOptionId,
            'value' => $value,
        ];
    }

    /** An in-unit food booking (type 11) with plain values and one selected option. */
    protected function foodValues(): array
    {
        return [
            $this->node(6, null, 2, 'ישיבת עבודה'),
            $this->node(9, null, 3, '2026-11-09'),
            $this->node(10, null, 4, '11:41'),
            $this->node(8, null, 1, '50'),
            $this->node(7, null, 5, null, $this->option(3, 7)),
        ];
    }

    /** The full record of a booking, as the export writes a booking it has not exported before. */
    protected function bookingRecord(int $serialNumber, int $bookingTypeId, array $values, array $overrides = []): array
    {
        return array_merge([
            'booking_type_id' => $bookingTypeId,
            'booking_owner_personal_id' => self::OWNER,
            'serial_number' => $serialNumber,
            'status_id' => 1,
            'created_at' => (string) self::CREATED_AT,
            'updated_at' => (string) self::CREATED_AT,
            'booking_values' => $values,
            'comments' => null,
            'files' => null,
            'images' => null,
        ], $overrides);
    }

    /** A booking of the in-unit food type, enough to be saved. */
    protected function foodBooking(int $serialNumber, array $overrides = []): array
    {
        return $this->bookingRecord($serialNumber, 11, $this->foodValues(), $overrides);
    }

    /** A barber booking (type 5). */
    protected function barberBooking(int $serialNumber, string $date, string $time, array $overrides = []): array
    {
        return $this->bookingRecord($serialNumber, 5, [
            $this->node(83, null, 3, $date),
            $this->node(84, null, 4, $time),
        ], $overrides);
    }

    /** The record of a booking the export sent before: no type, no owner, only the news. */
    protected function changeRecord(int $serialNumber, int $statusId, int $updatedAt, ?array $values = null, ?array $comments = null): array
    {
        return [
            'booking_type_id' => null,
            'booking_owner_personal_id' => null,
            'serial_number' => $serialNumber,
            'status_id' => $statusId,
            'created_at' => (string) self::CREATED_AT,
            'updated_at' => (string) $updatedAt,
            'booking_values' => $values,
            'comments' => $comments,
            'files' => null,
            'images' => null,
        ];
    }

    protected function logRecord(int $serialNumber, int $actionTypeId, string $personalId, int $createdAt = self::CREATED_AT, int $statusId = 1): array
    {
        return [
            'serial_number' => $serialNumber,
            'status_id' => $statusId,
            'action_type_id' => $actionTypeId,
            'personal_id' => $personalId,
            'created_at' => (string) $createdAt,
        ];
    }

    //----------------------------- The bucket ------------------------//

    protected function putBookingFile(array $bookings, string $name = self::BOOKINGS_FILE): void
    {
        Storage::disk('minio')->put(self::BOOKING_DIRECTORY . '/' . $name, json_encode(['bookings' => $bookings], JSON_UNESCAPED_UNICODE));
    }

    protected function putLogFile(array $logs, string $name = self::LOGS_FILE): void
    {
        Storage::disk('minio')->put(self::LOG_DIRECTORY . '/' . $name, json_encode(['application_logs' => $logs], JSON_UNESCAPED_UNICODE));
    }
}
