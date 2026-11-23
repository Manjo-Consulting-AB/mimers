<?php

namespace App\Support\Help;

use Carbon\CarbonImmutable;

/**
 * En hjälpartikel ur en markdownfil med front matter — M80 · issue 460,
 * [[ADR-0094 Hjälpcentret]] § 1.
 *
 * Klassen är ett readonly värdeobjekt och bär inga regler: det som får stå i
 * front mattern prövas av App\Support\Help\HelpValidator, och det som ska
 * visas väljs av App\Support\Help\HelpCatalog. Fälten motsvarar ADR-0094 § 1
 * ett till ett, med två undantag:
 *
 * - `contexts` är front matterns `context`: en lista, inte ett enskilt värde.
 *   Ett sammanhang får bära ett `#ankare`, till exempel
 *   `account.security#two-factor`.
 * - `markdown` är filens kropp utan front matter, orörd. Renderingen hör till
 *   463 och sker inte här.
 *
 * **`updated` är en CarbonImmutable**, inte en sträng. Datumet skrivs för hand
 * i front mattern som ett quotat `YYYY-MM-DD` (utrullningen saknar `.git`), men
 * den som renderar artikeln vill kunna formatera det per språk, och då är ett
 * datum en bättre bärare än en sträng.
 *
 * **Cachen bär arrayer, inte objekt.** `toArray()` skriver `updated` som
 * `Y-m-d`, och `fromArray()` bygger tillbaka objektet. Skälet är att
 * `config/cache.php` inte tillåter att klasser packas upp
 * (`serializable_classes => false`): en cachad CarbonImmutable kommer tillbaka
 * som `__PHP_Incomplete_Class` ur en riktig butik.
 *
 * `locale` är katalogen filen lästes ur, inte ett fält i front mattern
 * (ADR-0094 § 2).
 */
final readonly class HelpArticle
{
    /**
     * @param  list<string>  $keywords  Nyckelord ur front mattern.
     * @param  list<string>  $related  Id:n på relaterade artiklar.
     * @param  list<string>  $contexts  Sammanhang, med valfritt `#ankare`.
     * @param  string  $status  `published` eller `draft`.
     * @param  bool  $featured  Ska artikeln lyftas fram på startsidan?
     * @param  int  $order  Ordning inom kategorin; lika ordnas på titel.
     * @param  string  $markdown  Kroppen utan front matter.
     * @param  string  $locale  Katalogen artikeln lästes ur.
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $type,
        public string $category,
        public ?string $description,
        public array $keywords,
        public array $related,
        public array $contexts,
        public string $status,
        public CarbonImmutable $updated,
        public bool $featured,
        public int $order,
        public string $markdown,
        public string $locale,
    ) {}

    /** Är artikeln ett utkast? Katalogen avgör om utkastet syns. */
    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    /**
     * Artikeln som bara arrayer och skalärer — det som går i cachen.
     *
     * @return array{
     *     id: string,
     *     title: string,
     *     type: string,
     *     category: string,
     *     description: ?string,
     *     keywords: list<string>,
     *     related: list<string>,
     *     contexts: list<string>,
     *     status: string,
     *     updated: string,
     *     featured: bool,
     *     order: int,
     *     markdown: string,
     *     locale: string,
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'type' => $this->type,
            'category' => $this->category,
            'description' => $this->description,
            'keywords' => $this->keywords,
            'related' => $this->related,
            'contexts' => $this->contexts,
            'status' => $this->status,
            'updated' => $this->updated->format('Y-m-d'),
            'featured' => $this->featured,
            'order' => $this->order,
            'markdown' => $this->markdown,
            'locale' => $this->locale,
        ];
    }

    /**
     * Bygger tillbaka artikeln ur `toArray()`-formen.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            title: (string) $data['title'],
            type: (string) $data['type'],
            category: (string) $data['category'],
            description: isset($data['description']) ? (string) $data['description'] : null,
            keywords: self::strings($data['keywords'] ?? []),
            related: self::strings($data['related'] ?? []),
            contexts: self::strings($data['contexts'] ?? []),
            status: (string) $data['status'],
            updated: CarbonImmutable::parse((string) $data['updated'])->startOfDay(),
            featured: (bool) $data['featured'],
            order: (int) $data['order'],
            markdown: (string) $data['markdown'],
            locale: (string) $data['locale'],
        );
    }

    /**
     * En lista av strängar ur ett godtyckligt värde.
     *
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $item) {
            if (is_scalar($item)) {
                $strings[] = (string) $item;
            }
        }

        return $strings;
    }
}
