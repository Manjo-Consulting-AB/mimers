<?php

namespace App\Http\Controllers;

use App\Actions\Help\LoadHelpArticles;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Hjälpsidan, /help — M78 · issue 458.
 *
 * Texterna är markdownfiler under lang/{locale}/help/ och ritas på servern av
 * LoadHelpArticles. Saknas katalogen för det aktiva språket används
 * `fallback_locale`. Rutten ligger utanför `auth` och `guest`, som de andra
 * informationssidorna.
 */
class HelpController extends Controller
{
    public function __invoke(LoadHelpArticles $load): Response
    {
        $directory = lang_path(app()->getLocale().'/help');

        if (! is_dir($directory)) {
            $directory = lang_path(config('app.fallback_locale').'/help');
        }

        return Inertia::render('Help', ['categories' => $load->handle($directory)]);
    }
}
