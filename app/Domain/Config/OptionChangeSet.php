<?php

namespace App\Domain\Config;

/** Diffs a field's options into ordered changes (removals, renames, additions, moves) addressed by name path. */
final class OptionChangeSet
{
    /**
     * @param array<int, array{name: string, parent_id: ?int}> $before
     * @param array<int, array{name: string, parent_id: ?int}> $after
     * @param array<int, int> $archivedIds ids missing from $after that are archived, not deleted
     * @return array<int, array{op: string, id: int, option_path: array<int, string>, data: array<string, mixed>}> id is local and not sent
     */
    public static function between(array $before, array $after, array $archivedIds = []): array
    {
        $changes = [];
        // Names as the receiver has them by the time each change is applied.
        $current = array_map(fn (array $option): string => $option['name'], $before);
        $removed = array_diff_key($before, $after);
        $removedIds = array_keys($removed);

        // Removals: deepest first, so a child goes before its parent.
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

        // Renames: parents first, so child paths use the new parent name.
        foreach ($kept as $id) {
            if ($before[$id]['name'] === $after[$id]['name']) {
                continue;
            }

            $changes[] = ['op' => 'update', 'id' => $id, 'option_path' => self::path($id, $before, $current, $removedIds), 'data' => ['name' => $after[$id]['name']]];
            $current[$id] = $after[$id]['name'];
        }

        // Additions: parents first, spelled with final names.
        $added = array_keys(array_diff_key($after, $before));
        usort($added, fn (int $a, int $b): int => self::depth($a, $after) <=> self::depth($b, $after));
        $final = array_map(fn (array $option): string => $option['name'], $after);

        foreach ($added as $id) {
            $changes[] = ['op' => 'create', 'id' => $id, 'option_path' => self::path($id, $after, $final, []), 'data' => ['name' => $after[$id]['name']]];
        }

        // Moves: the new parent exists by now.
        foreach ($kept as $id) {
            if ($before[$id]['parent_id'] === $after[$id]['parent_id']) {
                continue;
            }

            $parent = $after[$id]['parent_id'];

            $changes[] = [
                'op' => 'update',
                'id' => $id,
                // A removed parent is gone on the receiver: its children became top options.
                'option_path' => self::path($id, $before, $current, $removedIds),
                'data' => ['parent_path' => $parent === null ? [] : self::path($parent, $after, $final, [])],
            ];
        }

        return $changes;
    }

    /**
     * Names from the top option down; ancestors in $gone are skipped (the receiver no longer has them).
     * @param array<int, array{name: string, parent_id: ?int}> $snapshot
     * @param array<int, string> $names id => name to spell with
     * @param array<int, int> $gone
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

    /** @param array<int, array{name: string, parent_id: ?int}> $snapshot */
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
