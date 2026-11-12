<?php

namespace App\Http\Controllers;

use App\Actions\User\SaveOverviewPanels;
use App\Http\Requests\UpdateOverviewPanelsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Personens ordning för översiktens paneler, se
 * [[ADR-0073 Översiktens ordning]] § 7 och 9. Två rutter bakom `auth`, utan
 * person-id och utan modellbindning: raden är alltid den inloggades.
 */
class OverviewPanelController extends Controller
{
    public function update(UpdateOverviewPanelsRequest $request, SaveOverviewPanels $save): RedirectResponse
    {
        /** @var list<array{key: string, width: string}> $panels */
        $panels = $request->validated('panels');

        $save->handle($request->user(), array_map(
            fn (array $panel): array => ['key' => $panel['key'], 'width' => $panel['width']],
            $panels,
        ));

        return back();
    }

    public function destroy(Request $request, SaveOverviewPanels $save): RedirectResponse
    {
        $save->reset($request->user());

        return back();
    }
}
