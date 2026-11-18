<?php

namespace App\Enums;

use App\Domain\Workflow\Workflow;
use App\Models\Status;

enum StatusBookingEnum: int
{
    case RECEIVED  = 1;
    case PROCESSING  = 2;
    case APPROVED  = 3;
    case REJECTED  = 4;
    case COMPLETED  = 5;
    case CANCELED_BY_USER  = 6;
    case CANCELED_BY_APPROVER  = 7;
    //TRANSPORTATION_BOOKING
    case PENDING_FILE  = 8;
    case PENDING_PROCESSING  = 9;
    case PENDING_APPROVED  = 10;
    //BARBER_BOOKING
    case APPROVED_BY_BARBER  = 11;
    case CANCELED_BY_BARBER  = 12;
    case CANCELED_BY_CHANGING_TIME  = 13;
    //TRANSPORTATION_BOOKING
    case PROCESSING_BY_CONTROL_OFFICER  = 14;
    // The vice approver's decision, sent on to the approver for the final answer.
    case VICE_APPROVER_APPROVED  = 15;
    case VICE_APPROVER_REJECTED  = 16;
    // TRANSPORTATION_BOOKING: the control officer's decision, sent on to the vice approver.
    case CONTROL_OFFICER_APPROVED  = 17;
    case CONTROL_OFFICER_REJECTED  = 18;
    // The clearance grant route (type 14, web only - from 101 so a future mobile status cannot collide).
    case INTERVIEW_ASSIGNMENT = 101;
    case INTERVIEW_SCHEDULED = 102;
    case INTERVIEWED = 103;
    case AWAITING_CLEARANCE = 104;
    case CLEARANCE_GRANTED = 105;

    /** Resolves the enum from `statuses.name`. */
    public static function fromStatusName(string $name): ?self
    {
        return match ($name) {
            'received' => self::RECEIVED,
            'processing' => self::PROCESSING,
            'approved' => self::APPROVED,
            'rejected' => self::REJECTED,
            'completed' => self::COMPLETED,
            'canceled_by_user' => self::CANCELED_BY_USER,
            'canceled_by_approver' => self::CANCELED_BY_APPROVER,
            'pending_file' => self::PENDING_FILE,
            'pending_processing' => self::PENDING_PROCESSING,
            'pending_approved' => self::PENDING_APPROVED,
            'approved_by_barber' => self::APPROVED_BY_BARBER,
            'canceled_by_barber' => self::CANCELED_BY_BARBER,
            'canceled_by_changing_time' => self::CANCELED_BY_CHANGING_TIME,
            'processing_by_control_officer' => self::PROCESSING_BY_CONTROL_OFFICER,
            'vice_approver_approved' => self::VICE_APPROVER_APPROVED,
            'vice_approver_rejected' => self::VICE_APPROVER_REJECTED,
            'control_officer_approved' => self::CONTROL_OFFICER_APPROVED,
            'control_officer_rejected' => self::CONTROL_OFFICER_REJECTED,
            'interview_assignment' => self::INTERVIEW_ASSIGNMENT,
            'interview_scheduled' => self::INTERVIEW_SCHEDULED,
            'interviewed' => self::INTERVIEWED,
            'awaiting_clearance' => self::AWAITING_CLEARANCE,
            'clearance_granted' => self::CLEARANCE_GRANTED,
            default => null,
        };
    }

    /** `statuses.name` of the status (the inverse of fromStatusName). */
    public function getStatusName(): string
    {
        return match ($this) {
            self::RECEIVED => 'received',
            self::PROCESSING => 'processing',
            self::APPROVED => 'approved',
            self::REJECTED => 'rejected',
            self::COMPLETED => 'completed',
            self::CANCELED_BY_USER => 'canceled_by_user',
            self::CANCELED_BY_APPROVER => 'canceled_by_approver',
            self::PENDING_FILE => 'pending_file',
            self::PENDING_PROCESSING => 'pending_processing',
            self::PENDING_APPROVED => 'pending_approved',
            self::APPROVED_BY_BARBER => 'approved_by_barber',
            self::CANCELED_BY_BARBER => 'canceled_by_barber',
            self::CANCELED_BY_CHANGING_TIME => 'canceled_by_changing_time',
            self::PROCESSING_BY_CONTROL_OFFICER => 'processing_by_control_officer',
            self::VICE_APPROVER_APPROVED => 'vice_approver_approved',
            self::VICE_APPROVER_REJECTED => 'vice_approver_rejected',
            self::CONTROL_OFFICER_APPROVED => 'control_officer_approved',
            self::CONTROL_OFFICER_REJECTED => 'control_officer_rejected',
            self::INTERVIEW_ASSIGNMENT => 'interview_assignment',
            self::INTERVIEW_SCHEDULED => 'interview_scheduled',
            self::INTERVIEWED => 'interviewed',
            self::AWAITING_CLEARANCE => 'awaiting_clearance',
            self::CLEARANCE_GRANTED => 'clearance_granted',
        };
    }

    /** A status of a route type (web only): the mobile app never sees it. */
    public function isRouteStatus(): bool
    {
        return $this->value >= self::INTERVIEW_ASSIGNMENT->value;
    }

    /** Display order on the status dashboards. */
    public function getPosition(): int
    {
        return match ($this) {
            self::RECEIVED => 1,
            self::PROCESSING_BY_CONTROL_OFFICER => 2,
            self::CONTROL_OFFICER_APPROVED => 3,
            self::CONTROL_OFFICER_REJECTED => 4,
            self::PROCESSING => 5,
            self::VICE_APPROVER_APPROVED => 6,
            self::VICE_APPROVER_REJECTED => 7,
            self::APPROVED => 8,
            self::COMPLETED => 9,
            self::REJECTED => 10,
            self::CANCELED_BY_USER => 11,
            self::CANCELED_BY_APPROVER => 12,
            self::PENDING_FILE => 13,
            self::PENDING_PROCESSING => 14,
            self::PENDING_APPROVED => 15,
            self::APPROVED_BY_BARBER => 16,
            self::CANCELED_BY_BARBER => 17,
            self::CANCELED_BY_CHANGING_TIME => 18,
            self::INTERVIEW_ASSIGNMENT => 19,
            self::INTERVIEW_SCHEDULED => 20,
            self::INTERVIEWED => 21,
            self::AWAITING_CLEARANCE => 22,
            self::CLEARANCE_GRANTED => 23,
        };
    }

    /** Waiting for the vice approver (control officer answered; incl. legacy PROCESSING_BY_CONTROL_OFFICER). */
    public function isWaitingForViceApprover(): bool
    {
        return in_array($this, [self::PROCESSING_BY_CONTROL_OFFICER, self::CONTROL_OFFICER_APPROVED, self::CONTROL_OFFICER_REJECTED], true);
    }

    /** Waiting for the approver's final decision (vice approver answered; incl. legacy PROCESSING). */
    public function isWaitingForApprover(): bool
    {
        return in_array($this, [self::PROCESSING, self::VICE_APPROVER_APPROVED, self::VICE_APPROVER_REJECTED], true);
    }

    /** TRANSPORTATION_BOOKING only: on hold on the supplier side (files, approval or number). */
    public function isPendingForSupplier(): bool
    {
        return in_array($this, [self::PENDING_FILE, self::PENDING_PROCESSING, self::PENDING_APPROVED], true);
    }

    /** @return array<int, int> Ids of the holds (see isPendingForSupplier). */
    public static function holdIds(): array
    {
        return array_values(array_map(
            fn (self $status): int => $status->value,
            array_filter(self::cases(), fn (self $status): bool => $status->isPendingForSupplier())
        ));
    }

    /**
     * A status a booking can be put on hold from, and so be parked at: it waits for the control officer, the vice approver or the approver.
     * Not a hold itself, not approved, not closed.
     */
    public function canBeParked(): bool
    {
        return $this === self::RECEIVED || $this->isWaitingForViceApprover() || $this->isWaitingForApprover();
    }

    /** Whether the owner can still cancel in this status (a barber booking too once the barber approved it: the appointment is still ahead). */
    public function canBeCanceledByUser(): bool
    {
        return $this === self::RECEIVED
            || $this === self::APPROVED
            || $this === self::APPROVED_BY_BARBER
            || $this->isWaitingForViceApprover()
            || $this->isWaitingForApprover();
    }

    /** Closed: rejected, completed or canceled. */
    public function isTerminal(): bool
    {
        return !$this->canBeCanceledByRestrictionDates();
    }

    /** @return array<int, int> Ids of the closed statuses. */
    public static function terminalIds(): array
    {
        return array_values(array_map(
            fn (self $status): int => $status->value,
            array_filter(self::cases(), fn (self $status): bool => $status->isTerminal())
        ));
    }

    /** The action logged in application_logs when a booking moves into this status. */
    public function getActionType(): ActionTypeEnum
    {
        return match ($this) {
            self::RECEIVED => ActionTypeEnum::RETURN_TO_RECEIVED,
            self::PROCESSING, self::VICE_APPROVER_APPROVED, self::VICE_APPROVER_REJECTED => ActionTypeEnum::VICE_APPROVER_PROCESSING_BOOKING,
            self::PROCESSING_BY_CONTROL_OFFICER, self::CONTROL_OFFICER_APPROVED, self::CONTROL_OFFICER_REJECTED => ActionTypeEnum::CONTROL_OFFICER_PROCESSING_BOOKING,
            self::APPROVED => ActionTypeEnum::APPROVE_BOOKING,
            self::REJECTED => ActionTypeEnum::REJECT_BOOKING,
            self::COMPLETED => ActionTypeEnum::COMPLETE_BOOKING,
            self::CANCELED_BY_USER => ActionTypeEnum::CANCEL_BOOKING_BY_USER,
            self::CANCELED_BY_APPROVER, self::CANCELED_BY_BARBER, self::CANCELED_BY_CHANGING_TIME => ActionTypeEnum::CANCEL_BOOKING_BY_APPROVER,
            self::APPROVED_BY_BARBER => ActionTypeEnum::APPROVE_BOOKING_BY_BARBER,
            self::PENDING_FILE, self::PENDING_PROCESSING, self::PENDING_APPROVED => ActionTypeEnum::MOVE_TO_PENDING_BOOKING,
            // A route request arrives at its first status by creation (store_booking, logged by the creator) or by a move back.
            self::INTERVIEW_ASSIGNMENT => ActionTypeEnum::RETURN_STAGE,
            self::INTERVIEW_SCHEDULED, self::INTERVIEWED, self::AWAITING_CLEARANCE, self::CLEARANCE_GRANTED => ActionTypeEnum::ADVANCE_STAGE,
        };
    }

    /**
     * A barber booking in this status no longer holds its slot: canceled by the requester (6), rejected or canceled by the barber (12) or by a change
     * of the barber's schedule (13), or closed by the old route (5, 7).
     * APPROVED_BY_BARBER (11) keeps it: the barber approved an appointment that is still ahead. REJECTED (4) is deliberately absent: the mobile app
     * keeps that slot.
     */
    public function freesBarberSlot(): bool
    {
        return in_array($this, [
            self::COMPLETED,
            self::CANCELED_BY_USER,
            self::CANCELED_BY_APPROVER,
            self::CANCELED_BY_BARBER,
            self::CANCELED_BY_CHANGING_TIME,
        ], true);
    }

    public static function resolveRoleFlags(iterable $permissionNames, int $bookingTypeId): array
    {
        return Workflow::roleFlags($permissionNames, $bookingTypeId);
    }

    /**
     * Statuses the user may move this booking to. $this is the status that decides whose turn it is; $hold is the hold the booking is on, if any
     * (see Workflow::availableActions).
     */
    public function getNextStatuses(array $roleFlags, ?int $bookingTypeId = null, ?self $hold = null): array
    {
        $allowed = $this->getAllowedTransitions($roleFlags, $bookingTypeId, $hold);

        if (empty($allowed)) {
            return [];
        }

        $statusRecords = Status::query()
            ->whereIn('id', array_map(fn(array $transition) => $transition['status']->value, $allowed))
            ->get(['id', 'name', 'display_name'])
            ->keyBy('id');

        return array_map(function (array $transition) use ($statusRecords): array {
            $targetStatus = $transition['status'];
            $statusRecord = $statusRecords->get($targetStatus->value);

            return [
                'id' => $targetStatus->value,
                'name' => $statusRecord?->name,
                'display_name' => $statusRecord?->display_name,
                'label' => $transition['label'],
            ];
        }, $allowed);
    }

    public function canChangeTo(self $targetStatus, array $roleFlags, ?int $bookingTypeId = null, ?self $hold = null): bool
    {
        foreach ($this->getAllowedTransitions($roleFlags, $bookingTypeId, $hold) as $transition) {
            if ($transition['status'] === $targetStatus) {
                return true;
            }
        }

        return false;
    }

    /** Transitions the roles allow from this status (see Workflow). */
    private function getAllowedTransitions(array $roleFlags, ?int $bookingTypeId = null, ?self $hold = null): array
    {
        return app(Workflow::class)->allowedTransitions($this, $roleFlags, (int) $bookingTypeId, $hold);
    }

    public function getDashboardDisplayName(): string
    {
        return match ($this) {
            self::RECEIVED => 'הזמנות שהתקבלו',
            self::PROCESSING => 'טופלו על ידי גורם מטפל',
            self::APPROVED => 'אושרו על ידי מפקד תחום',
            self::REJECTED => 'נדחו ע"י מפקד תחום',
            self::COMPLETED => 'הזמנות שהסתיימו',
            self::CANCELED_BY_USER => 'בוטלו ע"י משתמש',
            self::CANCELED_BY_APPROVER => 'בוטלו ע"י מפקד תחום',
            self::PENDING_FILE => 'ממתין לקבצים מהלקוח',
            self::PENDING_PROCESSING => 'ממתין לאישור ספק',
            self::PENDING_APPROVED => 'ממתין למספר ספק',
            self::APPROVED_BY_BARBER => 'טופל ע"י הספר',
            self::CANCELED_BY_BARBER => 'בוטלה ע"י הספר',
            self::CANCELED_BY_CHANGING_TIME => 'בוטלה עכב שינויים בזמני המספרה',
            self::PROCESSING_BY_CONTROL_OFFICER => 'טופלו רמ"ד תו"ב',
            self::VICE_APPROVER_APPROVED => 'הועברו לאישור סופי',
            self::VICE_APPROVER_REJECTED => 'הועברו לביטול סופי',
            self::CONTROL_OFFICER_APPROVED => 'אושרו ע"י רמ"ד תו"ב',
            self::CONTROL_OFFICER_REJECTED => 'נדחו ע"י רמ"ד תו"ב',
            self::INTERVIEW_ASSIGNMENT => 'הקצאה לתחקיר',
            self::INTERVIEW_SCHEDULED => 'נקבע מול המועמד',
            self::INTERVIEWED => 'תוחקר',
            self::AWAITING_CLEARANCE => 'ממתין לקבלת הכשר',
            self::CLEARANCE_GRANTED => 'קיבל הכשר ביטחוני',
        };
    }


    public function getStatusIconDashBoard(): string
    {
        return match ($this) {
            self::RECEIVED => '1',//'הזמנות חדשות',
            self::PROCESSING => '2',//'הזמנות בטיפול'
            self::APPROVED => '3',//'הזמנות שאושרו'
            self::REJECTED => '4',//'נדחו ע"י גורם טכני'
            self::COMPLETED => '5',//'הזמנות שהסתיימו'
            self::CANCELED_BY_USER => '6',//'בוטלו ע"י משתמש'
            self::CANCELED_BY_APPROVER => '7',//'בוטלו ע"י גורם טכני',
            self::PENDING_FILE => '8',//'ממתינים לקבצים נוספים מהלקוח'
            self::PENDING_PROCESSING => '9',//'ממתין לאישור מהספק'
            self::PENDING_APPROVED => '10',// 'ממתינים לאישור סופי מהספק (כלומר למספר ספק)'
            self::APPROVED_BY_BARBER => '11',// 'בקשה שנסגרה ע"י הספר'
            self::CANCELED_BY_BARBER => '12',// 'בקשה שבוטלה ע"י הספר'
            self::CANCELED_BY_CHANGING_TIME => '13',// 'בקשה שנסגרה ע"י שינויים בזמנים'
            self::PROCESSING_BY_CONTROL_OFFICER => '14',// 'בטיפול רמ"ד תו"ב'
            self::VICE_APPROVER_APPROVED => '15',// 'הועברו לאישור סופי'
            self::VICE_APPROVER_REJECTED => '16',// 'הועברו לביטול סופי'
            self::CONTROL_OFFICER_APPROVED => '17',// 'אושרו ע"י רמ"ד תו"ב'
            self::CONTROL_OFFICER_REJECTED => '18',// 'נדחו ע"י רמ"ד תו"ב'
            // The clearance route reuses the icons of received / in handling / passed on / approved / completed.
            self::INTERVIEW_ASSIGNMENT => '1',
            self::INTERVIEW_SCHEDULED => '2',
            self::INTERVIEWED => '15',
            self::AWAITING_CLEARANCE => '3',
            self::CLEARANCE_GRANTED => '5',
        };
    }

    public function getStatusMessage(): string|null
    {
        return match ($this) {
            self::INTERVIEW_ASSIGNMENT => 'הבקשה התקבלה וממתינה להקצאה לתחקיר',
            self::INTERVIEW_SCHEDULED => 'נקבע מועד לתחקיר מול המועמד',
            self::INTERVIEWED => 'התחקיר בוצע',
            self::AWAITING_CLEARANCE => 'ממתין לקבלת הכשר ביטחוני',
            self::CLEARANCE_GRANTED => 'הסיווג הביטחוני נקלט והבקשה נסגרה',
            self::RECEIVED => 'התקבלה בהצלחה',
            self::PROCESSING,
            self::VICE_APPROVER_APPROVED,
            self::VICE_APPROVER_REJECTED => 'הועברה לטיפול',
            self::APPROVED => 'אושרה וממתינה ללקוח',
            self::REJECTED => 'נדחתה ע"י גורם טכני',
            self::COMPLETED => 'הסתיימה בהצלחה',
            self::CANCELED_BY_USER => 'בוטלה ע"י המשתמש',
            self::CANCELED_BY_APPROVER => 'בוטלה ע"י גורם מאשר',
            self::PENDING_FILE => 'ממתינה להעלת קבצים נוספים במערכת',
            self::PENDING_PROCESSING => 'ממתינה לאישור מחברת ספק',
            self::PENDING_APPROVED => 'ממתינה למספר ספק',
            self::APPROVED_BY_BARBER => 'נסגרה ע"י הספר',
            self::CANCELED_BY_BARBER => 'בוטלה ע"י הספר',
            self::CANCELED_BY_CHANGING_TIME => 'בוטלה עכב שינויי זמנים במספרה',
            self::PROCESSING_BY_CONTROL_OFFICER,
            self::CONTROL_OFFICER_APPROVED,
            self::CONTROL_OFFICER_REJECTED => 'ההזמנה בטיפול רמ"ד תו"ב',
            default => null,
        };
    }

    /** Status message used in the SMS log sent to S3. */
    public function getStatusMessageToLogSMS(): string|null
    {
        return match ($this) {
            self::RECEIVED => 'הזמנה בוצעה',
            self::PROCESSING,
            self::VICE_APPROVER_APPROVED,
            self::VICE_APPROVER_REJECTED => 'בקשה ממתינה לגורם מאשר',
            self::APPROVED => 'בקשה אושרה ע"י גורם מאשר',
            self::REJECTED => 'בקשה נדחתה ע"י גורם מאשר',
            self::COMPLETED => 'בקשה הסתיימה בהצלחה',
            self::CANCELED_BY_USER => 'בקשה בוטלה ע"י משתמש',
            self::CANCELED_BY_APPROVER => 'בקשה בוטלה ע"י גורם טכני',
            self::PENDING_FILE => 'בקשה ממתינה לקבצים נוספים',
            self::PENDING_PROCESSING => 'בקשה ממתינה לאישור ספק',
            self::PENDING_APPROVED => 'בקשה ממתינה למספר ספק',
            self::APPROVED_BY_BARBER => 'נסגרה ע"י הספר',
            self::CANCELED_BY_BARBER => 'בוטלה ע"י הספר',
            self::CANCELED_BY_CHANGING_TIME => 'בוטלה עכב שינויי זמנים במספרה',
            self::PROCESSING_BY_CONTROL_OFFICER,
            self::CONTROL_OFFICER_APPROVED,
            self::CONTROL_OFFICER_REJECTED => 'בקשה בטיפול רמ"ד תו"ב',
            default => null,
        };
    }

    /** Subtitle shown to approvers in the bookings list. */
    public function getSubTitleStatusMessage(): ?string
    {
        return match ($this) {
            self::INTERVIEW_ASSIGNMENT => 'הבקשה ממתינה להקצאת מטפל ולתיאום תחקיר',
            self::INTERVIEW_SCHEDULED => 'נקבע מועד לתחקיר',
            self::INTERVIEWED => 'התחקיר בוצע וממתין להעברה להכשר',
            self::AWAITING_CLEARANCE => 'הבקשה ממתינה לקבלת הכשר ביטחוני',
            self::CLEARANCE_GRANTED => 'הסיווג נקלט והבקשה נסגרה',
            self::PROCESSING,
            self::VICE_APPROVER_APPROVED => 'הבקשה ממתינה לאישור סופי',
            self::VICE_APPROVER_REJECTED => 'בקשה ממתינה לביטול סופי',
            self::APPROVED => 'בקשה אושרה ע"י גורם מאשר',
            self::REJECTED => 'בקשה נדחתה ע"י גורם מאשר',
            self::COMPLETED => 'בקשה הסתיימה בהצלחה',
            self::CANCELED_BY_USER => 'בקשה בוטלה ע"י משתמש',
            self::CANCELED_BY_APPROVER => 'בקשה בוטלה ע"י גורם טכני',
            self::PENDING_FILE => 'בקשה ממתינה לקבצים נוספים',
            self::PENDING_PROCESSING => 'בקשה ממתינה לאישור ספק',
            self::PENDING_APPROVED => 'בקשה ממתינה למספר ספק',
            self::APPROVED_BY_BARBER => 'נסגרה ע"י הספר',
            self::CANCELED_BY_BARBER => 'בוטלה ע"י הספר',
            self::CANCELED_BY_CHANGING_TIME => 'בוטלה עכב שינויי זמנים במספרה',
            self::PROCESSING_BY_CONTROL_OFFICER => 'בקשה בטיפול רמ"ד תו"ב',
            self::CONTROL_OFFICER_APPROVED => 'הבקשה אושרה ע"י רמ"ד תו"ב וממתינה למפקד',
            self::CONTROL_OFFICER_REJECTED => 'הבקשה הועברה לביטול ע"י רמ"ד תו"ב וממתינה למפקד',
            default => null,
        };
    }


    /** Extra status-specific message, e.g. the realization deadline once APPROVED. */
    public function getAdditionalMessage(int $bookingTypeId): ?string
    {
        return match ($this) {
            self::APPROVED => match ($bookingTypeId) {
                default => 'יש לממש את ההזמנה תוך 7 ימים ממועד האישור',
            },

            default => null,
        };
    }

    public function getStatusStage(): string|null
    {
        return match ($this) {
            self::RECEIVED => 'שלב 1',
            self::PROCESSING,
            self::VICE_APPROVER_APPROVED,
            self::VICE_APPROVER_REJECTED => 'שלב 2',
            self::APPROVED => 'שלב 3',
            self::COMPLETED => 'שלב 4',
            self::REJECTED => 'שלב 5',
            self::CANCELED_BY_APPROVER => 'שלב 6',
            self::CANCELED_BY_USER => 'שלב 7',
            self::PENDING_FILE => 'שלב 8',
            self::PENDING_PROCESSING => 'שלב 9',
            self::PENDING_APPROVED => 'שלב 10',
            self::APPROVED_BY_BARBER => 'שלב 11',
            self::CANCELED_BY_BARBER => 'שלב 12',
            self::CANCELED_BY_CHANGING_TIME => 'שלב 13',
            self::PROCESSING_BY_CONTROL_OFFICER,
            self::CONTROL_OFFICER_APPROVED,
            self::CONTROL_OFFICER_REJECTED => 'שלב 14',
            // The clearance route's own steps, counted along its progress bar.
            self::INTERVIEW_ASSIGNMENT => 'שלב 1',
            self::INTERVIEW_SCHEDULED => 'שלב 2',
            self::INTERVIEWED => 'שלב 3',
            self::AWAITING_CLEARANCE => 'שלב 4',
            self::CLEARANCE_GRANTED => 'שלב 5',
            default => null,
        };
    }

    /** False for closed statuses, which a newly restricted date cannot cancel. */
    public function canBeCanceledByRestrictionDates(): bool
    {
        return !in_array($this, [
            self::REJECTED,
            self::COMPLETED,
            self::CANCELED_BY_USER,
            self::CANCELED_BY_APPROVER,
            self::CANCELED_BY_BARBER,
            self::CANCELED_BY_CHANGING_TIME,
            self::CLEARANCE_GRANTED,
        ], true);
    }

    /** Whether the status holds a slot/resource that counts toward capacity. */
    public function isActiveForCapacityValidation(): bool
    {
        return in_array($this, [
            // CAR_BOOKING, CAR_MAINTENANCE_BOOKING, FOOD_BOOKINGS, etc.
            self::RECEIVED,
            self::PROCESSING,
            self::VICE_APPROVER_APPROVED,
            self::VICE_APPROVER_REJECTED,
            self::APPROVED,

            // TRANSPORTATION_BOOKING specific
            self::PENDING_FILE,
            self::PENDING_PROCESSING,
            self::PENDING_APPROVED,
            self::PROCESSING_BY_CONTROL_OFFICER,
            self::CONTROL_OFFICER_APPROVED,
            self::CONTROL_OFFICER_REJECTED,

            // BARBER_BOOKING specific
            self::APPROVED_BY_BARBER,
        ], true);
    }
}
