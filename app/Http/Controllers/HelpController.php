<?php

namespace App\Http\Controllers;

use App\Actions\Help\CountHelpEvent;
use App\Support\Help\HelpArticle;
use App\Support\Help\HelpCatalog;
use App\Support\Help\HelpLibrary;
use App\Support\Help\HelpMarkdown;
use App\Support\Help\HelpRendered;
use App\Support\Help\HelpSearch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Hjälpcentret — M78 · issue 458, M80 · issue 461 och 462.
 *
 * Innehållet är markdownfiler under lang/{locale}/help/ i formen ur
 * [[ADR-0094 Hjälpcentret]] § 1: en `categories.yaml`, en `synonyms.yaml` och
 * en artikelfil per fråga. HelpLibrary fogar samman språket med engelskan och
 * cachar katalogen, och HelpMarkdown ritar svaren på servern.
 *
 * **`index` är startsidan, `/help`.** Den skickar kategorierna — `id`, `title`,
 * `description`, antalet artiklar och de tre första frågorna som `top` — de
 * högst sex `featured`-artiklarna, och `anchors`, en karta från varje
 * ankare till den adress det hör hemma på (ADR-0094 § 3). Kartan är det som
 * låter de gamla adresserna leva: `Help.vue` ser `location.hash`, slår upp det
 * i kartan och skickar webbläsaren vidare. Fragmentet når aldrig servern, så
 * det kan inte göras som en omdirigering.
 *
 * **`?q=` gör startsidan till träfflistan** (issue 467). Finns frågan skickas
 * `q` och `search` — svaret ur HelpSearch med taket 20, i samma form som
 * `/help/search` svarar — och `Help.vue` ritar träffarna i stället för
 * kategorikorten. Sidan får `noindex`: en sökträfflista är ingen adress någon
 * ska hitta i en sökmotor (ADR-0094 § 10). Utan fråga finns varken `q` eller
 * `search` bland propsen, och sidhuvudet är som förut.
 *
 * **`category` är kategorisidan, `/help/{kategori}`.** Frågorna (`faq`) får
 * sitt svar ritat; övriga typer får sin beskrivning och länkas till
 * `/help/articles/{id}`. Ett okänt kategori-id ger 404.
 *
 * **`article` är artikelsidan, `/help/articles/{id}`.** Varje artikel — också
 * varje fråga — har en egen sida. Sidan bär brödsmulan, typen, datumet,
 * innehållet, innehållsförteckningen, de relaterade artiklarna och grannarna i
 * kategorins ordning. Ett okänt id ger 404, och ett utkast ger 404 i
 * produktion men syns utanför den (regeln sitter i HelpCatalog).
 *
 * **Servern skriver sidhuvudet.** De tre sidorna skickar `meta` som vydata med
 * `withViewData()`, och `app.blade.php` skriver `<title>`, beskrivningen och
 * den kanoniska adressen när de finns (ADR-0094 § 10). Utan dem ser huvudet ut
 * som förut, så en sida utan `meta` — `/dashboard` — får ingen beskrivning.
 *
 * Rutterna ligger utanför `auth` och `guest`, som de andra informationssidorna.
 */
class HelpController extends Controller
{
    /** Startsidan, `/help` — och träfflistan, `/help?q=…`. */
    public function index(
        Request $request,
        HelpLibrary $library,
        HelpSearch $search,
        CountHelpEvent $counter,
    ): Response {
        $catalog = $library->catalog();

        // Frågan är bara en fråga när den är en sträng med något i. Ett tomt
        // `?q=` och ett `?q[]=` behandlas som startsidan.
        $raw = $request->query('q');
        $query = is_string($raw) ? trim($raw) : '';
        $searching = $query !== '';

        // Träfflistan är en sökning, även om den kom som en vanlig GET i
        // stället för genom JSON-ytan (ADR-0094 § 8). Den räknas först när
        // svaret är byggt, längst ned — ett fel i propsen ska inte bli en
        // räknad sökning. Gav frågan noll träffar sparas den som en lucka.
        $result = null;

        if ($searching) {
            $result = $search->search($catalog, $query, 20);
        }

        // Kortet bär de tre första frågorna som egna länkar. Hela kortet kan
        // inte vara en länk, för då hamnade länkar inuti en länk; rubriken är
        // kortets enda väg till kategorisidan och frågorna ligger bredvid.
        $categories = [];

        foreach ($catalog->categories() as $category) {
            $articles = $catalog->inCategory($category->id);

            $categories[] = [
                'id' => $category->id,
                'title' => $category->title,
                'description' => $category->description,
                'count' => count($articles),
                'top' => array_map(
                    static fn (HelpArticle $article): array => [
                        'id' => $article->id,
                        'title' => $article->title,
                    ],
                    array_slice($articles, 0, 3),
                ),
            ];
        }

        // *Popular articles* — högst sex, i katalogens ordning. Kategorins
        // titel följer med så att raden kan säga var artikeln hör hemma utan
        // att klienten slår upp kategorin själv.
        $featured = [];

        foreach (array_slice($catalog->featured(), 0, 6) as $article) {
            $category = $catalog->category($article->category);

            $featured[] = [
                'id' => $article->id,
                'title' => $article->title,
                'type' => $article->type,
                'category_title' => $category === null ? '' : $category->title,
            ];
        }

        // Varje kategori och varje artikel får en post. Ett kategori-id och ett
        // artikel-id kan aldrig krocka: de gamla ankarna är unika tillsammans
        // (provet i tests/Feature/Frontend/HjalpsidanTest.php).
        //
        // Adressen byggs ur ruttnamnet, inte ur en handsatt sträng, så att den
        // är samma källa som kategorisidans egen `url` och följer rutten om
        // dess mönster ändras (ADR-0094 § 3).
        $anchors = [];

        foreach ($catalog->categories() as $category) {
            $anchors[$category->id] = route('help.category', $category->id, false);
        }

        foreach ($catalog->articles() as $article) {
            $anchors[$article->id] = route('help.category', $article->category, false).'#'.$article->id;
        }

        $response = Inertia::render('Help', [
            'categories' => $categories,
            'featured' => $featured,
            'anchors' => $anchors,
            ...($searching ? [
                'q' => $query,
                'search' => $result->toArray(),
            ] : []),
        ])->withViewData(['meta' => [
            'title' => $searching
                ? (string) __('ui.help.search.title', ['query' => $query])
                : (string) __('ui.info.help.title'),
            'description' => (string) __('ui.info.help.subtitle'),
            'canonical' => route('info.help'),
            // En sökträfflista ska inte indexeras (ADR-0094 § 10).
            'robots' => $searching ? 'noindex' : null,
        ]]);

        if ($result !== null) {
            $counter->event(CountHelpEvent::SEARCH);

            if ($result->results === []) {
                $counter->searchGap($query);
            }
        }

        return $response;
    }

    /**
     * Hjälpen för ett sammanhang, `/help/context/{sammanhang}` — M81 · issue
     * 468, [[ADR-0094 Hjälpcentret]] § 6.
     *
     * Vyn känner inte till någon artikeladress: den länkar med sammanhanget,
     * och servern slår upp artikeln som bär det. En träff ger 302 till
     * artikelsidan, med `#ankaret` när sammanhanget har ett. Ett okänt
     * sammanhang ger 302 till `/help` och en rad på nivån `info` med
     * sammanhanget — 470 räknar båda utfallen. Ankaret står i front matterns
     * värde och aldrig i adressen, så det finns inget att läsa ur rutten.
     */
    public function context(string $context, HelpLibrary $library, CountHelpEvent $counter): RedirectResponse
    {
        $found = $library->catalog()->byContext($context);

        if ($found === null) {
            Log::info('help.context_unknown', ['context' => $context]);

            // Ett okänt sammanhang är en lucka i texterna: någon länkade hit
            // med ett sammanhang ingen artikel bär. Det räknas utan artikel.
            $counter->event(CountHelpEvent::CONTEXT_UNKNOWN, '', $context);

            return redirect()->route('info.help');
        }

        // Ett sammanhang som hittade en artikel räknas med både sammanhanget
        // och artikeln, så rapporten kan se vilka ingångar som används.
        $counter->event(CountHelpEvent::CONTEXT_OPEN, $found['article']->id, $context);

        $url = route('help.article', $found['article']->id);

        return redirect($found['anchor'] === null ? $url : $url.'#'.$found['anchor']);
    }

    /** Kategorisidan, `/help/{kategori}`. */
    public function category(string $category, HelpLibrary $library, HelpMarkdown $markdown): Response
    {
        $catalog = $library->catalog();
        $found = $catalog->category($category);

        if ($found === null) {
            abort(404);
        }

        $articles = array_map(
            static fn (HelpArticle $article): array => [
                'id' => $article->id,
                'title' => $article->title,
                'type' => $article->type,
                // Svaret ritas bara för en fråga. Övriga typer bär en
                // beskrivning och en egen sida, som 464 bygger.
                'answer_html' => $article->type === 'faq'
                    ? ($markdown->toHtml($article->markdown) ?? '')
                    : null,
                'description' => $article->description,
            ],
            $catalog->inCategory($category),
        );

        return Inertia::render('Help/Category', [
            'category' => [
                'id' => $found->id,
                'title' => $found->title,
                'description' => $found->description,
                'intro_html' => $markdown->toHtml($found->intro ?? ''),
                // Kategorins egen adress ur ruttnamnet. Kopieringsknappen
                // sätter bara `location.origin` framför, så den kopierade
                // länken blir klistringsbar (ADR-0094 § 3).
                'url' => route('help.category', $found->id, false),
            ],
            'articles' => $articles,
        ])->withViewData(['meta' => [
            'title' => $found->title,
            'description' => $found->description,
            'canonical' => route('help.category', $found->id),
        ]]);
    }

    /** Artikelsidan, `/help/articles/{id}`. */
    public function article(
        string $article,
        Request $request,
        HelpLibrary $library,
        CountHelpEvent $counter,
    ): Response {
        $catalog = $library->catalog();
        $found = $catalog->article($article);

        if ($found === null) {
            abort(404);
        }

        $category = $catalog->category($found->category);

        if ($category === null) {
            abort(404);
        }

        $rendered = $catalog->rendered($found);
        $neighbours = $catalog->inCategory($found->category);

        $response = Inertia::render('Help/Article', [
            'article' => [
                'id' => $found->id,
                'title' => $found->title,
                'type' => $found->type,
                'description' => $found->description,
                // Datumet som ISO-dag, så att klienten formaterar det för
                // användarens locale med datumregeln (issue 104).
                'updated' => $found->updated->format('Y-m-d'),
                'html' => $rendered->html,
                // Innehållsförteckningen länkar bara till nivå 2 — en
                // underrubrik är ingen egen destination på sidan (ADR-0094
                // § 4).
                'headings' => $this->contents($rendered),
            ],
            'category' => [
                'id' => $category->id,
                'title' => $category->title,
            ],
            'related' => $this->related($catalog, $found),
            'previous' => $this->neighbour($neighbours, $found->id, -1),
            'next' => $this->neighbour($neighbours, $found->id, 1),
        ])->withViewData(['meta' => [
            'title' => $found->title,
            'description' => $this->description($found, $rendered),
            'canonical' => route('help.article', $found->id),
        ]]);

        $this->countArticleView($request, $counter, $found->id);

        return $response;
    }

    /**
     * Räknar artikelvisningen — och sökklikket när adressen bär `?ref=search`.
     *
     * Bara fullständiga sidladdningar räknas: en partiell Inertia-omladdning
     * bär `X-Inertia-Partial-*` och är ingen visning av artikeln, samma regel
     * som App\Http\Middleware\CountRouteView. Räknandet sker efter att
     * artikeln hittats, så ett 404 blir aldrig en visning.
     */
    private function countArticleView(Request $request, CountHelpEvent $counter, string $articleId): void
    {
        if ($request->headers->has('X-Inertia-Partial-Component') || $request->headers->has('X-Inertia-Partial-Data')) {
            return;
        }

        $counter->event(CountHelpEvent::ARTICLE_VIEW, $articleId);

        if ($request->query('ref') === 'search') {
            $counter->event(CountHelpEvent::SEARCH_CLICK, $articleId);
        }
    }

    /**
     * Rubrikerna på nivå 2, som `id` och `title` — innehållsförteckningen.
     *
     * @return list<array{id: string, title: string}>
     */
    private function contents(HelpRendered $rendered): array
    {
        $contents = [];

        foreach ($rendered->headings as $heading) {
            if ($heading['level'] !== 2) {
                continue;
            }

            $contents[] = ['id' => $heading['id'], 'title' => $heading['title']];
        }

        return $contents;
    }

    /**
     * De relaterade artiklarna, i front matterns ordning.
     *
     * Ett id som inte finns — eller ett utkast i produktion — hoppas över:
     * katalogen svarar null för det som inte syns, och en trasig referens ska
     * inte ge en tom rad på sidan. Ordningen är artikelns egen och inte
     * katalogens.
     *
     * @return list<array{id: string, title: string, type: string}>
     */
    private function related(HelpCatalog $catalog, HelpArticle $article): array
    {
        $related = [];

        foreach ($article->related as $id) {
            $found = $catalog->article($id);

            if ($found === null) {
                continue;
            }

            $related[] = [
                'id' => $found->id,
                'title' => $found->title,
                'type' => $found->type,
            ];
        }

        return $related;
    }

    /**
     * Grannen före eller efter artikeln i kategorins ordning.
     *
     * @param  list<HelpArticle>  $neighbours
     * @return array{id: string, title: string}|null
     */
    private function neighbour(array $neighbours, string $id, int $offset): ?array
    {
        foreach ($neighbours as $index => $neighbour) {
            if ($neighbour->id !== $id) {
                continue;
            }

            $neighbour = $neighbours[$index + $offset] ?? null;

            return $neighbour === null
                ? null
                : ['id' => $neighbour->id, 'title' => $neighbour->title];
        }

        return null;
    }

    /**
     * Sidans beskrivning — artikelns egen, annars för en fråga de första 160
     * tecknen av den ritade texten (ADR-0094 § 10).
     */
    private function description(HelpArticle $article, HelpRendered $rendered): ?string
    {
        if ($article->description !== null && trim($article->description) !== '') {
            return $article->description;
        }

        if ($article->type !== 'faq') {
            return null;
        }

        $text = trim($rendered->text);

        return $text === '' ? null : mb_substr($text, 0, 160);
    }
}
