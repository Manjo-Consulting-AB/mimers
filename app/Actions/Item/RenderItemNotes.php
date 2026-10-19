<?php

namespace App\Actions\Item;

use Illuminate\Support\Str;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;

/**
 * Ritar itemets anteckning som HTML — [[ADR-0066 Itemets anteckning]] § 2.
 *
 * Samma ritning används av sidan och av svaret när anteckningen sparas, så de
 * två aldrig kan visa olika saker för samma text.
 *
 * **HTML i texten ritas som text.** `html_input: escape` låter användaren
 * skriva `<script>` utan att webbläsaren kör det; saneringen ligger i
 * ritningen och aldrig i klienten (ADR-0066 § 2 och 3).
 *
 * **Osäkra länkar blir inga länkar.** `allow_unsafe_links: false` gör att en
 * `javascript:`-adress renderas som text och aldrig som `href`.
 *
 * **Externa länkar öppnas i en ny flik** med `rel="noopener noreferrer
 * nofollow"`, så att en anteckning inte kan använda sidan som mellanhand.
 *
 * En tom eller blank anteckning ger `null` — `Str::markdown('')` hade gett en
 * tom sträng, men `null` är det svar vyn ska kunna skilja från en ruta med
 * innehåll.
 */
class RenderItemNotes
{
    public function handle(?string $notes): ?string
    {
        if ($notes === null || trim($notes) === '') {
            return null;
        }

        return Str::markdown($notes, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'external_link' => [
                'internal_hosts' => [],
                'open_in_new_window' => true,
                'nofollow' => 'external',
                'noopener' => 'external',
                'noreferrer' => 'external',
            ],
        ], [
            new ExternalLinkExtension,
        ]);
    }
}
