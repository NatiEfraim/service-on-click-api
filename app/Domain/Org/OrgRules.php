<?php

namespace App\Domain\Org;

/** Validation for units (fixed mobile-app letter code plus internal name) and departments. */
final class OrgRules
{
    /**
     * @param array{code?: string, name?: string} $data
     * @param array<int, array{code: string, name: string}> $others the other units
     * @return ?string Hebrew error, or null when valid
     */
    public static function validateUnit(array $data, array $others): ?string
    {
        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        $name = trim((string) ($data['name'] ?? ''));

        if (!preg_match('/^[A-Z]{1,3}$/', $code)) {
            return 'האות של היחידה חייבת להיות 1 עד 3 אותיות באנגלית';
        }

        if ($error = self::validateName($name)) {
            return $error;
        }

        foreach ($others as $other) {
            if (strtoupper($other['code']) === $code) {
                return "האות {$code} כבר משויכת ליחידה אחרת";
            }

            if (mb_strtolower(trim($other['name'])) === mb_strtolower($name)) {
                return 'כבר קיימת יחידה בשם זה';
            }
        }

        return null;
    }

    /** @param array{name?: string} $data @param array<int, array{name: string}> $siblings the other departments of the unit @return ?string */
    public static function validateDepartment(array $data, array $siblings): ?string
    {
        $name = trim((string) ($data['name'] ?? ''));

        if ($error = self::validateName($name)) {
            return $error;
        }

        foreach ($siblings as $sibling) {
            if (mb_strtolower(trim($sibling['name'])) === mb_strtolower($name)) {
                return 'כבר קיימת ביחידה מחלקה בשם זה';
            }
        }

        return null;
    }

    /** A department with people in it cannot be deactivated until they are moved. */
    public static function canDeactivateDepartment(int $activeUsers): ?string
    {
        return $activeUsers > 0 ? "למחלקה משויכים {$activeUsers} משתמשים פעילים. יש להעביר אותם למחלקה אחרת לפני ההשבתה" : null;
    }

    private static function validateName(string $name): ?string
    {
        if (mb_strlen($name) < 2 || mb_strlen($name) > 40) {
            return 'השם חייב להכיל בין 2 ל-40 תווים';
        }

        if (!preg_match('/^[\p{Hebrew}a-zA-Z0-9\s"\'\-\.\/]+$/u', $name)) {
            return 'השם יכול להכיל אותיות, ספרות וסימנים בסיסיים בלבד';
        }

        return null;
    }
}
