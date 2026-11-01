<?php

namespace App\Domain\Config;

/**
 * What changed among the options of one form field, as the changes the mobile app is told. The options of the two systems have different ids, so an
 * option is named by the path of names from the top option down ("equipment" > "clothing" > "shirt"), and the changes are listed in the order
 * the receiver can apply them one after the other, each path spelled the way the receiver has it by then:
 *   1. removals (the deepest first)   2. renames (parents before children)   3. new options (parents before children)   4. moves under another parent.
 * No database access - unit-tested standalone.
 */
final class OptionChangeSet
{
    /**
     * @param array<int, array{name: string, parent_id: ?int}> $before the options as they were (id => name and parent)
     * @param array<int, array{name: string, parent_id: ?int}> $after the options as they are
     * @param array<int, int> $archivedIds the ids missing from $after that are kept, out of the form (archived), instead of deleted
     * @return array<int, array{op: string, id: int, option_path: array<int, string>, data: array<string, mixed>}> id is this system's, for the caller; it is not sent
     */
    public static function between(array $before, array $after, array $archivedIds = []): array
    {
        $changes = [];
        // The name each option has on the receiver's side by the time a change is applied there.
        $current = array_map(fn (array $option): string => $option['name'], $before);
        $removed = array_diff_key($before, $after);
        $removedIds = array_keys($removed);

        // 1. Removed: the deepest first, so a child goes before its parent.
        usort($removedIds, fn (int $a, int $b): int => self::depth($b, $before) <=> self::depth($a, $before));

        foreach ($removedIds as $id) {
            $changes[] = [
                'op' => in_array($id, $archivedIds, true) ? 'archive' : 'delete',
                'id' => $id,
                'option_path' => self::path($id, $before, $current, []),
                'data' => [],
            ];
        }

        $kept = array_keys(array_intersect_key($after, $before));
        usort($kept, fn (int $a, int $b): int => self::depth($a, $after) <=> self::depth($b, $after));

        // 2. Renamed: a parent before its children, so a child's path already has the parent's new name.
        foreach ($kept as $id) {
            if ($before[$id]['name'] === $after[$id]['name']) {
                continue;
            }

            $changes[] = ['op' => 'update', 'id' => $id, 'option_path' => self::path($id, $before, $current, $removedIds), 'data' => ['name' => $after[$id]['name']]];
            $current[$id] = $after[$id]['name'];
        }

        // 3. New: parents before children, spelled with the final names.
        $added = array_keys(array_diff_key($after, $before));
        usort($added, fn (int $a, int $b): int => self::depth($a, $after) <=> self::depth($b, $after));
        $final = array_map(fn (array $option): string => $option['name'], $after);

        foreach ($added as $id) {
            $changes[] = ['op' => 'create', 'id' => $id, 'option_path' => self::path($id, $after, $final, []), 'data' => ['name' => $after[$id]['name']]];
        }

        // 4. Moved under another parent (or to the top): the new parent exists by now.
        foreach ($kept as $id) {
            if ($before[$id]['parent_id'] === $after[$id]['parent_id']) {
                continue;
            }

            $parent = $after[$id]['parent_id'];

            $changes[] = [
                'op' => 'update',
                'id' => $id,
                // A parent that was removed is gone there: its children became top options.
                'option_path' => self::path($id, $before, $current, $removedIds),
                'data' => ['parent_path' => $parent === null ? [] : self::path($parent, $after, $final, [])],
            ];
        }

        return $changes;
    }

    /**
     * The names from the top option down to this one. An ancestor in $gone is not on the path (the receiver no longer has it).
     * @param array<int, array{name: string, parent_id: ?int}> $snapshot @param array<int, string> $names id => name to spell with @param array<int, int> $gone
     * @return array<int, string>
     */
    public static function path(int $id, array $snapshot, array $names, array $gone): array
    {
        $path = [];
        $seen = [];

        for ($next = $id; $next !== null && isset($snapshot[$next]) && !isset($seen[$next]); $next = $snapshot[$next]['parent_id']) {
            if ($next !== $id && in_array($next, $gone, true)) {
                break;
            }

            $seen[$next] = true;
            array_unshift($path, $names[$next] ?? $snapshot[$next]['name']);
        }

        return $path;
    }

    /** How many options are above this one (0 for a top option). @param array<int, array{name: string, parent_id: ?int}> $snapshot */
    private static function depth(int $id, array $snapshot): int
    {
        $depth = 0;
        $seen = [];

        for ($next = $snapshot[$id]['parent_id'] ?? null; $next !== null && isset($snapshot[$next]) && !isset($seen[$next]); $next = $snapshot[$next]['parent_id']) {
            $seen[$next] = true;
            $depth++;
        }

        return $depth;
    }
}
