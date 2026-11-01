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

    /** Get the StatusBookingEnum instance based on the status name (the `statuses.name` column). */
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
            default => null,
        };
    }

    /** Get this status's display order on the status dashboards. @return int */
    public function getPosition(): int
    {
        return match ($this) {
            self::RECEIVED => 1,
                //TRANSPORTATION_BOOKING
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
                //TRANSPORTATION_BOOKING
            self::PENDING_FILE => 13,
            self::PENDING_PROCESSING => 14,
            self::PENDING_APPROVED => 15,
                //BARBER_BOOKING
            self::APPROVED_BY_BARBER => 16,
            self::CANCELED_BY_BARBER => 17,
            self::CANCELED_BY_CHANGING_TIME => 18,
        };
    }

    /** Waiting for the vice approver (control officer answered; incl. legacy PROCESSING_BY_CONTROL_OFFICER). @return bool */
    public function isWaitingForViceApprover(): bool
    {
        return in_array($this, [self::PROCESSING_BY_CONTROL_OFFICER, self::CONTROL_OFFICER_APPROVED, self::CONTROL_OFFICER_REJECTED], true);
    }

    /** Waiting for the approver's final decision (vice approver answered; incl. legacy PROCESSING). @return bool */
    public function isWaitingForApprover(): bool
    {
        return in_array($this, [self::PROCESSING, self::VICE_APPROVER_APPROVED, self::VICE_APPROVER_REJECTED], true);
    }

    /** TRANSPORTATION_BOOKING only: on hold on the supplier side (files, supplier approval or number). @return bool */
    public function isPendingForSupplier(): bool
    {
        return in_array($this, [self::PENDING_FILE, self::PENDING_PROCESSING, self::PENDING_APPROVED], true);
    }

    /** Check if the booking's owner can still cancel it in this status. */
    public function canBeCanceledByUser(): bool
    {
        return $this === self::RECEIVED
            || $this === self::APPROVED
            || $this->isWaitingForViceApprover()
            || $this->isWaitingForApprover();
    }

    /** The booking is closed: nothing more happens to it (rejected, completed or canceled). */
    public function isTerminal(): bool
    {
        return !$this->canBeCanceledByRestrictionDates();
    }

    /** The ids of the closed statuses (rejected, completed, canceled). @return array<int, int> */
    public static function terminalIds(): array
    {
        return array_values(array_map(
            fn (self $status): int => $status->value,
            array_filter(self::cases(), fn (self $status): bool => $status->isTerminal())
        ));
    }

    /** The action_types row written to application_logs when a booking moves into this status. */
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
        };
    }

    /**
     * A barber booking in this status no longer holds its slot (the statuses the mobile app frees, plus the one a changed barber time sets).
     * Rejected and canceled-by-the-barber are deliberately not in the list: the mobile app keeps those slots, and this system mirrors it. (A
     * correction of a time still treats every closed booking as not holding its time - see BookingValueService.)
     */
    public function freesBarberSlot(): bool
    {
        return in_array($this, [
            self::COMPLETED,
            self::CANCELED_BY_USER,
            self::CANCELED_BY_APPROVER,
            self::APPROVED_BY_BARBER,
            self::CANCELED_BY_CHANGING_TIME,
        ], true);
    }

    /** Get role flags by permission names (hasPermissionTo may throw). @param iterable $permissionNames, int $bookingTypeId @return array */
    public static function resolveRoleFlags(iterable $permissionNames, int $bookingTypeId): array
    {
        return Workflow::roleFlags($permissionNames, $bookingTypeId);
    }

    /** Get the status_id options the user may move this booking to. @param array $roleFlags, ?int $bookingTypeId @return array */
    public function getNextStatuses(array $roleFlags, ?int $bookingTypeId = null): array
    {
        $allowed = $this->getAllowedTransitions($roleFlags, $bookingTypeId);

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

    /** Check if the roles allow moving to $targetStatus. @param self $targetStatus, array $roleFlags, ?int $bookingTypeId @return bool */
    public function canChangeTo(self $targetStatus, array $roleFlags, ?int $bookingTypeId = null): bool
    {
        foreach ($this->getAllowedTransitions($roleFlags, $bookingTypeId) as $transition) {
            if ($transition['status'] === $targetStatus) {
                return true;
            }
        }

        return false;
    }

    /** The transitions the roles allow from this status - turn-based, see App\Domain\Workflow\Workflow. @param array $roleFlags, ?int $bookingTypeId @return array */
    private function getAllowedTransitions(array $roleFlags, ?int $bookingTypeId = null): array
    {
        return app(Workflow::class)->allowedTransitions($this, $roleFlags, (int) $bookingTypeId);
    }

    /** Get the Hebrew dashboard display name for this status. @return string */
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
                //TRANSPORTATION_BOOKING
            self::PENDING_FILE => 'ממתין לקבצים מהלקוח',
            self::PENDING_PROCESSING => 'ממתין לאישור ספק',
            self::PENDING_APPROVED => 'ממתין למספר ספק',
                //BARBER_BOOKING
            self::APPROVED_BY_BARBER => 'טופל ע"י הספר',
            self::CANCELED_BY_BARBER => 'בוטלה ע"י הספר',
            self::CANCELED_BY_CHANGING_TIME => 'בוטלה עכב שינויים בזמני המספרה',
                //TRANSPORTATION_BOOKING
            self::PROCESSING_BY_CONTROL_OFFICER => 'טופלו רמ"ד תו"ב',
            self::VICE_APPROVER_APPROVED => 'הועברו לאישור סופי',
            self::VICE_APPROVER_REJECTED => 'הועברו לביטול סופי',
            self::CONTROL_OFFICER_APPROVED => 'אושרו ע"י רמ"ד תו"ב',
            self::CONTROL_OFFICER_REJECTED => 'נדחו ע"י רמ"ד תו"ב',
        };
    }


    /** Get the dashboard icon id for this status. @return string */
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
                //TRANSPORTATION_BOOKING
            self::PENDING_FILE => '8',//'ממתינים לקבצים נוספים מהלקוח'
            self::PENDING_PROCESSING => '9',//'ממתין לאישור מהספק'
            self::PENDING_APPROVED => '10',// 'ממתינים לאישור סופי מהספק (כלומר למספר ספק)'
                //BARBERֹBֹ_BOOKING
            self::APPROVED_BY_BARBER => '11',// 'בקשה שנסגרה ע"י הספר'
            self::CANCELED_BY_BARBER => '12',// 'בקשה שבוטלה ע"י הספר'
            self::CANCELED_BY_CHANGING_TIME => '13',// 'בקשה שנסגרה ע"י שינויים בזמנים'
                //TRANSPORTATION_BOOKING
            self::PROCESSING_BY_CONTROL_OFFICER => '14',// 'בטיפול רמ"ד תו"ב'
            self::VICE_APPROVER_APPROVED => '15',// 'הועברו לאישור סופי'
            self::VICE_APPROVER_REJECTED => '16',// 'הועברו לביטול סופי'
            self::CONTROL_OFFICER_APPROVED => '17',// 'אושרו ע"י רמ"ד תו"ב'
            self::CONTROL_OFFICER_REJECTED => '18',// 'נדחו ע"י רמ"ד תו"ב'
        };
    }

    /** Get the Hebrew status message for this booking status. @return string|null */
    public function getStatusMessage(): string|null
    {
        return match ($this) {
            self::RECEIVED => 'התקבלה בהצלחה',
            self::PROCESSING,
            self::VICE_APPROVER_APPROVED,
            self::VICE_APPROVER_REJECTED => 'הועברה לטיפול',
            self::APPROVED => 'אושרה וממתינה ללקוח',
            self::REJECTED => 'נדחתה ע"י גורם טכני',
            self::COMPLETED => 'הסתיימה בהצלחה',
            self::CANCELED_BY_USER => 'בוטלה ע"י המשתמש',
            self::CANCELED_BY_APPROVER => 'בוטלה ע"י גורם מאשר',
                //TRANSPORTATION_BOOKING
            self::PENDING_FILE => 'ממתינה להעלת קבצים נוספים במערכת',
            self::PENDING_PROCESSING => 'ממתינה לאישור מחברת ספק',
            self::PENDING_APPROVED => 'ממתינה למספר ספק',
                //BARBERֹBֹ_BOOKING
            self::APPROVED_BY_BARBER => 'נסגרה ע"י הספר',
            self::CANCELED_BY_BARBER => 'בוטלה ע"י הספר',
            self::CANCELED_BY_CHANGING_TIME => 'בוטלה עכב שינויי זמנים במספרה',
                //TRANSPORTATION_BOOKING
            self::PROCESSING_BY_CONTROL_OFFICER,
            self::CONTROL_OFFICER_APPROVED,
            self::CONTROL_OFFICER_REJECTED => 'ההזמנה בטיפול רמ"ד תו"ב',
            default => null,
        };
    }

    /** Get the Hebrew status message used in the SMS log to S3. @return string|null */
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
                //TRANSPORTATION_BOOKING
            self::PENDING_FILE => 'בקשה ממתינה לקבצים נוספים',
            self::PENDING_PROCESSING => 'בקשה ממתינה לאישור ספק',
            self::PENDING_APPROVED => 'בקשה ממתינה למספר ספק',
                //BARBERֹBֹ_BOOKING
            self::APPROVED_BY_BARBER => 'נסגרה ע"י הספר',
            self::CANCELED_BY_BARBER => 'בוטלה ע"י הספר',
            self::CANCELED_BY_CHANGING_TIME => 'בוטלה עכב שינויי זמנים במספרה',
                //TRANSPORTATION_BOOKING
            self::PROCESSING_BY_CONTROL_OFFICER,
            self::CONTROL_OFFICER_APPROVED,
            self::CONTROL_OFFICER_REJECTED => 'בקשה בטיפול רמ"ד תו"ב',
            default => null,
        };
    }

    /** Get the status subtitle shown to approvers in getBookings. @return ?string */
    public function getSubTitleStatusMessage(): ?string
    {
        return match ($this) {
            self::PROCESSING,
            self::VICE_APPROVER_APPROVED => 'הבקשה ממתינה לאישור סופי',
            self::VICE_APPROVER_REJECTED => 'בקשה ממתינה לביטול סופי',
            self::APPROVED => 'בקשה אושרה ע"י גורם מאשר',
            self::REJECTED => 'בקשה נדחתה ע"י גורם מאשר',
            self::COMPLETED => 'בקשה הסתיימה בהצלחה',
            self::CANCELED_BY_USER => 'בקשה בוטלה ע"י משתמש',
            self::CANCELED_BY_APPROVER => 'בקשה בוטלה ע"י גורם טכני',
                //TRANSPORTATION_BOOKING
            self::PENDING_FILE => 'בקשה ממתינה לקבצים נוספים',
            self::PENDING_PROCESSING => 'בקשה ממתינה לאישור ספק',
            self::PENDING_APPROVED => 'בקשה ממתינה למספר ספק',
                //BARBERֹBֹ_BOOKING
            self::APPROVED_BY_BARBER => 'נסגרה ע"י הספר',
            self::CANCELED_BY_BARBER => 'בוטלה ע"י הספר',
            self::CANCELED_BY_CHANGING_TIME => 'בוטלה עכב שינויי זמנים במספרה',
                //TRANSPORTATION_BOOKING
            self::PROCESSING_BY_CONTROL_OFFICER => 'בקשה בטיפול רמ"ד תו"ב',
            self::CONTROL_OFFICER_APPROVED => 'הבקשה אושרה ע"י רמ"ד תו"ב וממתינה למפקד',
            self::CONTROL_OFFICER_REJECTED => 'הבקשה הועברה לביטול ע"י רמ"ד תו"ב וממתינה למפקד',
            default => null,
        };
    }


    /** Resolve an extra, status-specific message (e.g. a realization deadline once APPROVED). */
    public function getAdditionalMessage(int $bookingTypeId): ?string
    {
        return match ($this) {
            self::APPROVED => match ($bookingTypeId) {
                default => 'יש לממש את ההזמנה תוך 7 ימים ממועד האישור',
            },

            default => null,
        };
    }

    /** Get the Hebrew stage label ("שלב N") for this status. @return string|null */
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
                //TRANSPORTATION_BOOKING
            self::PENDING_FILE => 'שלב 8',
            self::PENDING_PROCESSING => 'שלב 9',
            self::PENDING_APPROVED => 'שלב 10',
            self::APPROVED_BY_BARBER => 'שלב 11',
            self::CANCELED_BY_BARBER => 'שלב 12',
            self::CANCELED_BY_CHANGING_TIME => 'שלב 13',
            self::PROCESSING_BY_CONTROL_OFFICER,
            self::CONTROL_OFFICER_APPROVED,
            self::CONTROL_OFFICER_REJECTED => 'שלב 14',
            default => null,
        };
    }

    /** Check if this status can still be canceled by a newly restricted date (terminal statuses cannot). */
    public function canBeCanceledByRestrictionDates(): bool
    {
        return !in_array($this, [
            self::REJECTED,
            self::COMPLETED,
            self::CANCELED_BY_USER,
            self::CANCELED_BY_APPROVER,
            self::CANCELED_BY_BARBER,
            self::CANCELED_BY_CHANGING_TIME,
        ], true);
    }

    /** Check if this status holds a slot/resource and should count toward capacity validation. */
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
