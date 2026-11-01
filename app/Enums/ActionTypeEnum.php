<?php

namespace App\Enums;

/** Actions recorded in application_logs; source of the action_types table. */
enum ActionTypeEnum: int
{
    case STORE_BOOKING = 1;
    case UPDATE_BOOKING = 2;
    case VICE_APPROVER_PROCESSING_BOOKING = 3;
    case APPROVE_BOOKING = 4;
    case REJECT_BOOKING = 5;
    case COMPLETE_BOOKING = 6;
    case CANCEL_BOOKING_BY_APPROVER = 7;
    case RETURN_TO_RECEIVED = 8;
    case CANCEL_BOOKING_BY_USER = 9;
    case CONTROL_OFFICER_PROCESSING_BOOKING = 10;
    case APPROVE_BOOKING_BY_BARBER = 11;
    case DELETE_BOOKING = 12;
    case MOVE_TO_PENDING_BOOKING = 13;

    public function getType(): string
    {
        return match ($this) {
            self::STORE_BOOKING => 'store_booking',
            self::UPDATE_BOOKING => 'update_booking',
            self::VICE_APPROVER_PROCESSING_BOOKING => 'vice_approver_processing_booking',
            self::APPROVE_BOOKING => 'approve_booking',
            self::REJECT_BOOKING => 'reject_booking',
            self::COMPLETE_BOOKING => 'complete_booking',
            self::CANCEL_BOOKING_BY_APPROVER => 'cancel_booking_by_approver',
            self::RETURN_TO_RECEIVED => 'return_to_received',
            self::CANCEL_BOOKING_BY_USER => 'cancel_booking_by_user',
            self::CONTROL_OFFICER_PROCESSING_BOOKING => 'control_officer_processing_booking',
            self::APPROVE_BOOKING_BY_BARBER => 'approve_booking_by_barber',
            self::DELETE_BOOKING => 'delete_booking',
            self::MOVE_TO_PENDING_BOOKING => 'move_to_pending_booking',
        };
    }

    public function getDisplayType(): string
    {
        return match ($this) {
            self::STORE_BOOKING => 'פתיחת הזמנה',
            self::UPDATE_BOOKING => 'עדכון הזמנה',
            self::VICE_APPROVER_PROCESSING_BOOKING => 'העברה לטיפול',
            self::APPROVE_BOOKING => 'אישור הזמנה',
            self::REJECT_BOOKING => 'דחיית הזמנה',
            self::COMPLETE_BOOKING => 'סגירת הזמנה',
            self::CANCEL_BOOKING_BY_APPROVER => 'ביטול על ידי מאשר',
            self::RETURN_TO_RECEIVED => 'החזרה לסטטוס התקבלה',
            self::CANCEL_BOOKING_BY_USER => 'ביטול על ידי המזמין',
            self::CONTROL_OFFICER_PROCESSING_BOOKING => 'העברה לטיפול על ידי קצין בקרה',
            self::APPROVE_BOOKING_BY_BARBER => 'אישור על ידי הספר',
            self::DELETE_BOOKING => 'מחיקת הזמנה',
            self::MOVE_TO_PENDING_BOOKING => 'העברה להמתנה',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::STORE_BOOKING => 'משתמש פתח הזמנה חדשה במערכת',
            self::UPDATE_BOOKING => 'עודכנו פרטי ההזמנה במערכת',
            self::VICE_APPROVER_PROCESSING_BOOKING => 'גורם מטפל בחן את הבקשה והעביר למפקד תחום',
            self::APPROVE_BOOKING => 'מפקד תחום אישר את ההזמנה',
            self::REJECT_BOOKING => 'מפקד תחום דחה את ההזמנה',
            self::COMPLETE_BOOKING => 'מפקד תחום סגר את ההזמנה לאחר שהטיפול בה הסתיים',
            self::CANCEL_BOOKING_BY_APPROVER => 'מפקד תחום ביטל את ההזמנה',
            self::RETURN_TO_RECEIVED => 'ההזמנה הוחזרה לסטטוס התקבלה',
            self::CANCEL_BOOKING_BY_USER => 'המזמין ביטל את ההזמנה שפתח',
            self::CONTROL_OFFICER_PROCESSING_BOOKING => 'רמ"ד תו"ב בחן את הבקשה והעביר לגורם מטפל',
            self::APPROVE_BOOKING_BY_BARBER => 'הספר אישר את ההזמנה',
            self::DELETE_BOOKING => 'ההזמנה נמחקה',
            self::MOVE_TO_PENDING_BOOKING => 'ההזמנה הועברה להמתנה - לקבצים מהלקוח, לאישור ספק או למספר ספק',
        };
    }

    public function toRow(): array
    {
        return [
            'id' => $this->value,
            'type' => $this->getType(),
            'display_type' => $this->getDisplayType(),
            'description' => $this->getDescription(),
        ];
    }

    public static function rows(): array
    {
        return array_map(
            fn(self $actionType): array => $actionType->toRow(),
            self::cases()
        );
    }
}
