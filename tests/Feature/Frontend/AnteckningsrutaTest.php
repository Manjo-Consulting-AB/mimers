<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;

/*
 * M42 · issue 325 · Anteckningens ruta på itemets översikt, se
 * resources/js/components/ItemNotes.vue,
 * resources/js/pages/Containers/Items/Show.vue och
 * [[ADR-0066 Itemets anteckning]] § 3–9.
 *
 * **Det som prövas är formen, inte webbläsaren.** Sviten har ingen browser:
 * klicket, fokuset, markören och den växande rutan går inte att köra här.
 * Proven läser därför källkoden med kommentarerna borta — samma sorts prov som
 * ItemflikTest, GenomgangTest och FokusKartaTest gör på samma fil — och det
 * som kräver en webbläsare står i PR-kroppen.
 *
 * **Ritningen ägs av servern.** Att Markdown blir HTML, att HTML i texten
 * escapas och att externa länkar får rätt `rel` prövas i
 * tests/Feature/Item/AnteckningensRitningTest.php (issue 324). Här prövas bara
 * att vyn ritar `notesHtml` med `v-html` och aldrig ritar Markdown själv.
 *
 * Hjälparna har prefixet `anteckningsruta` — Pest lägger alla testfiler i
 * samma namnrymd när hela sviten körs.
 */

/**
 * Källkoden med kommentarer borta — samma tre slag som GenomgangTest rensar.
 */
function anteckningsrutaKod(string $sokvag): string
{
    $kod = File::get(resource_path("js/{$sokvag}"));
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/**
 * Öppningstaggen för en komponent, som rå markup.
 */
function anteckningsrutaTagg(string $kod, string $komponent): string
{
    preg_match(
        '/<'.$komponent.'\b((?:"[^"]*"|\'[^\']*\'|[^>"\'])*?)>/s',
        $kod,
        $träffar,
    );

    return $träffar[0] ?? '';
}

/*
 * Klart när: översikten visar bild, beskrivning och snabbfakta i en rad, sedan
 * *All fields*, anteckningen och *Recent activity*.
 *
 * Ordningen läses i mallen. `<dl>`-formen som bar beskrivningen och
 * anteckningen före den här issuen är borta: beskrivningen är ett stycke
 * ovanför `ItemQuickFacts`, och anteckningen har flyttat ned i sin egen ruta.
 */
it('ritar översikten i ordningen beskrivning, snabbfakta, alla fält, anteckningen och aktiviteten', function () {
    $vy = anteckningsrutaKod('pages/Containers/Items/Show.vue');

    $beskrivning = strpos($vy, '{{ item.description }}');
    $snabbfakta = strpos($vy, '<ItemQuickFacts');
    $allaFalt = strpos($vy, "t('item.show.all_fields')");
    $anteckningen = strpos($vy, '<ItemNotes');
    $aktiviteten = strpos($vy, '<ItemActivityPanel');

    expect($beskrivning)->not->toBeFalse('beskrivningen står inte på översikten')
        ->and($snabbfakta)->not->toBeFalse('snabbfakta står inte på översikten')
        ->and($allaFalt)->not->toBeFalse('*Visa alla fält* står inte på översikten')
        ->and($anteckningen)->not->toBeFalse('anteckningen står inte på översikten')
        ->and($aktiviteten)->not->toBeFalse('aktiviteten står inte på översikten');

    expect($beskrivning)->toBeLessThan($snabbfakta)
        ->and($snabbfakta)->toBeLessThan($allaFalt)
        ->and($allaFalt)->toBeLessThan($anteckningen)
        ->and($anteckningen)->toBeLessThan($aktiviteten);

    $anteckningsrutan = anteckningsrutaTagg($vy, 'ItemNotes');

    expect($anteckningsrutan)->toContain(':item="item"')
        ->and($anteckningsrutan)->toContain(':notes-html="notesHtml"')
        ->and($anteckningsrutan)->toContain(':can-edit="can.update"')
        ->and($anteckningsrutan)->toContain(':update-url="notesUrl"');

    // Den råa texten ritas inte längre i översikten — läsläget ritar
    // `notesHtml` — och anteckningen bär inte längre en egen `<dt>`.
    expect($vy)->not->toContain('{{ item.notes }}')
        ->and($vy)->not->toContain("t('item.show.notes')");
});

/*
 * Klart när: anteckningen ritas ur `notesHtml` som Markdown.
 *
 * Servern ritade HTML:en i issue 324. Här bevisas att komponenten ritar den
 * med `v-html` och inte försöker rita Markdown själv — det är den enda
 * `v-html` som bär innehåll i koden (ADR-0066 § 3).
 */
it('ritar anteckningen ur notesHtml med v-html', function () {
    $komponent = anteckningsrutaKod('components/ItemNotes.vue');

    expect($komponent)->toContain('notesHtml: { type: String, default: null }')
        ->and($komponent)->toContain('v-html="rendered"')
        ->and(substr_count($komponent, 'v-html'))->toBe(1);

    expect($komponent)->not->toContain("'markdown-it'")
        ->and($komponent)->not->toContain('DOMPurify');
});

/*
 * Klart när: den som har `update` kan klicka i rutan, eller trycka Enter på
 * den, och skriva.
 *
 * Klicket fångas av en lyssnare på omslaget därför att den ritade HTML:en kan
 * bära länkar: en `<div>` med `@click` är ingen tabbbar yta (GenomgangTest),
 * och ett klick i en länk ska följa länken i stället för att öppna fältet.
 * Markören sätts i slutet av texten, och fältet växer med den.
 */
it('gör rutan till ett textfält vid klick och Enter med markören i slutet', function () {
    $komponent = anteckningsrutaKod('components/ItemNotes.vue');

    expect($komponent)->toContain("addEventListener('click', onReadClick)")
        ->and($komponent)->toContain("closest('a')")
        ->and($komponent)->toContain('@keydown.enter.prevent="enterEdit"')
        ->and($komponent)->toContain('v-if="editing"')
        ->and($komponent)->toContain('<textarea');

    // Markören i slutet: längden används som både start och slut.
    expect($komponent)->toContain('const end = element.value.length')
        ->and($komponent)->toContain('element.setSelectionRange(end, end)');

    // Fältet växer med texten.
    expect($komponent)->toContain('element.scrollHeight')
        ->and($komponent)->toContain("element.style.height = 'auto'");
});

/*
 * Klart när: texten sparas en sekund efter sista tangenttrycket och när
 * fältet tappar fokus, utan att sidan laddas om.
 *
 * `useHttp` är Inertias vanliga HTTP-förfrågan och inte ett sidbesök
 * (ADR-0066 § 6): ingen `router`, ingen omladdning, och det man skriver
 * medan en sparning pågår står kvar. Bara en förfrågan åt gången — `saving`
 * är grinden och `onFinish` skickar en ändring som kom under tiden.
 */
it('sparar en sekund efter sista tangenttrycket och direkt vid fokusförlust', function () {
    $komponent = anteckningsrutaKod('components/ItemNotes.vue');

    expect($komponent)->toContain("import { useHttp } from '@inertiajs/vue3'")
        ->and($komponent)->toContain('useHttp({ notes:')
        ->and($komponent)->toContain('setTimeout(saveNow, 1000)')
        ->and($komponent)->toContain('@blur="onBlur"')
        ->and($komponent)->toContain('clearTimeout(timer)');

    // Fokusförlusten sparar direkt i stället för att vänta på timern.
    expect($komponent)->toMatch('/function onBlur\(\)[\s\S]*?saveNow\(\)/');

    // Ingen sidnavigering i komponenten.
    expect($komponent)->not->toContain('router.')
        ->and($komponent)->not->toContain('router,')
        ->and($komponent)->not->toContain('usePage');

    // En förfrågan åt gången, och en ändring som kom under tiden skickas när
    // den första är klar.
    expect($komponent)->toMatch('/saving\.value \|\| draft\.value === savedText\.value/')
        ->and($komponent)->toMatch('/! failed && draft\.value !== savedText\.value/');

    // Webbläsaren varnar medan en ändring väntar.
    expect($komponent)->toContain("window.addEventListener('beforeunload', onBeforeUnload)")
        ->and($komponent)->toContain("event.returnValue = ''");
});

/*
 * Klart när: statusraden visar *Saving…*, *Saved* och *Not saved* med
 * *Try again*.
 *
 * Orden kommer ur `lang/en/ui.php`, och `retry` kallar samma sparning igen.
 */
it('visar sparningens tre lägen med en väg tillbaka', function () {
    $komponent = anteckningsrutaKod('components/ItemNotes.vue');

    expect($komponent)->toContain("t('item.notes.saving')")
        ->and($komponent)->toContain("t('item.notes.saved')")
        ->and($komponent)->toContain("t('item.notes.not_saved')")
        ->and($komponent)->toContain("t('item.notes.try_again')")
        ->and($komponent)->toContain('@click="retry"');

    foreach (['saving', 'saved', 'not_saved', 'try_again'] as $nyckel) {
        expect(Lang::get("ui.item.notes.{$nyckel}", [], 'en'))
            ->not->toBe("ui.item.notes.{$nyckel}", "ui.item.notes.{$nyckel} saknas");
    }
});

/*
 * Klart när: en tom anteckning visar fältet direkt för den som har `update`
 * och ingen ruta för den som saknar det.
 *
 * `editing` startar sant bara när användaren får skriva och texten är tom.
 * Rutan ritas när den går att redigera eller när det finns ritad HTML — en
 * tom anteckning för en läsare är alltså ingen tom ruta (ADR-0066 § 8).
 */
it('visar fältet direkt för en tom anteckning och ingen ruta för en läsare', function () {
    $komponent = anteckningsrutaKod('components/ItemNotes.vue');

    expect($komponent)->toContain("const editing = ref(props.canEdit && draft.value.trim() === '')")
        ->and($komponent)->toContain('const showCard = computed(() => props.canEdit || rendered.value !== null)')
        ->and($komponent)->toContain('<UiCard v-if="showCard">')
        ->and($komponent)->toContain("t('item.notes.placeholder')")
        ->and($komponent)->toContain("t('item.notes.markdown_supported')");
});

/*
 * Klart när: den som saknar `update` ser den ritade anteckningen utan fält.
 *
 * Både klicket och Enter är grindade på `canEdit`, och rutan är bara tabbbar
 * när hon får redigera. Läsläget ritar `rendered` med `v-html`.
 */
it('låter en läsare se anteckningen utan fält', function () {
    $komponent = anteckningsrutaKod('components/ItemNotes.vue');

    expect($komponent)->toContain('if (! props.canEdit || editing.value)')
        ->and($komponent)->toContain(':tabindex="canEdit ? 0 : undefined"')
        ->and($komponent)->toContain('v-else')
        ->and($komponent)->toContain('v-html="rendered"');

    // Textfältet finns bara i redigeringsläget, som bara en `canEdit`-anropare
    // kan nå.
    expect($komponent)->toMatch('/<textarea\s+v-if="editing"/');
});

/*
 * Klart när: *Edit item* har inget fält för anteckningen och skickar inte
 * `notes`, och formuläret för ett nytt item har kvar fältet.
 *
 * Fältet ritas bara när `item` är null, och `notes` ligger i samma gren av
 * fältobjektet — annars hade formulärets gamla text skrivit över det som
 * sparats av sig självt (ADR-0066 § 9).
 */
it('ritar anteckningsfältet bara när itemet skapas och skickar det inte vid ändring', function () {
    $form = anteckningsrutaKod('components/ItemForm.vue');

    $position = strpos($form, "t('item.form.notes')");

    expect($position)->not->toBeFalse('anteckningsfältet finns inte kvar');

    $start = strrpos(substr($form, 0, (int) $position), '<FormField');
    $tagg = substr($form, (int) $start, strpos($form, '>', (int) $start) - (int) $start + 1);

    expect($tagg)->toContain('v-if="item === null"');

    // Fältet följer bara med i skapandets gren av fältobjektet.
    expect($form)->toContain("...(props.item === null ? { notes: '' } : {})")
        ->and($form)->not->toContain('notes: props.item?.notes');
});

/*
 * Klart när: typografin för den ritade HTML:en står i en klass i app.css med
 * designsystemets token.
 *
 * Rubriker, listor, kod, citat, tabeller och länkar har regler i
 * `.item-notes-content`, och färgerna är roller ur `@theme`. Ingen
 * typografiplugin — den hade varit ett nytt npm-paket (ADR-0066 § 3).
 */
it('lägger anteckningens typografi i app.css med designsystemets token', function () {
    $css = File::get(resource_path('css/app.css'));

    expect($css)->toContain('.item-notes-content')
        ->and($css)->toContain('var(--color-ink)')
        ->and($css)->toContain('var(--color-ink-muted)')
        ->and($css)->toContain('var(--color-accent)')
        ->and($css)->toContain('var(--color-border)')
        ->and($css)->toContain('var(--color-surface-sunken)')
        ->and($css)->toContain('var(--text-body)');

    foreach (['h1', 'ul', 'ol', 'code', 'pre', 'blockquote', 'table', 'a'] as $element) {
        expect($css)->toContain(".item-notes-content {$element}");
    }

    expect(File::get(base_path('package.json')))->not->toContain('typography');
});

/*
 * Klart när: anteckningsrutans ord kommer ur `lang/en/ui.php`.
 *
 * Komponenten ligger utanför SprakTest:s formulärsvep, så nycklarna prövas
 * här: varje `t()`-uppslag i filen ska finnas i katalogen, annars syns
 * nyckelnamnet i vyn på en produkt vars enda katalog är engelsk.
 */
it('hämtar anteckningsrutans ord ur ui.php', function () {
    $komponent = anteckningsrutaKod('components/ItemNotes.vue');

    preg_match_all("/(?<![\w$.])t\('([a-z0-9_.]+)'/", $komponent, $träffar);

    expect($träffar[1])->toContain('item.show.notes')
        ->toContain('item.notes.placeholder')
        ->toContain('item.notes.markdown_supported');

    foreach (array_unique($träffar[1]) as $nyckel) {
        expect(Lang::get("ui.{$nyckel}", [], 'en'))
            ->not->toBe("ui.{$nyckel}", "ui.{$nyckel} saknas");
    }
});
