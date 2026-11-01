<?php

namespace App\Domain\Sync;

/**
 * The check on everything that leaves for the mobile app (the r2b files): the mobile app knows a unit by its letter only, so the display name of a
 * unit, the name of a department and any reference to a department must never go out. Fail closed: a payload that holds one is not sent.
 * No database access - the names to look for are given.
 */
final class PayloadGuard
{
    /** Keys that carry a unit's display name or a department. */
    public const FORBIDDEN_KEYS = ['unit_name', 'unit_display_name', 'internal_name', 'department', 'department_id', 'department_name'];

    /** A name shorter than this is not looked for: it would match ordinary words (and a unit's letter is not secret). */
    public const MIN_NAME_LENGTH = 3;

    /**
     * Where the payload holds a forbidden key or a text with one of the names as a whole word ("NASA" is found in "ביחידת NASA", not in "NASAL").
     * @param string[] $forbiddenValues the internal names (see usableNames)
     * @return string[] the paths of what was found, empty when the payload is clean
     */
    public static function violations(mixed $payload, array $forbiddenValues, string $path = '$'): array
    {
        $found = [];

        if (is_array($payload)) {
            foreach ($payload as $key => $value) {
                if (is_string($key) && in_array(strtolower($key), self::FORBIDDEN_KEYS, true)) {
                    $found[] = "{$path}.{$key}";
                }

                $found = [...$found, ...self::violations($value, $forbiddenValues, "{$path}.{$key}")];
            }

            return $found;
        }

        if (is_string($payload) && $payload !== '') {
            foreach ($forbiddenValues as $name) {
                if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($name, '/') . '(?![\p{L}\p{N}])/iu', $payload) === 1) {
                    return ["{$path}: internal name"];
                }
            }
        }

        return $found;
    }

    /**
     * The names worth looking for: not empty, not shorter than MIN_NAME_LENGTH, not the same as a unit letter (a unit that has no name of its own
     * shows its letter), each once.
     * @param array<int, ?string> $names the display names of the units and the names of the departments @param array<int, string> $unitLetters
     * @return string[]
     */
    public static function usableNames(array $names, array $unitLetters = []): array
    {
        $letters = array_map('mb_strtolower', $unitLetters);
        $usable = [];

        foreach ($names as $name) {
            $name = trim((string) $name);

            if (mb_strlen($name) >= self::MIN_NAME_LENGTH && !in_array(mb_strtolower($name), $letters, true)) {
                $usable[mb_strtolower($name)] = $name;
            }
        }

        return array_values($usable);
    }
}
