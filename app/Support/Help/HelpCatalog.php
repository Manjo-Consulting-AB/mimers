<?php

namespace App\Support\Help;

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
 * Katalogen måste gå att serialisera: HelpLibrary lägger den i cachen som den
 * är, och alla fält är skalärer, listor eller CarbonImmutable.
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
     * @return list<HelpArticle>
     */
    public function articles(): array
    {
        $articles = array_values(array_filter($this->articles, $this->visible(...)));

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

    /** Syns artikeln i den här miljön? Utkast gör det bara utanför produktion. */
    private function visible(HelpArticle $article): bool
    {
        return ! $article->isDraft() || ! app()->environment('production');
    }
}
