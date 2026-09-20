<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\CostEntry;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

/*
 * Issue 85 · Valutan ärvs nedåt. Se [[ADR-0037 Valutans arv]] och
 * [[ADR-0016 Kostnadsregistrering]].
 *
 * Den här filen prövar KOSTNADSRADENS del av arvet, och den handlar mindre om
 * vad som händer än om vad som INTE får hända:
 *
 * - en ny rad får containerns valuta som förval — arvsregeln
 *   App\Models\Container::effectiveCurrency(), som kontrollern fyller
 *   tomrummet med när kroppen inte skickar någon valuta. Det är så
 *   "kostnadsformuläret föreslår containerns" blir prövbart i dag: den
 *   Vue-yta som visar värdet för användaren byggs i den issue som bygger
 *   kostnadsytan, och formuläret skickar då antingen det visade värdet eller
 *   ingenting — båda vägarna prövas här.
 * - användaren kan välja en annan valuta på den enskilda raden och den sparas;
 *   ett skickat värde vinner alltid över förvalet,
 * - ett byte av containerns valuta lämnar befintliga rader OFÖRÄNDRADE.
 *
 * Att ett byte av KONTOTS valuta lämnar raden orörd prövas i
 * tests/Feature/Konto/KontovalutaTest.php, genom inställningsändpunkten — det
 * är ytan användaren kan skriva på, och en invariant som bara prövas mot
 * modellen säger ingenting om vad formuläret gör.
 *
 * `cost_entry` ändras inte av issuen: ingen kolumn läggs till och ingen tas
 * bort, och tabellen prövas mot sin exakta kolumnlista just därför. `item`
 * får ingen valutakolumn — itemet är stället formuläret öppnas, inte en nivå
 * i arvet.
 *
 * Hjälparna har prefixet valutansArv* för att inte krocka med de globala
 * hjälparna i andra Feature-filer.
 */

/**
 * Ett konto med en medlem, en container ägd av kontot och ett item i
 * containern. Kontots valuta och containerns egna går att sätta.
 *
 * @return array{0: Account, 1: User, 2: array<string, string>, 3: Container, 4: Item}
 */
function valutansArvKontext(string $kontovaluta = 'SEK', ?string $containerValuta = null): array
{
    [$account, $user, $headers] = kontoMedMedlem();

    $account->update(['currency' => $kontovaluta]);

    $container = Container::factory()->for($account, 'account')->create([
        'currency' => $containerValuta,
    ]);

    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);

    return [$account, $user, $headers, $container, $item];
}

/**
 * Kroppen för POST /costs, med valutan som enda variabel. Utan argument
 * UTELÄMNAS `currency` helt — det är den väg en klient går som låter
 * containern föreslå, och den prövas separat från den som skickar ett värde.
 *
 * @return array<string, mixed>
 */
function valutansArvKropp(?string $valuta = null): array
{
    $kropp = [
        'incurred_on' => '2026-04-12',
        'amount' => '1200,50',
        'description' => 'Impeller',
        'supplier' => null,
    ];

    if ($valuta !== null) {
        $kropp['currency'] = $valuta;
    }

    return $kropp;
}

/*
 * Förvalet. `currency` är valfri i kroppen sedan issue 85: servern skriver
 * containerns App\Models\Container::effectiveCurrency(), som är samma värde
 * formuläret visar. Kolumnen är ändå `NOT NULL` — det finns ingen väg genom
 * store() som skapar en rad utan valuta.
 */
it('föreslår containerns egen valuta när kroppen inte skickar någon', function () {
    [$account, $user, $headers, $container, $item] = valutansArvKontext('SEK', 'EUR');

    expect($container->effectiveCurrency())->toBe('EUR');

    $response = postJson(
        "/api/containers/{$container->ulid}/items/{$item->ulid}/costs",
        valutansArvKropp(),
        $headers,
    );

    $response->assertCreated();
    expect($response->json('data.currency'))->toBe('EUR');

    $kostnad = CostEntry::query()->where('item_id', $item->id)->firstOrFail();
    expect($kostnad->currency)->toBe('EUR');
});

it('föreslår kontots valuta när containern saknar en egen', function () {
    [$account, $user, $headers, $container, $item] = valutansArvKontext('NOK', null);

    expect($container->currency)->toBeNull();
    expect($container->effectiveCurrency())->toBe('NOK');

    $response = postJson(
        "/api/containers/{$container->ulid}/items/{$item->ulid}/costs",
        valutansArvKropp(),
        $headers,
    );

    $response->assertCreated();
    expect($response->json('data.currency'))->toBe('NOK');

    $kostnad = CostEntry::query()->where('item_id', $item->id)->firstOrFail();
    expect($kostnad->currency)->toBe('NOK');
});

it('föreslår containerns valuta också när valutan skickas som en tom ruta', function () {
    [, , $headers, $container, $item] = valutansArvKontext('SEK', 'EUR');

    // ConvertEmptyStringsToNull gör rutan till `null` innan reglerna körs.
    // Tomt värde och saknad nyckel betyder samma sak — annars blev "lämna
    // fältet tomt" en tredje väg med ett eget svar.
    $response = postJson(
        "/api/containers/{$container->ulid}/items/{$item->ulid}/costs",
        valutansArvKropp(''),
        $headers,
    );

    $response->assertCreated();
    expect($response->json('data.currency'))->toBe('EUR');
});

/*
 * Och formen prövas så fort ett värde ÄR där: valfritt betyder inte
 * ovaliderat. `alpha|size:3` är samma regel som före issue 85.
 */
it('avvisar en skickad valuta som inte är tre bokstäver', function () {
    [, , $headers, $container, $item] = valutansArvKontext('SEK', 'EUR');
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/costs";

    postJson($url, valutansArvKropp('SE1'), $headers)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation.failed');

    postJson($url, valutansArvKropp('KRONOR'), $headers)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation.failed');

    expect(CostEntry::query()->where('item_id', $item->id)->count())->toBe(0);
});

it('låter användaren välja en annan valuta på den enskilda raden och sparar den', function () {
    [$account, $user, $headers, $container, $item] = valutansArvKontext('SEK', 'EUR');
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/costs";

    // Den föreslagna valutan är EUR — raden är en hamnavgift betald i USD.
    $response = postJson($url, valutansArvKropp('usd'), $headers);

    $response->assertCreated();
    expect($response->json('data.currency'))->toBe('USD');

    $kostnad = CostEntry::query()->where('item_id', $item->id)->firstOrFail();
    expect($kostnad->currency)->toBe('USD');

    // Och den går att ändra i efterhand, med beloppet — valutan och beloppet
    // är ett par i PATCH (issue 45a § Beslut 12).
    patchJson("{$url}/{$kostnad->ulid}", [
        'amount' => '1200,50',
        'currency' => 'nok',
    ], $headers)->assertOk();

    expect($kostnad->fresh()->currency)->toBe('NOK');
});

it('lämnar befintliga kostnadsrader oförändrade när containerns valuta byts', function () {
    [$account, $user, $headers, $container, $item] = valutansArvKontext('SEK', 'EUR');

    $kostnad = CostEntry::factory()->for($item, 'item')->create([
        'currency' => 'EUR',
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);

    patchJson("/api/containers/{$container->ulid}", ['currency' => 'USD'], $headers)
        ->assertOk();

    expect($container->fresh()->currency)->toBe('USD');
    expect($kostnad->fresh()->currency)->toBe('EUR');

    // Även när containern tömmer sin valuta och går tillbaka till arvet står
    // raden kvar: arvet gäller nya poster, aldrig skrivna.
    patchJson("/api/containers/{$container->ulid}", ['currency' => null], $headers)
        ->assertOk();

    expect($container->fresh()->currency)->toBeNull();
    expect($container->fresh()->effectiveCurrency())->toBe('SEK');
    expect($kostnad->fresh()->currency)->toBe('EUR');
});

it('har varken lagt till eller tagit bort en kolumn i cost_entry', function () {
    // Den exakta listan, inte en delmängd: en ny kolumn gör testet rött lika
    // säkert som en borttagen. Att flytta valutan till containern är det
    // "förenkling" issuen uttryckligen förbjuder — den hade gjort arvet till
    // en datamigrering och rivit regeln att en rad bär vad som betalades.
    expect(Schema::getColumnListing('cost_entry'))->toEqualCanonicalizing([
        'id',
        'ulid',
        'container_id',
        'item_id',
        'incurred_on',
        'amount',
        'currency',
        'description',
        'supplier',
        'created_by_user_id',
        'created_by_account_id',
        'created_at',
        'updated_at',
        'deleted_at',
    ]);
});

it('har ingen valutakolumn på item', function () {
    // Itemet är stället formuläret öppnas, inte en nivå i arvet
    // ([[ADR-0037 Valutans arv]] § Beslut).
    expect(Schema::getColumnListing('item'))->not->toContain('currency');
});
