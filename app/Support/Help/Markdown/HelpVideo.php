<?php

namespace App\Support\Help\Markdown;

use League\CommonMark\Node\Block\AbstractBlock;

/**
 * En YouTube-video som en länk ensam i ett stycke — M80 · issue 463,
 * [[ADR-0094 Hjälpcentret]] § 4.
 *
 * Noden ritar ingen spelare. Den bär videons id, och renderaren gör en
 * `<figure>` med en vanlig länk; 464 byter in en `iframe` mot
 * `youtube-nocookie.com` först när användaren klickar. Fram till dess
 * kontaktas ingen tredje part och sätts ingen kaka.
 */
final class HelpVideo extends AbstractBlock
{
    public function __construct(private readonly string $videoId)
    {
        parent::__construct();
    }

    /** YouTube-id:t, elva tecken ur `[A-Za-z0-9_-]`. */
    public function videoId(): string
    {
        return $this->videoId;
    }
}
