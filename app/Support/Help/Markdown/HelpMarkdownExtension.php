<?php

namespace App\Support\Help\Markdown;

use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\ExtensionInterface;

/**
 * Kopplar hjälpcentrets rendering till league/commonmark — M80 · issue 463,
 * [[ADR-0094 Hjälpcentret]] § 4.
 *
 * Tillägget registrerar lyssnaren och de två egna nodtyperna; inga nya paket.
 * Det läggs efter `AttributesExtension` i HelpMarkdown, eftersom båda lyssnar
 * på `DocumentParsedEvent` med samma prioritet och registreringsordningen
 * avgör: attributlyssnaren måste ha satt `attributes/id` från `{#eget-id}`
 * innan rubriklyssnaren räknar ut resten.
 */
final class HelpMarkdownExtension implements ExtensionInterface
{
    public function __construct(private readonly HelpCollector $collector) {}

    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment->addEventListener(DocumentParsedEvent::class, new HelpDocumentListener($this->collector));
        $environment->addRenderer(HelpCallout::class, new HelpCalloutRenderer);
        $environment->addRenderer(HelpVideo::class, new HelpVideoRenderer);
    }
}
