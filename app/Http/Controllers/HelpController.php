<?php

namespace App\Http\Controllers;

use App\Support\Help\HelpArticle;
use App\Support\Help\HelpCategory;
use App\Support\Help\HelpLibrary;
use App\Support\Help\HelpMarkdown;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Hjälpsidan, /help — M78 · issue 458, M80 · issue 461.
 *
 * Innehållet är markdownfiler under lang/{locale}/help/ i formen ur
 * [[ADR-0094 Hjälpcentret]] § 1: en `categories.yaml`, en `synonyms.yaml` och
 * en artikelfil per fråga. HelpLibrary fogar samman språket med engelskan och
 * cachar katalogen, och HelpMarkdown ritar svaren på servern.
 *
 * **Katalogen skickas i samma form som förut** — `slug`, `title`, `intro_html`
 * och `questions` med `anchor`, `question` och `answer_html` — så att
 * Help.vue är oförändrad. `anchor` är artikelns `id`, som är exakt det gamla
 * ankaret, `Str::slug` av frågan (ADR-0094 § 3).
 *
 * Rutten ligger utanför `auth` och `guest`, som de andra informationssidorna.
 */
class HelpController extends Controller
{
    public function __invoke(HelpLibrary $library, HelpMarkdown $markdown): Response
    {
        $catalog = $library->catalog();

        $categories = array_map(
            static fn (HelpCategory $category): array => [
                'slug' => $category->id,
                'title' => $category->title,
                'intro_html' => $markdown->toHtml($category->intro ?? ''),
                'questions' => array_map(
                    static fn (HelpArticle $article): array => [
                        'anchor' => $article->id,
                        'question' => $article->title,
                        'answer_html' => $markdown->toHtml($article->markdown) ?? '',
                    ],
                    $catalog->inCategory($category->id),
                ),
            ],
            $catalog->categories(),
        );

        return Inertia::render('Help', ['categories' => $categories]);
    }
}
