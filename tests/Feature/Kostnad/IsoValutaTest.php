<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\CostEntry;
use App\Models\Item;
use App\Models\User;
use App\Support\Cost\Currencies;
use Illuminate\Support\Facades\Lang;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\from;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withoutVite;

/*
 * Issue 1253 · Bara aktiva ISO 4217-koder, se [[ADR-0078 Valutor ur ISO
 * 4217]] § 1 och 3.
 *
 * Regeln är App\Rules\IsoCurrency, listan App\Support\Cost\Currencies, och
 * den används av de fyra requesterna: kostnadens store och update, containerns
 * update och kontots update. Provet går genom ytorna — webbens formulär och
 * `/api` — och inte mot regeln direkt, för det som ska gälla är svaret en
 * anropare får: ett fältfel i webben och `currency.invalid` i API-hötjet
 * (AGENTS.md § Felformat i API:et).
 *
 * **Kontot har ingen API-yta.** Valutan skrivs bara av
 * `UpdateProfileRequest` på profilsidan (issue 398); `currency.invalid`
 * prövas därför på kostnaden och containern, som båda finns i `/api`.
 *
 * `ABC`, `DEM` och `XAU` är de tre fallen ADR:n namnger: en påhittad kod, en
 * historisk och en fondkod. Alla tre hade passerat `alpha|size:3`.
 *
 * Hjälparna har prefixet isoValuta* — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Ett konto med en medlem, ett Sanctum-headerpar, en container under kontot
 * och ett item i containern.
 *
 * @return array{0: Account, 1: User, 2: array<string, string>, 3: Container, 4: Item}
 */
function isoValutaKontext(): array
{
    [$konto, $anvandare, $rubriker] = kontoMedMedlem();
    $konto->update(['currency' => 'SEK']);

    $container = Container::factory()->for($konto, 'account')->create(['currency' => 'SEK']);

    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $konto->id,
    ]);

    return [$konto, $anvandare, $rubriker, $container, $item];
}

/**
 * Kroppen för en ny kostnadsrad. Varje fält kan överstyras.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function isoValutaKostnadskropp(array $overrides = []): array
{
    return array_merge([
        'incurred_on' => '2026-04-12',
        'amount' => '1200,50',
        'currency' => 'EUR',
        'description' => 'Impeller',
    ], $overrides);
}

// --- listan ---------------------------------------------------------------

it('bär de aktiva ISO 4217-koderna och inte de historiska eller fondkoderna', function () {
    // De fem koderna prövningen i § 6 namnger, och de två som ADR-0078 § 1
    // stänger ute.
    expect(Currencies::codes())->toContain('SEK', 'EUR', 'USD', 'NOK', 'DKK');

    // Egen förväntan: `->not` efter `toContain` fäller phpstan (Pest-extensionen
    // typmarkerar inte negationen i en kedja).
    expect(Currencies::codes())->not->toContain('DEM', 'XAU');
});

it('ger namn ur ICU, med koden som reserv', function () {
    expect(Currencies::name('SEK', 'en'))->toBe('Swedish Krona')
        // En kod utanför listan har inget namn — fältet visar koden som den är
        // i stället för att gissa, se ADR-0078 § 3.
        ->and(Currencies::name('ABC', 'en'))->toBe('ABC');
});

it('sorterar options på kod och bär både kod och namn', function () {
    $valutor = Currencies::options('en');

    expect(array_column($valutor, 'code'))->toBe(Currencies::codes())
        ->and($valutor[0])->toBe(['code' => 'AED', 'name' => Currencies::name('AED', 'en')]);

    $sek = collect($valutor)->firstWhere('code', 'SEK');

    expect($sek)->toBe(['code' => 'SEK', 'name' => 'Swedish Krona']);
});

// --- kostnaden ------------------------------------------------------------

it('godtar en aktiv kod och versaliserar den på kostnaden', function () {
    [, , $rubriker, $container, $item] = isoValutaKontext();

    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/costs";

    $svar = postJson($url, isoValutaKostnadskropp(['currency' => 'eur']), $rubriker);

    $svar->assertCreated();

    expect($svar->json('data.currency'))->toBe('EUR')
        ->and(CostEntry::query()->firstOrFail()->currency)->toBe('EUR');
});

it('avvisar ABC, DEM och XAU på kostnaden i API:et med currency.invalid', function () {
    [, , $rubriker, $container, $item] = isoValutaKontext();

    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/costs";

    foreach (['ABC', 'DEM', 'XAU'] as $kod) {
        $svar = postJson($url, isoValutaKostnadskropp(['currency' => $kod]), $rubriker);

        $svar->assertStatus(422);

        expect($svar->json('error.code'))->toBe('validation.failed')
            ->and($svar->json('error.data.fields.currency.0.code'))->toBe('currency.invalid');
    }

    expect(CostEntry::query()->count())->toBe(0);
});

it('avvisar ABC, DEM och XAU på kostnaden i webben', function () {
    withoutVite();

    [, $anvandare, , $container, $item] = isoValutaKontext();

    actingAs($anvandare);

    $url = "/containers/{$container->ulid}/items/{$item->ulid}/costs";

    foreach (['ABC', 'DEM', 'XAU'] as $kod) {
        $svar = from($url)->post($url, isoValutaKostnadskropp(['currency' => $kod]));

        $svar->assertSessionHasErrors('currency');

        expect(session('errors')->get('currency')[0])
            ->toBe(Lang::get('validation.iso_currency', [], 'en'));
    }

    expect(CostEntry::query()->count())->toBe(0);
});

it('låter en befintlig rad med en kod utanför listan få ny beskrivning', function () {
    // ADR-0078 § 3: raden är sanningen, och den rättas inte i tysthet. En
    // PATCH som inte nämner valutan godtas därför även när koden är `ABC`.
    [, , $rubriker, $container, $item] = isoValutaKontext();

    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/costs";

    $kostnad = CostEntry::factory()->for($item, 'item')->create([
        'currency' => 'ABC',
        'description' => 'Gammal',
        'created_by_user_id' => $item->created_by_user_id,
        'created_by_account_id' => $container->account_id,
    ]);

    patchJson("{$url}/{$kostnad->ulid}", ['description' => 'Ny beskrivning'], $rubriker)
        ->assertOk();

    $kostnad->refresh();

    expect($kostnad->description)->toBe('Ny beskrivning')
        ->and($kostnad->currency)->toBe('ABC');
});

it('kräver en giltig kod när beloppet ändras', function () {
    // `required_with` parar belopp och valuta åt båda hållen, och när paret
    // väl skickas prövas valutan mot listan — en ogiltig kod kan alltså inte
    // skrivas in, bara stå kvar.
    [, , $rubriker, $container, $item] = isoValutaKontext();

    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/costs";

    $kostnad = CostEntry::factory()->for($item, 'item')->create([
        'currency' => 'ABC',
        'created_by_user_id' => $item->created_by_user_id,
        'created_by_account_id' => $container->account_id,
    ]);

    $ogiltig = patchJson("{$url}/{$kostnad->ulid}", [
        'amount' => '2000,00',
        'currency' => 'ABC',
    ], $rubriker);

    $ogiltig->assertStatus(422);
    expect($ogiltig->json('error.data.fields.currency.0.code'))->toBe('currency.invalid');

    patchJson("{$url}/{$kostnad->ulid}", [
        'amount' => '2000,00',
        'currency' => 'eur',
    ], $rubriker)->assertOk();

    expect($kostnad->fresh()->currency)->toBe('EUR');
});

// --- containern -----------------------------------------------------------

it('avvisar ABC, DEM och XAU på containern i API:et med currency.invalid', function () {
    [, , $rubriker, $container] = isoValutaKontext();

    foreach (['ABC', 'DEM', 'XAU'] as $kod) {
        $svar = patchJson("/api/containers/{$container->ulid}", ['currency' => $kod], $rubriker);

        $svar->assertStatus(422);

        expect($svar->json('error.data.fields.currency.0.code'))->toBe('currency.invalid');
    }

    expect($container->fresh()->currency)->toBe('SEK');
});

it('avvisar ABC, DEM och XAU på containern i webben', function () {
    withoutVite();

    [, $anvandare, , $container] = isoValutaKontext();

    actingAs($anvandare);

    foreach (['ABC', 'DEM', 'XAU'] as $kod) {
        from("/containers/{$container->ulid}/edit")
            ->patch("/containers/{$container->ulid}", ['currency' => $kod])
            ->assertSessionHasErrors('currency');
    }

    expect($container->fresh()->currency)->toBe('SEK');
});

// --- kontot ---------------------------------------------------------------

it('avvisar ABC, DEM och XAU på kontot i webben', function () {
    withoutVite();

    [$konto, $anvandare] = isoValutaKontext();

    actingAs($anvandare);

    foreach (['ABC', 'DEM', 'XAU'] as $kod) {
        from('/settings/profile')
            ->patch('/settings/profile', ['name' => $anvandare->name, 'account_name' => $konto->name, 'currency' => $kod])
            ->assertSessionHasErrors('currency');
    }

    expect($konto->fresh()->currency)->toBe('SEK');
});
