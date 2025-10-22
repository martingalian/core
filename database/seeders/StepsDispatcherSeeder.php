<?php

declare(strict_types=1);

namespace Martingalian\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class StepsDispatcherSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        // The 10 groups to pre-populate
        $groups = [
            'alpha', 'beta', 'gamma', 'delta', 'epsilon',
            'zeta', 'eta', 'theta', 'iota', 'kappa',
        ];

        // Avoid duplicates even if no unique index exists
        $existing = DB::table('steps_dispatcher')
            ->whereIn('group', $groups)
            ->pluck('group')
            ->all();

        $missing = array_values(array_diff($groups, $existing));

        if (empty($missing)) {
            return;
        }

        $rows = array_map(static function (string $g) use ($now) {
            return [
                'group' => $g,
                'can_dispatch' => true,
                'current_tick_id' => null,
                'last_tick_completed' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, $missing);

        DB::table('steps_dispatcher')->insert($rows);
    }
}
