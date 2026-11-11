<?php

namespace Database\Seeders\ProdSeeders;

use App\Enums\BookingTypeEnum;
use App\Enums\FieldTypeEnum;
use App\Enums\UnitEnum;
use App\Models\FormField;
use App\Models\OptionField;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Dropdown options for the ביטחון מידע and תקשוב booking types.
 * Uses updateOrCreate so the seeder is safe to re-run.
 */
class InfosecIctOptionFieldSeeder extends Seeder
{
    public function run(): void
    {
        try {
            DB::beginTransaction();

            $this->seedClearanceGrantOptions();
            $this->seedRemovableMediaOptions();
            $this->seedFolderPermissionOptions();
            $this->seedMantaPermissionOptions();

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('InfosecIctOptionFieldSeeder failed: ' . $e->getMessage());
            throw $e;
        }
    }

    private function seedClearanceGrantOptions(): void
    {
        $bookingTypeId = BookingTypeEnum::CLEARANCE_GRANT_BOOKING->getBookingTypeId();
        if (!$bookingTypeId) {
            return;
        }

        $this->seedOptions($bookingTypeId, 'requested_level', [
            'שמור',
            'סודי',
            'סודי ביותר',
        ]);
    }

    private function seedRemovableMediaOptions(): void
    {
        $bookingTypeId = BookingTypeEnum::REMOVABLE_MEDIA_BOOKING->getBookingTypeId();
        if (!$bookingTypeId) {
            return;
        }

        $this->seedOptions($bookingTypeId, 'media_type', [
            'דיסק און קי',
            'כונן חיצוני',
            'דיסק',
            'כרטיס זיכרון',
        ]);
    }

    private function seedFolderPermissionOptions(): void
    {
        $bookingTypeId = BookingTypeEnum::FOLDER_PERMISSION_BOOKING->getBookingTypeId();
        if (!$bookingTypeId) {
            return;
        }

        $this->seedOptions($bookingTypeId, 'access', [
            'קריאה',
            'קריאה וכתיבה',
            'שליטה מלאה',
        ]);
    }

    private function seedMantaPermissionOptions(): void
    {
        $bookingTypeId = BookingTypeEnum::MANTA_PERMISSION_BOOKING->getBookingTypeId();
        if (!$bookingTypeId) {
            return;
        }

        $this->seedOptions($bookingTypeId, 'profile', [
            'צפייה',
            'עריכה',
            'מנהל',
        ]);
    }

    // ---------------------------------------------------------------------------
    // Helper
    // ---------------------------------------------------------------------------

    private function seedOptions(int $bookingTypeId, string $fieldName, array $options): void
    {
        $formField = FormField::where('booking_type_id', $bookingTypeId)
            ->where('unit_id', UnitEnum::H->getUnitId())
            ->where('field_name', $fieldName)
            ->first();

        if (!$formField) {
            Log::warning("InfosecIctOptionFieldSeeder: form field '{$fieldName}' not found for booking_type_id={$bookingTypeId}");
            return;
        }

        foreach ($options as $name) {
            OptionField::updateOrCreate(
                [
                    'form_field_id' => $formField->id,
                    'name' => $name,
                ],
                [
                    'field_type_id' => FieldTypeEnum::TEXT->getFieldTypeId(),
                    'is_active' => true,
                ]
            );
        }
    }
}
