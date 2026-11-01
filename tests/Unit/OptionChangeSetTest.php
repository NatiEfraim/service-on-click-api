<?php

namespace Tests\Unit;

use App\Domain\Config\OptionChangeSet as Changes;
use PHPUnit\Framework\TestCase;

/** What changed among the options of a form field, told in names (the ids of the two systems differ) and in an order the receiver can apply. No database. */
class OptionChangeSetTest extends TestCase
{
    /** @return array<int, array{name: string, parent_id: ?int}> */
    private function tree(): array
    {
        return [
            1 => ['name' => 'ציוד', 'parent_id' => null],
            2 => ['name' => 'ביגוד', 'parent_id' => 1],
            3 => ['name' => 'חולצה', 'parent_id' => 2],
            4 => ['name' => 'הנעלה', 'parent_id' => 1],
        ];
    }

    private function ops(array $changes): array
    {
        return array_map(fn (array $change): string => $change['op'] . ' ' . implode(' > ', $change['option_path']), $changes);
    }

    public function test_nothing_changed_is_no_change(): void
    {
        $this->assertSame([], Changes::between($this->tree(), $this->tree()));
    }

    public function test_a_new_option_is_told_with_its_path(): void
    {
        $after = $this->tree() + [5 => ['name' => 'כובע', 'parent_id' => 2]];

        $changes = Changes::between($this->tree(), $after);

        $this->assertSame(['create ציוד > ביגוד > כובע'], $this->ops($changes));
        $this->assertSame(5, $changes[0]['id'], 'the id is this system\'s, for the caller');
        $this->assertSame(['name' => 'כובע'], $changes[0]['data']);
    }

    public function test_a_new_parent_comes_before_its_new_child(): void
    {
        $after = $this->tree() + [7 => ['name' => 'מגן', 'parent_id' => 6], 6 => ['name' => 'ציוד מגן', 'parent_id' => 1]];

        $this->assertSame(['create ציוד > ציוד מגן', 'create ציוד > ציוד מגן > מגן'], $this->ops(Changes::between($this->tree(), $after)));
    }

    public function test_a_rename_is_told_by_the_old_path_and_the_new_name(): void
    {
        $after = $this->tree();
        $after[3]['name'] = 'חולצה קצרה';

        $changes = Changes::between($this->tree(), $after);

        $this->assertSame(['update ציוד > ביגוד > חולצה'], $this->ops($changes));
        $this->assertSame(['name' => 'חולצה קצרה'], $changes[0]['data']);
    }

    public function test_after_a_parent_is_renamed_its_children_are_spelled_with_the_new_name_and_new_options_with_the_final_one(): void
    {
        $after = $this->tree() + [5 => ['name' => 'כובע', 'parent_id' => 2]];
        $after[2]['name'] = 'לבוש';
        $after[3]['name'] = 'גופייה';

        $this->assertSame([
            'update ציוד > ביגוד',          // the parent first
            'update ציוד > לבוש > חולצה',   // the receiver has the parent's new name by now; the child still has its old one
            'create ציוד > לבוש > כובע',    // new: the final path
        ], $this->ops(Changes::between($this->tree(), $after)));
    }

    public function test_a_removed_option_is_deleted_or_archived_and_a_child_goes_before_its_parent(): void
    {
        $after = [1 => $this->tree()[1], 4 => $this->tree()[4]];

        $this->assertSame(['delete ציוד > ביגוד > חולצה', 'delete ציוד > ביגוד'], $this->ops(Changes::between($this->tree(), $after)));

        $archived = Changes::between($this->tree(), $after, [3]);
        $this->assertSame(['archive', 'delete'], array_column($archived, 'op'), 'the option that is still stored (it holds values) is archived');
    }

    public function test_a_child_whose_parent_was_removed_is_a_top_option_for_the_receiver(): void
    {
        // The parent (2) is deleted; its child (3) stays and is moved to the top.
        $after = [1 => $this->tree()[1], 3 => ['name' => 'חולצה', 'parent_id' => null], 4 => $this->tree()[4]];

        $changes = Changes::between($this->tree(), $after);

        $this->assertSame(['delete ציוד > ביגוד', 'update חולצה'], $this->ops($changes));
        $this->assertSame(['parent_path' => []], $changes[1]['data']);
    }

    public function test_a_move_under_a_new_parent_comes_after_the_parent_is_created(): void
    {
        $after = $this->tree() + [5 => ['name' => 'חדש', 'parent_id' => null]];
        $after[4]['parent_id'] = 5;

        $changes = Changes::between($this->tree(), $after);

        $this->assertSame(['create חדש', 'update ציוד > הנעלה'], $this->ops($changes));
        $this->assertSame(['parent_path' => ['חדש']], $changes[1]['data']);
    }
}
