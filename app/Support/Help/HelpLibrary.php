<?php

namespace App\Support\Help;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Läser, fogar samman och cachar hjälpinnehållet — M80 · issue 460,
 * [[ADR-0094 Hjälpcentret]] § 1 och § 2.
 *
 * Källan är `lang/{locale}/help/`: `categories.yaml`, `synonyms.yaml` och
 * katalogen `articles/` med en markdownfil per artikel. Filnamnet är
 * artikelns `id`. Klassen används inte av någon sida än — 461 flyttar texterna
 * och låter `/help` läsa dem härifrån.
 *
 * **Standardroten är `lang_path()` och går att byta i konstruktorn.** Provet
 * pekar på en testkatalog, och en framtida release kan peka någon annanstans
 * utan att klassen ändras.
 *
 * **Engelskan är basen.** Engelskan läses alltid. Finns en katalog för språket
 * läggs dess artiklar över engelskans på `id`, och dess kategorititlar läggs
 * över engelskans på `id` — ordningen och de engelska kategoriernas plats
 * behålls (ADR-0094 § 2). Saknas katalogen används engelskan som den är. En
 * artikel som bara finns på engelska blir alltså kvar med engelsk text, vilket
 * är exakt vad ADR-0094 § 2 säger.
 *
 * **Cachen nycklas på innehållet, inte på en version.** Nyckeln är
 * `help:{locale}:{fingerprint}`, där fingeravtrycket är en hash av sökväg,
 * `filemtime` och `filesize` för varje fil i engelskan och språkets katalog.
 * En ändrad fil ger därför en ny nyckel, och utrullningen behöver aldrig rensa
 * något: den gamla nyckeln blir bara liggande.
 *
 * **Cachen bär arrayer, inte objekt.** Värdet är `HelpCatalog::toArray()`, och
 * katalogen byggs med `HelpCatalog::fromArray()` efter hämtningen. Skälet är
 * att `config/cache.php` inte tillåter att klasser packas upp
 * (`serializable_classes => false`): en cachad HelpCatalog hade kommit tillbaka
 * som `__PHP_Incomplete_Class` ur file-, database- och redis-butikarna.
 *
 * **Ett språk är en enkel språkkod.** `catalog()` tar emot språket som en
 * sträng, men bara `^[a-z]{2,3}(_[A-Z]{2})?$` — annars kastar den. Sökvägarna
 * byggs av värdet, och en kod som `../..` hade annars läst utanför roten.
 *
 * Front mattern läses med symfony/yaml (Tony godkände paketet 2026-11-23).
 * Klassen förutsätter att innehållet är giltigt — det prövar
 * App\Support\Help\HelpValidator, som CI kör. Ett trasigt innehåll som når
 * hit kastar i stället för att tyst bli en halv katalog.
 */
final class HelpLibrary
{
    private readonly string $root;

    /**
     * @param  ?string  $root  Katalogen som innehåller `{locale}/help/`; standard `lang_path()`.
     */
    public function __construct(?string $root = null)
    {
        $this->root = rtrim($root ?? lang_path(), '/');
    }

    /**
     * Katalogen för språket, ur cachen när inget har ändrats.
     *
     * @param  ?string  $locale  Språket; standard `app()->getLocale()`.
     *
     * @throws InvalidArgumentException När språket inte är en enkel språkkod.
     */
    public function catalog(?string $locale = null): HelpCatalog
    {
        $locale ??= app()->getLocale();

        if (preg_match('/^[a-z]{2,3}(_[A-Z]{2})?$/', $locale) !== 1) {
            throw new InvalidArgumentException("Ogiltig språkkod \"{$locale}\".");
        }

        $key = 'help:'.$locale.':'.$this->fingerprint($locale);

        /** @var array{categories: list<array<string, mixed>>, articles: list<array<string, mixed>>, synonyms: list<list<string>>} $data */
        $data = Cache::rememberForever($key, fn (): array => $this->build($locale)->toArray());

        return HelpCatalog::fromArray($data);
    }

    /**
     * Bygger katalogen för språket, med engelskan som bas.
     */
    private function build(string $locale): HelpCatalog
    {
        $english = $this->read($this->root.'/en/help', 'en');

        if ($locale === 'en' || ! is_dir($this->root.'/'.$locale.'/help')) {
            return new HelpCatalog($english['categories'], $english['articles'], $english['synonyms']);
        }

        $local = $this->read($this->root.'/'.$locale.'/help', $locale);

        return new HelpCatalog(
            $this->mergeCategories($english['categories'], $local['categories']),
            $this->mergeArticles($english['articles'], $local['articles']),
            is_file($this->root.'/'.$locale.'/help/synonyms.yaml') ? $local['synonyms'] : $english['synonyms'],
        );
    }

    /**
     * Läser en språkkatalog.
     *
     * @return array{categories: list<HelpCategory>, articles: list<HelpArticle>, synonyms: list<list<string>>}
     */
    private function read(string $directory, string $locale): array
    {
        return [
            'categories' => $this->readCategories($directory),
            'articles' => $this->readArticles($directory, $locale),
            'synonyms' => $this->readSynonyms($directory),
        ];
    }

    /**
     * @return list<HelpCategory>
     */
    private function readCategories(string $directory): array
    {
        $file = $directory.'/categories.yaml';

        if (! is_file($file)) {
            return [];
        }

        $rows = Yaml::parseFile($file);

        if (! is_array($rows)) {
            return [];
        }

        $categories = [];

        foreach (array_values($rows) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $categories[] = new HelpCategory(
                id: (string) ($row['id'] ?? ''),
                title: (string) ($row['title'] ?? ''),
                description: (string) ($row['description'] ?? ''),
                intro: isset($row['intro']) ? (string) $row['intro'] : null,
                order: $index,
            );
        }

        return $categories;
    }

    /**
     * @return list<HelpArticle>
     */
    private function readArticles(string $directory, string $locale): array
    {
        $files = glob($directory.'/articles/*.md') ?: [];
        sort($files);

        $articles = [];

        foreach ($files as $file) {
            $articles[] = $this->readArticle($file, $locale);
        }

        return $articles;
    }

    private function readArticle(string $file, string $locale): HelpArticle
    {
        $content = file_get_contents($file);

        if ($content === false) {
            throw new RuntimeException("Kunde inte läsa hjälpartikeln {$file}.");
        }

        [$front, $markdown] = $this->split($file, $content);

        $data = Yaml::parse($front);

        if (! is_array($data)) {
            throw new RuntimeException("Front mattern i {$file} är inte en mappning.");
        }

        return new HelpArticle(
            id: (string) ($data['id'] ?? ''),
            title: (string) ($data['title'] ?? ''),
            type: (string) ($data['type'] ?? ''),
            category: (string) ($data['category'] ?? ''),
            description: isset($data['description']) ? (string) $data['description'] : null,
            keywords: $this->strings($data['keywords'] ?? []),
            related: $this->strings($data['related'] ?? []),
            contexts: $this->strings($data['context'] ?? []),
            status: (string) ($data['status'] ?? 'published'),
            updated: $this->updated($data['updated'] ?? '', $file),
            featured: (bool) ($data['featured'] ?? false),
            order: (int) ($data['order'] ?? 100),
            markdown: $markdown,
            locale: $locale,
        );
    }

    /**
     * @return list<list<string>>
     */
    private function readSynonyms(string $directory): array
    {
        $file = $directory.'/synonyms.yaml';

        if (! is_file($file)) {
            return [];
        }

        $rows = Yaml::parseFile($file);

        if (! is_array($rows)) {
            return [];
        }

        $groups = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $groups[] = $this->strings($row);
        }

        return $groups;
    }

    /**
     * Delar en artikel i front matter och kropp.
     *
     * @return array{0: string, 1: string}
     */
    private function split(string $file, string $content): array
    {
        return FrontMatter::split($content)
            ?? throw new RuntimeException("{$file} saknar front matter.");
    }

    /**
     * @param  list<HelpCategory>  $english
     * @param  list<HelpCategory>  $local
     * @return list<HelpCategory>
     */
    private function mergeCategories(array $english, array $local): array
    {
        $translated = [];

        foreach ($local as $category) {
            $translated[$category->id] = $category;
        }

        $merged = [];

        foreach ($english as $category) {
            $localCategory = $translated[$category->id] ?? null;

            if ($localCategory === null) {
                $merged[] = $category;

                continue;
            }

            $merged[] = new HelpCategory(
                id: $category->id,
                title: $localCategory->title,
                description: $localCategory->description !== '' ? $localCategory->description : $category->description,
                intro: $localCategory->intro ?? $category->intro,
                order: $category->order,
            );
        }

        return $merged;
    }

    /**
     * Lägger språkets artiklar över engelskans på id; engelskans ordning behålls.
     *
     * Är översättningen ett utkast men engelskan publicerad läggs båda in, den
     * översatta först. Katalogen väljer den första synliga per id, så utkastet
     * syns utanför produktion och engelskan tar över i produktion i stället för
     * att artikeln försvinner.
     *
     * @param  list<HelpArticle>  $english
     * @param  list<HelpArticle>  $local
     * @return list<HelpArticle>
     */
    private function mergeArticles(array $english, array $local): array
    {
        $translated = [];

        foreach ($local as $article) {
            $translated[$article->id] = $article;
        }

        $merged = [];
        $englishIds = [];

        foreach ($english as $article) {
            $englishIds[$article->id] = true;

            $localArticle = $translated[$article->id] ?? null;

            if ($localArticle === null) {
                $merged[] = $article;

                continue;
            }

            $merged[] = $localArticle;

            if ($localArticle->isDraft() && ! $article->isDraft()) {
                $merged[] = $article;
            }
        }

        foreach ($local as $article) {
            if (! isset($englishIds[$article->id])) {
                $merged[] = $article;
            }
        }

        return $merged;
    }

    /**
     * Fingeravtrycket för engelskan och språkets katalog.
     */
    private function fingerprint(string $locale): string
    {
        $parts = [];

        foreach (array_unique(['en', $locale]) as $language) {
            foreach ($this->files($language) as $file) {
                $parts[] = $file.'|'.filemtime($file).'|'.filesize($file);
            }
        }

        return hash('xxh128', $locale.'|'.implode("\n", $parts));
    }

    /**
     * Filerna som fingeravtrycket räknar: kategorier, synonymer och artiklar.
     *
     * @return list<string>
     */
    private function files(string $locale): array
    {
        $directory = $this->root.'/'.$locale.'/help';

        if (! is_dir($directory)) {
            return [];
        }

        $files = [];

        foreach (['categories.yaml', 'synonyms.yaml'] as $name) {
            if (is_file($directory.'/'.$name)) {
                $files[] = $directory.'/'.$name;
            }
        }

        $articles = glob($directory.'/articles/*.md') ?: [];
        sort($articles);

        return [...$files, ...$articles];
    }

    /**
     * Tolkar `updated` till ett datum.
     *
     * Datumet måste skrivas som ett quotat `YYYY-MM-DD` i front mattern. Ett
     * oquoterat datum blir en Unix-tid när symfony/yaml läser det, och
     * `2026-02-30` normaliseras då tyst till 2 mars — därför underkänner
     * HelpValidator den formen, och här kastar den i stället för att tyst bli
     * fel dag.
     */
    private function updated(mixed $value, string $file): CarbonImmutable
    {
        if (! is_string($value)) {
            throw new RuntimeException(
                "updated i {$file} måste skrivas som ett quotat datum på formen YYYY-MM-DD."
            );
        }

        return CarbonImmutable::parse($value)->startOfDay();
    }

    /**
     * Gör en YAML-lista till en lista av strängar.
     *
     * Ett värde som inte är skalärt hoppas över; det kan inte bli en sträng
     * utan att kasta, och valideringen fäller formen i CI.
     *
     * @return list<string>
     */
    private function strings(mixed $value): array
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
