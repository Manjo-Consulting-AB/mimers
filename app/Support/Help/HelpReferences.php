<?php

namespace App\Support\Help;

/**
 * Länkarna och bilderna i en hjälpartikels markdown — M80 · issue 463.
 *
 * HelpMarkdown::scan() läser dem ur dokumentträdet, inte ur texten med ett
 * reguljärt uttryck, så att en `help:`-länk i ett kodblock eller en bild i en
 * kodrad aldrig blir ett falskt fynd. HelpValidator prövar sedan varje mål.
 *
 * `helpLinks` bär målet efter `help:` orört, till exempel
 * `how-do-i-share-a-container#step-2`, så att valideringen kan skilja id:t från
 * ankaret. `images` bär adressen som den står i markdownen och alt-texten som
 * den skulle ritas.
 */
final readonly class HelpReferences
{
    /**
     * @param  list<string>  $helpLinks  Målen efter `help:`, orörda.
     * @param  list<array{src: string, alt: string}>  $images
     */
    public function __construct(
        public array $helpLinks,
        public array $images,
    ) {}
}
