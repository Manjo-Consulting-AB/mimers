<?php

use App\Exceptions\Api\ApiException;
use App\Models\CostEntry;
use App\Support\Cost\MinorUnits;

use function Pest\Laravel\postJson;

/*
 * Issue 45a · Beloppstolkningen: att läsa ett belopp ur en textruta utan att
 * tappa ett öre. Se App\Support\Cost\MinorUnits, config/kostnader.php och
 * issue 45a § Beslut 4–6.
 *
 * De första blocken anropar MinorUnits::parse() direkt — omvandlingen är ren
 * logik utan databas. De sista går genom API:ytan: felkoderna (och att de har
 * rätt data) syns bara där, och ett par acceptanskriterier handlar om vad som
 * faktiskt LAGRAS.
 *
 * skapaForekomstKontext() är en global testhjälpare i
 * tests/Support/Testhjalpare.php — konto, användare, container och item.
 */

/**
 * En giltig kropp för POST /costs. Varje fält kan överstyras.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function kostnadsbeloppKropp(array $overrides = []): array
{
    return array_merge([
        'incurred_on' => '2026-04-12',
        'amount' => '1200,50',
        'currency' => 'EUR',
        'description' => 'Impeller',
        'supplier' => null,
    ], $overrides);
}

it('tolkar "1200,50" och "1200.50" till samma heltal', function () {
    expect(MinorUnits::parse('1200,50', 'EUR'))->toBe(120050);
    expect(MinorUnits::parse('1200.50', 'EUR'))->toBe(120050);
});

it('kräver inte decimaler — "1200" med EUR är 1 200,00', function () {
    expect(MinorUnits::parse('1200', 'EUR'))->toBe(120000);
});

it('tillåter negativa belopp — en kreditfaktura', function () {
    expect(MinorUnits::parse('-450,25', 'EUR'))->toBe(-45025);
});

it('tolkar noll och minusnoll till noll', function () {
    expect(MinorUnits::parse('0', 'EUR'))->toBe(0);
    expect(MinorUnits::parse('-0', 'EUR'))->toBe(0);
});

it('fyller ut till valutans exponent — JPY har noll decimaler', function () {
    expect(MinorUnits::parse('1200', 'JPY'))->toBe(1200);
});

it('tillåter tre decimaler för KWD', function () {
    expect(MinorUnits::parse('1200,505', 'KWD'))->toBe(1200505);
});

it('en okänd valuta får default_minor_units — XYZ har två decimaler', function () {
    expect(MinorUnits::parse('1200,5', 'XYZ'))->toBe(120050);
});

it('avvisar en siffersträng som inte ryms i BIGINT', function () {
    expect(fn () => MinorUnits::parse(str_repeat('9', 19), 'EUR'))
        ->toThrow(ApiException::class);
});

it('"1200,50" och "1200.50" med EUR lagras som 120050', function () {
    [, , $headers, $container, $item] = skapaForekomstKontext();
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/costs";

    postJson($url, kostnadsbeloppKropp(['description' => 'Med komma']), $headers)->assertCreated();
    postJson($url, kostnadsbeloppKropp(['description' => 'Med punkt', 'amount' => '1200.50']), $headers)
        ->assertCreated();

    $belopp = CostEntry::pluck('amount')->sort()->values();
    expect($belopp)->toHaveCount(2);
    expect($belopp[0])->toBe(120050);
    expect($belopp[1])->toBe(120050);
});

it('"1200" med EUR lagras som 120000', function () {
    [, , $headers, $container, $item] = skapaForekomstKontext();
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/costs";

    $response = postJson($url, kostnadsbeloppKropp(['amount' => '1200']), $headers);

    $response->assertCreated();
    expect($response->json('data.amount'))->toBe(120000);
});

it('"-450,25" lagras som -45025', function () {
    [, , $headers, $container, $item] = skapaForekomstKontext();
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/costs";

    $response = postJson($url, kostnadsbeloppKropp(['amount' => '-450,25']), $headers);

    $response->assertCreated();
    expect($response->json('data.amount'))->toBe(-45025);
});

it('"0" lagras som 0', function () {
    [, , $headers, $container, $item] = skapaForekomstKontext();
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/costs";

    $response = postJson($url, kostnadsbeloppKropp(['amount' => '0']), $headers);

    $response->assertCreated();
    expect($response->json('data.amount'))->toBe(0);
});

it('fler decimaler än EUR tillåter avvisas med cost.amount_decimals', function () {
    [, , $headers, $container, $item] = skapaForekomstKontext();
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/costs";

    $response = postJson($url, kostnadsbeloppKropp(['amount' => '1200,505']), $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('cost.amount_decimals');
    expect($response->json('error.data.currency'))->toBe('EUR');
    expect($response->json('error.data.max_decimals'))->toBe(2);
});

it('fler decimaler än JPY tillåter avvisas, men hela JPY-belopp lagras', function () {
    [, , $headers, $container, $item] = skapaForekomstKontext();
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/costs";

    $avvisat = postJson($url, kostnadsbeloppKropp(['amount' => '1200,5', 'currency' => 'JPY']), $headers);
    $avvisat->assertStatus(422);
    expect($avvisat->json('error.code'))->toBe('cost.amount_decimals');
    expect($avvisat->json('error.data.max_decimals'))->toBe(0);

    $lagrat = postJson($url, kostnadsbeloppKropp(['amount' => '1200', 'currency' => 'JPY']), $headers);
    $lagrat->assertCreated();
    expect($lagrat->json('data.amount'))->toBe(1200);
});

it('tre decimaler lagras för KWD', function () {
    [, , $headers, $container, $item] = skapaForekomstKontext();
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/costs";

    $response = postJson($url, kostnadsbeloppKropp(['amount' => '1200,505', 'currency' => 'KWD']), $headers);

    $response->assertCreated();
    expect($response->json('data.amount'))->toBe(1200505);
});

it('ogiltiga former avvisas med cost.amount_invalid', function () {
    [, , $headers, $container, $item] = skapaForekomstKontext();
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/costs";

    foreach (['abc', '1,2,3', '1 200', '1e3', ''] as $amount) {
        $response = postJson($url, kostnadsbeloppKropp(['amount' => $amount]), $headers);

        $response->assertStatus(422);
        expect($response->json('error.code'))->toBe('cost.amount_invalid');
    }
});

it('ett belopp som inte ryms i BIGINT ger cost.amount_invalid, inte ett databasfel', function () {
    [, , $headers, $container, $item] = skapaForekomstKontext();
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/costs";

    $response = postJson($url, kostnadsbeloppKropp(['amount' => str_repeat('9', 19)]), $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('cost.amount_invalid');
});

it('amount som JSON-tal ger validation.failed med validation.string', function () {
    [, , $headers, $container, $item] = skapaForekomstKontext();
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/costs";

    $response = postJson($url, array_merge(
        kostnadsbeloppKropp(),
        ['amount' => 1200.50],
    ), $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('validation.failed');
    expect($response->json('error.data.fields.amount.0.code'))->toBe('validation.string');
});

it('currency "eur" normaliseras till versaler och sparas som EUR', function () {
    [, , $headers, $container, $item] = skapaForekomstKontext();
    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/costs";

    $response = postJson($url, kostnadsbeloppKropp(['currency' => 'eur']), $headers);

    $response->assertCreated();
    expect($response->json('data.currency'))->toBe('EUR');

    $rad = CostEntry::where('ulid', $response->json('data.ulid'))->first();
    expect($rad->currency)->toBe('EUR');
});
