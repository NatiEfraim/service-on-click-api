<?php

namespace App\Enums;

enum HttpStatusCodeEnum: int
{
    case OK = 200;
    case NOT_FOUND = 404;
    case CREATED = 201;
    case BAD_REQUEST = 400;
    case UNAUTHORIZED = 401;
    case INTERNAL_SERVER_ERROR = 500;
    case UNPROCESSABLE_ENTITY = 422;
    case CONFLICT = 409;
    case FORBIDDEN = 403;
    case NO_CONTENT = 204;

    /** Shared vague message for refused requests (403/404/405). */
    public const DENIED_MESSAGE = 'הבקשה לא ניתנת לביצוע.';

    public function getMessage(): string
    {
        return match ($this) {
            self::OK => 'בקשה הושלמה בהצלחה.',
            self::NOT_FOUND => self::DENIED_MESSAGE,
            self::CREATED => 'הנתונים נשמרו במערכת.',
            self::BAD_REQUEST => 'בקשה שגויה.',
            self::UNAUTHORIZED => 'הגישה נדחתה. יש להתחבר למערכת.',
            self::INTERNAL_SERVER_ERROR => 'שגיאה פנימית בשרת. יש לנסות מאוחר יותר.',
            self::CONFLICT => 'קיימת סתירה בנתונים.',
            self::FORBIDDEN => self::DENIED_MESSAGE,
            self::NO_CONTENT => 'תוכן לא נמצא במערכת.',
            self::UNPROCESSABLE_ENTITY => 'נתונים שנשלחו אינם תקינים.',
        };
    }
}
