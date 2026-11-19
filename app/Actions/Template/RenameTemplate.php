<?php

namespace App\Actions\Template;

use App\Models\ContainerTemplate;
use App\Support\Usage\MetadataLedger;
use Illuminate\Support\Facades\DB;

/**
 * Döper om en mall, se issue 421 § 3 och [[ADR-0089 Egna fält på items]]
 * § Riktning 6.
 *
 * Raden skrivs genom App\Support\Usage\MetadataLedger så att
 * `metadata_bytes` följer namnbytet — namnet är fritext och räknas
 * ([[ADR-0081 All lagrad data räknas mot kvoten]] § 2). Bara levande mallar
 * går att döpa om, så raden väger som vanligt; en slängd mall väger noll
 * (se App\Support\Usage\MetadataSize).
 *
 * **Ingen `Gate::authorize()`.** Behörigheten prövas av anroparen; mallen nås
 * bara genom App\Models\ContainerTemplate::scopeMemberOf() i routebindningen,
 * så den som får tag i raden är medlem i ägarkontot (issue 421 § 4).
 */
final class RenameTemplate
{
    public function handle(ContainerTemplate $template, string $name): void
    {
        DB::transaction(function () use ($template, $name): void {
            $template->name = trim($name);

            MetadataLedger::save($template);
        });
    }
}
