<?php

namespace App\Support\Help;

use App\Support\Help\Markdown\HelpCollector;
use App\Support\Help\Markdown\HelpMarkdownExtension;
use Illuminate\Support\Str;
use League\CommonMark\Extension\Attributes\AttributesExtension;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;
use League\CommonMark\Extension\Table\TableExtension;

/**
 * Ritar hjälpartiklarnas markdown — M80 · issue 463,
 * [[ADR-0094 Hjälpcentret]] § 4.
 *
 * Samma regler som hjälpsidan alltid har haft: `html_input: strip`, så rå HTML
 * som `<!-- video -->` eller `<script>` försvinner, och
 * `allow_unsafe_links: false`, så en `javascript:`-adress aldrig blir en
 * `href`. `TableExtension` behövs för pristabellen. **Samma princip som
 * App\Actions\Item\RenderItemNotes**, [[ADR-0066 Itemets anteckning]] § 2.
 *
 * Utöver grunden ritar `render()` det ADR-0094 § 4 kräver:
 *
 * - rubrikankare, med `{#eget-id}` via `AttributesExtension` och `Str::slug`
 *   av titeln annars; krockande id:n får `-2`, `-3`,
 * - faktarutor `> [!NOTE]`, `> [!TIP]` och `> [!WARNING]`,
 * - bilder ur `public/help-media/`, med `loading="lazy"`; andra adresser ritas
 *   inte alls,
 * - YouTube-video som en länk ensam i ett stycke, utan `iframe`,
 * - interna `help:`-länkar till `/help/articles/…`,
 * - externa länkar i en ny flik med `rel="noopener noreferrer"`.
 *
 * Tilläggen och lyssnarna bor i App\Support\Help\Markdown; inga nya paket
 * behövs. `render()` lämnar också den rena texten och rubrikerna, för
 * sökningen och innehållsförteckningen. `toHtml()` är kvar som ett tunt
 * omslag, så att 461:s anropare inte behöver ändras; tom text ger `null` där,
 * så en kategori utan ingress skiljs från en som ritas till tom HTML.
 *
 * `scan()` läser samma dokumentträd efter länkarna och bilderna, åt
 * HelpValidator. Ett reguljärt uttryck över råtexten hade fällt en `help:`-länk
 * som står som exempel i ett kodblock.
 */
final class HelpMarkdown
{
    public function toHtml(string $markdown): ?string
    {
        if (trim($markdown) === '') {
            return null;
        }

        return $this->render($markdown)->html;
    }

    /** Ritar markdownen, den rena texten och rubrikerna. */
    public function render(string $markdown): HelpRendered
    {
        if (trim($markdown) === '') {
            return new HelpRendered('', '', []);
        }

        $collector = new HelpCollector;
        $html = $this->convert($markdown, $collector);

        return new HelpRendered($html, $this->text($html), $collector->headings());
    }

    /** Länkarna och bilderna i markdownen, för HelpValidator. */
    public function scan(string $markdown): HelpReferences
    {
        if (trim($markdown) === '') {
            return new HelpReferences([], []);
        }

        $collector = new HelpCollector;
        $this->convert($markdown, $collector);

        return new HelpReferences($collector->helpLinks(), $collector->images());
    }

    private function convert(string $markdown, HelpCollector $collector): string
    {
        return Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'external_link' => [
                'internal_hosts' => $this->internalHosts(),
                'open_in_new_window' => true,
                'nofollow' => '',
                'noopener' => 'external',
                'noreferrer' => 'external',
            ],
        ], [
            new TableExtension,
            new AttributesExtension,
            new ExternalLinkExtension,
            new HelpMarkdownExtension($collector),
        ]);
    }

    /**
     * Appens egen värd, så att en länk dit inte öppnas i en ny flik.
     *
     * @return list<string>
     */
    private function internalHosts(): array
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? [$host] : [];
    }

    /**
     * Den ritade HTML:en som ren text — utan taggar, blanksteg ihopslagna.
     *
     * Entiteterna avkodas efter `strip_tags`, så att `&amp;` blir `&` också i
     * sökningen. Sökningen (M81) ska matcha samma tecken användaren ser.
     */
    private function text(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
