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
            default => null,
        };
    }
}
