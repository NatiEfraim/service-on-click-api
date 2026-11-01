<?php

namespace App\Domain\Sync;

/** Fail-closed check on r2b payloads: unit display names and department names/references must never leave. */
final class PayloadGuard
{
    /** Keys that carry a unit's display name or a department. */
    public const FORBIDDEN_KEYS = ['unit_name', 'unit_display_name', 'internal_name', 'department', 'department_id', 'department_name'];

    /** Shorter names are skipped: they would match ordinary words. */
    public const MIN_NAME_LENGTH = 3;

    /**
     * Paths where the payload holds a forbidden key or one of the names as a whole word.
     * @param string[] $forbiddenValues
     * @return string[] empty when the payload is clean
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
     * Names worth searching for: non-empty, long enough, not a unit letter, each once.
     * @param array<int, ?string> $names @param array<int, string> $unitLetters
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
