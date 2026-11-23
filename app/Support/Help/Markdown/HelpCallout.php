<?php

namespace App\Support\Help\Markdown;

use League\CommonMark\Node\Block\AbstractBlock;

/**
 * En faktaruta ur GitHubs form — M80 · issue 463, [[ADR-0094 Hjälpcentret]] § 4.
 *
 * Noden ersätter det `<blockquote>` som markdownen gav. `kind` är `note`,
 * `tip` eller `warning`; rubriken hämtas ur `lang/en/ui.php` av renderaren, så
 * ordet aldrig står i markdownen.
 */
final class HelpCallout extends AbstractBlock
{
    public function __construct(private readonly string $kind)
    {
        parent::__construct();
    }

    /** `note`, `tip` eller `warning`. */
    public function kind(): string
    {
        return $this->kind;
    }
}
