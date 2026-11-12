<?php

use App\Models\Container;
use App\Models\User;

use function Pest\Laravel\getJson;
use function Pest\Laravel\withToken;

/*
 * Issue 476 · Identitetsbytet i tester. Se somAnvandare() i
 * tests/Support/Testhjalpare.php — den finns för att Sanctums RequestGuard
 * cachar den autentiserade användaren, så att ett andra anrop i samma test
 * svarar som den första användaren.
 *
 * Filen prövar mekanismen själv, mot en riktig `/api`-rutt med bearer-token,
 * och är därmed provet som hjälparens docblock hänvisar till: det ena testet
 * går genom hjälparen, det andra visar felet den finns för att stänga.
 *
 * Ingen produktionskod rörs. Beteendet är Sanctums och är rätt i drift, där
 * varje request är en egen process — det är bara testerna som delar en.
 */

/**
 * Ett konto med en medlem, medlemmens bearer-token och en container i kontot.
 * Containern gör att svaret från GET /api/containers skiljer sig åt mellan två
 * användare, så testet kan avgöra vems svar det fick.
 *
 * @return array{0: User, 1: array<string, string>, 2: Container} [$user, $headers, $container]
 */
function identitetsbyteKontext(string $containernamn): array
{
    [$konto, $användare, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create(['name' => $containernamn]);

    return [$användare, $headers, $container];
}

it('två användare i samma test får var sitt svar från /api', function () {
    [$a, $aHeaders, $aContainer] = identitetsbyteKontext('A:s container');
    [$b, $bHeaders, $bContainer] = identitetsbyteKontext('B:s container');

    $a_svar = somAnvandare($a)->getJson('/api/containers', $aHeaders);
    $a_svar->assertOk();
    expect($a_svar->json('data.0.ulid'))->toBe($aContainer->ulid);

    // Andra anropet i samma test, en annan användare — svaret är B:s.
    $b_svar = somAnvandare($b)->getJson('/api/containers', $bHeaders);
    $b_svar->assertOk();
    expect($b_svar->json('data.0.ulid'))->toBe($bContainer->ulid);
    expect($b_svar->json('data.0.ulid'))->not->toBe($aContainer->ulid);
});

it('utan hjälparen svarar det andra anropet som den första användaren', function () {
    [$a, $aHeaders, $aContainer] = identitetsbyteKontext('A:s container');
    [, $bHeaders, $bContainer] = identitetsbyteKontext('B:s container');

    $a_svar = getJson('/api/containers', $aHeaders);
    $a_svar->assertOk();
    expect($a_svar->json('data.0.ulid'))->toBe($aContainer->ulid);

    /*
     * Ingen somAnvandare() emellan. RequestGuard ligger kvar med A sedan
     * första anropet, och B:s token läses aldrig — B:s begäran svarar som A.
     * Det är felet hjälparen finns för, och det här testet håller det
     * synligt: rättar Sanctum någon gång cachen slutar testet att vara rött
     * och hjälparen kan tas bort.
     */
    $b_svar = getJson('/api/containers', $bHeaders);
    $b_svar->assertOk();
    expect($b_svar->json('data.0.ulid'))->toBe($aContainer->ulid);
    expect($b_svar->json('data.0.ulid'))->not->toBe($bContainer->ulid);
});

it('medToken() ger två token-anrop i följd var sitt svar, och withToken() utan den gör det inte', function () {
    [$a, , $aContainer] = identitetsbyteKontext('A:s container');
    [$b, , $bContainer] = identitetsbyteKontext('B:s container');
    $aToken = $a->createToken('api')->plainTextToken;
    $bToken = $b->createToken('api')->plainTextToken;

    // Med hjälparen: det andra svaret gäller den andra användaren.
    $a_svar = medToken($aToken)->getJson('/api/containers');
    $a_svar->assertOk();
    expect($a_svar->json('data.0.ulid'))->toBe($aContainer->ulid);

    $b_svar = medToken($bToken)->getJson('/api/containers');
    $b_svar->assertOk();
    expect($b_svar->json('data.0.ulid'))->toBe($bContainer->ulid);
    expect($b_svar->json('data.0.ulid'))->not->toBe($aContainer->ulid);

    // Utan hjälparen: guarden ligger kvar med A, och B:s token läses aldrig.
    // Beviset att medToken() behövs.
    auth()->forgetGuards();
    $forsta = withToken($aToken)->getJson('/api/containers');
    expect($forsta->json('data.0.ulid'))->toBe($aContainer->ulid);

    $andra = withToken($bToken)->getJson('/api/containers');
    $andra->assertOk();
    expect($andra->json('data.0.ulid'))->toBe($aContainer->ulid);
    expect($andra->json('data.0.ulid'))->not->toBe($bContainer->ulid);
});
