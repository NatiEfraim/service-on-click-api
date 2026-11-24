<?php

namespace Database\Seeders\ProdSeeders;

use App\Domain\Workflow\Routes\ClearanceGrantFields;
use App\Enums\BookingTypeEnum;
use App\Enums\FieldTypeEnum;
use App\Enums\FormSectionEnum;
use App\Enums\SystemDomainEnum;
use App\Enums\UnitEnum;
use App\Models\FormField;
use App\Models\OptionField;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Form fields of the ביטחון מידע system (types 14-16), allocated inside its own id block
 * (SystemDomainEnum::INFOSEC->fieldRange(), 1001-2999) so neither the mobile app nor the תקשוב system is ever touched
 * when a field is added here. Uses find-or-create keyed by field_name, safe to re-run.
 *
 * The clearance grant (14) is the legacy screen's form: a requester section (פרטים נוספים) with the candidate's details - the ID number
 * looks the person up and fills personal number, names and rank - and a handler section (למילוי על ידי גורמים רלוונטיים) that the
 * coordinators fill during the route (place, date and time of the interview). Field names the workflow addresses are in ClearanceGrantFields.
 */
class InfosecFormFieldSeeder extends Seeder
{
    private const MOBILE_PHONE = 'regex:/^05[012458]\d{7}$/';

    public function run(): void
    {
        try {
            DB::beginTransaction();

            $this->seedClearanceGrantFields();
            $this->seedRemovableMediaFields();
            $this->seedSecurityIncidentFields();

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('InfosecFormFieldSeeder failed: ' . $e->getMessage());
            throw $e;
        }
    }

    // ---------------------------------------------------------------------------
    // ביטחון מידע
    // ---------------------------------------------------------------------------

    private function seedClearanceGrantFields(): void
    {
        $id = BookingTypeEnum::CLEARANCE_GRANT_BOOKING->getBookingTypeId();
        if (!$id) {
            return;
        }

        $text = fn (string $required): array => ['required' => $required, 'string' => 'פורמט אינו תקין', 'max:255' => 'ניתן להזין עד 255 תווים'];

        // --- Requester section: פרטים נוספים (positions 10..160) ---
        $this->create($id, ClearanceGrantFields::PERSONAL_ID, 'תעודת זהות', FieldTypeEnum::TEXT, true, [
            'required' => 'יש להזין מספר תעודת זהות',
            'regex:/^\d{9}$/' => 'תעודת זהות חייבת להכיל 9 ספרות בדיוק',
        ], position: 10, lookupSource: 'person');
        $this->create($id, ClearanceGrantFields::PERSONAL_NUMBER, 'מספר אישי', FieldTypeEnum::TEXT, true, [
            'required' => 'יש להזין מספר אישי',
            'regex:/^\d{5,9}$/' => 'מספר אישי חייב להכיל 5 עד 9 ספרות',
        ], position: 20, autofillKey: 'personal_number');
        $this->create($id, ClearanceGrantFields::FIRST_NAME, 'שם פרטי', FieldTypeEnum::TEXT, true, $text('יש להזין שם פרטי'), position: 30, autofillKey: 'first_name');
        $this->create($id, ClearanceGrantFields::LAST_NAME, 'שם משפחה', FieldTypeEnum::TEXT, true, $text('יש להזין שם משפחה'), position: 40, autofillKey: 'last_name');
        $this->create($id, 'phone', 'מספר טלפון', FieldTypeEnum::TEL, true, [
            'required' => 'יש לספק מספר טלפון',
            self::MOBILE_PHONE => 'מספר טלפון נייד לא תקין (חייב להתחיל ב-05)',
        ], position: 50);
        $this->create($id, 'department', 'מחלקה', FieldTypeEnum::TEXT, true, $text('יש להזין מחלקה'), position: 60);
        $this->create($id, 'branch', 'ענף', FieldTypeEnum::TEXT, false, ['string' => 'פורמט אינו תקין', 'max:255' => 'ניתן להזין עד 255 תווים'], position: 70);
        $this->create($id, ClearanceGrantFields::RANK, 'דרגה', FieldTypeEnum::TEXT, true, $text('יש להזין דרגה'), position: 80, autofillKey: 'rank');
        $this->create($id, 'service_type', 'סוג שירות', FieldTypeEnum::SELECT, true, ['required' => 'יש לבחור סוג שירות'], position: 90);
        $this->create($id, 'commander_name', 'שם מפקד', FieldTypeEnum::TEXT, true, $text('יש להזין שם מפקד'), position: 100);
        $this->create($id, 'commander_phone', 'נייד מפקד', FieldTypeEnum::TEL, true, [
            'required' => 'יש לספק נייד מפקד',
            self::MOBILE_PHONE => 'מספר טלפון נייד לא תקין (חייב להתחיל ב-05)',
        ], position: 110);
        $this->create($id, ClearanceGrantFields::REQUESTED_LEVEL, 'רמת סיווג', FieldTypeEnum::SELECT, true, ['required' => 'יש לבחור רמת סיווג'], position: 120);
        $this->create($id, 'preferred_interview_location', 'מיקום מועדף לתחקיר', FieldTypeEnum::SELECT, false, [], position: 130, colSpan: 2);
        $this->create($id, 'candidate_role', 'תפקיד נדרש', FieldTypeEnum::TEXT_AREA, true, [
            'required' => 'יש להזין תפקיד',
            'string' => 'פורמט אינו תקין',
            'max:1000' => 'ניתן להזין עד 1000 תווים',
        ], position: 140, colSpan: 2);
        $this->create($id, 'role_characteristics', 'מאפייני תפקיד', FieldTypeEnum::TEXT_AREA, true, [
            'required' => 'יש להזין מאפייני תפקיד',
            'string' => 'פורמט אינו תקין',
            'max:1000' => 'ניתן להזין עד 1000 תווים',
        ], position: 150, colSpan: 2);
        $this->create($id, 'notes', 'הערות', FieldTypeEnum::TEXT_AREA, false, [
            'string' => 'פורמט אינו תקין',
            'max:2000' => 'ניתן להזין עד 2000 תווים',
        ], position: 160, colSpan: 4);

        // --- Handler section: למילוי על ידי גורמים רלוונטיים (positions 200..220). "Required" = by the scheduling move, never on submission. ---
        $this->create($id, ClearanceGrantFields::INTERVIEW_LOCATION, 'מקום התחקיר', FieldTypeEnum::TEXT, true, [
            'required' => 'יש להזין את מקום התחקיר',
            'string' => 'פורמט אינו תקין',
            'max:255' => 'ניתן להזין עד 255 תווים',
        ], position: 200, colSpan: 2, section: FormSectionEnum::HANDLER);
        $this->create($id, ClearanceGrantFields::INTERVIEW_DATE, 'מועד התחקיר - תאריך', FieldTypeEnum::DATE, true, [
            'required' => 'יש לבחור תאריך לתחקיר',
            'date' => 'פורמט תאריך אינו תקין',
        ], position: 210, section: FormSectionEnum::HANDLER);
        $this->create($id, ClearanceGrantFields::INTERVIEW_TIME, 'מועד התחקיר - שעה', FieldTypeEnum::TIME, true, [
            'required' => 'יש לבחור שעה לתחקיר',
            'date_format:H:i' => 'שעה אינה תקינה (HH:MM)',
        ], position: 220, section: FormSectionEnum::HANDLER);

        // The form before this route had other fields (reason...): on a database that is not rebuilt they leave the form - archived when a
        // request holds a value for them, deleted otherwise.
        $this->retireOtherFields($id, [
            ClearanceGrantFields::PERSONAL_ID, ClearanceGrantFields::PERSONAL_NUMBER, ClearanceGrantFields::FIRST_NAME, ClearanceGrantFields::LAST_NAME,
            'phone', 'department', 'branch', ClearanceGrantFields::RANK, 'service_type', 'commander_name', 'commander_phone',
            ClearanceGrantFields::REQUESTED_LEVEL, 'preferred_interview_location', 'candidate_role', 'role_characteristics', 'notes',
            ClearanceGrantFields::INTERVIEW_LOCATION, ClearanceGrantFields::INTERVIEW_DATE, ClearanceGrantFields::INTERVIEW_TIME,
        ]);
    }

    private function seedRemovableMediaFields(): void
    {
        $id = BookingTypeEnum::REMOVABLE_MEDIA_BOOKING->getBookingTypeId();
        if (!$id) {
            return;
        }

        $this->create($id, 'media_type', 'סוג המדיה', FieldTypeEnum::SELECT, true);
        $this->create($id, 'serial', 'מספר סידורי של ההתקן', FieldTypeEnum::TEXT, true, [
            'required' => 'יש להזין מספר סידורי',
            'string' => 'פורמט אינו תקין',
            'max:100' => 'ניתן להזין עד 100 תווים',
        ]);
        $this->create($id, 'purpose', 'מטרת השימוש', FieldTypeEnum::TEXT_AREA, true, [
            'required' => 'יש להזין מטרת שימוש',
            'string' => 'פורמט אינו תקין',
        ]);
        $this->create($id, 'until', 'נדרש עד תאריך', FieldTypeEnum::DATE, false, [
            'date' => 'פורמט תאריך אינו תקין',
        ]);
    }

    private function seedSecurityIncidentFields(): void
    {
        $id = BookingTypeEnum::SECURITY_INCIDENT_BOOKING->getBookingTypeId();
        if (!$id) {
            return;
        }

        $this->create($id, 'incident_date', 'תאריך האירוע', FieldTypeEnum::DATE, true, [
            'required' => 'יש לבחור תאריך האירוע',
            'date' => 'פורמט תאריך אינו תקין',
        ]);
        $this->create($id, 'description', 'תיאור האירוע', FieldTypeEnum::TEXT_AREA, true, [
            'required' => 'יש להזין תיאור האירוע',
            'string' => 'פורמט אינו תקין',
        ]);
        $this->create($id, 'location', 'מיקום', FieldTypeEnum::TEXT, false, [
            'string' => 'פורמט אינו תקין',
            'max:255' => 'ניתן להזין עד 255 תווים',
        ]);
    }

    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    /**
     * @param array<string, string> $validations Laravel rule => Hebrew message, stored as an array (the model casts the column; never json_encode here)
     */
    private function create(
        int $bookingTypeId,
        string $fieldName,
        string $displayName,
        FieldTypeEnum $fieldType,
        bool $required,
        array $validations = [],
        int $position = 0,
        int $colSpan = 1,
        FormSectionEnum $section = FormSectionEnum::REQUESTER,
        ?string $lookupSource = null,
        ?string $autofillKey = null,
    ): void {
        $formField = FormField::query()
            ->where('booking_type_id', $bookingTypeId)
            ->where('unit_id', UnitEnum::H->getUnitId())
            ->where('field_name', $fieldName)
            ->first();

        // Every ביטחון מידע field lives inside its own block; a row outside it means an un-renumbered database or a
        // cross-system mixup - stop loudly instead of corrupting another system's id space.
        [$floor, $ceiling] = SystemDomainEnum::INFOSEC->fieldRange();

        if ($formField && ((int) $formField->id < $floor || (int) $formField->id > $ceiling)) {
            throw new \RuntimeException(
                "InfosecFormFieldSeeder: field '{$fieldName}' sits at id {$formField->id}, outside the ביטחון מידע block {$floor}-{$ceiling} - "
                . 'renumber first (docs/frontend-system-domains-guide.md).'
            );
        }

        if (!$formField) {
            $formField = new FormField([
                'booking_type_id' => $bookingTypeId,
                'unit_id' => UnitEnum::H->getUnitId(),
                'field_name' => $fieldName,
            ]);
            $formField->id = FormField::nextSeededIdFor(SystemDomainEnum::INFOSEC);
        }

        $formField->fill([
            'field_display_name' => $displayName,
            'field_type_id' => $fieldType->getFieldTypeId(),
            'is_required' => $required,
            'field_validations' => $validations === [] ? null : $validations,
            // Requester fields are not corrected after submission; handler fields are written during the route.
            'editable' => $section === FormSectionEnum::HANDLER,
            'is_input' => false,
            'is_active' => true,
            'position' => $position,
            'col_span' => $colSpan,
            'section' => $section->value,
            'lookup_source' => $lookupSource,
            'autofill_key' => $autofillKey,
        ])->save();
    }

    /** Fields of the type/unit that are not in the list any more: archived when a request holds a value for them, deleted otherwise. @param array<int, string> $keep */
    private function retireOtherFields(int $bookingTypeId, array $keep): void
    {
        $others = FormField::query()
            ->where('booking_type_id', $bookingTypeId)
            ->where('unit_id', UnitEnum::H->getUnitId())
            ->whereNotIn('field_name', $keep)
            ->get();

        foreach ($others as $field) {
            if ($field->bookingValue()->exists()) {
                $field->forceFill(['is_active' => false])->save();
            } else {
                OptionField::query()->where('form_field_id', $field->id)->delete();
                $field->delete();
            }
        }
    }
}
