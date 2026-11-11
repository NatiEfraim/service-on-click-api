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
 * Form fields for the six new ביטחון מידע and תקשוב booking types (ids 14-19).
 * Uses updateOrCreate so the seeder is safe to re-run.
 */
class InfosecIctFormFieldSeeder extends Seeder
{
    public function run(): void
    {
        try {
            DB::beginTransaction();

            $this->seedClearanceGrantFields();
            $this->seedRemovableMediaFields();
            $this->seedSecurityIncidentFields();
            $this->seedFolderPermissionFields();
            $this->seedMantaPermissionFields();
            $this->seedNetworkFolderFields();

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('InfosecIctFormFieldSeeder failed: ' . $e->getMessage());
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

        $this->create($id, 'candidate_role', 'תפקיד', FieldTypeEnum::TEXT, true, [
            'required' => 'יש להזין תפקיד',
            'string' => 'פורמט אינו תקין',
            'max:255' => 'ניתן להזין עד 255 תווים',
        ]);
        $this->create($id, 'requested_level', 'רמת סיווג מבוקשת', FieldTypeEnum::SELECT, true);
        $this->create($id, 'reason', 'נימוק הבקשה', FieldTypeEnum::TEXT_AREA, true, [
            'required' => 'יש להזין נימוק',
            'string' => 'פורמט אינו תקין',
        ]);
        $this->create($id, 'phone', 'טלפון לתיאום', FieldTypeEnum::TEL, true, [
            'required' => 'יש לספק מספר טלפון',
            'regex:/^05[012458]\d{7}$/' => 'מספר טלפון נייד לא תקין (חייב להתחיל ב-05)',
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
    // תקשוב
    // ---------------------------------------------------------------------------

    private function seedFolderPermissionFields(): void
    {
        $id = BookingTypeEnum::FOLDER_PERMISSION_BOOKING->getBookingTypeId();
        if (!$id) {
            return;
        }

        $this->create($id, 'path', 'נתיב התיקייה', FieldTypeEnum::TEXT, true, [
            'required' => 'יש להזין נתיב התיקייה',
            'string' => 'פורמט אינו תקין',
            'max:500' => 'ניתן להזין עד 500 תווים',
        ]);
        $this->create($id, 'access', 'סוג ההרשאה', FieldTypeEnum::SELECT, true);
        $this->create($id, 'beneficiary', 'מספר אישי של מקבל ההרשאה', FieldTypeEnum::TEXT, true, [
            'required' => 'יש להזין מספר אישי',
            'regex:/^\d{5,9}$/' => 'מספר אישי חייב להכיל 5 עד 9 ספרות',
        ]);
        $this->create($id, 'justification', 'הצדקה', FieldTypeEnum::TEXT_AREA, true, [
            'required' => 'יש להזין הצדקה',
            'string' => 'פורמט אינו תקין',
        ]);
        $this->create($id, 'until', 'בתוקף עד', FieldTypeEnum::DATE, false, [
            'date' => 'פורמט תאריך אינו תקין',
        ]);
    }

    private function seedMantaPermissionFields(): void
    {
        $id = BookingTypeEnum::MANTA_PERMISSION_BOOKING->getBookingTypeId();
        if (!$id) {
            return;
        }

        $this->create($id, 'profile', 'פרופיל מבוקש', FieldTypeEnum::SELECT, true);
        $this->create($id, 'beneficiary', 'מספר אישי של מקבל ההרשאה', FieldTypeEnum::TEXT, true, [
            'required' => 'יש להזין מספר אישי',
            'regex:/^\d{5,9}$/' => 'מספר אישי חייב להכיל 5 עד 9 ספרות',
        ]);
        $this->create($id, 'justification', 'הצדקה', FieldTypeEnum::TEXT_AREA, true, [
            'required' => 'יש להזין הצדקה',
            'string' => 'פורמט אינו תקין',
        ]);
    }

    private function seedNetworkFolderFields(): void
    {
        $id = BookingTypeEnum::NETWORK_FOLDER_BOOKING->getBookingTypeId();
        if (!$id) {
            return;
        }

        $this->create($id, 'folder_name', 'שם התיקייה', FieldTypeEnum::TEXT, true, [
            'required' => 'יש להזין שם תיקייה',
            'string' => 'פורמט אינו תקין',
            'max:255' => 'ניתן להזין עד 255 תווים',
        ]);
        $this->create($id, 'location', 'מיקום (שרת או נתיב אב)', FieldTypeEnum::TEXT, true, [
            'required' => 'יש להזין מיקום',
            'string' => 'פורמט אינו תקין',
            'max:500' => 'ניתן להזין עד 500 תווים',
        ]);
        $this->create($id, 'owners', 'בעלי התיקייה (מספרים אישיים)', FieldTypeEnum::TEXT_AREA, true, [
            'required' => 'יש להזין מספרים אישיים של בעלי התיקייה',
            'string' => 'פורמט אינו תקין',
        ]);
        $this->create($id, 'size', 'נפח משוער (GB)', FieldTypeEnum::NUMBER, false, [
            'numeric' => 'יש להזין מספר תקין',
            'min:0' => 'נפח חייב להיות מספר חיובי',
        ]);
    }

    // ---------------------------------------------------------------------------
    // Helper
    // ---------------------------------------------------------------------------

    private function create(
        int $bookingTypeId,
        string $fieldName,
        string $displayName,
        FieldTypeEnum $fieldType,
        bool $required,
        array $validations = [],
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
                'field_validations' => empty($validations) ? null : json_encode($validations, JSON_UNESCAPED_UNICODE),
                'editable' => false,
                'is_input' => false,
            ]
        );
    }
}
