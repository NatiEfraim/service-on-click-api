<?php

namespace Database\Seeders\ProdSeeders;

use App\Enums\BookingTypeEnum;
use App\Enums\FieldTypeEnum;
use App\Enums\SystemDomainEnum;
use App\Enums\UnitEnum;
use App\Models\FormField;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Form fields for the תקשוב installation, fault and user-management types (ids 20-25), beside types 17-19.
 * Uses updateOrCreate so the seeder is safe to re-run. Options are in IctOptionFieldSeeder.
 *
 * Not yet: prefilled / locked fields (פתיחת משתמש) need new form_fields columns, agreed with the front end; the printer
 * IP is optional until a field can depend on an option. The software and printer-model lists are a temporary five
 * each (IctOptionFieldSeeder) until the product owner sends the real ones.
 */
class IctFormFieldSeeder extends Seeder
{
    private const NETWORK_LABEL = 'רשת';

    private const PHONE_REGEX = 'regex:/^(?:(?:0[23489]\d{7})|(?:05[012458]\d{7})|(?:051\d{7}))$/';

    public function run(): void
    {
        try {
            DB::beginTransaction();

            $this->seedSoftwareInstallFields();
            $this->seedPrinterInstallFields();
            $this->seedEmailFaultFields();
            $this->seedUserRenameFields();
            $this->seedUserOpenFields();
            $this->seedUserMoveFields();

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('IctFormFieldSeeder failed: ' . $e->getMessage());
            throw $e;
        }
    }

    // ---------------------------------------------------------------------------
    // התקנות
    // ---------------------------------------------------------------------------

    private function seedSoftwareInstallFields(): void
    {
        $id = BookingTypeEnum::SOFTWARE_INSTALL_BOOKING->getBookingTypeId();
        if (!$id) {
            return;
        }

        $this->create($id, 'network', self::NETWORK_LABEL, FieldTypeEnum::SELECT, true, ['required' => 'יש לבחור רשת']);
        $this->computerName($id);
        // a temporary list of five (IctOptionFieldSeeder) until the product owner sends the real one
        $this->create($id, 'software_name', 'שם התוכנה', FieldTypeEnum::SELECT, true, ['required' => 'יש לבחור תוכנה']);
    }

    private function seedPrinterInstallFields(): void
    {
        $id = BookingTypeEnum::PRINTER_INSTALL_BOOKING->getBookingTypeId();
        if (!$id) {
            return;
        }

        $this->create($id, 'network', self::NETWORK_LABEL, FieldTypeEnum::SELECT, true, ['required' => 'יש לבחור רשת']);
        $this->computerName($id);
        // a temporary list of five (IctOptionFieldSeeder) until the product owner sends the real one
        $this->create($id, 'printer_model', 'דגם המדפסת', FieldTypeEnum::SELECT, true, ['required' => 'יש לבחור דגם מדפסת']);
        $this->create($id, 'install_type', 'סוג ההתקנה', FieldTypeEnum::SELECT, true, ['required' => 'יש לבחור סוג התקנה']);
        // needed only for "מדפסת מחוברת": optional until a field can depend on an option
        $this->create($id, 'printer_ip', 'כתובת IP של המדפסת (למדפסת מחוברת)', FieldTypeEnum::TEXT, false, [
            'regex:/^(\d{1,3}\.){3}\d{1,3}$/' => 'יש להזין כתובת IP תקינה, לדוגמה 10.0.0.1',
        ]);
    }

    // ---------------------------------------------------------------------------
    // תקלות
    // ---------------------------------------------------------------------------

    private function seedEmailFaultFields(): void
    {
        $id = BookingTypeEnum::EMAIL_FAULT_BOOKING->getBookingTypeId();
        if (!$id) {
            return;
        }

        $this->computerName($id);
        $this->create($id, 'fault_type', 'סוג התקלה', FieldTypeEnum::SELECT, true, ['required' => 'יש לבחור סוג תקלה']);
        $this->create($id, 'details', 'פירוט התקלה', FieldTypeEnum::TEXT_AREA, true, [
            'required' => 'יש לפרט את התקלה',
            'string' => 'פורמט אינו תקין',
        ]);
    }

    // ---------------------------------------------------------------------------
    // ניהול משתמשים
    // ---------------------------------------------------------------------------

    private function seedUserRenameFields(): void
    {
        $id = BookingTypeEnum::USER_RENAME_BOOKING->getBookingTypeId();
        if (!$id) {
            return;
        }

        $this->create($id, 'new_username', 'שם משתמש חדש (לדוגמה: ז"י/אט"ל/החטיבה הטכנולוגית)', FieldTypeEnum::TEXT, true, [
            'required' => 'יש להזין שם משתמש חדש',
            'string' => 'פורמט אינו תקין',
            'max:255' => 'ניתן להזין עד 255 תווים',
        ]);
    }

    private function seedUserOpenFields(): void
    {
        $id = BookingTypeEnum::USER_OPEN_BOOKING->getBookingTypeId();
        if (!$id) {
            return;
        }

        // text, not a number: an ID number may start with 0
        $this->create($id, 'personal_id', 'ת.ז', FieldTypeEnum::TEXT, true, [
            'required' => 'יש להזין מספר תעודת זהות',
            'regex:/^\d{9}$/' => 'תעודת זהות חייבת להכיל 9 ספרות בדיוק',
        ]);
        $this->text($id, 'personal_number', 'מ.א', 'יש להזין מספר אישי', 20);
        $this->text($id, 'first_name', 'שם פרטי', 'יש להזין שם פרטי');
        $this->text($id, 'last_name', 'שם משפחה', 'יש להזין שם משפחה');
        $this->text($id, 'rank', 'דרגה', 'יש להזין דרגה');
        $this->create($id, 'phone', 'טלפון נייד', FieldTypeEnum::TEL, true, [
            'required' => 'יש להזין מספר טלפון',
            self::PHONE_REGEX => 'מספר טלפון לא תקין',
        ]);
        // free text until the departments list is agreed (then SELECT + options)
        $this->text($id, 'department', 'מחלקה', 'יש להזין מחלקה');
        $this->text($id, 'branch', 'ענף', 'יש להזין ענף');
        $this->text($id, 'section', 'מדור', 'יש להזין מדור');
        $this->text($id, 'role', 'תפקיד', 'יש להזין תפקיד');
        $this->create($id, 'network', self::NETWORK_LABEL, FieldTypeEnum::SELECT, true, ['required' => 'יש לבחור רשת']);
        $this->create($id, 'security_declaration', 'קראתי את הנחיות ביטחון המידע ואני מאשר/ת', FieldTypeEnum::CHECKBOX, true, [
            'accepted' => 'יש לאשר את הצהרת ביטחון המידע',
        ]);
    }

    private function seedUserMoveFields(): void
    {
        $id = BookingTypeEnum::USER_MOVE_BOOKING->getBookingTypeId();
        if (!$id) {
            return;
        }

        $this->text($id, 'ou_upper', 'OU עליון', 'יש להזין OU עליון');
        $this->text($id, 'ou_lower', 'OU תחתון', 'יש להזין OU תחתון');
        $this->create($id, 'permissions_removal_ack', 'המשתמש מאשר - בעת העברת יחידה יסירו לו את ההרשאות הקיימות', FieldTypeEnum::CHECKBOX, true, [
            'accepted' => 'יש לאשר את הסרת ההרשאות',
        ]);
    }

    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    private function computerName(int $bookingTypeId): void
    {
        $this->text($bookingTypeId, 'computer_name', 'שם מחשב', 'יש להזין שם מחשב');
    }

    /** A required free-text field with the usual messages. */
    private function text(int $bookingTypeId, string $fieldName, string $displayName, string $requiredMessage, int $max = 255): void
    {
        $this->create($bookingTypeId, $fieldName, $displayName, FieldTypeEnum::TEXT, true, [
            'required' => $requiredMessage,
            'string' => 'פורמט אינו תקין',
            "max:{$max}" => "ניתן להזין עד {$max} תווים",
        ]);
    }

    private function create(
        int $bookingTypeId,
        string $fieldName,
        string $displayName,
        FieldTypeEnum $fieldType,
        bool $required,
        array $validations = [],
    ): void {
        $formField = FormField::query()
            ->where('booking_type_id', $bookingTypeId)
            ->where('unit_id', UnitEnum::H->getUnitId())
            ->where('field_name', $fieldName)
            ->first();

        // Every תקשוב field lives inside its own block (SystemDomainEnum::ICT->fieldRange()); a row outside it means
        // an un-renumbered database or a cross-system mixup - stop loudly instead of corrupting another id space.
        [$floor, $ceiling] = SystemDomainEnum::ICT->fieldRange();

        if ($formField && ((int) $formField->id < $floor || (int) $formField->id > $ceiling)) {
            throw new \RuntimeException(
                "IctFormFieldSeeder: field '{$fieldName}' sits at id {$formField->id}, outside the תקשוב block {$floor}-{$ceiling} - "
                . 'renumber first (docs/frontend-system-domains-guide.md).'
            );
        }

        if (!$formField) {
            $formField = new FormField([
                'booking_type_id' => $bookingTypeId,
                'unit_id' => UnitEnum::H->getUnitId(),
                'field_name' => $fieldName,
            ]);
            $formField->id = FormField::nextSeededIdFor(SystemDomainEnum::ICT);
        }

        $formField->fill([
            'field_display_name' => $displayName,
            'field_type_id' => $fieldType->getFieldTypeId(),
            'is_required' => $required,
            // An array: the model casts the column (json_encode here would double-encode it into a JSON string).
            'field_validations' => empty($validations) ? null : $validations,
            'editable' => false,
            'is_input' => false,
        ])->save();
    }
}
