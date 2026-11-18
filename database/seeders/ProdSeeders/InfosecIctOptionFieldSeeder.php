<?php

namespace Database\Seeders\ProdSeeders;

use App\Domain\Clearance\ClearanceLevel;
use App\Domain\Workflow\Routes\ClearanceGrantFields;
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
 * Uses updateOrCreate so the seeder is safe to re-run. An option may carry a stable `code` (option_fields.code) the code
 * compares instead of the Hebrew name, which the option editor may rename.
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

        // The codes rank the levels (ClearanceLevel) for the registry check.
        $this->seedOptions($bookingTypeId, ClearanceGrantFields::REQUESTED_LEVEL, [
            'שמור' => ClearanceLevel::RESTRICTED,
            'סודי' => ClearanceLevel::SECRET,
            'סודי ביותר' => ClearanceLevel::TOP_SECRET,
        ]);

        // Placeholders until the product owner sends the real lists; editable in the option editor without code.
        $this->seedOptions($bookingTypeId, 'service_type', [
            'חובה' => 'mandatory',
            'קבע' => 'career',
            'מילואים' => 'reserve',
            'אע"צ' => 'civilian',
        ]);
        $this->seedOptions($bookingTypeId, 'preferred_interview_location', [
            'צפון' => 'north',
            'מרכז' => 'center',
            'דרום' => 'south',
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

    /** @param array<int|string, string> $options a list of names, or name => code */
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

        foreach ($options as $key => $value) {
            [$name, $code] = is_string($key) ? [$key, $value] : [$value, null];

            OptionField::updateOrCreate(
                [
                    'form_field_id' => $formField->id,
                    'name' => $name,
                ],
                [
                    'field_type_id' => FieldTypeEnum::TEXT->getFieldTypeId(),
                    'is_active' => true,
                    'code' => $code,
                ]
            );
        }
    }
}
