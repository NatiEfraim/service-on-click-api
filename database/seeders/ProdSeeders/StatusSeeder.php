<?php

namespace Database\Seeders\ProdSeeders;

use App\Enums\StatusBookingEnum;
use App\Models\Status;
use Illuminate\Database\Seeder;

class StatusSeeder extends Seeder
{
    /**
     * Ids are pinned to StatusBookingEnum (1-18 are the mobile app's; 101+ belong to the web-only routes), `position` comes from
     * StatusBookingEnum::getPosition(), the single source of truth.
     */
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
            // Control-officer types (transportation, in-unit food): control officer's answer, sent on to the vice approver
            'control_officer_approved' => 'אושרה ע"י רמ"ד תו"ב',
            'control_officer_rejected' => 'נדחתה ע"י רמ"ד תו"ב',
            // Types 3, 4, 8, 10, 11, 13: parked until an outside party answers; closed only by the approver/admin
            'waiting_for_external_party' => 'ממתין לגורם חיצוני',
            // CLEARANCE_GRANT_BOOKING (type 14), web only: its own route, see App\Domain\Workflow\Routes\ClearanceGrantRoute
            'interview_assignment' => 'הקצאה לתחקיר',
            'interview_scheduled' => 'נקבע מול המועמד',
            'interviewed' => 'תוחקר',
            'awaiting_clearance' => 'ממתין לקבלת הכשר',
            'clearance_granted' => 'קיבל הכשר ביטחוני',
            // ICT service desk route (types 20-25), web only: see App\Domain\Workflow\Routes\IctServiceDeskRoute
            'in_service_desk' => 'בטיפול המוקד',
        ];

        foreach ($statusTypes as $statusType => $statusTypeDisplayName) {
            $status = StatusBookingEnum::fromStatusName($statusType);

            // Pinned by id, so a route status lands at its enum value and never at the next auto-increment.
            Status::unguarded(fn () => Status::updateOrCreate(
                ['id' => $status->value],
                [
                    'name' => $statusType,
                    'display_name' => $statusTypeDisplayName,
                    'position' => $status->getPosition(),
                ]
            ));
        }
    }
}
