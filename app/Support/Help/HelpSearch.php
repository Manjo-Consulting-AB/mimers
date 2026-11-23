<?php

namespace App\Support\Help;

/**
 * Sökningen i hjälpinnehållet — M81 · issue 466, [[ADR-0094 Hjälpcentret]] § 5.
 *
 * Sökningen sker i PHP över det inlästa innehållet i stället för genom Scout
 * eller ett sökpaket: under hundra artiklar, redan cachade av HelpLibrary, och
 * en PHP-sökning går att pröva i Pest (ADR-0094 § 5, [[ADR-0012 Sök]] § Beslut).
 *
 * **Normaliseringen är publik** därför att 470 återanvänder den när en fråga
 * utan träff ska sparas avidentifierad (ADR-0094 § 8). Den gör allt till
 * gemener och ersätter allt utom bokstäver, siffror och bindestreck med
 * blanksteg — bindestrecket stannar för att `two-factor` ska vara ett ord —
 * och slår ihop blanksteg. `normalize()` ger en sträng; orden delas på
 * blanksteg.
 *
 * **Stoppord tas bort per språk**, ur `config('help.stopwords.{locale}')`.
 * Undantaget är en fråga som bara består av stoppord: då behålls de, för
 * annars hade frågan blivit tom och gett noll träffar. `how do i` ska alltså
 * söka på just de orden.
 *
 * **Synonymerna kommer ur `synonyms.yaml`.** Ett ord som finns i en grupp
 * matchar alla ord i gruppen; de andra orden väger 0,6 av det sökta ordets
 * vikt. `invite` hittar därför en artikel som säger `share`, men hamnar efter
 * den när någon söker på `share` själv.
 *
 * **Ett ord träffar ett fält som helt ord eller, från tre tecken, som början
 * av ett ord.** Titel väger tyngst, sedan nyckelord, beskrivning och sist
 * brödtext (ADR-0094 § 5). I texten summeras förekomsterna men högst tre per
 * ord. Per fält tas den bästa kandidaten — det sökta ordet eller en synonym —
 * så att samma ord inte räknas två gånger.
 *
 * **Alla ord måste träffa.** Ger det inga träffar alls görs sökningen om med
 * kravet att minst hälften av orden (avrundat uppåt) träffar, och svaret
 * markerar `partial: true`. En fråga på ett enda ord har inget att sänka
 * kravet till och ger tomt svar i stället.
 *
 * **Rangordningen** är poäng fallande, sedan `faq` före andra typer och sist
 * titeln. Titelbonusen på 15 läggs på när hela den normaliserade frågan står i
 * den normaliserade titeln, så en titelträff hamnar före en artikel där ordet
 * bara nämns i brödtexten.
 *
 * **Utdraget** är beskrivningen när träffen står där, annars ett fönster om
 * cirka 160 tecken av brödtexten runt första träffen — kapat på ordgräns med
 * `…` där det klipptes — och annars början av texten. Titel och utdrag lämnas
 * som segment, så klienten markerar träffarna utan `v-html` (ADR-0094 § 5).
 */
final class HelpSearch
{
    /** Vikten för en synonym mot det sökta ordet. */
    private const SYNONYM_WEIGHT = 0.6;

    /** Bonus när hela den normaliserade frågan står i titeln. */
    private const TITLE_BONUS = 15.0;

    /** Utdragets längd i tecken, ungefär. */
    private const SNIPPET_LENGTH = 160;

    /** Hur mycket av utdraget som står före den första träffen. */
    private const SNIPPET_LEAD = 60;

    /** Taket för hur mycket ett ord får bidra ur brödtexten. */
    private const TEXT_CAP = 3.0;

    /**
     * Normaliserar en fråga eller ett fält — gemener, utan skiljetecken.
     *
     * Bokstäver, siffror och bindestreck behålls; allt annat blir blanksteg,
     * och blanksteg slås ihop. `What’s the 2FA?!` blir `what s the 2fa`.
     */
    public static function normalize(string $query): string
    {
        $lower = mb_strtolower($query);
        $spaced = preg_replace('/[^\p{L}\p{N}-]+/u', ' ', $lower) ?? '';
        $collapsed = preg_replace('/\s+/u', ' ', $spaced) ?? '';

        return trim($collapsed);
    }

    /** Söker i katalogen och returnerar träffarna i rangordning. */
    public function search(HelpCatalog $catalog, string $query, int $limit = 20): HelpSearchResult
    {
        $normalized = self::normalize($query);

        if ($normalized === '') {
            return new HelpSearchResult($normalized, false, []);
        }

        $words = $this->searchWords($normalized);
        $candidates = [];

        foreach ($words as $word) {
            $candidates[$word] = $this->candidates($word, $catalog->synonyms());
        }

        /** @var list<array{article: HelpArticle, scores: array<string, float>, text: string}> $hits */
        $hits = [];

        foreach ($catalog->articles() as $article) {
            $text = $catalog->rendered($article)->text;

            $hits[] = [
                'article' => $article,
                'scores' => $this->wordScores($article, $text, $words, $candidates),
                'text' => $text,
            ];
        }

        [$selected, $partial] = $this->select($hits, count($words));

        $ranked = [];

        foreach ($selected as $hit) {
            $ranked[] = [
                'hit' => $hit,
                'score' => array_sum($hit['scores'])
                    + $this->bonus($hit['article'], $normalized),
            ];
        }

        usort($ranked, $this->compare(...));

        $matchWords = $this->matchWords($candidates);
        $results = [];
        $scores = [];

        foreach (array_slice($ranked, 0, max(0, $limit)) as $row) {
            $article = $row['hit']['article'];
            $category = $catalog->category($article->category);

            $results[] = [
                'id' => $article->id,
                'url' => route('help.article', $article->id, false),
                'type' => $article->type,
                'category' => [
                    'id' => $article->category,
                    'title' => $category === null ? '' : $category->title,
                ],
                'title' => $this->segments($article->title, $matchWords),
                'snippet' => $this->snippet($article, $row['hit']['text'], $matchWords),
            ];

            $scores[$article->id] = $row['score'];
        }

        return new HelpSearchResult($normalized, $partial, $results, $scores);
    }

    /**
     * Orden frågan söks på — stopporden borttagna, om något annat ord finns kvar.
     *
     * @return list<string>
     */
    private function searchWords(string $normalized): array
    {
        $words = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $stopwords = $this->stopwords();

        $kept = array_values(array_filter(
            $words,
            static fn (string $word): bool => ! in_array($word, $stopwords, true),
        ));

        if ($kept !== []) {
            $words = $kept;
        }

        return array_values(array_unique($words));
    }

    /**
     * Stopporden för språket.
     *
     * @return list<string>
     */
    private function stopwords(): array
    {
        $stopwords = config('help.stopwords.'.app()->getLocale(), []);

        if (! is_array($stopwords)) {
            return [];
        }

        $words = [];

        foreach ($stopwords as $word) {
            if (! is_string($word)) {
                continue;
            }

            $normalized = self::normalize($word);

            if ($normalized !== '') {
                $words[] = $normalized;
            }
        }

        return $words;
    }

    /**
     * Det sökta ordet och dess synonymer, med sina vikter.
     *
     * @param  list<list<string>>  $groups
     * @return list<array{word: string, weight: float}>
     */
    private function candidates(string $word, array $groups): array
    {
        $candidates = [$word => 1.0];

        foreach ($groups as $group) {
            $synonyms = [];

            foreach ($group as $member) {
                $synonyms[] = $this->synonym($member);
            }

            if (! in_array($word, $synonyms, true)) {
                continue;
            }

            foreach ($synonyms as $synonym) {
                if ($synonym !== '' && $synonym !== $word) {
                    $candidates[$synonym] = self::SYNONYM_WEIGHT;
                }
            }
        }

        $list = [];

        foreach ($candidates as $candidate => $weight) {
            $list[] = ['word' => $candidate, 'weight' => $weight];
        }

        return $list;
    }

    /** Ett synonymord som går att matcha som ett enskilt ord, annars tomt. */
    private function synonym(string $value): string
    {
        $normalized = self::normalize($value);

        return str_contains($normalized, ' ') ? '' : $normalized;
    }

    /**
     * Poängen för varje ord som träffar artikeln.
     *
     * @param  list<string>  $words
     * @param  array<string, list<array{word: string, weight: float}>>  $candidates
     * @return array<string, float>
     */
    private function wordScores(HelpArticle $article, string $text, array $words, array $candidates): array
    {
        $title = $this->tokens($article->title);
        $keywords = $this->tokens(implode(' ', $article->keywords));
        $description = $this->tokens($article->description ?? '');
        $body = $this->tokens($text);

        $scores = [];

        foreach ($words as $word) {
            $best = 0.0;

            foreach ($candidates[$word] ?? [] as $candidate) {
                $score = $this->fieldScore($title, $candidate['word'], 10.0, 7.0)
                    + $this->fieldScore($keywords, $candidate['word'], 8.0, 5.0)
                    + $this->fieldScore($description, $candidate['word'], 3.0, 2.0)
                    + $this->textScore($body, $candidate['word']);

                $best = max($best, $score * $candidate['weight']);
            }

            if ($best > 0.0) {
                $scores[$word] = $best;
            }
        }

        return $scores;
    }

    /**
     * Ett fälts bästa bidrag för ett ord — helt ord eller början av ett ord.
     *
     * @param  list<string>  $tokens
     */
    private function fieldScore(array $tokens, string $word, float $whole, float $prefix): float
    {
        if (in_array($word, $tokens, true)) {
            return $whole;
        }

        if (mb_strlen($word) < 3) {
            return 0.0;
        }

        foreach ($tokens as $token) {
            if (str_starts_with($token, $word)) {
                return $prefix;
            }
        }

        return 0.0;
    }

    /**
     * Brödtextens bidrag: förekomsterna summeras, högst tre per ord.
     *
     * @param  list<string>  $tokens
     */
    private function textScore(array $tokens, string $word): float
    {
        $score = 0.0;

        foreach ($tokens as $token) {
            if ($token === $word) {
                $score += 1.0;

                continue;
            }

            if (mb_strlen($word) >= 3 && str_starts_with($token, $word)) {
                $score += 0.5;
            }
        }

        return min(self::TEXT_CAP, $score);
    }

    /**
     * Väljer träffarna: alla ord, annars hälften med `partial` satt.
     *
     * @param  list<array{article: HelpArticle, scores: array<string, float>, text: string}>  $hits
     * @return array{0: list<array{article: HelpArticle, scores: array<string, float>, text: string}>, 1: bool}
     */
    private function select(array $hits, int $count): array
    {
        $all = array_values(array_filter(
            $hits,
            static fn (array $hit): bool => count($hit['scores']) >= $count,
        ));

        if ($all !== []) {
            return [$all, false];
        }

        $half = (int) ceil($count / 2);

        if ($half >= $count) {
            return [[], false];
        }

        $partial = array_values(array_filter(
            $hits,
            static fn (array $hit): bool => count($hit['scores']) >= $half,
        ));

        return [$partial, true];
    }

    /** Titelbonusen, när hela den normaliserade frågan står i titeln. */
    private function bonus(HelpArticle $article, string $normalized): float
    {
        return str_contains(self::normalize($article->title), $normalized)
            ? self::TITLE_BONUS
            : 0.0;
    }

    /**
     * Rangordningen: poäng fallande, sedan `faq` före andra typer, sedan
     * `featured`, sedan titeln.
     *
     * `featured` står mellan typen och titeln därför att frågan `share` ger
     * två artiklar med `share` i titeln som samma poäng: den vanliga frågan
     * och "Can I share just part of a container?". Utan en sista nyckel hade
     * titelns alfabetiska ordning lagt "Can …" först, men *How do I share a
     * container?* är den artikel innehållet lyfter fram (`featured: true`) och
     * den som `share` ska ge först (issuens Klart när). Bland lika relevanta
     * träffar vinner alltså den kurerade — samma anda som `featured` på
     * startsidan (ADR-0094 § 1).
     *
     * @param  array{hit: array{article: HelpArticle, scores: array<string, float>, text: string}, score: float}  $a
     * @param  array{hit: array{article: HelpArticle, scores: array<string, float>, text: string}, score: float}  $b
     */
    private function compare(array $a, array $b): int
    {
        if ($a['score'] !== $b['score']) {
            return $b['score'] <=> $a['score'];
        }

        $aFaq = $a['hit']['article']->type === 'faq' ? 0 : 1;
        $bFaq = $b['hit']['article']->type === 'faq' ? 0 : 1;

        if ($aFaq !== $bFaq) {
            return $aFaq <=> $bFaq;
        }

        $aFeatured = $a['hit']['article']->featured ? 0 : 1;
        $bFeatured = $b['hit']['article']->featured ? 0 : 1;

        if ($aFeatured !== $bFeatured) {
            return $aFeatured <=> $bFeatured;
        }

        return mb_strtolower($a['hit']['article']->title)
            <=> mb_strtolower($b['hit']['article']->title);
    }

    /**
     * Alla ord som får markeras — frågans ord och deras synonymer.
     *
     * @param  array<string, list<array{word: string, weight: float}>>  $candidates
     * @return list<string>
     */
    private function matchWords(array $candidates): array
    {
        $words = [];

        foreach ($candidates as $list) {
            foreach ($list as $candidate) {
                $words[$candidate['word']] = true;
            }
        }

        return array_keys($words);
    }

    /**
     * Utdraget: beskrivningen vid träff där, annars ett fönster ur texten.
     *
     * @param  list<string>  $words
     * @return list<array{text: string, match: bool}>
     */
    private function snippet(HelpArticle $article, string $text, array $words): array
    {
        $description = trim($article->description ?? '');

        if ($description !== '' && $this->contains($description, $words)) {
            return $this->segments($description, $words);
        }

        if ($text === '') {
            return [];
        }

        return $this->segments($this->window($text, $words), $words);
    }

    /**
     * Ett fönster om cirka 160 tecken runt första träffen, kapat på ordgräns.
     *
     * @param  list<string>  $words
     */
    private function window(string $text, array $words): string
    {
        $length = mb_strlen($text);
        $start = 0;
        $end = min($length, self::SNIPPET_LENGTH);
        $first = $this->firstMatch($text, $words);

        if ($first !== null) {
            $start = max(0, $first - self::SNIPPET_LEAD);
            $end = min($length, $start + self::SNIPPET_LENGTH);
        }

        if ($start > 0) {
            $space = mb_strpos($text, ' ', $start);

            if ($space !== false && $space < $end) {
                $start = $space + 1;
            }
        }

        if ($end < $length) {
            $space = mb_strrpos(mb_substr($text, 0, $end), ' ');

            if ($space !== false && $space > $start) {
                $end = $space;
            }
        }

        $snippet = trim(mb_substr($text, $start, max(0, $end - $start)));

        return ($start > 0 ? '…' : '').$snippet.($end < $length ? '…' : '');
    }

    /**
     * Teckenpositionen för den första token som träffar, eller null.
     *
     * @param  list<string>  $words
     */
    private function firstMatch(string $text, array $words): ?int
    {
        foreach ($this->positioned($text) as $token) {
            if ($this->matchesAny($token['word'], $words)) {
                return $token['char'];
            }
        }

        return null;
    }

    /**
     * Innehåller värdet någon av träffarna?
     *
     * @param  list<string>  $words
     */
    private function contains(string $value, array $words): bool
    {
        foreach ($this->tokens($value) as $token) {
            if ($this->matchesAny($token, $words)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Delar upp ett värde i segment med träffarna markerade.
     *
     * Segmenten fogade ihop ger exakt indata — inget tecken tappas, också när
     * titeln bär skiljetecken eller bokstäver utanför ASCII.
     *
     * @param  list<string>  $words
     * @return list<array{text: string, match: bool}>
     */
    private function segments(string $value, array $words): array
    {
        $segments = [];
        $offset = 0;
        $length = strlen($value);

        foreach ($this->positioned($value) as $token) {
            if ($token['byte'] > $offset) {
                $this->appendSegment($segments, substr($value, $offset, $token['byte'] - $offset), false);
            }

            $this->appendSegment($segments, $token['text'], $this->matchesAny($token['word'], $words));
            $offset = $token['byte'] + strlen($token['text']);
        }

        if ($offset < $length) {
            $this->appendSegment($segments, substr($value, $offset), false);
        }

        return $segments;
    }

    /**
     * Lägger till ett segment, och slår ihop det med grannen om märket är detsamma.
     *
     * @param  list<array{text: string, match: bool}>  $segments
     */
    private function appendSegment(array &$segments, string $text, bool $match): void
    {
        if ($text === '') {
            return;
        }

        $last = count($segments) - 1;

        if ($last >= 0 && $segments[$last]['match'] === $match) {
            $segments[$last]['text'] .= $text;

            return;
        }

        $segments[] = ['text' => $text, 'match' => $match];
    }

    /**
     * Token med sitt original, sin byteposition, sin teckenposition och sin
     * normaliserade form.
     *
     * Bytepositionen används för att klippa strängen exakt — segmenten ska
     * fogas ihop till indata tecken för tecken. Teckenpositionen används för
     * utdragsfönstret, så att det räknas i tecken och inte i byte.
     *
     * @return list<array{text: string, byte: int, char: int, word: string}>
     */
    private function positioned(string $value): array
    {
        preg_match_all('/[\p{L}\p{N}-]+/u', $value, $matches, PREG_OFFSET_CAPTURE);

        $tokens = [];

        foreach ($matches[0] as $match) {
            $text = $match[0];
            $byte = $match[1];

            $tokens[] = [
                'text' => $text,
                'byte' => $byte,
                'char' => mb_strlen(substr($value, 0, $byte)),
                'word' => mb_strtolower($text),
            ];
        }

        return $tokens;
    }

    /**
     * Ett fälts token, normaliserade — orddelningen är normaliseringens.
     *
     * @return list<string>
     */
    private function tokens(string $value): array
    {
        preg_match_all('/[\p{L}\p{N}-]+/u', mb_strtolower($value), $matches);

        $tokens = [];

        foreach ($matches[0] as $token) {
            $tokens[] = (string) $token;
        }

        return $tokens;
    }

    /**
     * Träffar token något av orden — helt eller, från tre tecken, som början?
     *
     * @param  list<string>  $words
     */
    private function matchesAny(string $token, array $words): bool
    {
        foreach ($words as $word) {
            if ($token === $word) {
                return true;
            }

            if (mb_strlen($word) >= 3 && str_starts_with($token, $word)) {
                return true;
            }
        }

        return false;
    }
}
