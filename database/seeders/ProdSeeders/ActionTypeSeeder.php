<?php

namespace Database\Seeders\ProdSeeders;

use App\Enums\ActionTypeEnum;
use App\Models\ActionType;
use Illuminate\Database\Seeder;

class ActionTypeSeeder extends Seeder
{
    /** Rows are built from ActionTypeEnum so ids cannot drift. */
    public function run(): void
    {
        foreach (ActionTypeEnum::rows() as $row) {
            ActionType::updateOrCreate(
                ['id' => $row['id']],
                [
                    'type' => $row['type'],
                    'display_type' => $row['display_type'],
                    'description' => $row['description'],
                ]
            );
        }
    }
}
