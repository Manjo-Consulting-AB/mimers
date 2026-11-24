<?php

namespace App\Support;

use App\Support\Help\HelpMarkdown;

/**
 * Ritar en informationssidas markdown — M85 · issue 478 (GitHub #1617),
 * [[ADR-0094 Hjälpcentret]] § 4.
 *
 * Informationssidorna bär sin text i `lang/{locale}/pages/{sida}.md`, inte som
 * nycklar i `lang/en/ui.php`. Samma princip som hjälpartiklarna under
 * `lang/en/help/`: texten ligger i en fil i repot, ritas på servern och kommer
 * aldrig från användaren. `resources/js/pages/Info.vue` bär därför ingen copy.
 *
 * Ritningen är `App\Support\Help\HelpMarkdown::toHtml()`, så samma regler
 * gäller som för hjälpartiklarna: `html_input: strip`, rubrikankare och inga
 * osäkra länkar. Ingen egen markdownmotor och inget nytt paket.
 *
 * **Engelskan är reserven.** Filen för språket läses först; saknas den används
 * `en`. Saknas båda, eller är sidans markdown tom, blir svaret `null` — då
 * ritar sidan sin platshållare, precis som `/privacy` och `/terms` gör tills
 * deras texter skrivs (#1600).
 *
 * **Roten går att byta i konstruktorn.** Standard är `lang_path()`, som i
 * App\Support\Help\HelpLibrary: provet pekar på en testkatalog, och en
 * framtida release kan peka någon annanstans utan att klassen ändras.
 *
 * **Sidan och språket prövas innan sökvägen byggs.** Bara enkel språkkod och
 * ett sidnamn av gemener, siffror och bindestreck når `lang_path()`; ett värde
 * som `../..` hade annars kunnat läsa utanför roten. Ett ogiltigt värde ger
 * `null`, samma svar som en saknad fil.
 */
final class InfoPage
{
    private readonly string $root;

    private readonly HelpMarkdown $markdown;

    /**
     * @param  ?string  $root  Katalogen som innehåller `{locale}/pages/`; standard `lang_path()`.
     */
    public function __construct(?string $root = null)
    {
        $this->root = rtrim($root ?? lang_path(), '/');
        $this->markdown = new HelpMarkdown;
    }

    /**
     * Sidans markdown ritad som HTML, eller `null` när ingen fil finns.
     *
     * @param  ?string  $locale  Språket; standard `app()->getLocale()`.
     */
    public function html(string $page, ?string $locale = null): ?string
    {
        $file = $this->file($page, $locale ?? app()->getLocale());

        if ($file === null) {
            return null;
        }

        $markdown = file_get_contents($file);

        if ($markdown === false) {
            return null;
        }

        return $this->markdown->toHtml($markdown);
    }

    /**
     * Filen för sidan: språkets först, engelskans annars.
     */
    private function file(string $page, string $locale): ?string
    {
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $page) !== 1) {
            return null;
        }

        foreach (array_unique([$locale, 'en']) as $language) {
            if (preg_match('/^[a-z]{2,3}(_[A-Z]{2})?$/', $language) !== 1) {
                continue;
            }

            $file = $this->root.'/'.$language.'/pages/'.$page.'.md';

            if (is_file($file)) {
                return $file;
            }
        }

        return null;
    }
}
