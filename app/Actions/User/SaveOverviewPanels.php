<?php

namespace App\Actions\User;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ersätter personens alla rader i `overview_panel` i en transaktion, och
 * återställer genom att radera dem. Listan är redan validerad av
 * App\Http\Requests\UpdateOverviewPanelsRequest.
 */
class SaveOverviewPanels
{
    /**
     * @param  list<array{key: string, width: string}>  $panels
     */
    public function handle(User $user, array $panels): void
    {
        DB::transaction(function () use ($user, $panels): void {
            DB::table('overview_panel')->where('user_id', $user->id)->delete();

            $now = now();
            $rows = [];

            foreach ($panels as $position => $panel) {
                $rows[] = [
                    'user_id' => $user->id,
                    'panel_key' => $panel['key'],
                    'position' => $position,
                    'width' => $panel['width'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('overview_panel')->insert($rows);
        });
    }

    public function reset(User $user): void
    {
        DB::table('overview_panel')->where('user_id', $user->id)->delete();
    }
}
