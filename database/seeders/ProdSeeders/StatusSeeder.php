<?php

namespace Database\Seeders\ProdSeeders;

use App\Enums\StatusBookingEnum;
use App\Models\Status;
use Illuminate\Database\Seeder;

class StatusSeeder extends Seeder
{
    /** `position` is resolved from StatusBookingEnum::getPosition(), the single source of truth. */
    public function run(): void
    {
        $statusTypes = [
            'received' => 'ההזמנה נשלחה',
            'processing' => 'ההזמנה התקבלה והחל טיפול',
            'approved' => 'ההזמנה אושרה וניתנת למימוש',
            'rejected' => 'נדחה ע"י גורם טכני',
            'completed' => 'סיום טיפול',
            'canceled_by_user' => 'בוטל ע"י משתמש',
            'canceled_by_approver' => 'בוטל ע"י גורם טכני',
            //TRANSPORTATION_BOOKING
            'pending_file' => 'ממתין לקבצים',
            'pending_processing' =>'בתהליך אישור מול חברת ספק',
            'pending_approved' => 'ממתין למספר ספק',
            //BARBER_BOOKING
            'approved_by_barber' => 'טופל ע"י הספר',
            'canceled_by_barber' => 'בוטלה ע"י הספר',
            'canceled_by_changing_time' => 'בוטלה עקב שינויי זמנים במספרה',
            //TRANSPORTATION_BOOKING
            'processing_by_control_officer' => 'ההזמנה בטיפול רמ"ד תו"ב',
            // Vice approver's answer, sent on to the approver
            'vice_approver_approved' => 'הועברה לאישור סופי',
            'vice_approver_rejected' => 'הועברה לביטול סופי',
            // TRANSPORTATION_BOOKING: control officer's answer, sent on to the vice approver
            'control_officer_approved' => 'אושרה ע"י רמ"ד תו"ב',
            'control_officer_rejected' => 'נדחתה ע"י רמ"ד תו"ב',
        ];

        foreach ($statusTypes as $statusType => $statusTypeDisplayName) {
            Status::updateOrCreate(
                ['name' => $statusType],
                [
                    'display_name' => $statusTypeDisplayName,
                    'position' => StatusBookingEnum::fromStatusName($statusType)?->getPosition(),
                ]
            );
        }
    }
}
