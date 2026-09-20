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
 * - en ny rad får containerns valuta som förifyllt värde — arvsregeln
 *   App\Models\Container::effectiveCurrency(), som formuläret läser,
 * - användaren kan välja en annan valuta på den enskilda raden och den sparas,
 * - ett byte av containerns eller kontots valuta lämnar befintliga rader
 *   OFÖRÄNDRADE. Det är issuens viktigaste acceptanskriterium: det som står i
 *   en rad är vad som betalades, inte vad containern för närvarande föreslår.
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
 * Kroppen för POST /costs, med valutan som enda variabel.
 *
 * @return array<string, mixed>
 */
function valutansArvKropp(string $valuta): array
{
    return [
        'incurred_on' => '2026-04-12',
        'amount' => '1200,50',
        'currency' => $valuta,
        'description' => 'Impeller',
        'supplier' => null,
    ];
}

it('föreslår containerns egen valuta för en ny kostnadsrad', function () {
    [$account, $user, $headers, $container, $item] = valutansArvKontext('SEK', 'EUR');

    expect($container->effectiveCurrency())->toBe('EUR');

    $response = postJson(
        "/api/containers/{$container->ulid}/items/{$item->ulid}/costs",
        valutansArvKropp($container->effectiveCurrency()),
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
        valutansArvKropp($container->effectiveCurrency()),
        $headers,
    );

    $response->assertCreated();
    expect($response->json('data.currency'))->toBe('NOK');
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

it('lämnar befintliga kostnadsrader oförändrade när kontots valuta byts', function () {
    [$account, $user, $headers, $container, $item] = valutansArvKontext('SEK', null);

    $kostnad = CostEntry::factory()->for($item, 'item')->create([
        'currency' => 'SEK',
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);

    // Bytet sker på modellen och inte genom PATCH /settings/accounts: den
    // skrivvägen ligger i app/Http/Requests/Settings/**, utanför issue 85:s
    // ruta. Invarianten som prövas är kontots valuta kontra radens, och den
    // är oberoende av vilken yta som skrev kontot.
    $account->update(['currency' => 'EUR']);

    expect($account->fresh()->currency)->toBe('EUR');
    // Containern ärver det nya värdet — för NYA poster.
    expect($container->fresh()->effectiveCurrency())->toBe('EUR');
    // Raden står kvar: det som står i en rad är vad som betalades.
    expect($kostnad->fresh()->currency)->toBe('SEK');
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
