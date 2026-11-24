<?php

use App\Models\Account;
use App\Models\PrivacyRequest;
use App\Models\PrivacyRequestEvent;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/*
 * Issue 482 · M87 Integritetsbegäranden, statusarna, tilldelningen och
 * verifieringen. Se App\Actions\Privacy\ChangePrivacyRequestStatus,
 * AssignPrivacyRequest, RecordPrivacyVerification och LinkPrivacyRequest,
 * kommandona under App\Console\Commands\Privacy, [[ADR-0095
 * Integritetsbegäranden]] § 1–§ 4 och [[Konton och åtkomst]]
 * § privacy_request_event.
 *
 * Varje "Klart när"-punkt i issuen motsvarar ett namngivet test här.
 * Kommandona prövas genom Artisan och utdata som text, som
 * tests/Feature/Integritet/ArendetTest.php: det är utdatan och raderna i
 * tabellerna som är kontraktet.
 *
 * Hjälparna har prefixet `sv` (status och verifiering) — Pests funktioner är
 * globala och ArendetTest definierar redan `integritet*`.
 *
 * Tiden är fryst: `closed_at` sätts till nu, och ett test som jämför mot en
 * riktig klocka hade blivit svajigt. Samma grepp som ArendetTest.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-06-01 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Ett ärende att flytta, fördela eller koppla. Vägen dit prövas i
 * ArendetTest, inte här: den här issuen handlar om vad som händer efteråt.
 *
 * @param  array<string, mixed>  $attribut
 */
function svArende(array $attribut = []): PrivacyRequest
{
    return PrivacyRequest::factory()->create($attribut);
}

/**
 * Kör ett privacy-kommando och returnerar [utgångskod, utdata].
 *
 * @param  array<string, mixed>  $argument
 * @param  array<string, mixed>  $val
 * @return array{0: int, 1: string}
 */
function svKor(string $kommando, array $argument, array $val = []): array
{
    $utgång = Artisan::call($kommando, array_merge($argument, $val));

    return [$utgång, Artisan::output()];
}

/*
 * Klart när: privacy:status mellan två öppna statusar sparar statusen och en
 * händelse status_changed med from, to och handläggaren.
 */
it('flyttar ärendet mellan två öppna statusar och skriver status_changed', function () {
    $ärende = svArende(['status' => 'received']);

    [$utgång] = svKor('privacy:status', [
        'reference' => $ärende->reference,
        'status' => 'in_progress',
    ], ['--by' => 'Anna Handläggare']);

    expect($utgång)->toBe(Command::SUCCESS);

    $ärende->refresh();

    expect($ärende->status)->toBe('in_progress')
        ->and($ärende->closed_at)->toBeNull()
        ->and($ärende->outcome_reason)->toBeNull();

    $händelse = PrivacyRequestEvent::query()->sole();

    expect($händelse->kind)->toBe('status_changed')
        ->and($händelse->actor)->toBe('Anna Handläggare')
        ->and($händelse->data)->toMatchArray([
            'from' => 'received',
            'to' => 'in_progress',
        ]);
});

/*
 * Klart när: till partially_completed, rejected och closed utan --reason ger
 * exit 1 och ingen ändring. Med skäl sätts outcome_reason och closed_at.
 */
it('kräver ett skäl för att avsluta som annat än completed', function (string $status) {
    $ärende = svArende(['status' => 'in_progress']);

    [$utgång, $utdata] = svKor('privacy:status', [
        'reference' => $ärende->reference,
        'status' => $status,
    ], ['--by' => 'Anna Handläggare']);

    expect($utgång)->toBe(Command::FAILURE);
    expect($utdata)->toContain('--reason');

    $ärende->refresh();

    expect($ärende->status)->toBe('in_progress')
        ->and($ärende->closed_at)->toBeNull()
        ->and($ärende->outcome_reason)->toBeNull();

    expect(PrivacyRequestEvent::query()->count())->toBe(0);
})->with(['partially_completed', 'rejected', 'closed']);

it('avslutar med skäl och sätter outcome_reason och closed_at', function (string $status) {
    $ärende = svArende(['status' => 'in_progress']);
    $skäl = 'Begäran kunde inte knytas till en identitet.';

    [$utgång] = svKor('privacy:status', [
        'reference' => $ärende->reference,
        'status' => $status,
    ], [
        '--reason' => $skäl,
        '--by' => 'Anna Handläggare',
    ]);

    expect($utgång)->toBe(Command::SUCCESS);

    $ärende->refresh();

    expect($ärende->status)->toBe($status)
        ->and($ärende->outcome_reason)->toBe($skäl)
        ->and($ärende->closed_at)->not->toBeNull()
        ->and($ärende->closed_at->format('Y-m-d H:i'))->toBe('2026-06-01 12:00');

    $händelse = PrivacyRequestEvent::query()->sole();

    expect($händelse->kind)->toBe('status_changed')
        ->and($händelse->body)->toBe($skäl)
        ->and($händelse->data)->toMatchArray([
            'from' => 'in_progress',
            'to' => $status,
            'reason' => $skäl,
        ]);
})->with(['partially_completed', 'rejected', 'closed']);

/*
 * Klart när: till completed utan skäl går, och closed_at sätts.
 */
it('avslutar som completed utan skäl', function () {
    $ärende = svArende(['status' => 'in_progress']);

    [$utgång] = svKor('privacy:status', [
        'reference' => $ärende->reference,
        'status' => 'completed',
    ], ['--by' => 'Anna Handläggare']);

    expect($utgång)->toBe(Command::SUCCESS);

    $ärende->refresh();

    expect($ärende->status)->toBe('completed')
        ->and($ärende->outcome_reason)->toBeNull()
        ->and($ärende->closed_at)->not->toBeNull();

    expect(PrivacyRequestEvent::query()->sole()->data)->toMatchArray([
        'from' => 'in_progress',
        'to' => 'completed',
    ]);
});

/*
 * Klart när: från ett avslutat läge till ett öppet kräver skäl, nollställer
 * closed_at och outcome_reason och bär det gamla skälet på händelsen.
 */
it('kräver ett skäl för att öppna ett avslutat ärende igen', function () {
    $ärende = svArende([
        'status' => 'rejected',
        'outcome_reason' => 'Identiteten gick inte att pröva.',
        'closed_at' => now(),
    ]);

    [$utgång, $utdata] = svKor('privacy:status', [
        'reference' => $ärende->reference,
        'status' => 'verification_required',
    ], ['--by' => 'Anna Handläggare']);

    expect($utgång)->toBe(Command::FAILURE);
    expect($utdata)->toContain('--reason');

    $ärende->refresh();

    expect($ärende->status)->toBe('rejected')
        ->and($ärende->outcome_reason)->toBe('Identiteten gick inte att pröva.')
        ->and($ärende->closed_at)->not->toBeNull();

    expect(PrivacyRequestEvent::query()->count())->toBe(0);
});

it('öppnar ett avslutat ärende igen med skäl och nollställer avslutet', function () {
    $ärende = svArende([
        'status' => 'rejected',
        'outcome_reason' => 'Identiteten gick inte att pröva.',
        'closed_at' => now(),
    ]);
    $skäl = 'Kompletterande uppgifter inkom.';

    [$utgång] = svKor('privacy:status', [
        'reference' => $ärende->reference,
        'status' => 'verification_required',
    ], [
        '--reason' => $skäl,
        '--by' => 'Anna Handläggare',
    ]);

    expect($utgång)->toBe(Command::SUCCESS);

    $ärende->refresh();

    expect($ärende->status)->toBe('verification_required')
        ->and($ärende->closed_at)->toBeNull()
        ->and($ärende->outcome_reason)->toBeNull();

    $händelse = PrivacyRequestEvent::query()->sole();

    expect($händelse->kind)->toBe('status_changed')
        ->and($händelse->body)->toBe($skäl)
        ->and($händelse->data)->toMatchArray([
            'from' => 'rejected',
            'to' => 'verification_required',
            'reason' => $skäl,
            'previous_outcome_reason' => 'Identiteten gick inte att pröva.',
        ]);
});

/*
 * Klart när: från ett avslutat läge till ett annat avslutat ger exit 1.
 */
it('vägrar flytta mellan två avslutade lägen', function () {
    $ärende = svArende([
        'status' => 'rejected',
        'outcome_reason' => 'Saknar identitet.',
        'closed_at' => now(),
    ]);

    [$utgång, $utdata] = svKor('privacy:status', [
        'reference' => $ärende->reference,
        'status' => 'closed',
    ], [
        '--reason' => 'Dubblett.',
        '--by' => 'Anna Handläggare',
    ]);

    expect($utgång)->toBe(Command::FAILURE);
    expect($utdata)->toContain('avslutat');

    $ärende->refresh();

    expect($ärende->status)->toBe('rejected')
        ->and($ärende->outcome_reason)->toBe('Saknar identitet.');

    expect(PrivacyRequestEvent::query()->count())->toBe(0);
});

/*
 * Klart när: privacy:assign sätter handläggare och prioritet och skriver
 * assigned med värdena före och efter.
 */
it('tilldelar ärendet och sätter prioriteten med värdena före och efter', function () {
    $ärende = svArende([
        'assignee' => null,
        'priority' => 'normal',
    ]);

    [$utgång] = svKor('privacy:assign', [
        'reference' => $ärende->reference,
        'assignee' => 'Bo Handläggare',
    ], [
        '--priority' => 'high',
        '--by' => 'Anna Handläggare',
    ]);

    expect($utgång)->toBe(Command::SUCCESS);

    $ärende->refresh();

    expect($ärende->assignee)->toBe('Bo Handläggare')
        ->and($ärende->priority)->toBe('high');

    $händelse = PrivacyRequestEvent::query()->sole();

    expect($händelse->kind)->toBe('assigned')
        ->and($händelse->actor)->toBe('Anna Handläggare')
        ->and($händelse->data)->toBe([
            'assignee' => ['from' => null, 'to' => 'Bo Handläggare'],
            'priority' => ['from' => 'normal', 'to' => 'high'],
        ]);
});

/*
 * Klart när: med bara --priority och tom assignee ändras bara prioriteten.
 */
it('ändrar bara prioriteten när handläggaren är tom', function () {
    $ärende = svArende([
        'assignee' => 'Anna Gammal',
        'priority' => 'normal',
    ]);

    [$utgång] = svKor('privacy:assign', [
        'reference' => $ärende->reference,
        'assignee' => '',
    ], [
        '--priority' => 'low',
        '--by' => 'Anna Handläggare',
    ]);

    expect($utgång)->toBe(Command::SUCCESS);

    $ärende->refresh();

    expect($ärende->assignee)->toBe('Anna Gammal')
        ->and($ärende->priority)->toBe('low');

    expect(PrivacyRequestEvent::query()->sole()->data)->toBe([
        'assignee' => ['from' => 'Anna Gammal', 'to' => 'Anna Gammal'],
        'priority' => ['from' => 'normal', 'to' => 'low'],
    ]);
});

/*
 * Klart när: en okänd prioritet ger exit 1.
 */
it('nekar en okänd prioritet utan att skriva något', function () {
    $ärende = svArende([
        'assignee' => null,
        'priority' => 'normal',
    ]);

    [$utgång, $utdata] = svKor('privacy:assign', [
        'reference' => $ärende->reference,
        'assignee' => 'Bo Handläggare',
    ], [
        '--priority' => 'akut',
        '--by' => 'Anna Handläggare',
    ]);

    expect($utgång)->toBe(Command::FAILURE);
    expect($utdata)->toContain('prioritet');

    $ärende->refresh();

    expect($ärende->assignee)->toBeNull()
        ->and($ärende->priority)->toBe('normal');

    expect(PrivacyRequestEvent::query()->count())->toBe(0);
});

/*
 * Klart när: privacy:verify identity --method=account_email
 * --result=verified skriver en händelse verification.
 *
 * Händelsen säger vad som prövades, inte vad som visades: data bär bara
 * ämne, metod och resultat, och anteckningen är handläggarens egen text
 * (ADR-0095 § 2).
 */
it('skriver en verifiering med ämne, metod, resultat och anteckning', function () {
    $ärende = svArende(['status' => 'verification_required']);

    [$utgång] = svKor('privacy:verify', [
        'reference' => $ärende->reference,
        'subject' => 'identity',
    ], [
        '--method' => 'account_email',
        '--result' => 'verified',
        '--note' => 'Svar kom från kontots adress.',
        '--by' => 'Anna Handläggare',
    ]);

    expect($utgång)->toBe(Command::SUCCESS);

    $ärende->refresh();

    // Verifieringen ändrar inte statusen; det är ett eget byte.
    expect($ärende->status)->toBe('verification_required');

    $händelse = PrivacyRequestEvent::query()->sole();

    expect($händelse->kind)->toBe('verification')
        ->and($händelse->actor)->toBe('Anna Handläggare')
        ->and($händelse->body)->toBe('Svar kom från kontots adress.')
        ->and($händelse->data)->toBe([
            'subject' => 'identity',
            'method' => 'account_email',
            'result' => 'verified',
        ]);
});

/*
 * Klart när: ett okänt subjekt, en okänd metod eller ett okänt resultat ger
 * exit 1.
 */
it('nekar ett okänt ämne, en okänd metod och ett okänt resultat', function (
    array $argument,
    array $val,
    string $ord,
) {
    $ärende = svArende(['status' => 'verification_required']);

    [$utgång, $utdata] = svKor(
        'privacy:verify',
        array_merge(['reference' => $ärende->reference], $argument),
        array_merge($val, ['--by' => 'Anna Handläggare']),
    );

    expect($utgång)->toBe(Command::FAILURE);
    expect($utdata)->toContain($ord);

    expect(PrivacyRequestEvent::query()->count())->toBe(0);
})->with([
    'ämne' => [
        ['subject' => 'finns-inte'],
        ['--method' => 'account_email', '--result' => 'verified'],
        'ämne',
    ],
    'metod' => [
        ['subject' => 'identity'],
        ['--method' => 'finns-inte', '--result' => 'verified'],
        'metod',
    ],
    'resultat' => [
        ['subject' => 'identity'],
        ['--method' => 'account_email', '--result' => 'finns-inte'],
        'resultat',
    ],
]);

/*
 * Klart när: privacy:link kopplar ärendet och skriver linked. En okänd ULID
 * ger exit 1.
 */
it('kopplar ärendet till ett konto och en person och skriver linked', function () {
    $konto = Account::factory()->create();
    $person = User::factory()->create();
    $konto->users()->attach($person, ['role' => 'owner']);

    $ärende = svArende();

    [$utgång] = svKor('privacy:link', [
        'reference' => $ärende->reference,
    ], [
        '--account' => $konto->ulid,
        '--user' => $person->ulid,
        '--by' => 'Anna Handläggare',
    ]);

    expect($utgång)->toBe(Command::SUCCESS);

    $ärende->refresh();

    expect($ärende->account_id)->toBe($konto->id)
        ->and($ärende->user_id)->toBe($person->id);

    $händelse = PrivacyRequestEvent::query()->sole();

    expect($händelse->kind)->toBe('linked')
        ->and($händelse->actor)->toBe('Anna Handläggare')
        ->and($händelse->data)->toBe([
            'account' => $konto->ulid,
            'user' => $person->ulid,
        ]);
});

it('kopplar bara det som anges', function () {
    $konto = Account::factory()->create();
    $person = User::factory()->create();
    $konto->users()->attach($person, ['role' => 'owner']);

    $ärende = svArende();

    svKor('privacy:link', ['reference' => $ärende->reference], [
        '--account' => $konto->ulid,
        '--by' => 'Anna Handläggare',
    ]);

    $ärende->refresh();

    expect($ärende->account_id)->toBe($konto->id)
        ->and($ärende->user_id)->toBeNull();

    expect(PrivacyRequestEvent::query()->sole()->data)->toBe([
        'account' => $konto->ulid,
        'user' => null,
    ]);
});

it('nekar en okänd ULID utan att skriva något', function (string $flagga) {
    $ärende = svArende();

    [$utgång, $utdata] = svKor('privacy:link', [
        'reference' => $ärende->reference,
    ], [
        $flagga => (string) Str::ulid(),
        '--by' => 'Anna Handläggare',
    ]);

    expect($utgång)->toBe(Command::FAILURE);
    expect($utdata)->toContain('ULID');

    $ärende->refresh();

    expect($ärende->account_id)->toBeNull()
        ->and($ärende->user_id)->toBeNull();

    expect(PrivacyRequestEvent::query()->count())->toBe(0);
})->with(['--account', '--user']);

it('kräver ett konto eller en person att koppla', function () {
    $ärende = svArende();

    [$utgång, $utdata] = svKor('privacy:link', [
        'reference' => $ärende->reference,
    ], ['--by' => 'Anna Handläggare']);

    expect($utgång)->toBe(Command::FAILURE);
    expect($utdata)->toContain('--account');

    expect(PrivacyRequestEvent::query()->count())->toBe(0);
});

/*
 * Klart när: ett okänt referensnummer ger exit 1 i alla fyra kommandona.
 */
it('ger exit 1 för ett okänt referensnummer i alla fyra kommandona', function (
    string $kommando,
    array $argument,
    array $val,
) {
    [$utgång, $utdata] = svKor($kommando, $argument, array_merge(
        $val,
        ['--by' => 'Anna Handläggare'],
    ));

    expect($utgång)->toBe(Command::FAILURE);
    expect($utdata)->toContain('Inget ärende');

    expect(PrivacyRequestEvent::query()->count())->toBe(0);
})->with([
    'status' => [
        'privacy:status',
        ['reference' => 'PR-00000000', 'status' => 'in_progress'],
        [],
    ],
    'assign' => [
        'privacy:assign',
        ['reference' => 'PR-00000000', 'assignee' => 'Bo Handläggare'],
        [],
    ],
    'verify' => [
        'privacy:verify',
        ['reference' => 'PR-00000000', 'subject' => 'identity'],
        ['--method' => 'account_email', '--result' => 'verified'],
    ],
    'link' => [
        'privacy:link',
        ['reference' => 'PR-00000000'],
        ['--account' => (string) Str::ulid()],
    ],
]);

/*
 * Alla fyra kommandona har obligatoriskt --by (ADR-0095 § 1): händelsen bär
 * handläggarens namn, och en händelse utan avsändare är inget bevis.
 */
it('kräver --by i alla fyra kommandona', function (
    string $kommando,
    array $argument,
    array $val,
) {
    $ärende = svArende(['status' => 'received']);

    [$utgång, $utdata] = svKor(
        $kommando,
        array_merge(['reference' => $ärende->reference], $argument),
        $val,
    );

    expect($utgång)->toBe(Command::FAILURE);
    expect($utdata)->toContain('--by');

    expect(PrivacyRequestEvent::query()->count())->toBe(0);
})->with([
    'status' => [
        'privacy:status',
        ['status' => 'in_progress'],
        [],
    ],
    'assign' => [
        'privacy:assign',
        ['assignee' => 'Bo Handläggare'],
        [],
    ],
    'verify' => [
        'privacy:verify',
        ['subject' => 'identity'],
        ['--method' => 'account_email', '--result' => 'verified'],
    ],
    'link' => [
        'privacy:link',
        [],
        ['--account' => (string) Str::ulid()],
    ],
]);
