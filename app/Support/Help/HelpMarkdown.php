<?php

namespace App\Support\Help;

use Illuminate\Support\Str;
use League\CommonMark\Extension\Table\TableExtension;

/**
 * Ritar hjälpartiklarnas markdown — M80 · issue 461,
 * [[ADR-0094 Hjälpcentret]] § 4.
 *
 * Samma regler som hjälpsidan alltid har haft: `html_input: strip`, så rå HTML
 * som `<!-- video -->` försvinner, och `allow_unsafe_links: false`, så en
 * `javascript:`-adress aldrig blir en `href`. `TableExtension` behövs för
 * pristabellen. Tom text ger `null`, så en kategori utan ingress skiljs från
 * en som ritas till tom HTML.
 *
 * **Samma princip som App\Actions\Item\RenderItemNotes**,
 * [[ADR-0066 Itemets anteckning]] § 2. 463 bygger ut renderingen med ankare,
 * faktarutor, bilder och video; reglerna här är den grunden.
 */
final class HelpMarkdown
{
    public function toHtml(string $markdown): ?string
    {
        if (trim($markdown) === '') {
            return null;
        }

        return Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ], [new TableExtension]);
    }
}
