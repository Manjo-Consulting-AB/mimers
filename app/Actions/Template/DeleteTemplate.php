<?php

namespace App\Actions\Template;

use App\Models\ContainerTemplate;
use App\Support\Usage\MetadataLedger;
use Illuminate\Support\Facades\DB;

/**
 * Tar bort en mall med mjukradering, se issue 421 § 3.
 *
 * **Borttagningen släpper mallens byte**, till skillnad från annat
 * mjukraderat ([[ADR-0081 All lagrad data räknas mot kvoten]] § 3). En mall
 * går inte att återställa och har inget gallringsjobb — papperskorgen får
 * ingen ny typ ([[ADR-0008 Soft delete och papperskorg]] § Uppföljning
 * 2026-09-26) — så byte som räknades vidare hade aldrig släppts. Användaren
 * hade då betalat för något hon varken ser eller kan ta tillbaka.
 * App\Support\Usage\MetadataSize ger därför en slängd mall vikten noll, och
 * App\Support\Usage\CountedRows räknar bara levande mallar, så avstämningen
 * säger samma sak som räknaren.
 *
 * Mjukraderingen står kvar som skydd mot buggar, som egenskaperna i samma
 * tabell: raden försvinner med kontot.
 *
 * **Ingen `Gate::authorize()`.** Behörigheten prövas av routebindningen, se
 * App\Models\ContainerTemplate::scopeMemberOf() och issue 421 § 4.
 */
final class DeleteTemplate
{
    public function handle(ContainerTemplate $template): void
    {
        DB::transaction(function () use ($template): void {
            // Före raderingen: release() läser raden under lås och minskar
            // betalaren med dess vikt. Bokförs i samma transaktion som
            // deleted_at sätts, så räknaren och raden aldrig glider isär.
            MetadataLedger::release($template);

            $template->delete();
        });
    }
}
