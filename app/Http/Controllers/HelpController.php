<?php

namespace App\Http\Controllers;

use App\Support\Help\HelpArticle;
use App\Support\Help\HelpCategory;
use App\Support\Help\HelpLibrary;
use App\Support\Help\HelpMarkdown;
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
 * `description` och antalet artiklar — och `anchors`, en karta från varje
 * ankare till den adress det hör hemma på (ADR-0094 § 3). Kartan är det som
 * låter de gamla adresserna leva: `Help.vue` ser `location.hash`, slår upp det
 * i kartan och skickar webbläsaren vidare. Fragmentet når aldrig servern, så
 * det kan inte göras som en omdirigering.
 *
 * **`category` är kategorisidan, `/help/{kategori}`.** Frågorna (`faq`) får
 * sitt svar ritat; övriga typer får sin beskrivning och länkas till
 * `/help/articles/{id}`, som 464 bygger. Ett okänt kategori-id ger 404.
 *
 * Rutterna ligger utanför `auth` och `guest`, som de andra informationssidorna.
 */
class HelpController extends Controller
{
    /** Startsidan, `/help`. */
    public function index(HelpLibrary $library): Response
    {
        $catalog = $library->catalog();

        $categories = array_map(
            static fn (HelpCategory $category): array => [
                'id' => $category->id,
                'title' => $category->title,
                'description' => $category->description,
                'count' => count($catalog->inCategory($category->id)),
            ],
            $catalog->categories(),
        );

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

        return Inertia::render('Help', [
            'categories' => $categories,
            'anchors' => $anchors,
        ]);
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
        ]);
    }
}
