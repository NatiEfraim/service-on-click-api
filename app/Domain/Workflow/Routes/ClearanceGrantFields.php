<?php

namespace App\Domain\Workflow\Routes;

/**
 * The field_names of the clearance grant form (type 14) the workflow addresses. They are the seeder's names
 * (InfosecFormFieldSeeder); FormFieldEnum is the mobile app's and does not know them.
 */
final class ClearanceGrantFields
{
    // Requester section (פרטים נוספים).
    public const PERSONAL_ID = 'personal_id';
    public const PERSONAL_NUMBER = 'personal_number';
    public const FIRST_NAME = 'first_name';
    public const LAST_NAME = 'last_name';
    public const RANK = 'rank';
    public const REQUESTED_LEVEL = 'requested_level';

    // Handler section (למילוי על ידי גורמים רלוונטיים).
    public const INTERVIEW_LOCATION = 'interview_location';
    public const INTERVIEW_DATE = 'interview_date';
    public const INTERVIEW_TIME = 'interview_time';

    /** @return array<int, string> the three fields the scheduling step fills and a move back clears */
    public static function interviewFields(): array
    {
        return [self::INTERVIEW_LOCATION, self::INTERVIEW_DATE, self::INTERVIEW_TIME];
    }
}
