<?php

use App\Models\Container;
use App\Models\PrivacyRequest;
use App\Models\PrivacyRequestEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;

/*
 * Issue 485 · M87 Integritetsbegäranden, begränsningen (art. 18). Se
 * App\Actions\Privacy\PlacePrivacyRestriction och LiftPrivacyRestriction,
 * kommandona under App\Console\Commands\Privacy, [[ADR-0095
 * Integritetsbegäranden]] § 8 och [[Konton och åtkomst]] § privacy_request.
 *
 * Varje "Klart när"-punkt i issuen motsvarar ett namngivet test här.
 * Kommandona prövas genom Artisan och utdata som text, som de andra
 * integritetsproven: det är utdatan och raderna i tabellerna som är
 * kontraktet.
 *
 * **Begränsningen är en markering, inte ett skrivskydd** (ADR-0095 § 8). Ett
 * av proven visar det med en inloggad begäran mot en container: kontot kan
 * fortfarande läsa och skriva medan begränsningen gäller.
 *
 * Hjälparna har prefixet `begransning` — Pests funktioner är globala och de
 * andra integritetsproven definierar redan `integritet*`, `frist*` och `sv*`.
 *
 * Tiden är fryst: `restricted_at` och `restriction_lifted_at` sätts till nu.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-06-01 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Ett ärende att sätta, häva eller visa en begränsning på. Vägen dit prövas
 * i ArendetTest; den här issuen handlar om markeringen.
 *
 * @param  array<string, mixed>  $attribut
 */
function begransningArende(array $attribut = []): PrivacyRequest
{
    return PrivacyRequest::factory()->create($attribut);
}

/**
 * Ett ärende med en gällande begränsning.
 *
 * @param  array<string, mixed>  $attribut
 */
function begransningAktivt(array $attribut = []): PrivacyRequest
{
    return begransningArende(array_merge([
        'status' => 'in_progress',
        'restriction_scope' => 'Profil och konto; ändamålet marknadsföring.',
        'restricted_at' => now(),
        'restriction_lifted_at' => null,
    ], $attribut));
}

/**
 * Kör ett privacy-kommando och returnerar [utgångskod, utdata].
 *
 * @param  array<string, mixed>  $argument
 * @param  array<string, mixed>  $val
 * @return array{0: int, 1: string}
 */
function begransningKor(string $kommando, array $argument, array $val = []): array
{
    $utgång = Artisan::call($kommando, array_merge($argument, $val));

    return [$utgång, Artisan::output()];
}

/*
 * Klart när: privacy:restrict sätter restriction_scope och restricted_at och
 * skriver restriction_placed.
 */
it('sätter begränsningen och skriver restriction_placed', function () {
    $ärende = begransningArende(['status' => 'in_progress']);

    [$utgång, $utdata] = begransningKor('privacy:restrict', [
        'reference' => $ärende->reference,
    ], [
        '--scope' => 'Profil och konto; ändamålet marknadsföring.',
        '--by' => 'Anna Handläggare',
    ]);

    expect($utgång)->toBe(Command::SUCCESS);

    $ärende->refresh();

    expect($ärende->restriction_scope)->toBe('Profil och konto; ändamålet marknadsföring.')
        ->and($ärende->restricted_at->format('Y-m-d H:i'))->toBe('2026-06-01 12:00')
        ->and($ärende->restriction_lifted_at)->toBeNull();

    $händelse = PrivacyRequestEvent::query()->sole();

    expect($händelse->kind)->toBe('restriction_placed')
        ->and($händelse->actor)->toBe('Anna Handläggare')
        ->and($händelse->data)->toMatchArray([
            'scope' => 'Profil och konto; ändamålet marknadsföring.',
        ]);

    expect($utdata)->toContain($ärende->reference);
    expect($utdata)->toContain('Profil och konto; ändamålet marknadsföring.');
});

/*
 * Klart när: utan --scope blir det exit 1. Ett --scope med bara blanksteg är
 * ingen omfattning och vägras också.
 */
it('vägrar en begränsning utan omfattning', function (?string $scope) {
    $ärende = begransningArende(['status' => 'in_progress']);

    $val = ['--by' => 'Anna Handläggare'];

    if ($scope !== null) {
        $val['--scope'] = $scope;
    }

    [$utgång, $utdata] = begransningKor('privacy:restrict', [
        'reference' => $ärende->reference,
    ], $val);

    expect($utgång)->toBe(Command::FAILURE);
    expect($utdata)->toContain('--scope');

    $ärende->refresh();

    expect($ärende->restriction_scope)->toBeNull()
        ->and($ärende->restricted_at)->toBeNull()
        ->and($ärende->restriction_lifted_at)->toBeNull();

    expect(PrivacyRequestEvent::query()->count())->toBe(0);
})->with([
    'flaggan saknas' => [null],
    'bara blanksteg' => ['   '],
]);

/*
 * Klart när: mot en gällande begränsning blir det exit 1. Den ska hävas
 * först, så att historiken får ett slut och en ny begränsning en egen
 * början.
 */
it('vägrar en ny begränsning medan en gällande finns', function () {
    $ärende = begransningAktivt();

    [$utgång, $utdata] = begransningKor('privacy:restrict', [
        'reference' => $ärende->reference,
    ], [
        '--scope' => 'Sessionsloggar.',
        '--by' => 'Anna Handläggare',
    ]);

    expect($utgång)->toBe(Command::FAILURE);
    expect($utdata)->toContain('gällande begränsning');

    $ärende->refresh();

    expect($ärende->restriction_scope)->toBe('Profil och konto; ändamålet marknadsföring.')
        ->and($ärende->restricted_at->format('Y-m-d H:i'))->toBe('2026-06-01 12:00')
        ->and($ärende->restriction_lifted_at)->toBeNull();

    expect(PrivacyRequestEvent::query()->count())->toBe(0);
});

/*
 * En ny begränsning efter en hävd nollställer restriction_lifted_at, så att
 * raden beskriver den begränsning som gäller nu.
 */
it('nollställer restriction_lifted_at när en ny begränsning sätts', function () {
    $ärende = begransningArende([
        'status' => 'in_progress',
        'restriction_scope' => 'Det gamla omfånget.',
        'restricted_at' => Carbon::parse('2026-05-01 09:00:00'),
        'restriction_lifted_at' => Carbon::parse('2026-05-15 09:00:00'),
    ]);

    [$utgång] = begransningKor('privacy:restrict', [
        'reference' => $ärende->reference,
    ], [
        '--scope' => 'Det nya omfånget.',
        '--by' => 'Anna Handläggare',
    ]);

    expect($utgång)->toBe(Command::SUCCESS);

    $ärende->refresh();

    expect($ärende->restriction_scope)->toBe('Det nya omfånget.')
        ->and($ärende->restricted_at->format('Y-m-d H:i'))->toBe('2026-06-01 12:00')
        ->and($ärende->restriction_lifted_at)->toBeNull();

    expect(PrivacyRequestEvent::query()->sole()->kind)->toBe('restriction_placed');
});

/*
 * Klart när: privacy:unrestrict sätter restriction_lifted_at och skriver
 * restriction_lifted med skälet. Utskriften påminner om art. 18.3.
 */
it('häver begränsningen med skäl och påminner om underrättelsen', function () {
    $ärende = begransningAktivt();

    [$utgång, $utdata] = begransningKor('privacy:unrestrict', [
        'reference' => $ärende->reference,
    ], [
        '--reason' => 'Begäran om begränsning återkallades.',
        '--by' => 'Bo Handläggare',
    ]);

    expect($utgång)->toBe(Command::SUCCESS);

    $ärende->refresh();

    expect($ärende->restriction_lifted_at->format('Y-m-d H:i'))->toBe('2026-06-01 12:00')
        ->and($ärende->restriction_scope)->toBe('Profil och konto; ändamålet marknadsföring.')
        ->and($ärende->hasActiveRestriction())->toBeFalse();

    $händelse = PrivacyRequestEvent::query()->sole();

    expect($händelse->kind)->toBe('restriction_lifted')
        ->and($händelse->actor)->toBe('Bo Handläggare')
        ->and($händelse->body)->toBe('Begäran om begränsning återkallades.')
        ->and($händelse->data)->toMatchArray([
            'scope' => 'Profil och konto; ändamålet marknadsföring.',
            'reason' => 'Begäran om begränsning återkallades.',
        ]);

    // Art. 18.3: den registrerade ska underrättas innan begränsningen hävs.
    expect($utdata)->toContain('privacy:communicate');
});

/*
 * Klart när: utan gällande begränsning blir det exit 1. Utan skäl blir det
 * också exit 1 — den hävs med skäl (ADR-0095 § 8).
 */
it('vägrar att häva något som inte gäller', function (string $fall) {
    $ärende = $fall === 'utan begränsning'
        ? begransningArende(['status' => 'in_progress'])
        : begransningAktivt();

    $val = ['--by' => 'Bo Handläggare'];

    if ($fall === 'utan skäl') {
        $val['--reason'] = '   ';
    } else {
        $val['--reason'] = 'Ett skäl.';
    }

    [$utgång, $utdata] = begransningKor('privacy:unrestrict', [
        'reference' => $ärende->reference,
    ], $val);

    expect($utgång)->toBe(Command::FAILURE);
    expect($utdata)->toContain($fall === 'utan skäl' ? '--reason' : 'ingen gällande begränsning');

    $ärende->refresh();

    expect($ärende->hasActiveRestriction())->toBe($fall === 'utan skäl')
        ->and($ärende->restriction_lifted_at)->toBeNull();

    expect(PrivacyRequestEvent::query()->count())->toBe(0);
})->with(['utan begränsning', 'utan skäl']);

/*
 * Klart när: privacy:list --restricted visar ärenden med gällande
 * begränsning, också avslutade, och inte hävda. Kolumnen scope står med, men
 * inte namn eller e-postadress.
 */
it('listar gällande begränsningar med scope och utan namn och e-post', function () {
    $öppen = begransningAktivt([
        'requester_name' => 'Unikt Namn Xyzzy',
        'requester_email' => 'unikt-xyzzy@example.com',
        'restriction_scope' => 'Profil och konto.',
    ]);

    $avslutad = begransningAktivt([
        'status' => 'completed',
        'closed_at' => Carbon::parse('2026-05-20'),
        'restriction_scope' => 'Sessionsloggar.',
    ]);

    $hävd = begransningAktivt([
        'restriction_scope' => 'Redan hävd.',
        'restriction_lifted_at' => Carbon::parse('2026-05-25'),
    ]);

    [$utgång, $utdata] = begransningKor('privacy:list', [], ['--restricted' => true]);

    expect($utgång)->toBe(Command::SUCCESS);

    // Också det avslutade ärendet syns: en begränsning kan gälla efter att
    // ärendet avgjorts (ADR-0095 § 8).
    expect($utdata)->toContain($öppen->reference);
    expect($utdata)->toContain($avslutad->reference);
    expect($utdata)->toContain('Profil och konto.');
    expect($utdata)->toContain('Sessionsloggar.');

    expect($utdata)->not->toContain($hävd->reference);
    expect($utdata)->not->toContain('Redan hävd.');

    // Kolumnen scope står med; namn och e-postadress gör det inte.
    expect($utdata)->toContain('Scope');
    expect($utdata)->not->toContain('Unikt Namn Xyzzy');
    expect($utdata)->not->toContain('unikt-xyzzy@example.com');

    // Listan är påminnelsen och skriver ingen händelse.
    expect(PrivacyRequestEvent::query()->count())->toBe(0);
});

/*
 * Klart när: privacy:show visar Restriction active bara medan begränsningen
 * gäller.
 */
it('visar Restriction active bara medan begränsningen gäller', function () {
    $ärende = begransningAktivt([
        'restriction_scope' => 'Profil och konto; ändamålet marknadsföring.',
    ]);

    [$utgång, $utdata] = begransningKor('privacy:show', [
        'reference' => $ärende->reference,
    ], ['--by' => 'Bo Granskare']);

    expect($utgång)->toBe(Command::SUCCESS);
    expect($utdata)->toContain('Restriction active: Profil och konto; ändamålet marknadsföring.');

    // Raden står överst, före ärendets uppgifter.
    $varning = strpos($utdata, 'Restriction active');
    $referens = strpos($utdata, "Referens: {$ärende->reference}");

    expect($varning)->not->toBeFalse();
    expect($referens)->not->toBeFalse();
    expect((int) $varning)->toBeLessThan((int) $referens);

    // Hävd: raden försvinner, men omfattningen står kvar på ärendet.
    begransningKor('privacy:unrestrict', [
        'reference' => $ärende->reference,
    ], [
        '--reason' => 'Begäran återkallad.',
        '--by' => 'Bo Granskare',
    ]);

    [, $utdata] = begransningKor('privacy:show', [
        'reference' => $ärende->reference,
    ], ['--by' => 'Bo Granskare']);

    expect($utdata)->not->toContain('Restriction active');
});

/*
 * Klart när: ett konto vars ärende har en gällande begränsning kan fortfarande
 * logga in, läsa och skriva. Det prövas med en inloggad begäran mot en
 * container — markeringen ändrar inget i appen (ADR-0095 § 8).
 */
it('låter kontot läsa och skriva i en container medan begränsningen gäller', function () {
    [$account, $user, $headers] = kontoMedMedlem();

    $container = Container::factory()->for($account, 'account')->create([
        'name' => 'Före begränsningen',
    ]);

    $ärende = begransningAktivt([
        'account_id' => $account->id,
        'user_id' => $user->id,
    ]);

    expect($ärende->hasActiveRestriction())->toBeTrue();

    // Läsa: den inloggade medlemmen når containern som vanligt.
    getJson("/api/containers/{$container->ulid}", $headers)->assertOk();

    // Skriva: samma begäran ändrar containern.
    patchJson("/api/containers/{$container->ulid}", ['name' => 'Efter begränsningen'], $headers)
        ->assertOk();

    expect($container->fresh()->name)->toBe('Efter begränsningen');

    // Markeringen ligger kvar och har inte påverkats av trafiken.
    expect($ärende->fresh()->hasActiveRestriction())->toBeTrue();
});

/*
 * Klart när: privacy:status … completed på ett ärende med gällande
 * begränsning lämnar begränsningen och skriver en varning.
 */
it('lämnar begränsningen kvar och varnar när ärendet avslutas', function () {
    $ärende = begransningAktivt(['status' => 'in_progress']);

    [$utgång, $utdata] = begransningKor('privacy:status', [
        'reference' => $ärende->reference,
        'status' => 'completed',
    ], ['--by' => 'Anna Handläggare']);

    expect($utgång)->toBe(Command::SUCCESS);
    expect($utdata)->toContain('Varning');
    expect($utdata)->toContain('begränsning');
    expect($utdata)->toContain('privacy:unrestrict');

    $ärende->refresh();

    expect($ärende->status)->toBe('completed')
        ->and($ärende->closed_at)->not->toBeNull()
        ->and($ärende->hasActiveRestriction())->toBeTrue()
        ->and($ärende->restriction_scope)->toBe('Profil och konto; ändamålet marknadsföring.')
        ->and($ärende->restriction_lifted_at)->toBeNull();

    // Avslutet skriver bara status_changed; begränsningen hävs inte.
    expect(PrivacyRequestEvent::query()->sole()->kind)->toBe('status_changed');
});
