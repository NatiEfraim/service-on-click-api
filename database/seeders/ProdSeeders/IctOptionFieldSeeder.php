<?php

namespace Database\Seeders\ProdSeeders;

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
 * Dropdown options for the תקשוב installation, fault and user-management types (fields in IctFormFieldSeeder).
 * Uses updateOrCreate so the seeder is safe to re-run. The email fault list is the known part of the sheet (it ends with
 * "…"); the product owner is to confirm it.
 */
class IctOptionFieldSeeder extends Seeder
{
    private const NETWORKS = ['ARMY', 'ARMYTS', 'TNG'];

    public function run(): void
    {
        try {
            DB::beginTransaction();

            foreach ([
                BookingTypeEnum::SOFTWARE_INSTALL_BOOKING,
                BookingTypeEnum::PRINTER_INSTALL_BOOKING,
                BookingTypeEnum::USER_OPEN_BOOKING,
            ] as $type) {
                $this->seedOptions($type, 'network', self::NETWORKS);
            }

            $this->seedOptions(BookingTypeEnum::PRINTER_INSTALL_BOOKING, 'install_type', ['מדפסת מחוברת', 'מדפסת חדשה']);

            // temporary lists of five, until the product owner sends the real ones
            $this->seedOptions(BookingTypeEnum::SOFTWARE_INSTALL_BOOKING, 'software_name', [
                'Microsoft Office',
                'Adobe Acrobat Reader',
                'Google Chrome',
                '7-Zip',
                'Notepad++',
            ]);
            $this->seedOptions(BookingTypeEnum::PRINTER_INSTALL_BOOKING, 'printer_model', [
                'HP LaserJet Pro M404',
                'HP Color LaserJet M454',
                'Canon imageRUNNER 2425',
                'Xerox VersaLink C405',
                'Brother HL-L2350DW',
            ]);

            $this->seedOptions(BookingTypeEnum::EMAIL_FAULT_BOOKING, 'fault_type', [
                'מייל נתקע',
                'מייל מלא',
                'חיבור PST',
                'לו"ז מלא',
                'הרשאות',
                'מייל לא נפתח',
            ]);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('IctOptionFieldSeeder failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /** @param array<int, string> $options */
    private function seedOptions(BookingTypeEnum $type, string $fieldName, array $options): void
    {
        $bookingTypeId = $type->getBookingTypeId();
        if (!$bookingTypeId) {
            return;
        }

        $formField = FormField::where('booking_type_id', $bookingTypeId)
            ->where('unit_id', UnitEnum::H->getUnitId())
            ->where('field_name', $fieldName)
            ->first();

        if (!$formField) {
            Log::warning("IctOptionFieldSeeder: form field '{$fieldName}' not found for booking_type_id={$bookingTypeId}");
            return;
        }

        [$floor, $ceiling] = SystemDomainEnum::ICT->optionRange();

        foreach ($options as $name) {
            $option = OptionField::query()
                ->where('form_field_id', $formField->id)
                ->where('name', $name)
                ->first();

            // Every תקשוב option lives inside its own block - stop loudly on a row outside it.
            if ($option && ((int) $option->id < $floor || (int) $option->id > $ceiling)) {
                throw new \RuntimeException(
                    "IctOptionFieldSeeder: option '{$name}' sits at id {$option->id}, outside the תקשוב block {$floor}-{$ceiling} - "
                    . 'renumber first (docs/frontend-system-domains-guide.md).'
                );
            }

            if (!$option) {
                $option = new OptionField(['form_field_id' => $formField->id, 'name' => $name]);
                $option->id = OptionField::nextSeededIdFor(SystemDomainEnum::ICT);
            }

            // a plain option (no sub-category), as the admin screens create them
            $option->fill(['field_type_id' => FieldTypeEnum::TEXT->value, 'is_active' => true])->save();
        }
    }
}
