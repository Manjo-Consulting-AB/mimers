<?php

namespace App\Support\Help\Markdown;

/**
 * Det en ritning samlar medan dokumentet bearbetas — M80 · issue 463.
 *
 * Lyssnaren fyller den med rubrikerna (id, titel och nivå), de interna länkarna
 * och bilderna. HelpMarkdown skapar en ny samlare per ritning, så två
 * ritningar aldrig blandar sina rubriker. Klassen är intern för renderingen och
 * bär inga regler; det som ska hända med det insamlade står i
 * HelpDocumentListener och HelpMarkdown.
 */
final class HelpCollector
{
    /** @var list<array{id: string, title: string, level: int}> */
    private array $headings = [];

    /** @var list<string> */
    private array $helpLinks = [];

    /** @var list<array{src: string, alt: string}> */
    private array $images = [];

    public function addHeading(string $id, string $title, int $level): void
    {
        $this->headings[] = ['id' => $id, 'title' => $title, 'level' => $level];
    }

    /** @return list<array{id: string, title: string, level: int}> */
    public function headings(): array
    {
        return $this->headings;
    }

    public function addHelpLink(string $target): void
    {
        $this->helpLinks[] = $target;
    }

    /** @return list<string> */
    public function helpLinks(): array
    {
        return $this->helpLinks;
    }

    public function addImage(string $src, string $alt): void
    {
        $this->images[] = ['src' => $src, 'alt' => $alt];
    }

    /** @return list<array{src: string, alt: string}> */
    public function images(): array
    {
        return $this->images;
    }
}
