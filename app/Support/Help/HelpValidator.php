<?php

namespace App\Support\Help;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Prövar hjälpinnehållet under en katalog — M80 · issue 460,
 * [[ADR-0094 Hjälpcentret]] § 1.
 *
 * `validate()` tar roten (katalogen som innehåller `{locale}/help/`) och
 * returnerar felen som färdiga meningar. Meningarna är utvecklarens text, inte
 * användarens, så de skrivs på svenska, och de innehåller alltid filens sökväg
 * relativt roten. Ett giltigt innehåll ger en tom lista.
 *
 * **Ett fel per fil.** Varje artikel prövas i den ordning ADR-0094 § 1 listar
 * fälten, och den första bristen blir filens enda fel. Poängen är att en
 * trasig fil ska ge ett besked som pekar på rätt sak, inte en kaskad av
 * följdfel som alla beror på det första. Kategorifilen kan däremot ge flera
 * fel, eftersom varje dubblett är sin egen brist.
 *
 * **Markdownen prövas också.** Varje `help:`-länk måste peka på en artikel som
 * finns och, om den bär ett ankare, på en rubrik i den artikeln. Varje
 * `context` med `#ankare` måste peka på en rubrik i sin egen artikel. Varje bild
 * måste ligga under `public/help-media/`, ha en tillåten ändelse, vara högst
 * 5 MB, ha en alt-text och sakna extern adress. Rubrikerna läses ur samma
 * ritning som sidan använder (HelpMarkdown), så valideringen och renderingen
 * aldrig kan tycka olika om vilka id:n som finns — `{#eget-id}`, `Str::slug`
 * och `-2`-svansen räknas på ett ställe.
 *
 * Ett oquoterat `updated: 2026-11-22` blir en Unix-tid när symfony/yaml läser
 * det, och en oquoterad, ogiltig dag som `2026-02-30` normaliseras då tyst till
 * 2 mars. Datumet måste därför skrivas som ett quotat `YYYY-MM-DD`; ett
 * oquoterat värde underkänns med ett eget besked. Ett quotat `2026-11-32` fälls
 * av datumregeln, och ett oquoterat fälls redan av YAML-tolkaren.
 */
final class HelpValidator
{
    /** Artikel-id: gemener, siffror och enstaka bindestreck. */
    private const ID = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    /** Sammanhang: `container.sharing` eller `account.security#two-factor`. */
    private const CONTEXT = '/^[a-z]+(\.[a-z_]+)*(#[a-z0-9-]+)?$/';

    /**
     * Prövar hela innehållet under roten.
     *
     * @return list<string> Felen som meningar; tomt när allt är giltigt.
     */
    public function validate(string $root): array
    {
        $root = rtrim($root, '/');

        $english = $this->language($root, 'en');
        $errors = $english['errors'];

        foreach ($this->locales($root) as $locale) {
            if ($locale === 'en') {
                continue;
            }

            $local = $this->language($root, $locale, $english['articles'], $english['headings']);

            $errors = [...$errors, ...$local['errors']];

            foreach (array_diff_key($local['articles'], $english['articles']) as $id => $file) {
                $errors[] = $this->relative($root, $file).": artikeln \"{$id}\" finns inte på engelska.";
            }

            $missing = array_diff_key($english['categories'], $local['categories']);
            $extra = array_diff_key($local['categories'], $english['categories']);

            if ($missing !== [] || $extra !== []) {
                $errors[] = $this->relative($root, $this->categoriesFile($root, $locale))
                    .': kategorierna stämmer inte med engelskan (saknas: '.
                    (implode(', ', array_keys($missing)) ?: '—').'; extra: '.
                    (implode(', ', array_keys($extra)) ?: '—').').';
            }
        }

        return $errors;
    }

    /**
     * Prövar en språkkatalog.
     *
     * @param  array<string, string>  $known  Engelskans artikel-id:n => fil; tom för engelskan.
     * @param  array<string, list<array{id: string, title: string, level: int}>>  $knownHeadings  Engelskans rubriker, som reserv för ett ankare till en artikel som bara finns på engelska.
     * @return array{errors: list<string>, articles: array<string, string>, categories: array<string, string>, headings: array<string, list<array{id: string, title: string, level: int}>>}
     */
    private function language(string $root, string $locale, array $known = [], array $knownHeadings = []): array
    {
        $directory = $root.'/'.$locale.'/help';

        [$categories, $errors] = $this->categories($root, $directory);

        $entries = $this->entries($directory);

        $declared = [];

        foreach ($entries as $entry) {
            if ($entry['data'] !== null && is_string($entry['data']['id'] ?? null)) {
                $declared[$entry['data']['id']] = $entry['file'];
            }
        }

        /** @var list<string> $targets */
        $targets = array_keys($known + $declared);

        $headings = $this->headings($entries);

        // Språkets egna rubriker vinner; engelskans är reserven för ett ankare
        // till en artikel som bara finns på engelska.
        $allHeadings = $headings + $knownHeadings;

        /** @var array<string, string> $seenContexts */
        $seenContexts = [];

        foreach ($entries as $entry) {
            $file = $entry['file'];

            if ($entry['data'] === null) {
                $errors[] = $this->relative($root, $file).': '.$entry['error'];

                continue;
            }

            $error = $this->articleError($entry['data'], $file, $categories, $targets, $seenContexts);

            if ($error === null) {
                $error = $this->markdownError($entry['data'], $entry['markdown'], $targets, $allHeadings);
            }

            if ($error !== null) {
                $errors[] = $this->relative($root, $file).': '.$error;

                continue;
            }

            foreach ($this->strings($entry['data']['context'] ?? []) as $context) {
                $seenContexts[$context] = $file;
            }
        }

        return ['errors' => $errors, 'articles' => $declared, 'categories' => $categories, 'headings' => $headings];
    }

    /**
     * Den första bristen i en artikel, eller null.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $categories
     * @param  list<string>  $targets
     * @param  array<string, string>  $seenContexts
     */
    private function articleError(array $data, string $file, array $categories, array $targets, array $seenContexts): ?string
    {
        $missing = [];

        foreach (['id', 'title', 'type', 'category', 'updated'] as $field) {
            if (! $this->filled($data, $field)) {
                $missing[] = $field;
            }
        }

        if ($missing !== []) {
            return 'obligatoriska fält saknas: '.implode(', ', $missing).'.';
        }

        foreach (['id', 'title', 'type', 'category'] as $field) {
            if (! is_string($data[$field])) {
                return "{$field} måste vara en sträng.";
            }
        }

        if (! is_string($data['updated'])) {
            return 'updated måste skrivas som ett quotat datum på formen YYYY-MM-DD.';
        }

        if (isset($data['description']) && ! is_string($data['description'])) {
            return 'description måste vara en sträng.';
        }

        $id = $data['id'];
        $filename = basename($file, '.md');

        if ($id !== $filename) {
            return "id \"{$id}\" stämmer inte med filnamnet \"{$filename}\".";
        }

        if (preg_match(self::ID, $id) !== 1) {
            return "id \"{$id}\" matchar inte ^[a-z0-9]+(-[a-z0-9]+)*$.";
        }

        if (mb_strlen($id) > 80) {
            return "id \"{$id}\" är längre än 80 tecken.";
        }

        $type = $data['type'];
        $types = config('help.types');

        if (! is_array($types) || ! in_array($type, $types, true)) {
            return "typen \"{$type}\" finns inte i config('help.types').";
        }

        $category = $data['category'];

        if (! array_key_exists($category, $categories)) {
            return "kategorin \"{$category}\" finns inte i categories.yaml.";
        }

        $description = $data['description'] ?? null;

        if ($type !== 'faq' && ($description === null || trim($description) === '')) {
            return 'description saknas; den krävs för alla typer utom faq.';
        }

        if ($description !== null && mb_strlen($description) > 200) {
            return 'description är längre än 200 tecken.';
        }

        if (! $this->validDate($data['updated'])) {
            return 'updated är inte ett giltigt datum på formen YYYY-MM-DD.';
        }

        $related = $data['related'] ?? [];
        $max = (int) config('help.max_related', 6);

        if (! is_array($related)) {
            return 'related måste vara en lista.';
        }

        if (count($related) > $max) {
            return "related har fler än {$max} id:n.";
        }

        foreach ($related as $target) {
            if (! is_string($target)) {
                return 'related måste innehålla id:n som strängar.';
            }

            if ($target === $id) {
                return "related pekar på artikeln själv ({$id}).";
            }

            if (! in_array($target, $targets, true)) {
                return "related pekar på \"{$target}\", som inte finns.";
            }
        }

        $contexts = $data['context'] ?? [];

        if (! is_array($contexts)) {
            return 'context måste vara en lista.';
        }

        $seen = [];

        foreach ($contexts as $context) {
            if (! is_string($context)) {
                return 'context måste innehålla sammanhang som strängar.';
            }

            if (preg_match(self::CONTEXT, $context) !== 1) {
                return "sammanhanget \"{$context}\" matchar inte formen för context.";
            }

            if (isset($seen[$context]) || isset($seenContexts[$context])) {
                return "sammanhanget \"{$context}\" förekommer mer än en gång i språket.";
            }

            $seen[$context] = true;
        }

        return null;
    }

    /**
     * Rubrik-id:na per artikel, räknade av samma ritning som sidan använder.
     *
     * @param  list<array{file: string, data: array<string, mixed>|null, error: string|null, markdown: string}>  $entries
     * @return array<string, list<array{id: string, title: string, level: int}>>
     */
    private function headings(array $entries): array
    {
        $markdown = new HelpMarkdown;
        $headings = [];

        foreach ($entries as $entry) {
            if ($entry['data'] === null || ! is_string($entry['data']['id'] ?? null)) {
                continue;
            }

            $headings[$entry['data']['id']] = $markdown->render($entry['markdown'])->headings;
        }

        return $headings;
    }

    /**
     * Prövar markdownen: interna länkar, sammanhangens ankare och bilderna.
     *
     * Länkarna läses ur dokumentträdet av HelpMarkdown::scan(), inte ur råtexten
     * med ett reguljärt uttryck, så att en `help:`-länk i ett kodblock inte
     * blir ett falskt fynd.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $targets
     * @param  array<string, list<array{id: string, title: string, level: int}>>  $headings
     */
    private function markdownError(array $data, string $markdown, array $targets, array $headings): ?string
    {
        $references = (new HelpMarkdown)->scan($markdown);

        foreach ($references->helpLinks as $link) {
            $parts = explode('#', $link, 2);
            $id = $parts[0];
            $anchor = $parts[1] ?? '';

            if (! in_array($id, $targets, true)) {
                return "länken \"help:{$link}\" pekar på artikeln \"{$id}\", som inte finns.";
            }

            if ($anchor !== '' && ! $this->hasHeading($headings[$id] ?? [], $anchor)) {
                return "länken \"help:{$link}\" pekar på ankaret \"#{$anchor}\", som inte finns i \"{$id}\".";
            }
        }

        $own = $headings[(string) ($data['id'] ?? '')] ?? [];

        foreach ($this->strings($data['context'] ?? []) as $context) {
            $parts = explode('#', $context, 2);
            $anchor = $parts[1] ?? '';

            if ($anchor !== '' && ! $this->hasHeading($own, $anchor)) {
                return "sammanhanget \"{$context}\" pekar på ankaret \"#{$anchor}\", som inte finns i artikeln.";
            }
        }

        foreach ($references->images as $image) {
            $error = $this->imageError($image['src'], $image['alt']);

            if ($error !== null) {
                return $error;
            }
        }

        return null;
    }

    /**
     * Finns rubriken med det id:t?
     *
     * @param  list<array{id: string, title: string, level: int}>  $headings
     */
    private function hasHeading(array $headings, string $anchor): bool
    {
        foreach ($headings as $heading) {
            if ($heading['id'] === $anchor) {
                return true;
            }
        }

        return false;
    }

    /**
     * Den första bristen i en bild, eller null.
     *
     * Ordningen är vald så att ett fel pekar på rätt sak: en extern adress
     * innan sökvägen, ändelsen innan filen prövas (en `.svg` är fel även om
     * filen inte finns), alt-texten innan filen (en tom alt-text är fel även om
     * bilden är kvar i en annan gren). Storleken prövas sist, eftersom den
     * kräver att filen finns.
     */
    private function imageError(string $src, string $alt): ?string
    {
        if (preg_match('~^([a-z][a-z0-9+.-]*:|//)~i', $src) === 1) {
            return "bilden \"{$src}\" har en extern adress; bilder måste ligga under public/help-media/.";
        }

        if (! str_starts_with($src, 'help-media/') || str_contains($src, '..')) {
            return "bilden \"{$src}\" ligger inte under public/help-media/.";
        }

        $extension = strtolower(pathinfo($src, PATHINFO_EXTENSION));

        if (! in_array($extension, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true)) {
            return "bilden \"{$src}\" har ändelsen .{$extension}; tillåtna är png, jpg, jpeg, gif och webp.";
        }

        if (trim($alt) === '') {
            return "bilden \"{$src}\" saknar alt-text.";
        }

        $path = public_path($src);

        if (! is_file($path)) {
            return "bilden \"{$src}\" finns inte i public/help-media/.";
        }

        $size = filesize($path);

        if ($size !== false && $size > 5 * 1024 * 1024) {
            return "bilden \"{$src}\" är större än 5 MB.";
        }

        return null;
    }

    /**
     * Läser kategorifilen och prövar att id:na är unika.
     *
     * @return array{0: array<string, string>, 1: list<string>}
     */
    private function categories(string $root, string $directory): array
    {
        $file = $directory.'/categories.yaml';

        if (! is_file($file)) {
            return [[], []];
        }

        try {
            $rows = Yaml::parseFile($file);
        } catch (ParseException $e) {
            return [[], [$this->relative($root, $file).': categories.yaml är inte giltig YAML ('.$e->getMessage().').']];
        }

        if (! is_array($rows)) {
            return [[], [$this->relative($root, $file).': categories.yaml är inte en lista.']];
        }

        /** @var array<string, string> $categories */
        $categories = [];
        $errors = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['id'] ?? null) || $row['id'] === '') {
                continue;
            }

            $id = $row['id'];

            if (isset($categories[$id])) {
                $errors[] = $this->relative($root, $file).": kategori-id:t \"{$id}\" förekommer mer än en gång.";

                continue;
            }

            $categories[$id] = $file;
        }

        return [$categories, $errors];
    }

    /**
     * Läser artikelfilerna, deras front matter och markdownkroppen.
     *
     * @return list<array{file: string, data: array<string, mixed>|null, error: string|null, markdown: string}>
     */
    private function entries(string $directory): array
    {
        $files = glob($directory.'/articles/*.md') ?: [];
        sort($files);

        $entries = [];

        foreach ($files as $file) {
            $content = file_get_contents($file);

            if ($content === false) {
                $entries[] = ['file' => $file, 'data' => null, 'error' => 'filen går inte att läsa.', 'markdown' => ''];

                continue;
            }

            $parts = FrontMatter::split($content);

            if ($parts === null) {
                $entries[] = ['file' => $file, 'data' => null, 'error' => 'front matter saknas.', 'markdown' => ''];

                continue;
            }

            try {
                $data = Yaml::parse($parts[0]);
            } catch (ParseException $e) {
                $entries[] = ['file' => $file, 'data' => null, 'error' => 'front mattern är inte giltig YAML ('.$e->getMessage().').', 'markdown' => $parts[1]];

                continue;
            }

            if (! is_array($data)) {
                $entries[] = ['file' => $file, 'data' => null, 'error' => 'front mattern är inte en mappning.', 'markdown' => $parts[1]];

                continue;
            }

            /** @var array<string, mixed> $data */
            $entries[] = ['file' => $file, 'data' => $data, 'error' => null, 'markdown' => $parts[1]];
        }

        return $entries;
    }

    /**
     * Är fältet satt med ett värde som inte är tomt?
     *
     * @param  array<string, mixed>  $data
     */
    private function filled(array $data, string $field): bool
    {
        return isset($data[$field]) && $data[$field] !== '';
    }

    /**
     * Är värdet en dag på formen `Y-m-d` utan klocka?
     *
     * Ett oquoterat datum kommer hit som en Unix-tid och godtas inte: tiden är
     * redan normaliserad av YAML-tolkaren, så en ogiltig dag går inte längre
     * att skilja från en giltig. Regeln kräver därför en sträng, och det egna
     * beskedet om det står i `articleError()`.
     */
    private function validDate(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    /**
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

    /**
     * Språkkatalogerna: underkataloger till roten som har en `help`-katalog.
     *
     * @return list<string>
     */
    private function locales(string $root): array
    {
        $locales = [];

        foreach (glob($root.'/*/help', GLOB_ONLYDIR) ?: [] as $directory) {
            $locales[] = basename(dirname($directory));
        }

        sort($locales);

        return $locales;
    }

    private function categoriesFile(string $root, string $locale): string
    {
        return $root.'/'.$locale.'/help/categories.yaml';
    }

    /** Sökvägen relativt roten, som felen skriver den. */
    private function relative(string $root, string $file): string
    {
        return str_starts_with($file, $root.'/') ? substr($file, strlen($root) + 1) : $file;
    }
}
