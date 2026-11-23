<?php

namespace App\Support\Help;

/**
 * En ritad hjälpartikel — M80 · issue 463, [[ADR-0094 Hjälpcentret]] § 4.
 *
 * `html` är det sidan visar med `v-html`, `text` är samma innehåll som ren text
 * för sökningen (M81), och `headings` är rubrikerna i dokumentordning med sina
 * id:n, så att en innehållsförteckning och ett `#ankare` pekar på exakt det id
 * rubriken fick.
 *
 * **Cachen bär arrayer, inte objekt.** `toArray()` skriver formen och
 * `fromArray()` bygger tillbaka den, av samma skäl som i HelpArticle:
 * `config/cache.php` tillåter inte att klasser packas upp
 * (`serializable_classes => false`), så en cachad HelpRendered hade kommit
 * tillbaka som `__PHP_Incomplete_Class` ur en butik som serialiserar värdet.
 */
final readonly class HelpRendered
{
    /**
     * @param  list<array{id: string, title: string, level: int}>  $headings  Rubrikerna i dokumentordning.
     */
    public function __construct(
        public string $html,
        public string $text,
        public array $headings,
    ) {}

    /**
     * Resultatet som bara arrayer och skalärer — det som går i cachen.
     *
     * @return array{html: string, text: string, headings: list<array{id: string, title: string, level: int}>}
     */
    public function toArray(): array
    {
        return [
            'html' => $this->html,
            'text' => $this->text,
            'headings' => $this->headings,
        ];
    }

    /**
     * Bygger tillbaka resultatet ur `toArray()`-formen.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $headings = [];

        foreach (is_array($data['headings'] ?? null) ? $data['headings'] : [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $headings[] = [
                'id' => (string) ($row['id'] ?? ''),
                'title' => (string) ($row['title'] ?? ''),
                'level' => (int) ($row['level'] ?? 2),
            ];
        }

        return new self(
            (string) ($data['html'] ?? ''),
            (string) ($data['text'] ?? ''),
            $headings,
        );
    }
}
