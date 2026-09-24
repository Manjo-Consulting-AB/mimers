<?php

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 116 · Historikflikarna. Se App\Http\Controllers\
 * ContainerHistoryController, app/Http/Controllers/ItemController::show(),
 * resources/js/pages/Containers/History.vue,
 * resources/js/components/HistoryRow.vue,
 * resources/js/layouts/containerSections.js och [[ADR-0043 Tre loggar]]
 * § Händelseloggen.
 *
 * **Filen prövar ytan och inte läsregeln.** Vilka rader en användare får läsa
 * avgörs av App\Actions\Audit\ListAuditEvents och prövas i
 * tests/Feature/Revision/LasregelTest.php (issue 108). Här prövas att flikarna
 * FINNS, att de läser genom den regeln — en gäst ser sina egna rader och
 * ägaren allas, ur samma sida — och att raden blir en mening: fälten en
 * ändring rörde, de neutrala ersättarna, datumet och att itemets rader bara
 * hämtas när fliken är aktiv.
 *
 * **Raderna skrivs med fabriken och inte genom RecordAuditEvent.** Proven
 * handlar om LÄSNINGEN; att varje handling skriver exakt en rad är issue
 * 109–111:s ärende och prövas i tests/Feature/Revision/. En rad skriven direkt
 * i loggen är dessutom den enda vägen till en rad med ett `created_at` vi
 * väljer, och ordningen "nyast först" går inte att bevisa utan det.
 *
 * **Det som INTE prövas här** är det som kräver en webbläsare: att raden ser ut
 * som *Senaste aktiviteter* i docs/Design/container.jpeg, att flikraden tänds
 * på rätt rad, och att datumet skrivs i användarens tidszon. Formen på
 * `UiListRow` prövas i YtornaTest, träffytan i GenomgangTest, och handprovet
 * står i PR-kroppen.
 *
 * Hjälparna har prefixet `historik` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Ett konto med en medlem som äger en container.
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function historikKontext(): array
{
    $konto = Account::factory()->create();
    $ägare = User::factory()->create();
    $konto->users()->attach($ägare, ['role' => 'owner']);
    $container = Container::factory()->for($konto, 'account')->create();

    return [$konto, $ägare, $container];
}

/**
 * En loggrad skriven DIREKT i loggen, med en tid vi väljer.
 *
 * @param  array<string, mixed>  $meta
 */
function historikRad(
    Container $container,
    Account $account,
    ?User $handlande,
    string $action,
    ?Item $item = null,
    array $meta = [],
    ?Carbon $när = null,
): AuditLog {
    return AuditLog::factory()->create([
        'container_id' => $container->id,
        'account_id' => $account->id,
        'user_id' => $handlande?->id,
        'item_id' => $item?->id,
        'action' => $action,
        'meta' => $meta,
        'created_at' => $när ?? now(),
    ]);
}

/**
 * En containerbred åtkomst på `write` för en gäst — samma form som
 * containerflikLasare() i ContainerflikTest, men en pinne högre: en gäst som
 * SKRIVER är den läsare historiken är till för.
 */
function historikGast(Container $container): User
{
    $gast = User::factory()->create();

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => null,
        'grantee_type' => 'user',
        'grantee_id' => $gast->id,
        'level' => 'write',
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    return $gast;
}

/**
 * Källkoden med kommentarer borta. Samma tre slag som GenomgangTest rensar:
 * blockkommentarer, HTML-kommentarer och radkommentarer — docblocken är
 * svenska med flit (AGENTS.md § Språk i koden), och en regel som letar efter en
 * markup eller en nyckel ska inte kunna nöjas av en mening i ett docblock.
 */
function historikKod(string $sokvag): string
{
    $kod = File::get(resource_path($sokvag));
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/**
 * Containerns historikflik som URL.
 */
function historikUrl(Container $container): string
{
    return "/containers/{$container->ulid}/history";
}

/**
 * Itemets detaljvy som URL, med fliken.
 */
function historikItemUrl(Container $container, Item $item, ?string $tab = null): string
{
    $url = "/containers/{$container->ulid}/items/{$item->ulid}";

    return $tab === null ? $url : "{$url}?tab={$tab}";
}

/*
 * Klart när: containern har en historikflik som listar containerns rader nyast
 * först.
 *
 * Sidan ligger på en egen rutt, som de andra flikarna i `containerTabs`
 * (issue 101), och raderna kommer ur ListAuditEvents (issue 108) — vyn
 * filtrerar ingenting själv, och ordningen är Actionens: `created_at` fallande
 * med `id` fallande, så två rader skrivna i samma sekund ändå får en stabil
 * ordning.
 *
 * Tre rader med var sin tid, och den NYASTE först: ett prov som bara räknade
 * raderna hade godtagit vilken ordning som helst, och "nyast först" är vad en
 * historik är till för.
 */
it('containern har en historikflik som listar containerns rader nyast först', function () {
    withoutVite();

    [$konto, $ägare, $pärm] = historikKontext();

    $äldst = historikRad($pärm, $konto, $ägare, AuditLog::ACTION_CONTAINER_CREATED, när: now()->subDays(3));
    $mellan = historikRad($pärm, $konto, $ägare, AuditLog::ACTION_CATEGORY_CREATED, när: now()->subDay());
    $nyast = historikRad($pärm, $konto, $ägare, AuditLog::ACTION_CONTAINER_UPDATED, när: now());

    actingAs($ägare)->get(historikUrl($pärm))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Containers/History')
            ->has('rows', 3)
            ->where('rows.0.ulid', $nyast->ulid)
            ->where('rows.1.ulid', $mellan->ulid)
            ->where('rows.2.ulid', $äldst->ulid)
            // Varje rad bär sin handling oförändrad: det är nyckeln vyn slår
            // upp meningen med, och en rad utan handling är ingen rad.
            ->where('rows.0.action', AuditLog::ACTION_CONTAINER_UPDATED)
        );

    // Och fliken finns i flikraden, med sin egen adress — samma lista
    // layouten renderar ur (resources/js/layouts/containerSections.js).
    expect(historikKod('js/layouts/containerSections.js'))
        ->toContain("key: 'history'")
        ->toContain('`/containers/${ulid}/history`');
});

/*
 * Klart när: itemet har en historikflik som listar itemets rader.
 *
 * Itemets rader är de med itemets `item_id`, oavsett subjekt (issue 107), och
 * containerns EGNA rader hör inte hit: `?tab=history` på itemet visar itemets
 * historia och inte containerns. Provet lägger båda sorternas rader i loggen
 * och bevisar att bara den ena kommer med — en flik som visade allt i
 * containern hade sett rätt ut i en container med ett enda item.
 */
it('itemet har en historikflik som listar itemets rader', function () {
    withoutVite();

    [$konto, $ägare, $pärm] = historikKontext();

    $motorn = Item::factory()->for($pärm, 'container')->create(['name' => 'Motorn']);

    $containerRad = historikRad($pärm, $konto, $ägare, AuditLog::ACTION_CONTAINER_CREATED, när: now()->subDay());
    $itemRad = historikRad($pärm, $konto, $ägare, AuditLog::ACTION_ITEM_CREATED, $motorn, när: now());

    actingAs($ägare)->get(historikItemUrl($pärm, $motorn, 'history'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Containers/Items/Show')
            ->has('history', 1)
            ->where('history.0.ulid', $itemRad->ulid)
            ->where('history.0.action', AuditLog::ACTION_ITEM_CREATED)
            ->where('history.0.item', 'Motorn')
        );

    expect($containerRad->ulid)->not->toBe($itemRad->ulid);
});

/*
 * Klart när: en gäst ser bara sina egna rader och ägaren allas.
 *
 * Läsregeln är ListAuditEvents (issue 108) och prövas där rad för rad; det här
 * provet bevisar att FLIKEN läser genom den och inte runt den. Ägaren ser
 * båda raderna, gästen ser sin egen och ingenting annat — ur samma sida, med
 * samma svar, utan en egen filtrering i vyn eller i kontrollern.
 *
 * Gästen har `write` på hela containern: den som får skriva i containern är
 * den läsare historiken finns för, och grinden (`viewAuditLog`) släpper in
 * henne på samma villkor som `GET /api/containers/{container}/audit-log` gör
 * sedan issue 108.
 */
it('en gäst ser bara sina egna rader och ägaren allas', function () {
    withoutVite();

    [$konto, $ägare, $pärm] = historikKontext();

    $gast = historikGast($pärm);

    $ägarensRad = historikRad($pärm, $konto, $ägare, AuditLog::ACTION_CONTAINER_CREATED, när: now()->subDay());
    $gästensRad = historikRad($pärm, $konto, $gast, AuditLog::ACTION_ITEM_CREATED, när: now());

    actingAs($ägare)->get(historikUrl($pärm))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('rows', 2)
            ->where('rows.0.ulid', $gästensRad->ulid)
            ->where('rows.1.ulid', $ägarensRad->ulid)
        );

    actingAs($gast)->get(historikUrl($pärm))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('rows', 1)
            ->where('rows.0.ulid', $gästensRad->ulid)
        );
});

/*
 * Klart när: en ändring visar vilka fält som ändrades.
 *
 * [[ADR-0043 Tre loggar]] § Händelseloggen: raden säger *att* namnet och
 * anteckningen ändrades, aldrig vad som stod där. Servern lyfter `meta.changed`
 * ur raden — fältens NAMN — och vyn slår upp orden ur `audit.field.*`. Provet
 * fäster båda ändarna: att namnen kommer fram oförändrade, och att komponenten
 * översätter dem i stället för att skriva ut dem.
 *
 * Den sista raden stänger den väg som annars är lätt att ta: en färdig mening
 * skriven i komponenten. Nycklarna läses ur källkoden i stället för att räknas
 * upp här, och var och en ska finnas i katalogen — `t()` skriver nyckeln själv
 * på skärmen när uppslaget misslyckas.
 */
it('en ändring visar vilka fält som ändrades', function () {
    withoutVite();

    [$konto, $ägare, $pärm] = historikKontext();

    $motorn = Item::factory()->for($pärm, 'container')->create(['name' => 'Motorn']);

    historikRad(
        $pärm,
        $konto,
        $ägare,
        AuditLog::ACTION_ITEM_UPDATED,
        $motorn,
        ['changed' => ['name', 'notes']],
    );

    actingAs($ägare)->get(historikItemUrl($pärm, $motorn, 'history'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('history.0.changed', ['name', 'notes'])
        );

    $rad = historikKod('js/components/HistoryRow.vue');

    expect($rad)->toContain('audit.field.${field}')
        ->toContain('audit.action.${props.row.action}')
        ->toContain('props.row.changed');

    // Varje nyckel komponenten väljer ska finnas i katalogen — också de två
    // ersättarna, som är meningar användaren läser.
    foreach (['audit.field.name', 'audit.field.notes', 'audit.fallback.user', 'audit.fallback.item'] as $nyckel) {
        expect(trans("ui.{$nyckel}", [], 'en'))->not->toBe("ui.{$nyckel}", "{$nyckel} saknas");
    }
});

/*
 * Klart när: en rad om ett gallrat item eller en raderad användare visas med en
 * ersättare.
 *
 * Loggen överlever det den handlar om ([[ADR-0043 Tre loggar]]
 * § Händelseloggen): nycklarna släpptes i issue 107, itemet kan gallras och
 * användaren raderas, och raden står kvar med sina identifierare. Namnen slås
 * upp när raden LÄSES — App\Actions\Audit\PresentAuditEvents — och det som
 * inte längre finns blir `null` och inte ett påhittat namn.
 *
 * **Ett tomt fält är felet provet letar efter.** Ersättaren är en mening ur
 * `lang/` (*a former user*, *a deleted item*) och inte en tom sträng: en tom
 * plats läses som ett ritfel, och raden är inte trasig, den är gammal. Provet
 * fäster både svaret och orden — `null` i proppen och nyckeln i komponenten —
 * för det är tillsammans de ger en rad som går att läsa.
 */
it('en rad om ett gallrat item eller en raderad användare visas med en ersättare', function () {
    withoutVite();

    [$konto, $ägare, $pärm] = historikKontext();

    $motorn = Item::factory()->for($pärm, 'container')->create(['name' => 'Motorn']);
    $handlande = historikGast($pärm);

    historikRad($pärm, $konto, $handlande, AuditLog::ACTION_ITEM_CREATED, $motorn);

    // Gallrat item: hårt borta, som PurgeContent gör efter trettio dagar i
    // papperskorgen. Raden i loggen rörs inte — den har ingen främmande nyckel
    // att falla på (issue 107).
    $motorn->forceDelete();

    // Raderad användare: `user_id` blir en siffra som inte pekar på någon
    // ([[ADR-0043 Tre loggar]] § Konsekvenser).
    $handlande->delete();

    actingAs($ägare)->get(historikItemUrl($pärm, $motorn, 'history'))->assertNotFound();

    // Itemet är borta, så raden läses från containerns flik i stället: den bar
    // samma `container_id` och ligger kvar där.
    actingAs($ägare)->get(historikUrl($pärm))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('rows', 1)
            ->where('rows.0.item', null)
            ->where('rows.0.user', null)
        );

    $rad = historikKod('js/components/HistoryRow.vue');

    expect($rad)->toContain("t('audit.fallback.user')")
        ->toContain("t('audit.fallback.item')");

    expect(trans('ui.audit.fallback.user', [], 'en'))->toBe('a former user');
    expect(trans('ui.audit.fallback.item', [], 'en'))->toBe('a deleted item');

    /*
     * Containerns ersättare kom med issue 126 och bor i samma katalog. Raden
     * ritar den bara när anroparen ber om den — `showContainer` är satt av
     * dashboardens händelsepanel och av ingen flik — så historikflikarnas rad
     * ser ut precis som förut: containern är given av sidan man står på, och
     * en rad som upprepade dess namn hade sagt samma sak två gånger.
     *
     * Provet fäster båda sidorna av det: nyckeln finns, och flikarna ber inte
     * om raden. Det senare är det som går sönder först om någon sätter flaggan
     * på fel ställe.
     */
    expect(trans('ui.audit.fallback.container', [], 'en'))->toBe('a deleted container');

    expect($rad)->toContain("t('audit.fallback.container')")
        ->toContain('props.showContainer');

    foreach ([
        'js/pages/Containers/History.vue',
        'js/pages/Containers/Items/Show.vue',
    ] as $flik) {
        expect(historikKod($flik))->not->toContain('show-container');
    }
});

/*
 * Klart när: itemets rader hämtas bara när historikfliken är aktiv.
 *
 * Historiken är den enda fliken vars rader kostar en egen fråga, och den som
 * öppnar itemet för att se bilagorna ska inte betala för den. Kontrollern
 * lämnar proppen HELT när `?tab=` inte är `history` — nyckeln finns inte i
 * svaret — och det är skillnaden mot en tom lista: `missing` bevisar att ingen
 * fråga ställdes, medan `[]` hade bevisat att en ställdes och gav noll.
 *
 * Provet prövar tre lägen: vilotillståndet (ingen flik vald), en annan flik
 * (taggarna), och historikfliken. Det mittersta är det som är lätt att glömma
 * — en kontroll som bara såg på om `tab` fanns hade släppt igenom varje flik.
 */
it('itemets rader hämtas bara när historikfliken är aktiv', function () {
    withoutVite();

    [$konto, $ägare, $pärm] = historikKontext();

    $motorn = Item::factory()->for($pärm, 'container')->create(['name' => 'Motorn']);

    historikRad($pärm, $konto, $ägare, AuditLog::ACTION_ITEM_CREATED, $motorn);

    foreach ([null, 'tags'] as $flik) {
        actingAs($ägare)->get(historikItemUrl($pärm, $motorn, $flik))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Containers/Items/Show')
                ->missing('history')
            );
    }

    actingAs($ägare)->get(historikItemUrl($pärm, $motorn, 'history'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Containers/Items/Show')
            ->has('history', 1)
        );
});
