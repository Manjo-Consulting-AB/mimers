<?php

namespace App\Actions\User;

use App\Models\OverviewPanel;
use App\Models\User;
use App\Support\Frontend\OverviewPanels;

/**
 * Personens ordning för översiktens paneler, se
 * [[ADR-0073 Översiktens ordning]] § 3 och 8. En post per nyckel i
 * panellistan: sparade rader i `position`-ordning, okända nycklar hoppas
 * över och nycklar utan rad läggs sist med sin förvalda bredd.
 */
class ReadOverviewPanels
{
    /**
     * @return list<array{key: string, width: string}>
     */
    public function handle(User $user): array
    {
        $panels = [];
        $seen = [];

        $rows = OverviewPanel::query()
            ->where('user_id', $user->id)
            ->orderBy('position')
            ->get(['panel_key', 'width']);

        foreach ($rows as $row) {
            if (! OverviewPanels::knows($row->panel_key) || isset($seen[$row->panel_key])) {
                continue;
            }

            $seen[$row->panel_key] = true;
            $panels[] = ['key' => $row->panel_key, 'width' => $row->width];
        }

        foreach (OverviewPanels::keys() as $key) {
            if (! isset($seen[$key])) {
                $panels[] = ['key' => $key, 'width' => OverviewPanels::defaultWidth($key)];
            }
        }

        return $panels;
    }
}
