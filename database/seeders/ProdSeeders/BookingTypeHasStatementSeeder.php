<?php

namespace Database\Seeders\ProdSeeders;

use App\Enums\BookingTypeEnum;
use App\Enums\StatementShowTimeEnum;
use App\Enums\UnitEnum;
use App\Models\BookingTypeHasStatement;
use Illuminate\Database\Seeder;

class BookingTypeHasStatementSeeder extends Seeder
{
    /** Run the database seeds. */
    public function run(): void
    {
        // [booking type, statement, when it is shown] - an empty statement is seeded inactive.
        $bookingTypeStatements = [
            [BookingTypeEnum::CAR_BOOKING, $this->getCarBookingStatement(), StatementShowTimeEnum::ON_SUBMIT],
            [BookingTypeEnum::CAR_MAINTENANCE_BOOKING, $this->getCarMaintenanceBookingStatement(), StatementShowTimeEnum::ON_SUBMIT],
            [BookingTypeEnum::BARBER_BOOKING, $this->getBarberBookingStatement(), StatementShowTimeEnum::ON_SUBMIT],
            [BookingTypeEnum::EQUIPMENT_BOOKING_MALE, $this->getEquipmentBookingStatement(), StatementShowTimeEnum::ON_SUBMIT],
            [BookingTypeEnum::EQUIPMENT_BOOKING_FEMALE, $this->getEquipmentBookingStatement(), StatementShowTimeEnum::ON_SUBMIT],
        ];

        foreach ($bookingTypeStatements as [$bookingTypeEnum, $statement, $showTime]) {
            $bookingTypeId = $bookingTypeEnum->getBookingTypeId();

            if (is_null($bookingTypeId)) {
                continue;
            }

            BookingTypeHasStatement::updateOrCreate(
                [
                    'unit_id' => UnitEnum::H->value,
                    'booking_type_id' => $bookingTypeId,
                ],
                [
                    'is_active' => $statement !== '',
                    'statement' => $statement,
                    'show_time' => $showTime,
                ]
            );
        }
    }

    // ------------------------------ Private Functions -------------------------------------------- //
    /** Mirrors the drive purpose date rules in StoreBookingRequest::validateCarBookingDrivePurposeDateRange(). */
    private function getCarBookingStatement(): string
    {
        return <<<TEXT
        לתשומת ליבך, בעת בחירת "מטרת נסיעה":
        1. משימה - תאריך הסיום חייב להיות זהה לתאריך ההתחלה (נסיעה של יום אחד), ושעת הסיום מאוחרת משעת ההתחלה.
        2. נופש קבע - ניתן להזמין רק מיום ראשון עד יום רביעי, או מיום חמישי עד יום ראשון.
        TEXT;
    }
    private function getBarberBookingStatement(): string
    {
        // Use placeholders that will be interpolated when the statement is retrieved
        return "לידיעתך, יוקצה לך זמן תספורת של {haircut_time} דקות בלבד";
    }


    private function getCarMaintenanceBookingStatement(): string
    {
        return <<<TEXT
        לתשומת ליבך, בעת בחירת "מהות התקלה":
        1. טיפול יזום - יש להגיע עם הרכב למשרד הרכב עד השעה 08:15.
        2. תיקון תאונה - יש להגיע למשרד הרכב עד השעה 08:15 ולהצטייד במס' אסמכתא לתיקון.
        3. טסט - יש להגיע עם רישיון רכב בתוקף, פוליסת ביטוח בתוקף ואישור עבודה מטעם חברת ההשכרה.
        TEXT;
    }

    /** Shared by EQUIPMENT_BOOKING_MALE and EQUIPMENT_BOOKING_FEMALE. */
    private function getEquipmentBookingStatement(): string
    {
        return 'הזמנת ציוד ניתנת רק לאוכלוסיית חובה';
    }
}
