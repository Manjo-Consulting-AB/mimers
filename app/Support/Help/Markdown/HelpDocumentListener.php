<?php

namespace App\Support\Help\Markdown;

use Illuminate\Support\Str;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use League\CommonMark\Node\NodeIterator;
use League\CommonMark\Node\StringContainerInterface;

/**
 * Gör om det tolkade dokumentet till hjälpcentrets former — M80 · issue 463,
 * [[ADR-0094 Hjälpcentret]] § 4.
 *
 * Lyssnaren går igenom dokumentet en gång, i fast ordning:
 *
 * 1. **Rubrikankare.** Varje `##` och `###` får ett `id`. `{#eget-id}` sist på
 *    raden har redan satts av `AttributesExtension`, som lyssnar före den här
 *    (samma prioritet, registrerad tidigare — se HelpMarkdownExtension); annars
 *    blir det `Str::slug` av rubrikens text. Krockar två id:n får den andra
 *    `-2`, sedan `-3`. Rubrikerna samlas i ordning, för innehållsförteckningen
 *    och sökningen.
 * 2. **Faktarutor.** Ett citat vars första rad är exakt `[!NOTE]`, `[!TIP]`
 *    eller `[!WARNING]` blir en HelpCallout i stället för `<blockquote>`.
 * 3. **Video.** Ett stycke som bara består av en YouTube-länk blir en
 *    HelpVideo. Länken mitt i en mening förblir en länk.
 * 4. **Bilder.** Bara `help-media/…` ritas, med `loading="lazy"`. En bild med
 *    en annan adress tas bort helt — också ur dokumentet, så att ett stycke
 *    som bara var en bild inte lämnar ett tomt `<p>`.
 * 5. **Interna länkar.** `help:{id}` och `help:{id}#{ankare}` blir
 *    `/help/articles/{id}` och `/help/articles/{id}#{ankare}`. Okänt id ritas
 *    ändå; det är HelpValidator som fäller det.
 *
 * Noderna samlas i en lista innan de ändras. Nya noder (faktarutan, videon)
 * flyttar in befintliga barn, så länkarna och bilderna i dem bearbetas
 * fortfarande — de ligger kvar i listan, oavsett vilken förälder de har fått.
 */
final class HelpDocumentListener
{
    /** Första raden i citatet → faktarutans sort. */
    private const CALLOUTS = [
        '[!NOTE]' => 'note',
        '[!TIP]' => 'tip',
        '[!WARNING]' => 'warning',
    ];

    /** En bildadress som får ritas: bara filer under `public/help-media/`. */
    private const MEDIA = '~^help-media/[A-Za-z0-9._/-]+$~';

    public function __construct(private readonly HelpCollector $collector) {}

    public function __invoke(DocumentParsedEvent $event): void
    {
        /** @var list<Node> $nodes */
        $nodes = iterator_to_array($event->getDocument()->iterator(), false);

        $this->headings($nodes);
        $this->callouts($nodes);
        $this->videos($nodes);
        $this->images($nodes);
        $this->links($nodes);
    }

    /**
     * Sätter id på varje rubrik och samlar dem i dokumentordning.
     *
     * @param  list<Node>  $nodes
     */
    private function headings(array $nodes): void
    {
        /** @var array<string, true> $used */
        $used = [];

        foreach ($nodes as $node) {
            if (! $node instanceof Heading) {
                continue;
            }

            $title = $this->inlineText($node);
            $attributes = $node->data->get('attributes');
            $explicit = is_array($attributes) ? ($attributes['id'] ?? null) : null;

            $base = is_string($explicit) && trim($explicit) !== ''
                ? trim($explicit)
                : (Str::slug($title) ?: 'rubrik');

            $id = $base;
            $suffix = 1;

            while (isset($used[$id])) {
                $suffix++;
                $id = $base.'-'.$suffix;
            }

            $used[$id] = true;
            $node->data->set('attributes/id', $id);

            $this->collector->addHeading($id, $title, $node->getLevel());
        }
    }

    /**
     * Gör om citat med en faktaruta-markör till HelpCallout.
     *
     * @param  list<Node>  $nodes
     */
    private function callouts(array $nodes): void
    {
        foreach ($nodes as $node) {
            if (! $node instanceof BlockQuote) {
                continue;
            }

            $kind = $this->calloutKind($node);

            if ($kind === null) {
                continue;
            }

            $callout = new HelpCallout($kind);

            while (($child = $node->firstChild()) !== null) {
                $callout->appendChild($child);
            }

            $node->replaceWith($callout);

            $this->stripMarker($callout);
        }
    }

    /** Faktarutans sort, om citatets första rad är en markör. */
    private function calloutKind(BlockQuote $quote): ?string
    {
        $paragraph = $quote->firstChild();

        if (! $paragraph instanceof Paragraph) {
            return null;
        }

        $first = $paragraph->firstChild();

        if (! $first instanceof Text) {
            return null;
        }

        return self::CALLOUTS[trim($first->getLiteral())] ?? null;
    }

    /**
     * Tar bort markörraden ur faktarutans första stycke.
     *
     * Raden är en egen textnod följd av en mjuk radbrytning. Blir stycket tomt
     * av det — ett citat med bara markören — tas det bort, så rutan inte ritar
     * ett tomt stycke under rubriken.
     */
    private function stripMarker(HelpCallout $callout): void
    {
        $paragraph = $callout->firstChild();

        if (! $paragraph instanceof Paragraph) {
            return;
        }

        $first = $paragraph->firstChild();

        if (! $first instanceof Text) {
            return;
        }

        $next = $first->next();
        $first->detach();

        if ($next instanceof Newline) {
            $next->detach();
        }

        if (! $paragraph->hasChildren()) {
            $paragraph->detach();
        }
    }

    /**
     * Gör ett stycke med bara en YouTube-länk till HelpVideo.
     *
     * @param  list<Node>  $nodes
     */
    private function videos(array $nodes): void
    {
        foreach ($nodes as $node) {
            if (! $node instanceof Paragraph) {
                continue;
            }

            $link = $node->firstChild();

            if (! $link instanceof Link || $link !== $node->lastChild()) {
                continue;
            }

            $id = $this->youtubeId($link->getUrl());

            if ($id === null) {
                continue;
            }

            $video = new HelpVideo($id);

            while (($child = $link->firstChild()) !== null) {
                $video->appendChild($child);
            }

            $node->replaceWith($video);
        }
    }

    /**
     * YouTube-id:t ur de två adressformer ADR:en tillåter, annars null.
     */
    private function youtubeId(string $url): ?string
    {
        $patterns = [
            '~^https://www\.youtube\.com/watch\?v=([A-Za-z0-9_-]{11})$~',
            '~^https://youtu\.be/([A-Za-z0-9_-]{11})$~',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * Ritar bilder ur `public/help-media/` och tar bort alla andra.
     *
     * @param  list<Node>  $nodes
     */
    private function images(array $nodes): void
    {
        foreach ($nodes as $node) {
            if (! $node instanceof Image) {
                continue;
            }

            $src = $node->getUrl();
            $this->collector->addImage($src, $this->inlineText($node));

            if (! $this->isMedia($src)) {
                $parent = $node->parent();
                $node->detach();

                if ($parent instanceof Paragraph && ! $parent->hasChildren()) {
                    $parent->detach();
                }

                continue;
            }

            $node->setUrl('/'.$src);
            $node->data->set('attributes/loading', 'lazy');
        }
    }

    /** Ligger adressen under `public/help-media/` utan att kunna lämna den? */
    private function isMedia(string $src): bool
    {
        return preg_match(self::MEDIA, $src) === 1 && ! str_contains($src, '..');
    }

    /**
     * Skriver om `help:`-länkarna och samlar målen för valideringen.
     *
     * @param  list<Node>  $nodes
     */
    private function links(array $nodes): void
    {
        foreach ($nodes as $node) {
            if (! $node instanceof Link) {
                continue;
            }

            $url = $node->getUrl();

            if (! str_starts_with($url, 'help:')) {
                continue;
            }

            $target = substr($url, 5);
            $this->collector->addHelpLink($target);

            $parts = explode('#', $target, 2);
            $anchor = $parts[1] ?? '';

            $node->setUrl('/help/articles/'.$parts[0].($anchor !== '' ? '#'.$anchor : ''));
        }
    }

    /**
     * Textens innehåll utan formatering — rubrikens titel, bildens alt-text.
     *
     * Radbrytningar blir blanksteg och upprepade blanksteg slås ihop, så att
     * titeln i rubriklistan är samma mening som sidan visar.
     */
    private function inlineText(Node $node): string
    {
        $text = '';

        foreach (new NodeIterator($node) as $child) {
            if ($child instanceof StringContainerInterface) {
                $text .= $child->getLiteral();
            } elseif ($child instanceof Newline) {
                $text .= ' ';
            }
        }

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
