<?php

namespace Database\Seeders\ProdSeeders;

use App\Domain\Clearance\ClearanceLevel;
use App\Domain\Workflow\Routes\ClearanceGrantFields;
use App\Enums\BookingTypeEnum;
use App\Enums\FieldTypeEnum;
use App\Enums\SystemDomainEnum;
use App\Enums\UnitEnum;
use App\Models\FormField;
use App\Models\OptionField;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Dropdown options of the ביטחון מידע system, allocated inside its own id block
 * (SystemDomainEnum::INFOSEC->optionRange(), 10001-29999). Safe to re-run. An option may carry a stable `code`
 * (option_fields.code) the code compares instead of the Hebrew name, which the option editor may rename.
 */
class InfosecOptionFieldSeeder extends Seeder
{
    public function run(): void
    {
        try {
            DB::beginTransaction();

            $this->seedClearanceGrantOptions();
            $this->seedRemovableMediaOptions();

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('InfosecOptionFieldSeeder failed: ' . $e->getMessage());
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
            Log::warning("InfosecOptionFieldSeeder: form field '{$fieldName}' not found for booking_type_id={$bookingTypeId}");
            return;
        }

        [$floor, $ceiling] = SystemDomainEnum::INFOSEC->optionRange();

        foreach ($options as $key => $value) {
            [$name, $code] = is_string($key) ? [$key, $value] : [$value, null];

            $option = OptionField::query()
                ->where('form_field_id', $formField->id)
                ->where('name', $name)
                ->first();

            // Every ביטחון מידע option lives inside its own block - stop loudly on a row outside it.
            if ($option && ((int) $option->id < $floor || (int) $option->id > $ceiling)) {
                throw new \RuntimeException(
                    "InfosecOptionFieldSeeder: option '{$name}' sits at id {$option->id}, outside the ביטחון מידע block {$floor}-{$ceiling} - "
                    . 'renumber first (docs/frontend-system-domains-guide.md).'
                );
            }

            if (!$option) {
                $option = new OptionField([
                    'form_field_id' => $formField->id,
                    'name' => $name,
                ]);
                $option->id = OptionField::nextSeededIdFor(SystemDomainEnum::INFOSEC);
            }

            $option->fill([
                'field_type_id' => FieldTypeEnum::TEXT->getFieldTypeId(),
                'is_active' => true,
                'code' => $code,
            ])->save();
        }
    }
}
