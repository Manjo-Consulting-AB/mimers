<?php

use App\Actions\Container\SetAllowedCurrencies;
use App\Actions\Trash\PurgeContainer;
use App\Exceptions\Api\ApiException;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\CostEntry;
use App\Models\Export;
use App\Models\Item;
use App\Models\User;
use App\Support\Export\ContainerExportBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use ZipArchive;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\from;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;
use function Pest\Laravel\withoutVite;

/*
 * Issue 1254 · Containerns urval av valutor för kostnader, se
 * [[ADR-0078 Valutor ur ISO 4217]] § 4 och 5.
 *
 * Filen prövar att urvalet finns på alla tre nivåer det verkar: i datan
 * (`container_allowed_currency`), i serverns regel (`permitsCurrency`) och i
 * de två ytorna (webbens kostnadsformulär och `/api`). Att ytorna ritar rätt
 * fält prövas i tests/Feature/Frontend/TillatnaValutorvyTest.php.
 *
 * **Regeln i en mening:** ett tomt urval betyder ALLA valutor, standardvalutan
 * är alltid tillåten, och en BEFINTLIG rad behåller sin valuta även när den
 * tagits bort ur urvalet. Det sista undantaget är det som gör historiken
 * redigerbar, och det prövas för sig.
 *
 * Hjälparna har prefixet `tillatnaValutor` — Pest lägger alla testfiler i
 * samma namnrymd när hela sviten körs.
 */

/**
 * Ett konto med en medlem, en container och ett item. Kontots valuta och
 * containerns egna går att sätta; urvalet skrivs i testet.
 *
 * @return array{0: Account, 1: User, 2: array<string, string>, 3: Container, 4: Item}
 */
function tillatnaValutorKontext(string $kontovaluta = 'SEK', ?string $containerValuta = null): array
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
 * Sätter containerns urval direkt, förbi rutten — samma väg som
 * App\Actions\Container\SetAllowedCurrencies gör.
 *
 * @param  list<string>  $koder
 */
function tillatnaValutorUrval(Container $container, array $koder): void
{
    app(SetAllowedCurrencies::class)->handle($container, $koder);

    $container->unsetRelation('allowedCurrencies');
}

/**
 * En sammanhängande kropp för POST /costs, med valutan som enda variabel.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function tillatnaValutorKropp(array $overrides = []): array
{
    return array_merge([
        'incurred_on' => '2026-04-12',
        'amount' => '1200,50',
        'currency' => 'AUD',
        'description' => 'Impeller',
        'counterparty' => null,
    ], $overrides);
}

it('tillåter alla valutor när urvalet är tomt', function () {
    [, , $headers, $container, $item] = tillatnaValutorKontext('SEK', 'EUR');

    expect($container->allowedCurrencyCodes())->toBe([])
        ->and($container->permitsCurrency('AUD'))->toBeTrue();

    $svar = postJson(
        "/api/containers/{$container->ulid}/items/{$item->ulid}/costs",
        tillatnaValutorKropp(['currency' => 'AUD']),
        $headers,
    );

    $svar->assertCreated();
    expect($svar->json('data.currency'))->toBe('AUD');
});

it('nekar en otillåten valuta i API:et med currency.not_allowed', function () {
    [, , $headers, $container, $item] = tillatnaValutorKontext('SEK', 'EUR');
    tillatnaValutorUrval($container, ['EUR', 'SEK']);

    $svar = postJson(
        "/api/containers/{$container->ulid}/items/{$item->ulid}/costs",
        tillatnaValutorKropp(['currency' => 'AUD']),
        $headers,
    );

    $svar->assertStatus(422)
        ->assertJsonPath('error.code', 'currency.not_allowed')
        ->assertJsonPath('error.data.currency', 'AUD')
        ->assertJsonPath('error.data.allowed', 'EUR, SEK');

    expect(CostEntry::query()->count())->toBe(0);
});

it('nekar en otillåten valuta på webben med ett fältfel på currency', function () {
    [, $user, , $container, $item] = tillatnaValutorKontext('SEK', 'EUR');
    tillatnaValutorUrval($container, ['EUR', 'SEK']);

    $svar = actingAs($user)->post(
        "/containers/{$container->ulid}/items/{$item->ulid}/costs",
        tillatnaValutorKropp(['currency' => 'AUD']),
    );

    $svar->assertSessionHasErrors('currency');
    expect($svar->getContent())->not->toContain('"error"');

    $mening = session('errors')->get('currency')[0];

    expect($mening)->toBe(
        Lang::get('ui.error.currency.not_allowed', [
            'currency' => 'AUD',
            'allowed' => 'EUR, SEK',
        ], 'en'),
    );
});

it('tillåter standardvalutan även när den inte står i urvalet', function () {
    // Kontots valuta är standard när containern saknar egen (ADR-0037).
    [, , $headers, $container, $item] = tillatnaValutorKontext('SEK', null);
    tillatnaValutorUrval($container, ['EUR', 'USD']);

    expect($container->permitsCurrency('SEK'))->toBeTrue();

    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/costs";

    // Uttryckligen skickad standardvaluta.
    postJson($url, tillatnaValutorKropp(['currency' => 'SEK']), $headers)
        ->assertCreated()
        ->assertJsonPath('data.currency', 'SEK');

    // Och den ÄRVDA valutan när kroppen saknar `currency` — samma svar.
    $kropp = tillatnaValutorKropp();
    unset($kropp['currency']);

    postJson($url, $kropp, $headers)
        ->assertCreated()
        ->assertJsonPath('data.currency', 'SEK');
});

it('låter en befintlig rad behålla sin valuta men inte byta till en annan otillåten', function () {
    [$account, $user, $headers, $container, $item] = tillatnaValutorKontext('SEK', 'EUR');
    tillatnaValutorUrval($container, ['EUR', 'SEK']);

    // Raden skrevs innan AUD togs bort ur urvalet — den står kvar, och är
    // sanningen om vad som betalades (ADR-0037 och ADR-0078 § 3).
    $rad = CostEntry::factory()->for($item, 'item')->create([
        'currency' => 'AUD',
        'amount' => 120050,
        'description' => 'Hamnavgift',
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);

    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/costs/{$rad->ulid}";

    // En ändring av BESKRIVNINGEN rör ingen valuta och godtas.
    patchJson($url, ['description' => 'Hamnavgift, rättad'], $headers)
        ->assertOk()
        ->assertJsonPath('data.currency', 'AUD');

    // Ett byte till en annan otillåten valuta nekas — beloppet och valutan är
    // ett par i PATCH, så båda följer med.
    patchJson($url, ['amount' => '1200,50', 'currency' => 'USD'], $headers)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'currency.not_allowed');

    expect($rad->fresh()->currency)->toBe('AUD');

    // Men att BEHÅLLA radens nuvarande valuta godtas, även med nytt belopp.
    patchJson($url, ['amount' => '1300,00', 'currency' => 'AUD'], $headers)
        ->assertOk()
        ->assertJsonPath('data.currency', 'AUD');

    expect($rad->fresh()->amount)->toBe(130000);
});

it('låter SetAllowedCurrencies neka en kod utanför ISO 4217 och logga ändringen', function () {
    [, $user, , $container] = tillatnaValutorKontext();

    expect(fn () => app(SetAllowedCurrencies::class)->handle($container, ['ABC'], $user))
        ->toThrow(ApiException::class, 'currency.invalid');

    expect($container->allowedCurrencyCodes())->toBe([])
        ->and(AuditLog::query()->count())->toBe(0);

    app(SetAllowedCurrencies::class)->handle($container, ['SEK', 'EUR'], $user);

    expect($container->allowedCurrencyCodes())->toBe(['EUR', 'SEK']);

    $logg = AuditLog::query()
        ->where('action', AuditLog::ACTION_CONTAINER_CURRENCIES_UPDATED)
        ->firstOrFail();

    expect($logg->user_id)->toBe($user->id)
        ->and($logg->container_id)->toBe($container->id)
        ->and($logg->meta['values']['allowed_currencies']['from'])->toBe([])
        ->and($logg->meta['values']['allowed_currencies']['to'])->toBe(['EUR', 'SEK']);
});

it('sparar urvalet via webb- och API-ytan', function () {
    [, $user, $headers, $container] = tillatnaValutorKontext('SEK', 'EUR');

    // Webben: back() med statuskoden, hela listan ersätts.
    from("/containers/{$container->ulid}/edit")
        ->actingAs($user)
        ->put("/containers/{$container->ulid}/currencies", ['currencies' => ['sek', 'EUR']])
        ->assertRedirect("/containers/{$container->ulid}/edit")
        ->assertSessionHas('status', 'container-currencies-updated');

    expect($container->fresh()->allowedCurrencyCodes())->toBe(['EUR', 'SEK']);

    // API:t: 200 med containerns resurs, och den nya listan i svaret.
    putJson("/api/containers/{$container->ulid}/currencies", ['currencies' => ['AUD']], $headers)
        ->assertOk()
        ->assertJsonPath('data.allowed_currencies', ['AUD']);

    expect($container->fresh()->allowedCurrencyCodes())->toBe(['AUD']);
});

it('visar urvalet i kostnadsformulärets förval', function () {
    [, $user, , $container, $item] = tillatnaValutorKontext('SEK', 'EUR');
    tillatnaValutorUrval($container, ['EUR', 'SEK']);

    withoutVite();

    actingAs($user)
        ->get("/containers/{$container->ulid}/items/{$item->ulid}?tab=costs")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('costDefaults.currency', 'EUR')
            ->where('costDefaults.allowed_currencies', ['EUR', 'SEK']));
});

it('rensar urvalet när containern gallras', function () {
    [, , , $container] = tillatnaValutorKontext();
    tillatnaValutorUrval($container, ['EUR', 'SEK']);

    $container->delete();

    app(PurgeContainer::class)->handle($container);

    expect(DB::table('container_allowed_currency')->where('container_id', $container->id)->count())->toBe(0)
        ->and(Container::withTrashed()->whereKey($container->id)->exists())->toBeFalse();
});

it('tar med urvalet i exporten', function () {
    Storage::fake('files');

    [$account, $user, , $container] = tillatnaValutorKontext();
    tillatnaValutorUrval($container, ['EUR', 'SEK']);

    $export = Export::factory()
        ->for($container, 'container')
        ->for($user, 'requestedBy')
        ->create();

    $sökväg = (new ContainerExportBuilder)->build($export);

    $zip = new ZipArchive;
    expect($zip->open(Storage::disk('files')->path($sökväg)))->toBeTrue();
    $json = $zip->getFromName('container.json');
    $zip->close();

    $payload = json_decode($json, true);

    expect($payload['container']['allowed_currencies'])->toBe(['EUR', 'SEK']);
});
