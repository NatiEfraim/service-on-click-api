<?php

namespace App\Domain\Audit;

/** Tamper-evidence for the audit log: each entry hashes its content together with the previous entry's hash. */
final class HashChain
{
    /** The columns the hash covers, in this order. */
    public const FIELDS = [
        'occurred_at', 'actor_type', 'actor_id', 'actor_name', 'source', 'action', 'entity_type', 'entity_id',
        'booking_serial', 'unit_id', 'booking_type_id', 'before', 'after', 'reason', 'result', 'correlation_id',
    ];

    /** Numeric columns: a driver may return them as text, which must not change the hash. */
    private const NUMERIC_FIELDS = ['booking_serial', 'unit_id', 'booking_type_id'];

    /** Chains $entry to $prevHash (null for the first entry). @param array<string, mixed> $entry */
    public static function hash(array $entry, ?string $prevHash): string
    {
        $canonical = [];

        foreach (self::FIELDS as $field) {
            $value = $entry[$field] ?? null;

            if (in_array($field, self::NUMERIC_FIELDS, true) && $value !== null) {
                $value = (int) $value;
            } elseif (is_array($value)) {
                $value = self::sortKeys($value);
            }

            $canonical[$field] = $value;
        }

        return hash('sha256', ($prevHash ?? 'GENESIS') . '|' . json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Checks that each entry points at the one before and hashes to what it says.
     * @param iterable<array<string, mixed>> $entriesInOrder rows as stored (before/after as JSON text or arrays)
     * @return array{valid: bool, checked: int, broken_at: ?int} broken_at is the id of the first bad entry
     */
    public static function verify(iterable $entriesInOrder): array
    {
        $prev = null;
        $checked = 0;

        foreach ($entriesInOrder as $entry) {
            $checked++;

            $before = is_string($entry['before'] ?? null) ? json_decode($entry['before'], true) : ($entry['before'] ?? null);
            $after = is_string($entry['after'] ?? null) ? json_decode($entry['after'], true) : ($entry['after'] ?? null);

            $calculated = self::hash([...$entry, 'before' => $before, 'after' => $after], $prev);

            if (($entry['prev_hash'] ?? null) !== $prev || $calculated !== $entry['hash']) {
                return ['valid' => false, 'checked' => $checked, 'broken_at' => (int) $entry['id']];
            }

            $prev = $entry['hash'];
        }

        return ['valid' => true, 'checked' => $checked, 'broken_at' => null];
    }

    /** Masks password, secret, token and key values. @param array<mixed> $data @return array<mixed> */
    public static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (preg_match('/pass|secret|token|digikey|digisecret|api[_-]?key/i', (string) $key)) {
                $data[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }

        return $data;
    }

    /** @param array<mixed> $values @return array<mixed> */
    private static function sortKeys(array $values): array
    {
        ksort($values);

        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = self::sortKeys($value);
            }
        }

        return $values;
    }
}
