<?php

namespace App\Enums;

/** Who or what a booking is waiting for (see Workflow::stageOf()). */
enum BookingStageEnum: string
{
    case CONTROL_OFFICER = 'control_officer';
    case VICE_APPROVER = 'vice_approver';
    case APPROVER = 'approver';
    // BARBER_BOOKING: the only step of its route - the barber answers.
    case BARBER = 'barber';
    case SUPPLIER_FILES = 'supplier_files';
    case SUPPLIER_APPROVAL = 'supplier_approval';
    case SUPPLIER_NUMBER = 'supplier_number';
    case REALIZATION = 'realization';
    case CLOSED = 'closed';
    // The clearance grant route (type 14): see App\Domain\Workflow\Routes\ClearanceGrantRoute.
    case INTERVIEW_ASSIGNMENT = 'interview_assignment';
    case INTERVIEW_SCHEDULED = 'interview_scheduled';
    case INTERVIEWED = 'interviewed';
    case AWAITING_CLEARANCE = 'awaiting_clearance';
    // The ICT service desk route (types 20-25): see App\Domain\Workflow\Routes\IctServiceDeskRoute.
    case SERVICE_DESK_QUEUE = 'service_desk_queue';
    case SERVICE_DESK_HANDLING = 'service_desk_handling';

    public function label(): string
    {
        return match ($this) {
            self::CONTROL_OFFICER => 'ממתין לרמ"ד תו"ב',
            self::VICE_APPROVER => 'ממתין לגורם מטפל',
            self::APPROVER => 'ממתין להחלטת מפקד תחום',
            self::BARBER => 'ממתין לספר',
            self::SUPPLIER_FILES => 'ממתין לקבצים מהלקוח',
            self::SUPPLIER_APPROVAL => 'ממתין לאישור ספק',
            self::SUPPLIER_NUMBER => 'ממתין למספר ספק',
            self::REALIZATION => 'אושרה - ממתינה למימוש וסגירה',
            self::CLOSED => 'סגורה',
            self::INTERVIEW_ASSIGNMENT => 'ממתין להקצאה ולתיאום תחקיר',
            self::INTERVIEW_SCHEDULED => 'תחקיר נקבע - ממתין למועד',
            self::INTERVIEWED => 'תוחקר - ממתין להעברה להכשר',
            self::AWAITING_CLEARANCE => 'ממתין לקבלת הכשר',
            self::SERVICE_DESK_QUEUE => 'ממתין לנציג מוקד',
            self::SERVICE_DESK_HANDLING => 'בטיפול נציג מוקד',
        };
    }

    /** Kind of wait: approval | customer_info | supplier | realization (null when closed). */
    public function waitKind(): ?string
    {
        return match ($this) {
            self::CONTROL_OFFICER, self::VICE_APPROVER, self::APPROVER, self::BARBER => 'approval',
            self::SUPPLIER_FILES => 'customer_info',
            self::SUPPLIER_APPROVAL, self::SUPPLIER_NUMBER => 'supplier',
            self::REALIZATION => 'realization',
            self::CLOSED => null,
            self::INTERVIEW_ASSIGNMENT, self::INTERVIEWED => 'approval',
            self::INTERVIEW_SCHEDULED, self::AWAITING_CLEARANCE => 'realization',
            self::SERVICE_DESK_QUEUE => 'approval',
            self::SERVICE_DESK_HANDLING => 'realization',
        };
    }

    /** The role whose turn it is; null when no single role owns the stage. */
    public function actingRole(): ?RoleEnum
    {
        return match ($this) {
            self::CONTROL_OFFICER => RoleEnum::TRANSPORTATION_CONTROL_OFFICER,
            self::VICE_APPROVER => RoleEnum::VICE_APPROVER,
            // The barber is the approver of the barber type (approver_5).
            self::APPROVER, self::REALIZATION, self::BARBER => RoleEnum::APPROVER,
            self::INTERVIEW_ASSIGNMENT, self::INTERVIEWED => RoleEnum::INTERVIEW_COORDINATOR,
            self::AWAITING_CLEARANCE => RoleEnum::INFOSEC_OFFICER,
            // The service desk representative (נציג מוקד) is the VICE_APPROVER of the ICT types.
            self::SERVICE_DESK_QUEUE, self::SERVICE_DESK_HANDLING => RoleEnum::VICE_APPROVER,
            // The interview is scheduled: the system moves the request on when its moment has passed.
            default => null,
        };
    }
}
