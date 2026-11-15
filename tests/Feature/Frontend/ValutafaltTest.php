<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Account;
use App\Models\Container;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 1253 · Valutafältet, se [[ADR-0078 Valutor ur ISO 4217]] § 2.
 *
 * Fältets beteende — att det filtrerar medan man skriver, att pilar och Enter
 * väljer, att det återgår till det senast valda värdet — kräver en webbläsare
 * och prövas för hand i PR:en. Det som går att avgöra på serversidan är formen
 * och kopplingen: att komponenten är en kombinerad ruta, att den hämtar listan
 * latt, att den bara sätter ett värde ur listan, och att de tre ytorna
 * använder den.
 *
 * **Tre ytor, fyra filer.** Ytorna är kostnaden, containern och kontot.
 * Kostnaden har två fält — det nya i CostEntryForm.vue och redigeringens i
 * ItemCostSection.vue — och de delar komponenten. ItemCostSection.vue står
 * utanför issue 1253:s `In scope` och är deklarerad under `Utanför rutan:` i
 * PR:en.
 *
 * Hjälparna har prefixet valutafalt* — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Ett konto med en medlem och en container under kontot.
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function valutafaltKontext(): array
{
    $konto = Account::factory()->create();
    $anvandare = User::factory()->create(['locale' => 'en_GB']);
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    $container = Container::factory()->for($konto, 'account')->create();

    return [$konto, $anvandare, $container];
}

it('använder CurrencyField på de tre valutaytorna', function () {
    // Kostnaden (ny rad och redigering), containern och kontot.
    foreach ([
        'js/components/CostEntryForm.vue',
        'js/components/ItemCostSection.vue',
        'js/pages/Containers/Edit.vue',
        'js/components/AccountSettingsForm.vue',
    ] as $sokvag) {
        expect(vueMall($sokvag))->toContain('<CurrencyField');
    }
});

it('är en kombinerad ruta som bara kan ge ett värde ur listan', function () {
    $mall = vueMall('js/components/CurrencyField.vue');

    expect($mall)->toContain('role="combobox"')
        ->toContain('role="listbox"')
        ->toContain('role="option"')
        // Träffytan (GenomgangTest § 68a): varje rad är en knapp på minst
        // 44 px, och fältet självt bär samma höjd.
        ->toContain('min-h-11');

    $skript = vueSkript('js/components/CurrencyField.vue');

    // Filtreringen: kod som börjar med texten först, sedan namn som
    // innehåller den (ADR-0078 § 2).
    expect($skript)->toContain('startsWith')
        ->toContain('includes')
        // Ett val skriver koden, aldrig det man skrev.
        ->toContain("emit('update:modelValue', option.code)")
        // Och en text som inte matchar en kod lämnar det senast valda värdet
        // kvar — se onBlur().
        ->toContain('text.value = display.value');
});

it('hämtar den delade listan latt när fältet får fokus', function () {
    $skript = vueSkript('js/components/CurrencyField.vue');

    // Samma mönster som itemTargets i ItemTargetPicker: en partiell
    // omladdning av just den nyckeln, en gång.
    expect($skript)->toContain('router.reload')
        ->toContain("only: ['currencies']");
});

it('delar valutorna som en lat propp med kod och namn', function () {
    withoutVite();

    [, $anvandare, $container] = valutafaltKontext();

    // En vanlig sidladdning bär den inte alls — fältet hämtar den först vid
    // fokus, och en sida där inget valutafält öppnats betalar ingenting.
    actingAs($anvandare)->get("/containers/{$container->ulid}/edit")->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->missing('currencies'),
    );

    $svar = actingAs($anvandare)->get("/containers/{$container->ulid}/edit", [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(Request::create('/')),
        'X-Inertia-Partial-Component' => 'Containers/Edit',
        'X-Inertia-Partial-Data' => 'currencies',
    ])->assertOk();

    /** @var list<array{code: string, name: string}> $valutor */
    $valutor = $svar->json('props.currencies');
    $koder = array_column($valutor, 'code');

    // Användaren är engelsk: namnen kommer ur ICU på hennes språk.
    expect($koder)->toContain('SEK', 'EUR', 'USD', 'NOK', 'DKK');

    // Egen förväntan: `->not` efter `toContain` fäller phpstan (Pest-extensionen
    // typmarkerar inte negationen i en kedja).
    expect($koder)->not->toContain('DEM', 'XAU');

    expect(collect($valutor)->firstWhere('code', 'SEK'))
        ->toBe(['code' => 'SEK', 'name' => 'Swedish Krona']);
});
