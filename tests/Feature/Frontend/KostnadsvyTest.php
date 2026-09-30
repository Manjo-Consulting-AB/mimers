<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\CostEntry;
use App\Models\Item;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 168 · Kostnadsraderna i webben — itemets kostnadsflik. Se
 * app/Http/Controllers/CostEntryController.php, app/Actions/Cost/*,
 * resources/js/components/ItemCostSection.vue, [[ADR-0050 Desktopdesignen]]
 * § 8, [[ADR-0016 Kostnadsregistrering]] och [[ADR-0037 Valutans arv]].
 *
 * **Ytan är webben, och logiken är API:ets.** Rader, valutaarv, beloppstolkning
 * och leverantörsuppslag bor i App\Actions\Cost sedan issue 168, och provet
 * här prövar den väg en webbläsare tar: formuläret, omdirigeringen och
 * fältfelet. Att `/api` beter sig oförändrat prövas av KostnadCrudTest och
 * LeverantorTest — de filerna är orörda av issuen och körs som de är.
 *
 * **Ett domänfel blir ett fältfel och aldrig en JSON-kropp.** `MinorUnits`
 * kastar App\Exceptions\Api\ApiException, som svarar `{"error":{…}}` var den
 * än kastas — också mitt i en webbsida. Kontrollern översätter den, och provet
 * kontrollerar både felpåsen och frånvaron av `"error"` i svaret.
 *
 * Hjälparna har prefixet `kostnadsvy` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Ett konto med en medlem, en container med egen valuta och ett item under
 * kontot. Kontot är svenskt, så meningarna kan jämföras mot `Lang::get(…, 'en')`.
 *
 * @return array{0: Account, 1: User, 2: Container, 3: Item}
 */
function kostnadsvyKontext(string $valuta = 'SEK'): array
{
    $konto = Account::factory()->create(['locale' => 'sv_SE', 'currency' => 'SEK']);
    $anvandare = User::factory()->create(['locale' => 'sv_SE']);
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    $container = Container::factory()->for($konto, 'account')->create(['currency' => $valuta]);
    $item = Item::factory()->for($container, 'container')->create([
        'name' => 'Motorn',
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $konto->id,
    ]);

    return [$konto, $anvandare, $container, $item];
}

/**
 * En mottagare UTANFÖR ägarkontot, med en itemgrant på angiven nivå.
 *
 * @return array{0: User, 1: Account} mottagaren och hennes EGET konto
 */
function kostnadsvyMottagare(Container $container, Item $item, string $niva): array
{
    $mottagare = User::factory()->create(['locale' => 'sv_SE']);

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item->id,
        'grantee_type' => 'user',
        'grantee_id' => $mottagare->id,
        'level' => $niva,
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    $egetKonto = Account::factory()->create();
    $egetKonto->users()->attach($mottagare, ['role' => 'owner']);

    return [$mottagare, $egetKonto];
}

/**
 * En kostnadsrad på itemet. Varje fält kan överstyras.
 *
 * @param  array<string, mixed>  $attribut
 */
function kostnadsvyRad(Item $item, array $attribut = []): CostEntry
{
    return CostEntry::factory()->for($item, 'item')->create(array_merge([
        'incurred_on' => '2026-04-12',
        'amount' => 120050,
        'currency' => 'SEK',
        'description' => 'Impeller',
        'supplier' => null,
        // Raden tillskrivs containerns ägarkonto — formen `CostEntryResource`
        // bär kontots ULID med, och den som skapade raden för hand i provet är
        // ägarkontots medlem.
        'created_by_account_id' => $item->container->account_id,
    ], $attribut));
}

function kostnadsvyUrl(Container $container, Item $item): string
{
    return "/containers/{$container->ulid}/items/{$item->ulid}";
}

/**
 * En kropp för webbens kostnadsformulär. Varje fält kan överstyras.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function kostnadsvyKropp(array $overrides = []): array
{
    return array_merge([
        'incurred_on' => '2026-04-12',
        'amount' => '1200,50',
        'currency' => 'EUR',
        'description' => 'Impeller',
        'supplier' => null,
    ], $overrides);
}

/**
 * Källkoden för sektionen, med kommentarerna borttagna — samma städning som
 * UtlaningsvyTest och SprakTest gör. Utan den hade en mening i ett docblock
 * kunnat bevisa eller motbevisa ett prov, och det är koden som gäller.
 */
function kostnadsvyKomponent(): string
{
    $kod = File::get(resource_path('js/components/ItemCostSection.vue'));
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/**
 * Leverantörslistan, hämtad som klienten hämtar den: en partiell omladdning av
 * den optionala proppen. Versionsheadern är den samma middleware skulle svara
 * med — ett anrop med fel version är 409, och det är inte det här provet
 * handlar om.
 */
function kostnadsvyLeverantorer(User $anvandare, Container $container, Item $item): TestResponse
{
    app()->forgetScopedInstances();

    return actingAs($anvandare)->get(kostnadsvyUrl($container, $item), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(Request::create('/')),
        'X-Inertia-Partial-Component' => 'Containers/Items/Show',
        'X-Inertia-Partial-Data' => 'costSuppliers',
    ])->assertOk();
}

// --- listan: itemets rader, nyast först ----------------------------------

it('bär itemets kostnadsrader nyast först', function () {
    withoutVite();

    [, $anvandare, $container, $item] = kostnadsvyKontext();

    $aldst = kostnadsvyRad($item, ['incurred_on' => '2026-01-05', 'description' => 'Vinterförvaring']);
    $forst = kostnadsvyRad($item, ['incurred_on' => '2026-04-12', 'description' => 'Impeller']);
    // Samma dag som den förra, och skapad EFTER: `id` fallande är
    // andrasorteringen, så den här står först av de två.
    $senast = kostnadsvyRad($item, ['incurred_on' => '2026-04-12', 'description' => 'Oljebyte']);

    // Ett annat item i samma container: dess rad hör inte hit.
    $annat = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $container->account_id,
    ]);
    kostnadsvyRad($annat, ['incurred_on' => '2026-12-01', 'description' => 'Drev']);

    actingAs($anvandare)->get(kostnadsvyUrl($container, $item))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('Containers/Items/Show')
            ->has('costs', 3)
            ->where('costs.0.ulid', $senast->ulid)
            ->where('costs.1.ulid', $forst->ulid)
            ->where('costs.2.ulid', $aldst->ulid)
            // Formen är CostEntryResource, samma som `/api` svarar med:
            // beloppet är heltalet i minsta enhet och kontot följer med.
            ->where('costs.0.amount', 120050)
            ->where('costs.0.currency', 'SEK')
            ->where('costs.0.incurred_on', '2026-04-12')
            ->where('costs.0.created_by_account', $container->account->ulid)
    );
});

it('bär containerns valuta som formulärets förval', function () {
    withoutVite();

    [$konto, $anvandare, $container, $item] = kostnadsvyKontext('NOK');

    actingAs($anvandare)->get(kostnadsvyUrl($container, $item))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('costDefaults.currency', 'NOK'),
    );

    // Utan egen valuta ärver containern kontots ([[ADR-0037 Valutans arv]]).
    $utanEgen = Container::factory()->for($konto, 'account')->create(['currency' => null]);
    $annatItem = Item::factory()->for($utanEgen, 'container')->create();

    actingAs($anvandare)->get(kostnadsvyUrl($utanEgen, $annatItem))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('costDefaults.currency', 'SEK'),
    );
});

// --- skrivningarna: samma regler som /api --------------------------------

it('lägger till en kostnad från webben och stannar på fliken', function () {
    withoutVite();

    [, $anvandare, $container, $item] = kostnadsvyKontext();

    $svar = actingAs($anvandare)->post(kostnadsvyUrl($container, $item).'/costs', kostnadsvyKropp([
        'supplier' => 'Volvo Penta',
    ]));

    // Omdirigeringen bär fliken: en skrivning som landade på översikten hade
    // tappat läsarens plats (Beslut 2).
    $svar->assertRedirect(kostnadsvyUrl($container, $item).'?tab=costs');
    $svar->assertSessionHas('status', 'cost-created');

    $kostnad = CostEntry::query()->firstOrFail();

    expect($kostnad->item_id)->toBe($item->id);
    expect($kostnad->container_id)->toBe($container->id);
    expect($kostnad->amount)->toBe(120050);
    expect($kostnad->currency)->toBe('EUR');
    expect($kostnad->supplier)->toBe('Volvo Penta');
    expect($kostnad->created_by_user_id)->toBe($anvandare->id);
    expect($kostnad->created_by_account_id)->toBe($container->account_id);

    actingAs($anvandare)->get(kostnadsvyUrl($container, $item))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('costs', 1)
            ->where('costs.0.description', 'Impeller')
    );
});

it('ger en kostnad utan valuta containerns valuta', function () {
    withoutVite();

    [, $anvandare, $container, $item] = kostnadsvyKontext('NOK');

    // Fältet är förifyllt, men en klient som skickar tomt ska få samma svar
    // som den som skickar containerns värde: arvet ligger i
    // App\Actions\Cost\CreateCostEntry, och tomt betyder "containern
    // föreslår" ([[ADR-0037 Valutans arv]]).
    actingAs($anvandare)
        ->post(kostnadsvyUrl($container, $item).'/costs', kostnadsvyKropp(['currency' => '']))
        ->assertRedirect();

    expect(CostEntry::query()->firstOrFail()->currency)->toBe('NOK');
});

it('låter en vald valuta vinna över arvet', function () {
    withoutVite();

    [, $anvandare, $container, $item] = kostnadsvyKontext('SEK');

    actingAs($anvandare)
        ->post(kostnadsvyUrl($container, $item).'/costs', kostnadsvyKropp(['currency' => 'usd']))
        ->assertRedirect();

    // Värdet sparas ordagrant, versalnormaliserat av den delade requesten —
    // arvet är ett förslag, aldrig ett tvång.
    expect(CostEntry::query()->firstOrFail()->currency)->toBe('USD');
});

it('sparar ett negativt belopp', function () {
    withoutVite();

    [, $anvandare, $container, $item] = kostnadsvyKontext();

    // En kreditfaktura är en rad som alla andra
    // ([[ADR-0016 Kostnadsregistrering]]): beloppet får vara negativt, och
    // ingen mening i vyn säger något annat.
    actingAs($anvandare)
        ->post(kostnadsvyUrl($container, $item).'/costs', kostnadsvyKropp([
            'amount' => '-1200,50',
            'description' => 'Returnerad del',
        ]))
        ->assertRedirect();

    expect(CostEntry::query()->firstOrFail()->amount)->toBe(-120050);
});

it('gör ett ogiltigt belopp till ett fältfel och inte en JSON-kropp', function () {
    withoutVite();

    [, $anvandare, $container, $item] = kostnadsvyKontext();

    $svar = actingAs($anvandare)->post(
        kostnadsvyUrl($container, $item).'/costs',
        kostnadsvyKropp(['amount' => 'två tusen']),
    );

    // `MinorUnits::parse()` kastar `cost.amount_invalid`, och ApiException
    // svarar `{"error":{…}}` var den än kastas — mitt i en webbsida hade
    // användaren fått rå JSON. Kontrollern översätter den till ett fältfel
    // (Beslut 2), och felet hamnar på `amount`.
    $svar->assertSessionHasErrors('amount');
    expect($svar->getContent())->not->toContain('"error"');

    $mening = session('errors')->get('amount')[0];

    expect($mening)->toBe(Lang::get('ui.error.cost.amount_invalid', [], 'en'));
    expect(CostEntry::query()->count())->toBe(0);
});

it('ändrar en kostnad från webben', function () {
    withoutVite();

    [, $anvandare, $container, $item] = kostnadsvyKontext();

    $kostnad = kostnadsvyRad($item);

    actingAs($anvandare)
        ->patch(kostnadsvyUrl($container, $item)."/costs/{$kostnad->ulid}", [
            'incurred_on' => '2026-05-01',
            'amount' => '1500',
            'currency' => 'SEK',
            'description' => 'Ny impeller',
            'supplier' => 'Biltema',
        ])
        ->assertRedirect(kostnadsvyUrl($container, $item).'?tab=costs')
        ->assertSessionHas('status', 'cost-updated');

    $kostnad->refresh();

    expect($kostnad->amount)->toBe(150000);
    expect($kostnad->description)->toBe('Ny impeller');
    expect($kostnad->supplier)->toBe('Biltema');
    expect($kostnad->incurred_on->toDateString())->toBe('2026-05-01');
});

it('rör inte beloppet vid en ändring av bara beskrivningen', function () {
    withoutVite();

    [, $anvandare, $container, $item] = kostnadsvyKontext();

    $kostnad = kostnadsvyRad($item, ['amount' => 120050, 'currency' => 'SEK']);

    // Kroppen bär bara beskrivningen — det är vad ItemCostSection.vue skickar
    // när varken beloppet eller valutan har rörts (se `patchBody()` där).
    // Fältet är förifyllt med beloppet omräknat till huvudenhet, och för en
    // valuta där CLDR och servern inte är överens om decimalerna (IQD, RSD,
    // LAK) hade den strängen skrivit om beloppet med en faktor 100 eller 1000.
    actingAs($anvandare)
        ->patch(kostnadsvyUrl($container, $item)."/costs/{$kostnad->ulid}", [
            'description' => 'Ny impeller',
        ])
        ->assertRedirect(kostnadsvyUrl($container, $item).'?tab=costs')
        ->assertSessionHas('status', 'cost-updated')
        ->assertSessionHasNoErrors();

    $kostnad->refresh();

    expect($kostnad->description)->toBe('Ny impeller');
    expect($kostnad->amount)->toBe(120050);
    expect($kostnad->currency)->toBe('SEK');
    expect($kostnad->incurred_on->toDateString())->toBe('2026-04-12');

    // Händelseloggen nämner bara beskrivningen — beloppet och valutan står
    // inte i `changed`, och deras värden följer inte med i `meta`
    // ([[ADR-0017 Missbruksvektorer]] § 7).
    $meta = AuditLog::query()
        ->where('action', AuditLog::ACTION_COST_ENTRY_UPDATED)
        ->firstOrFail()
        ->meta;

    expect($meta['changed'])->toBe(['description']);
    expect($meta)->not->toHaveKey('values');

    // Och vyn skickar bara de fält som ändrats: beloppet och valutan följer
    // med först när någon av dem har rört det sparade värdet.
    $vy = kostnadsvyKomponent();

    expect($vy)->toContain('savedAmount')
        ->toContain('savedCurrency');

    expect($vy)->toMatch('/body\.amount = editForm\.amount;\s+body\.currency = editForm\.currency;/');
    expect($vy)->toMatch('/editForm\.amount !== savedAmount \|\| editForm\.currency !== savedCurrency/');
});

it('tar bort en kostnad från webben', function () {
    withoutVite();

    [, $anvandare, $container, $item] = kostnadsvyKontext();

    $kostnad = kostnadsvyRad($item);

    actingAs($anvandare)
        ->delete(kostnadsvyUrl($container, $item)."/costs/{$kostnad->ulid}")
        ->assertRedirect(kostnadsvyUrl($container, $item).'?tab=costs')
        ->assertSessionHas('status', 'cost-deleted');

    // Mjuk radering: raden ligger kvar med `deleted_at` satt och hamnar inte i
    // papperskorgen — den listar fyra typer och behåller fyra (Beslut 2).
    expect($kostnad->fresh()->deleted_at)->not->toBeNull();
    expect(CostEntry::query()->count())->toBe(0);

    actingAs($anvandare)->get(kostnadsvyUrl($container, $item))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->has('costs', 0),
    );
});

// --- leverantörerna: en optional prop -------------------------------------

it('skickar leverantörerna bara vid en partiell omladdning', function () {
    withoutVite();

    [, $anvandare, $container, $item] = kostnadsvyKontext();

    kostnadsvyRad($item, ['supplier' => 'Volvo Penta']);
    kostnadsvyRad($item, ['supplier' => 'Volvo Penta']);
    kostnadsvyRad($item, ['supplier' => 'Biltema']);

    // En vanlig sidladdning bär den inte: uppslaget är ett inmatningsstöd för
    // formuläret, och den som bara läser raderna ska inte betala för det
    // (Beslut 3, samma konstruktion som `recentVisits`).
    actingAs($anvandare)->get(kostnadsvyUrl($container, $item))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->missing('costSuppliers'),
    );

    $svar = kostnadsvyLeverantorer($anvandare, $container, $item);

    // Frekvens fallande, namn stigande som andrasortering — samma svar som
    // `GET /api/containers/{container}/costs/suppliers` ger, ur samma action.
    expect($svar->json('props.costSuppliers'))->toBe([
        ['supplier' => 'Volvo Penta', 'count' => 2],
        ['supplier' => 'Biltema', 'count' => 1],
    ]);
});

it('hämtar leverantörerna på nytt efter varje skrivning', function () {
    withoutVite();

    [, $anvandare, $container, $item] = kostnadsvyKontext();

    kostnadsvyRad($item, ['supplier' => 'Volvo Penta']);

    // Varför frågan måste ställas om: en skrivning svarar med en omdirigering
    // tillbaka till itemvyn, och den sidan är en vanlig besökning — samma sida
    // som provet ovan visar saknar `costSuppliers`. Formuläret ligger kvar i
    // samma komponent genom skrivningen, så `onMounted` körs aldrig om, och
    // utan en ny fråga stod datalisten tom efter första sparade raden.
    $svar = actingAs($anvandare)->post(
        kostnadsvyUrl($container, $item).'/costs',
        kostnadsvyKropp(['supplier' => 'Biltema']),
    );

    $svar->assertRedirect(kostnadsvyUrl($container, $item).'?tab=costs');

    actingAs($anvandare)
        ->get(kostnadsvyUrl($container, $item).'?tab=costs')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->missing('costSuppliers'));

    // Och vyn ställer frågan om: en gång vid monteringen och en gång per
    // skrivning — skapa, ändra och ta bort. Varje mönster är bundet till sitt
    // eget anrop, så en `loadSuppliers` någon annanstans i filen räddar inte
    // en skrivväg som tappat sin.
    $vy = kostnadsvyKomponent();

    expect($vy)->toContain('onMounted(loadSuppliers)');

    // Skapa: fälten nollställs och frågan ställs om.
    expect($vy)->toMatch("/form\.reset\([^)]*\);\s+loadSuppliers\(\);/");

    // Ändra: redigeringen stängs och frågan ställs om.
    expect($vy)->toMatch('/editing\.value = null;\s+loadSuppliers\(\);/');

    // Ta bort.
    expect($vy)->toMatch('/onSuccess:\s*loadSuppliers,/');

    // Ett avvisat formulär svarar också med en besökning — båda `onError`-
    // blocken fokuserar felet och frågar om.
    expect(preg_match_all('/focusFirstError\(\);\s+loadSuppliers\(\);/', $vy))->toBe(2);
});

// --- kostnadskroken: datumet ur adressen ----------------------------------

it('läser kostnadskrokens datum ur adressen och förifyller fältet', function () {
    // Beslut 4. Erbjudandet att registrera en kostnad EFTER en avbockning i
    // webben byggs inte här, men adressen kroken leder till (`?incurred_on=`)
    // tas emot: fältet öppnas på förekomstens datum i stället för på dagens.
    //
    // Provet läser formen i källkoden: utan en webbläsare går det inte att
    // se vilket värde fältet har, och det som går att avgöra är att vyn
    // hämtar strängen ur adressen och prövar dess form innan den används.
    $vy = kostnadsvyKomponent();

    expect($vy)->toContain("get('incurred_on')")
        ->toContain('\d{4}-\d{2}-\d{2}');

    // Och den ligger i fältets förval, inte i en beräkning någon annanstans.
    expect($vy)->toContain('incurred_on: hookedDate()');
});

// --- grindarna: itemets pinnar, en per handling ---------------------------

it('visar raderna för en läsare men nekar alla tre skrivningarna', function () {
    withoutVite();

    [, , $container, $item] = kostnadsvyKontext();

    kostnadsvyRad($item, ['description' => 'Impeller']);

    [$lasare] = kostnadsvyMottagare($container, $item, 'read');

    // Läsning räcker för att SE raderna (grinden är `view` på itemet) men
    // inte för att skriva: `create`, `update` och `delete` är egna pinnar.
    actingAs($lasare)
        ->post(kostnadsvyUrl($container, $item).'/costs', kostnadsvyKropp())
        ->assertForbidden();

    $kostnad = CostEntry::query()->firstOrFail();

    actingAs($lasare)
        ->patch(kostnadsvyUrl($container, $item)."/costs/{$kostnad->ulid}", ['description' => 'Något annat'])
        ->assertForbidden();

    actingAs($lasare)
        ->delete(kostnadsvyUrl($container, $item)."/costs/{$kostnad->ulid}")
        ->assertForbidden();

    expect(CostEntry::query()->count())->toBe(1);
    expect($kostnad->fresh()->deleted_at)->toBeNull();

    actingAs($lasare)->get(kostnadsvyUrl($container, $item))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('costs', 1)
            ->where('costs.0.description', 'Impeller')
            ->where('can.create', false)
            ->where('can.update', false)
            ->where('can.delete', false)
    );

    // Grinden är presentation i vyn och auktorisering i kontrollern: en
    // användare som inte får skriva ser ingen skrivyta.
    $vy = kostnadsvyKomponent();

    expect($vy)->toContain('v-if="can.create"')
        ->toContain('v-if="can.update"')
        ->toContain('v-if="can.delete"');
});

it('ger 404 för en kostnad på ett annat item', function () {
    withoutVite();

    [, $anvandare, $container, $item] = kostnadsvyKontext();

    $annat = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $container->account_id,
    ]);

    $frammande = kostnadsvyRad($annat);

    // `{cost}` binds genom App\Models\Item::costs() via scopeBindings(), så en
    // ULID från ett annat item löser aldrig upp (Beslut 2).
    actingAs($anvandare)
        ->patch(kostnadsvyUrl($container, $item)."/costs/{$frammande->ulid}", ['description' => 'Nej'])
        ->assertNotFound();

    actingAs($anvandare)
        ->delete(kostnadsvyUrl($container, $item)."/costs/{$frammande->ulid}")
        ->assertNotFound();

    expect($frammande->fresh()->description)->toBe('Impeller');
    expect($frammande->fresh()->deleted_at)->toBeNull();
});
