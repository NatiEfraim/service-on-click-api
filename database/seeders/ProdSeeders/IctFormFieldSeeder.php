<?php

namespace Database\Seeders\ProdSeeders;

use App\Enums\BookingTypeEnum;
use App\Enums\FieldTypeEnum;
use App\Enums\UnitEnum;
use App\Models\FormField;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Form fields for the תקשוב installation, fault and user-management types (ids 20-25), as the product's sheet (מחשוב.xlsx)
 * describes them: hints, a help link on the infosec declaration, person-lookup autofill on פתיחת משתמש (locked fields and an
 * editable phone), and the printer IP that depends on "מדפסת מחוברת". Uses updateOrCreate so the seeder is safe to re-run.
 * Options are in IctOptionFieldSeeder; the software and printer-model lists are a temporary five each until the product
 * owner sends the real ones.
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

        $this->create($id, 'network', self::NETWORK_LABEL, FieldTypeEnum::SELECT, true, ['required' => 'יש לבחור רשת'], position: 10);
        $this->computerName($id, 20);
        // a temporary list of five (IctOptionFieldSeeder) until the product owner sends the real one
        $this->create($id, 'software_name', 'שם התוכנה', FieldTypeEnum::SELECT, true, ['required' => 'יש לבחור תוכנה'], position: 30);
    }

    private function seedPrinterInstallFields(): void
    {
        $id = BookingTypeEnum::PRINTER_INSTALL_BOOKING->getBookingTypeId();
        if (!$id) {
            return;
        }

        $this->create($id, 'network', self::NETWORK_LABEL, FieldTypeEnum::SELECT, true, ['required' => 'יש לבחור רשת'], position: 10);
        $this->computerName($id, 20);
        // a temporary list of five (IctOptionFieldSeeder) until the product owner sends the real one
        $this->create($id, 'printer_model', 'דגם המדפסת', FieldTypeEnum::SELECT, true, ['required' => 'יש לבחור דגם מדפסת'], position: 30);
        $this->create($id, 'install_type', 'סוג ההתקנה', FieldTypeEnum::SELECT, true, ['required' => 'יש לבחור סוג התקנה'], position: 40);
        // the sheet: "אם מדובר על מדפסת מחוברת שימלאו" - shown and required only for a connected printer
        $this->create($id, 'printer_ip', 'כתובת IP של המדפסת', FieldTypeEnum::TEXT, true, [
            'required' => 'יש להזין כתובת IP למדפסת מחוברת',
            'regex:/^(\d{1,3}\.){3}\d{1,3}$/' => 'יש להזין כתובת IP תקינה, לדוגמה 10.0.0.1',
        ], position: 50, dependsOn: ['field' => 'install_type', 'values' => ['מדפסת מחוברת']]);
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

        $this->computerName($id, 10);
        $this->create($id, 'fault_type', 'סוג התקלה', FieldTypeEnum::SELECT, true, ['required' => 'יש לבחור סוג תקלה'], position: 20);
        $this->create($id, 'details', 'פירוט התקלה', FieldTypeEnum::TEXT_AREA, true, [
            'required' => 'יש לפרט את התקלה',
            'string' => 'פורמט אינו תקין',
        ], position: 30);
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

        $this->create($id, 'new_username', 'שם משתמש חדש', FieldTypeEnum::TEXT, true, [
            'required' => 'יש להזין שם משתמש חדש',
            'string' => 'פורמט אינו תקין',
            'max:255' => 'ניתן להזין עד 255 תווים',
        ], position: 10, hint: 'יש להזין כך: ז"י/אט"ל/החטיבה הטכנולוגית');
    }

    private function seedUserOpenFields(): void
    {
        $id = BookingTypeEnum::USER_OPEN_BOOKING->getBookingTypeId();
        if (!$id) {
            return;
        }

        // text, not a number: an ID number may start with 0. It looks the person up and fills the fields below (מותיקן).
        $this->create($id, 'personal_id', 'ת.ז', FieldTypeEnum::TEXT, true, [
            'required' => 'יש להזין מספר תעודת זהות',
            'regex:/^\d{9}$/' => 'תעודת זהות חייבת להכיל 9 ספרות בדיוק',
        ], position: 10, lookupSource: 'person');
        // מותיקן, נעול (locked once the person is found)
        $this->text($id, 'personal_number', 'מ.א', 'יש להזין מספר אישי', 20, position: 20, autofillKey: 'personal_number');
        $this->text($id, 'first_name', 'שם פרטי', 'יש להזין שם פרטי', position: 30, autofillKey: 'first_name');
        $this->text($id, 'last_name', 'שם משפחה', 'יש להזין שם משפחה', position: 40, autofillKey: 'last_name');
        // מותיקן, נעול - no source holds the rank yet (PersonLookupService returns null), so it stays open until one does
        $this->text($id, 'rank', 'דרגה', 'יש להזין דרגה', position: 50, autofillKey: 'rank');
        // מותיקן, ניתן לעריכה
        $this->create($id, 'phone', 'טלפון נייד', FieldTypeEnum::TEL, true, [
            'required' => 'יש להזין מספר טלפון',
            self::PHONE_REGEX => 'מספר טלפון לא תקין',
        ], position: 60, autofillKey: 'phone', autofillLocked: false);
        // the sheet: a list of the departments (מחלקות החט"ל), later from the registry. Free text until the list is agreed - then
        // change FieldTypeEnum::TEXT to FieldTypeEnum::SELECT here and add the options in IctOptionFieldSeeder.
        $this->text($id, 'department', 'מחלקה', 'יש לבחור מחלקה', position: 70);
        $this->text($id, 'branch', 'ענף', 'יש להזין ענף', position: 80);
        $this->text($id, 'section', 'מדור', 'יש להזין מדור', position: 90);
        $this->text($id, 'role', 'תפקיד', 'יש להזין תפקיד', position: 100);
        $this->create($id, 'network', self::NETWORK_LABEL, FieldTypeEnum::SELECT, true, ['required' => 'יש לבחור רשת'], position: 110);
        $this->create($id, 'security_declaration', 'קראתי את הנחיות ביטחון המידע ואני מאשר/ת', FieldTypeEnum::CHECKBOX, true, [
            'accepted' => 'יש לאשר את הצהרת ביטחון המידע',
        ], position: 120, helpUrl: config('services.ict.infosec_guidelines_url'));
    }

    private function seedUserMoveFields(): void
    {
        $id = BookingTypeEnum::USER_MOVE_BOOKING->getBookingTypeId();
        if (!$id) {
            return;
        }

        $this->text($id, 'ou_upper', 'OU עליון', 'יש להזין OU עליון', position: 10);
        $this->text($id, 'ou_lower', 'OU תחתון', 'יש להזין OU תחתון', position: 20);
        $this->create($id, 'permissions_removal_ack', 'המשתמש מאשר - בעת העברת יחידה יסירו לו את ההרשאות הקיימות', FieldTypeEnum::CHECKBOX, true, [
            'accepted' => 'יש לאשר את הסרת ההרשאות',
        ], position: 30);
    }

    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    private function computerName(int $bookingTypeId, int $position): void
    {
        $this->text($bookingTypeId, 'computer_name', 'שם מחשב', 'יש להזין שם מחשב', position: $position);
    }

    /** A required free-text field with the usual messages. */
    private function text(int $bookingTypeId, string $fieldName, string $displayName, string $requiredMessage, int $max = 255, int $position = 0, ?string $autofillKey = null): void
    {
        $this->create($bookingTypeId, $fieldName, $displayName, FieldTypeEnum::TEXT, true, [
            'required' => $requiredMessage,
            'string' => 'פורמט אינו תקין',
            "max:{$max}" => "ניתן להזין עד {$max} תווים",
        ], position: $position, autofillKey: $autofillKey);
    }

    private function create(
        int $bookingTypeId,
        string $fieldName,
        string $displayName,
        FieldTypeEnum $fieldType,
        bool $required,
        array $validations = [],
        int $position = 0,
        ?string $hint = null,
        ?string $helpUrl = null,
        ?string $lookupSource = null,
        ?string $autofillKey = null,
        bool $autofillLocked = true,
        ?array $dependsOn = null,
    ): void {
        FormField::updateOrCreate(
            [
                'booking_type_id' => $bookingTypeId,
                'unit_id' => UnitEnum::H->getUnitId(),
                'field_name' => $fieldName,
            ],
            [
                'field_display_name' => $displayName,
                'field_type_id' => $fieldType->getFieldTypeId(),
                'is_required' => $required,
                // An array: the model casts the column (json_encode here would double-encode it into a JSON string).
                'field_validations' => empty($validations) ? null : $validations,
                'editable' => false,
                'is_input' => false,
                'position' => $position,
                'hint' => $hint,
                'help_url' => $helpUrl,
                'lookup_source' => $lookupSource,
                'autofill_key' => $autofillKey,
                'autofill_locked' => $autofillLocked,
                'depends_on' => $dependsOn,
            ]
        );
    }
}
