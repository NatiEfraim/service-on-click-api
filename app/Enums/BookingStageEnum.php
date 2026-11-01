<?php

namespace App\Enums;

/** Who or what a booking is waiting for (see Workflow::stageOf()). */
enum BookingStageEnum: string
{
    case CONTROL_OFFICER = 'control_officer';
    case VICE_APPROVER = 'vice_approver';
    case APPROVER = 'approver';
    case SUPPLIER_FILES = 'supplier_files';
    case SUPPLIER_APPROVAL = 'supplier_approval';
    case SUPPLIER_NUMBER = 'supplier_number';
    case REALIZATION = 'realization';
    case BARBER_DONE = 'barber_done';
    case CLOSED = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::CONTROL_OFFICER => 'ממתין לרמ"ד תו"ב',
            self::VICE_APPROVER => 'ממתין לגורם מטפל',
            self::APPROVER => 'ממתין להחלטת מפקד תחום',
            self::SUPPLIER_FILES => 'ממתין לקבצים מהלקוח',
            self::SUPPLIER_APPROVAL => 'ממתין לאישור ספק',
            self::SUPPLIER_NUMBER => 'ממתין למספר ספק',
            self::REALIZATION => 'אושרה - ממתינה למימוש וסגירה',
            self::BARBER_DONE => 'טופלה ע"י הספר - ממתינה לסגירה',
            self::CLOSED => 'סגורה',
        };
    }

    /** Kind of wait: approval | customer_info | supplier | realization (null when closed). */
    public function waitKind(): ?string
    {
        return match ($this) {
            self::CONTROL_OFFICER, self::VICE_APPROVER, self::APPROVER => 'approval',
            self::SUPPLIER_FILES => 'customer_info',
            self::SUPPLIER_APPROVAL, self::SUPPLIER_NUMBER => 'supplier',
            self::REALIZATION, self::BARBER_DONE => 'realization',
            self::CLOSED => null,
        };
    }

    /** The role whose turn it is; null when no single role owns the stage. */
    public function actingRole(): ?RoleEnum
    {
        return match ($this) {
            self::CONTROL_OFFICER => RoleEnum::TRANSPORTATION_CONTROL_OFFICER,
            self::VICE_APPROVER => RoleEnum::VICE_APPROVER,
            self::APPROVER, self::REALIZATION, self::BARBER_DONE => RoleEnum::APPROVER,
            default => null,
        };
    }
}
