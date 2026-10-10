<?php

use Illuminate\Support\Facades\File;

/*
 * M31 · issue 278 — avbockningen syns direkt, se
 * resources/js/components/TodoRow.vue och [[ADR-0056 Flytande navigering]].
 *
 * Filen är ett källkodsprov och inte ett sidprov: det som ska bevisas — att
 * `complete()` tänder ett LOKALT tillstånd innan förfrågan skickas, att
 * cirkeln byts mot den fyllda bocken medan svaret är på väg, och att
 * tillståndet släcks igen vid fel — går att avgöra ur markupen. Att bocken
 * FAKTISKT syns i samma ögonblick kräver en webbläsare och står i PR-kroppen.
 * Samma grepp som UppgiftsradTest och de andra formproven i sviten.
 *
 * Raden ritas på flera ytor (webbens `/tasks`, dashboarden, containerns och
 * itemets flikar) med olika propformer, men alla läser SAMMA komponent — därför
 * prövas formen en gång, i `TodoRow.vue`.
 *
 * Hjälparna har prefixet `avbockningDirekt` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * En källfil med kommentarer borta. Samma tre slag som UppgiftsradTest rensar —
 * blockkommentarer, HTML-kommentarer och radkommentarer — så att docblocken
 * (svenska med flit, AGENTS.md § Språk i koden) varken kan läsas som markup
 * eller råka bära en färgklass.
 */
function avbockningDirektKod(string $sokvag): string
{
    $kod = File::get(resource_path($sokvag));

    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/**
 * Kroppen av `complete()` — från funktionens egen `{` till dess `}` i kolumn
 * noll. Allt annat i funktionen har indragna klammer, så det första `\n}` är
 * funktionens slut. Skärs ut så att ett `onError` eller `completing` någon
 * annanstans i filen inte kan låtsas vara avbockningens.
 */
function avbockningDirektComplete(string $kod): string
{
    preg_match('/function complete\(\)\s*\{(.*?)\n\}/s', $kod, $traff);

    return $traff[1] ?? '';
}

/*
 * Klart när: `bocken visas när knappen trycks` — `TodoRow.vue` bär ett lokalt
 * `completing`-tillstånd och `complete()` tänder det.
 *
 * **Ett lokalt tillstånd och inte Inertias `optimistic`** (Beslut 1): raden
 * används på sex ytor med olika propformer, och `optimistic` skriver om sidans
 * props. Flaggan tänds FÖRE `form.post` — annars vore den serverns svar och
 * inte klicket.
 */
it('bocken visas när knappen trycks', function () {
    $rad = avbockningDirektKod('js/components/TodoRow.vue');

    expect($rad)->toContain('const completing = ref(false)');

    $kropp = avbockningDirektComplete($rad);

    expect($kropp)->not->toBeEmpty('complete() saknas i TodoRow.vue');
    expect($kropp)->toContain('completing.value = true')
        ->toContain('form.post');

    // Tänds innan förfrågan skickas: bocken hör till klicket, inte svaret.
    expect(strpos($kropp, 'completing.value = true'))
        ->toBeLessThan(strpos($kropp, 'form.post'));
});

/*
 * Klart när: `bocken försvinner vid fel` — `complete()` skickar `onError` och
 * `onCancel` som båda sätter `completing.value = false`.
 *
 * De två fångar varsitt fel: `onError` ett domänfel (422/403) och `onCancel`
 * ett avbrott när en nyare förfrågan tar över. Utan den andra hade bocken blivit
 * kvar på en rad inget svar kom tillbaka till.
 */
it('bocken försvinner vid fel', function () {
    $kropp = avbockningDirektComplete(avbockningDirektKod('js/components/TodoRow.vue'));

    expect($kropp)->not->toBeEmpty('complete() saknas i TodoRow.vue');

    expect($kropp)->toMatch('/onError:[^,]*completing\.value = false/', 'onError återställer completing');
    expect($kropp)->toMatch('/onCancel:[^,]*completing\.value = false/', 'onCancel återställer completing');
});

/*
 * Klart när: `knappen står kvar medan svaret är på väg` — `completing` ingår
 * inte i `isDone`, och knappens gren ritar `bg-success` när `completing` är
 * sant.
 *
 * **Flaggan byter inte grenen** (Beslut 2): gör den det försvinner knappen, och
 * tangentbordsfokuset med den. I stället ritas samma fyllda gröna bock som
 * grenen `isDone` ritar, inne i knappen. Avbockningsgrenen skärs ut först, så
 * att `bg-success` hör till KNAPPEN och inte till den klara radens egen bock.
 */
it('knappen står kvar medan svaret är på väg', function () {
    $rad = avbockningDirektKod('js/components/TodoRow.vue');

    preg_match('/const isDone = computed\((.*?)\);/s', $rad, $done);
    expect($done)->not->toBeEmpty('isDone saknas i TodoRow.vue');
    expect($done[1])->not->toContain('completing');

    preg_match('#<form v-if="entry\.can\.update".*?</form>#s', $rad, $form);
    expect($form)->not->toBeEmpty('avbockningsformuläret saknas i TodoRow.vue');

    $knapp = $form[0];

    // Den tomma cirkeln ritas bara medan svaret inte är på väg …
    expect($knapp)->toContain('v-if="! completing"');

    // … och ersätts då av den fyllda gröna bocken, samma som den klara raden.
    expect($knapp)->toContain('bg-success')
        ->toContain('v-else')
        ->toContain('m5 13 4 4L19 7');
});

/*
 * Klart när: knappen dämpas inte när bocken visas (Beslut 3).
 *
 * `disabled:opacity-50` gäller medan knappen är `:disabled` — vilket den är
 * medan svaret är på väg — och hade gjort den gröna bocken grå.
 * `disabled:opacity-100` ligger över den medan `completing` är sant.
 */
it('knappen dämpas inte när bocken visas', function () {
    $rad = avbockningDirektKod('js/components/TodoRow.vue');

    preg_match('#<form v-if="entry\.can\.update".*?</form>#s', $rad, $form);
    expect($form)->not->toBeEmpty('avbockningsformuläret saknas i TodoRow.vue');

    expect($form[0])->toContain('disabled:opacity-50')
        ->toContain("'disabled:opacity-100': completing");
});
