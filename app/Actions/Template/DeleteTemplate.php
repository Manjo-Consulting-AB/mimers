<?php

namespace App\Actions\Template;

use App\Models\ContainerTemplate;

/**
 * Tar bort en mall med mjukradering, se issue 421 § 3.
 *
 * Raden räknas fortfarande mot kvoten efter borttagningen — det är regeln för
 * allt mjukraderat ([[ADR-0081 All lagrad data räknas mot kvoten]] § 3) — och
 * därför anropas ingen `MetadataLedger::release()`. Mallen upptar ingen av de
 * 50 platserna, eftersom taket bara räknar levande mallar.
 *
 * **Ingen `Gate::authorize()`.** Behörigheten prövas av routebindningen, se
 * App\Models\ContainerTemplate::scopeMemberOf() och issue 421 § 4.
 */
final class DeleteTemplate
{
    public function handle(ContainerTemplate $template): void
    {
        $template->delete();
    }
}
