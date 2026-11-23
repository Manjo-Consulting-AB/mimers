<?php

namespace App\Support\Help;

use Illuminate\Support\Facades\Cache;

/**
 * Det inlästa hjälpinnehållet för ett språk — M80 · issue 460,
 * [[ADR-0094 Hjälpcentret]] § 1 och § 2.
 *
 * Katalogen är den färdiga bilden av `categories.yaml`, `synonyms.yaml` och
 * artikelfilerna, redan sammanfogad med engelskan när språket är ett annat.
 * Den bär inga filvägar och ingen cache: App\Support\Help\HelpLibrary bygger
 * den och cachar den, och klassens uppgift är att svara på frågor om
 * innehållet.
 *
 * **Utkast syns bara utanför produktion.** En artikel med `status: draft` ingår
 * bara när `app()->environment('production')` är falskt, så testarna på
 * staging kan läsa dem medan produktionssajten aldrig visar dem. Regeln sitter
 * i katalogen, inte i cachens nyckel, eftersom katalogen cachas med utkasten i
 * och miljön är densamma under hela processens liv.
 *
 * **Ordningen är bestämd.** `articles()` sorterar på kategorins plats i
 * `categories.yaml`, sedan artikelns `order` och sist titeln (ADR-0094 § 1).
 * Lika `order` ordnas alltså på titel, och sorteringen är stabil.
 *
 * **Ett id förekommer en gång.** När en översättning är ett utkast har
 * HelpLibrary lagt den före engelskan, så utkastet syns utanför produktion och
 * engelskan tar över i produktion. `articles()` och `article()` väljer den
 * första synliga posten per id, alltså aldrig två.
 *
 * **Cachen bär arrayer, inte objekt.** HelpLibrary cachar `toArray()`-formen
 * och bygger katalogen med `fromArray()` efter hämtningen, eftersom
 * `config/cache.php` inte tillåter att klasser packas upp
 * (`serializable_classes => false`).
 */
final class HelpCatalog
{
    /**
     * @param  list<HelpCategory>  $categories  I filordning.
     * @param  list<HelpArticle>  $articles  Alla artiklar, också utkast.
     * @param  list<list<string>>  $synonyms  Grupper av ord som söks som samma ord.
     */
    public function __construct(
        private readonly array $categories,
        private readonly array $articles,
        private readonly array $synonyms,
    ) {}

    /**
     * Kategorierna i filordning — ordningen på sidan.
     *
     * @return list<HelpCategory>
     */
    public function categories(): array
    {
        return $this->categories;
    }

    /** Kategorin med id:t, eller null om det inte finns någon. */
    public function category(string $id): ?HelpCategory
    {
        foreach ($this->categories as $category) {
            if ($category->id === $id) {
                return $category;
            }
        }

        return null;
    }

    /**
     * Alla synliga artiklar, ordnade på kategori, `order` och titel.
     *
     * Ett id som finns både som utkast och som publik post ger bara den första
     * synliga posten — i praktiken översättningen utanför produktion och
     * engelskan i produktion.
     *
     * @return list<HelpArticle>
     */
    public function articles(): array
    {
        $seen = [];
        $articles = [];

        foreach ($this->articles as $article) {
            if (! $this->visible($article) || isset($seen[$article->id])) {
                continue;
            }

            $seen[$article->id] = true;
            $articles[] = $article;
        }

        /** @var array<string, int> $position */
        $position = [];

        foreach ($this->categories as $category) {
            $position[$category->id] = $category->order;
        }

        usort($articles, static fn (HelpArticle $a, HelpArticle $b): int => [
            $position[$a->category] ?? PHP_INT_MAX,
            $a->order,
            mb_strtolower($a->title),
        ] <=> [
            $position[$b->category] ?? PHP_INT_MAX,
            $b->order,
            mb_strtolower($b->title),
        ]);

        return $articles;
    }

    /** Artikeln med id:t, eller null om den saknas eller är ett osynligt utkast. */
    public function article(string $id): ?HelpArticle
    {
        foreach ($this->articles as $article) {
            if ($article->id === $id && $this->visible($article)) {
                return $article;
            }
        }

        return null;
    }

    /**
     * Artikeln som bär sammanhanget, med ankaret — M81 · issue 468,
     * [[ADR-0094 Hjälpcentret]] § 6.
     *
     * Ett sammanhang i front mattern får bära ett `#ankare`
     * (`account.security#two-factor`). Ankaret hör till värdet och inte till
     * adressen, så uppslaget jämför bara delen före `#` och lämnar ankaret
     * tillbaka till den som bygger omdirigeringen.
     *
     * Katalogen söks i `articles()`-ordning, alltså bara synliga artiklar: ett
     * utkast svarar null i produktion, på samma sätt som `article()`. Ett
     * sammanhang förekommer en gång per språk (HelpValidator), så den första
     * träffen är den enda.
     *
     * @return array{article: HelpArticle, anchor: ?string}|null
     */
    public function byContext(string $context): ?array
    {
        foreach ($this->articles() as $article) {
            foreach ($article->contexts as $candidate) {
                $parts = explode('#', $candidate, 2);

                if ($parts[0] !== $context) {
                    continue;
                }

                return [
                    'article' => $article,
                    'anchor' => $parts[1] ?? null,
                ];
            }
        }

        return null;
    }

    /**
     * Kategorins artiklar, i samma ordning som `articles()`.
     *
     * @return list<HelpArticle>
     */
    public function inCategory(string $id): array
    {
        return array_values(array_filter(
            $this->articles(),
            static fn (HelpArticle $article): bool => $article->category === $id,
        ));
    }

    /**
     * Artiklarna som ska lyftas fram på startsidan, i `articles()`-ordning.
     *
     * @return list<HelpArticle>
     */
    public function featured(): array
    {
        return array_values(array_filter(
            $this->articles(),
            static fn (HelpArticle $article): bool => $article->featured,
        ));
    }

    /**
     * Synonymerna ur `synonyms.yaml` — varje grupp söks som samma ord.
     *
     * @return list<list<string>>
     */
    public function synonyms(): array
    {
        return $this->synonyms;
    }

    /**
     * Den ritade artikeln — M80 · issue 463, [[ADR-0094 Hjälpcentret]] § 4.
     *
     * Resultatet av `HelpMarkdown::render()` läggs i cachen per artikel, så att
     * ingen artikel ritas två gånger: en kategorisida som visar samma artikel
     * som artikelsidan ritar den en gång, och en andra sidvisning läser cachen.
     *
     * **Nyckeln är innehållet, inte id:t.** Markdownens text hashas tillsammans
     * med språk och id, så en ändrad artikel får en ny nyckel och den gamla
     * blir bara liggande — samma princip som HelpLibrarys fingeravtryck.
     *
     * **Cachen bär arrayer.** `HelpRendered::toArray()` skrivs och
     * `fromArray()` bygger tillbaka, eftersom `config/cache.php` inte tillåter
     * att klasser packas upp (`serializable_classes => false`).
     */
    public function rendered(HelpArticle $article): HelpRendered
    {
        $key = 'help:rendered:'.hash('xxh128', $article->locale."\0".$article->id."\0".$article->markdown);

        /** @var array{html: string, text: string, headings: list<array{id: string, title: string, level: int}>} $data */
        $data = Cache::rememberForever(
            $key,
            static fn (): array => (new HelpMarkdown)->render($article->markdown)->toArray(),
        );

        return HelpRendered::fromArray($data);
    }

    /**
     * Katalogen som bara arrayer och skalärer — det som går i cachen.
     *
     * @return array{
     *     categories: list<array<string, mixed>>,
     *     articles: list<array<string, mixed>>,
     *     synonyms: list<list<string>>,
     * }
     */
    public function toArray(): array
    {
        return [
            'categories' => array_map(
                static fn (HelpCategory $category): array => $category->toArray(),
                $this->categories,
            ),
            'articles' => array_map(
                static fn (HelpArticle $article): array => $article->toArray(),
                $this->articles,
            ),
            'synonyms' => $this->synonyms,
        ];
    }

    /**
     * Bygger tillbaka katalogen ur `toArray()`-formen.
     *
     * @param  array{
     *     categories?: list<array<string, mixed>>,
     *     articles?: list<array<string, mixed>>,
     *     synonyms?: list<list<string>>,
     * }  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            array_map(
                static fn (array $row): HelpCategory => HelpCategory::fromArray($row),
                $data['categories'] ?? [],
            ),
            array_map(
                static fn (array $row): HelpArticle => HelpArticle::fromArray($row),
                $data['articles'] ?? [],
            ),
            $data['synonyms'] ?? [],
        );
    }

    /** Syns artikeln i den här miljön? Utkast gör det bara utanför produktion. */
    private function visible(HelpArticle $article): bool
    {
        return ! $article->isDraft() || ! app()->environment('production');
    }
}
