<?php

use App\Models\Account;
use App\Models\LegalHold;
use App\Models\PrivacyRequest;
use App\Models\PrivacyRequestEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

/*
 * Issue 484 · M87 Integritetsbegäranden, bedömningen, åtgärderna och
 * kommunikationen. Se App\Actions\Privacy\RecordPrivacyAssessment,
 * RecordPrivacyAction, RecordPrivacyCommunication och RecordPrivacyNote,
 * kommandona under App\Console\Commands\Privacy, [[ADR-0095
 * Integritetsbegäranden]] § 7 och § 9, [[ADR-0043 Tre loggar]]
 * § Den rättsliga spärren och [[Konton och åtkomst]]
 * § privacy_request_event.
 *
 * Varje "Klart när"-punkt i issuen motsvarar ett namngivet test här.
 * Kommandona prövas genom Artisan och utdata som text, som
 * tests/Feature/Integritet/ArendetTest.php: det är utdatan och raderna i
 * tabellerna som är kontraktet.
 *
 * Hjälparna har prefixet `bed` (bedömning) — Pests funktioner är globala och
 * ArendetTest, FristenTest och StatusOchVerifieringTest definierar redan
 * `integritet*` och `sv*`.
 *
 * Tiden är fryst: händelsernas tidsstämplar jämförs och ett test ska inte
 * ändra betydelse beroende på när sviten körs. Samma grepp som de andra
 * proven i katalogen.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-06-01 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Ett ärende att anteckna på. Vägen dit prövas i ArendetTest, inte här.
 *
 * @param  array<string, mixed>  $attribut
 */
function bedArende(array $attribut = []): PrivacyRequest
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
function bedKor(string $kommando, array $argument, array $val = []): array
{
    $utgång = Artisan::call($kommando, array_merge($argument, $val));

    return [$utgång, Artisan::output()];
}

/**
 * Ett konto med en gällande rättslig spärr.
 */
function bedSparratKonto(): Account
{
    $konto = Account::factory()->create();

    LegalHold::factory()->create(['account_id' => $konto->id]);

    return $konto;
}

/*
 * Klart när: privacy:assess skriver assessment med roll, behandling, grund,
 * beslut och motivering, och sätter ärendets role.
 */
it('privacy:assess skriver assessment och sätter ärendets roll', function () {
    $ärende = bedArende();

    [$utgång] = bedKor('privacy:assess', [
        'reference' => $ärende->reference,
    ], [
        '--role' => 'controller',
        '--activity' => 'Behandlingen av kontouppgifterna',
        '--basis' => 'legal_obligation',
        '--decision' => 'grant',
        '--rationale' => 'Uppgifterna finns i kontot och lämnas ut i sin helhet.',
        '--by' => 'Anna Handläggare',
    ]);

    expect($utgång)->toBe(Command::SUCCESS);

    $ärende->refresh();

    expect($ärende->role)->toBe('controller');

    $händelse = PrivacyRequestEvent::query()->sole();

    expect($händelse->kind)->toBe('assessment')
        ->and($händelse->actor)->toBe('Anna Handläggare')
        ->and($händelse->body)->toBe('Uppgifterna finns i kontot och lämnas ut i sin helhet.')
        ->and($händelse->data)->toMatchArray([
            'role' => 'controller',
            'activity' => 'Behandlingen av kontouppgifterna',
            'basis' => 'legal_obligation',
            'decision' => 'grant',
        ])
        ->and($händelse->data)->not->toHaveKey('data_set');
});

/*
 * Klart när: två bedömningar med olika --data-set ger två händelser. Det är
 * så delvis bifall dokumenteras (ADR-0095 § 7).
 */
it('ger två händelser för två bedömningar med olika datamängd', function () {
    $ärende = bedArende(['status' => 'under_assessment']);

    foreach ([
        ['account', 'grant', 'Kontouppgifterna lämnas ut.'],
        ['user_content', 'refuse', 'Innehållet hör till kunden, se referred_to_controller.'],
    ] as [$datamängd, $beslut, $motivering]) {
        [$utgång] = bedKor('privacy:assess', [
            'reference' => $ärende->reference,
        ], [
            '--role' => 'both',
            '--activity' => 'Begäran enligt art. 15',
            '--basis' => 'contract',
            '--decision' => $beslut,
            '--rationale' => $motivering,
            '--data-set' => $datamängd,
            '--by' => 'Anna Handläggare',
        ]);

        expect($utgång)->toBe(Command::SUCCESS);
    }

    $händelser = PrivacyRequestEvent::query()->orderBy('id')->get();

    expect($händelser)->toHaveCount(2)
        ->and($händelser[0]->data['data_set'])->toBe('account')
        ->and($händelser[0]->data['decision'])->toBe('grant')
        ->and($händelser[1]->data['data_set'])->toBe('user_content')
        ->and($händelser[1]->data['decision'])->toBe('refuse');

    // Rollen på ärendet är den senaste bedömningens; historiken bär båda.
    expect($ärende->refresh()->role)->toBe('both');
});

/*
 * Klart när: ett värde utanför sin lista i config/privacy.php ger exit 1 och
 * ingen händelse. Varje lista i katalogen prövas på det kommando som äger
 * den. privacy:note har ingen lista — dess tomma text prövas för sig nedan.
 */
it('vägrar ett värde utanför sin lista och skriver ingen händelse', function (
    string $kommando,
    array $argument,
    array $val,
) {
    $ärende = bedArende();

    [$utgång] = bedKor(
        $kommando,
        array_merge(['reference' => $ärende->reference], $argument),
        array_merge(['--by' => 'Anna Handläggare'], $val),
    );

    expect($utgång)->toBe(Command::FAILURE);

    $ärende->refresh();

    expect($ärende->role)->toBe('undetermined');
    expect(PrivacyRequestEvent::query()->count())->toBe(0);
})->with([
    'assess: roll' => [
        'privacy:assess',
        [],
        [
            '--role' => 'owner',
            '--activity' => 'Behandlingen',
            '--basis' => 'contract',
            '--decision' => 'grant',
            '--rationale' => 'Motivering.',
        ],
    ],
    'assess: grund' => [
        'privacy:assess',
        [],
        [
            '--role' => 'controller',
            '--activity' => 'Behandlingen',
            '--basis' => 'because',
            '--decision' => 'grant',
            '--rationale' => 'Motivering.',
        ],
    ],
    'assess: beslut' => [
        'privacy:assess',
        [],
        [
            '--role' => 'controller',
            '--activity' => 'Behandlingen',
            '--basis' => 'contract',
            '--decision' => 'approve',
            '--rationale' => 'Motivering.',
        ],
    ],
    'action: system' => [
        'privacy:action',
        [],
        ['--system' => 'cloud', '--operation' => 'searched'],
    ],
    'action: operation' => [
        'privacy:action',
        [],
        ['--system' => 'database', '--operation' => 'deleted'],
    ],
    'action: kategori' => [
        'privacy:action',
        [],
        [
            '--system' => 'database',
            '--operation' => 'searched',
            '--category' => ['profile', 'inbox'],
        ],
    ],
    'action: skäl att behålla' => [
        'privacy:action',
        [],
        [
            '--system' => 'database',
            '--operation' => 'retained',
            '--retention-reason' => 'because',
        ],
    ],
    'action: självbetjäningsfunktion' => [
        'privacy:action',
        [],
        [
            '--system' => 'database',
            '--operation' => 'self_service',
            '--feature' => 'download',
        ],
    ],
    'communicate: slag' => [
        'privacy:communicate',
        ['kind' => 'letter'],
        ['--direction' => 'out', '--channel' => 'email'],
    ],
    'communicate: riktning' => [
        'privacy:communicate',
        ['kind' => 'acknowledgement'],
        ['--direction' => 'sideways', '--channel' => 'email'],
    ],
    'communicate: kanal' => [
        'privacy:communicate',
        ['kind' => 'acknowledgement'],
        ['--direction' => 'out', '--channel' => 'sms'],
    ],
]);

/*
 * Klart när: privacy:note skriver ingen händelse när texten är tom. Anteckningen
 * är den enda händelsen utan listvärden, så det är dess enda ogiltiga värde.
 */
it('vägrar en tom anteckning och skriver ingen händelse', function (string $text) {
    $ärende = bedArende();

    [$utgång] = bedKor('privacy:note', [
        'reference' => $ärende->reference,
        'text' => $text,
    ], ['--by' => 'Anna Handläggare']);

    expect($utgång)->toBe(Command::FAILURE);
    expect(PrivacyRequestEvent::query()->count())->toBe(0);
})->with(['', '   ']);

/*
 * Klart när: --operation=retained utan --retention-reason ger exit 1, och
 * --operation=self_service utan --feature ger exit 1.
 */
it('kräver skälet som hör till operationen', function (array $val) {
    $ärende = bedArende();

    [$utgång, $utdata] = bedKor('privacy:action', [
        'reference' => $ärende->reference,
    ], array_merge(['--system' => 'database', '--by' => 'Anna Handläggare'], $val));

    expect($utgång)->toBe(Command::FAILURE);
    expect($utdata)->toContain('krävs');
    expect(PrivacyRequestEvent::query()->count())->toBe(0);
})->with([
    'retained utan skäl' => [['--operation' => 'retained']],
    'self_service utan funktion' => [['--operation' => 'self_service']],
]);

/*
 * Klart när: --retention-reason=legal_hold går när kontot har en gällande
 * spärr, och ger exit 1 när kontot saknar spärr eller ärendet saknar konto.
 */
it('godtar legal_hold när kontot har en gällande spärr', function () {
    $konto = bedSparratKonto();
    $ärende = bedArende(['account_id' => $konto->id]);

    [$utgång] = bedKor('privacy:action', [
        'reference' => $ärende->reference,
    ], [
        '--system' => 'database',
        '--operation' => 'retained',
        '--retention-reason' => 'legal_hold',
        '--category' => ['security_log'],
        '--note' => 'Spärren hindrar gallringen medan anmälan utreds.',
        '--by' => 'Anna Handläggare',
    ]);

    expect($utgång)->toBe(Command::SUCCESS);

    $händelse = PrivacyRequestEvent::query()->sole();

    expect($händelse->kind)->toBe('action')
        ->and($händelse->data)->toMatchArray([
            'system' => 'database',
            'operation' => 'retained',
            'retention_reason' => 'legal_hold',
            'categories' => ['security_log'],
        ]);
});

it('vägrar legal_hold när ärendets konto saknar spärr', function () {
    $konto = Account::factory()->create();
    $ärende = bedArende(['account_id' => $konto->id]);

    [$utgång, $utdata] = bedKor('privacy:action', [
        'reference' => $ärende->reference,
    ], [
        '--system' => 'database',
        '--operation' => 'retained',
        '--retention-reason' => 'legal_hold',
        '--by' => 'Anna Handläggare',
    ]);

    expect($utgång)->toBe(Command::FAILURE);
    expect($utdata)->toContain('legal_hold');
    expect(PrivacyRequestEvent::query()->count())->toBe(0);
});

it('vägrar legal_hold när spärren är hävd', function () {
    $konto = Account::factory()->create();

    LegalHold::factory()->create([
        'account_id' => $konto->id,
        'lifted_at' => now(),
    ]);

    $ärende = bedArende(['account_id' => $konto->id]);

    [$utgång] = bedKor('privacy:action', [
        'reference' => $ärende->reference,
    ], [
        '--system' => 'database',
        '--operation' => 'retained',
        '--retention-reason' => 'legal_hold',
        '--by' => 'Anna Handläggare',
    ]);

    expect($utgång)->toBe(Command::FAILURE);
    expect(PrivacyRequestEvent::query()->count())->toBe(0);
});

it('vägrar legal_hold när ärendet saknar konto', function () {
    $ärende = bedArende(['account_id' => null]);

    [$utgång, $utdata] = bedKor('privacy:action', [
        'reference' => $ärende->reference,
    ], [
        '--system' => 'database',
        '--operation' => 'retained',
        '--retention-reason' => 'legal_hold',
        '--by' => 'Anna Handläggare',
    ]);

    expect($utgång)->toBe(Command::FAILURE);
    expect($utdata)->toContain('legal_hold');
    expect(PrivacyRequestEvent::query()->count())->toBe(0);
});

/*
 * Klart när: privacy:show visar *Legal hold active* för ett spärrat konto.
 * Raden står överst (ADR-0043 § Den rättsliga spärren).
 */
it('privacy:show skriver Legal hold active överst för ett spärrat konto', function () {
    $konto = bedSparratKonto();
    $ärende = bedArende(['account_id' => $konto->id]);

    [$utgång, $utdata] = bedKor('privacy:show', [
        'reference' => $ärende->reference,
    ], ['--by' => 'Bo Granskare']);

    expect($utgång)->toBe(Command::SUCCESS);
    expect($utdata)->toContain('Legal hold active');

    $spärr = strpos($utdata, 'Legal hold active');
    $referens = strpos($utdata, 'Referens:');

    expect($spärr)->not->toBeFalse()
        ->and($referens)->not->toBeFalse()
        ->and((int) $spärr)->toBeLessThan((int) $referens);
});

it('skriver ingen spärrrad för ett konto utan spärr', function () {
    $konto = Account::factory()->create();
    $ärende = bedArende(['account_id' => $konto->id]);

    [, $utdata] = bedKor('privacy:show', [
        'reference' => $ärende->reference,
    ], ['--by' => 'Bo Granskare']);

    expect($utdata)->not->toContain('Legal hold active');
});

/*
 * Klart när: privacy:show skriver *Self-service is not a complete response*
 * under en self_service-händelse (ADR-0095 § 9).
 */
it('privacy:show skriver att självbetjäningen inte är ett fullgörande', function () {
    $ärende = bedArende();

    [$åtgärd] = bedKor('privacy:action', [
        'reference' => $ärende->reference,
    ], [
        '--system' => 'user_content',
        '--operation' => 'self_service',
        '--feature' => 'container_export',
        '--note' => 'Hänvisad till containerexporten.',
        '--by' => 'Anna Handläggare',
    ]);

    expect($åtgärd)->toBe(Command::SUCCESS);

    [, $utdata] = bedKor('privacy:show', [
        'reference' => $ärende->reference,
    ], ['--by' => 'Bo Granskare']);

    expect($utdata)->toContain('Self-service is not a complete response');

    $händelse = strpos($utdata, 'action');
    $varning = strpos($utdata, 'Self-service is not a complete response');

    expect($händelse)->not->toBeFalse()
        ->and($varning)->not->toBeFalse()
        ->and((int) $varning)->toBeGreaterThan((int) $händelse);
});

it('skriver ingen självbetjäningsrad för en annan åtgärd', function () {
    $ärende = bedArende();

    bedKor('privacy:action', [
        'reference' => $ärende->reference,
    ], [
        '--system' => 'database',
        '--operation' => 'searched',
        '--by' => 'Anna Handläggare',
    ]);

    [, $utdata] = bedKor('privacy:show', [
        'reference' => $ärende->reference,
    ], ['--by' => 'Bo Granskare']);

    expect($utdata)->not->toContain('Self-service is not a complete response');
});

/*
 * Klart när: en self_service-händelse ändrar inte ärendets status.
 */
it('låter en self_service-händelse lämna ärendets status orörd', function () {
    $ärende = bedArende(['status' => 'in_progress']);

    [$utgång] = bedKor('privacy:action', [
        'reference' => $ärende->reference,
    ], [
        '--system' => 'database',
        '--operation' => 'self_service',
        '--feature' => 'profile',
        '--by' => 'Anna Handläggare',
    ]);

    expect($utgång)->toBe(Command::SUCCESS);
    expect($ärende->refresh()->status)->toBe('in_progress');
});

/*
 * Klart när: privacy:communicate och privacy:note skriver sina händelser med
 * handläggaren.
 */
it('privacy:communicate skriver communication med handläggaren', function (string $direction) {
    $ärende = bedArende();

    [$utgång] = bedKor('privacy:communicate', [
        'reference' => $ärende->reference,
        'kind' => 'acknowledgement',
    ], [
        '--direction' => $direction,
        '--channel' => 'email',
        '--note' => 'Bekräftelsen skickades samma dag.',
        '--by' => 'Anna Handläggare',
    ]);

    expect($utgång)->toBe(Command::SUCCESS);

    $händelse = PrivacyRequestEvent::query()->sole();

    expect($händelse->kind)->toBe('communication')
        ->and($händelse->actor)->toBe('Anna Handläggare')
        ->and($händelse->body)->toBe('Bekräftelsen skickades samma dag.')
        ->and($händelse->data)->toMatchArray([
            'kind' => 'acknowledgement',
            'direction' => $direction,
            'channel' => 'email',
        ]);
})->with(['in', 'out']);

it('privacy:note skriver note med handläggaren', function () {
    $ärende = bedArende();

    [$utgång] = bedKor('privacy:note', [
        'reference' => $ärende->reference,
        'text' => 'Kontot kontrollerat mot folkbokföringen.',
    ], ['--by' => 'Anna Handläggare']);

    expect($utgång)->toBe(Command::SUCCESS);

    $händelse = PrivacyRequestEvent::query()->sole();

    expect($händelse->kind)->toBe('note')
        ->and($händelse->actor)->toBe('Anna Handläggare')
        ->and($händelse->body)->toBe('Kontot kontrollerat mot folkbokföringen.')
        ->and($händelse->data)->toBe([]);
});

/*
 * Alla fyra kommandona har obligatoriskt --by (ADR-0095 § 1): händelsen bär
 * handläggarens namn, och en händelse utan avsändare är inget bevis.
 */
it('kräver --by i alla fyra kommandona', function (
    string $kommando,
    array $argument,
    array $val,
) {
    $ärende = bedArende();

    [$utgång, $utdata] = bedKor(
        $kommando,
        array_merge(['reference' => $ärende->reference], $argument),
        $val,
    );

    expect($utgång)->toBe(Command::FAILURE);
    expect($utdata)->toContain('--by');
    expect(PrivacyRequestEvent::query()->count())->toBe(0);
})->with([
    'assess' => [
        'privacy:assess',
        [],
        [
            '--role' => 'controller',
            '--activity' => 'Behandlingen',
            '--basis' => 'contract',
            '--decision' => 'grant',
            '--rationale' => 'Motivering.',
        ],
    ],
    'action' => [
        'privacy:action',
        [],
        ['--system' => 'database', '--operation' => 'searched'],
    ],
    'communicate' => [
        'privacy:communicate',
        ['kind' => 'acknowledgement'],
        ['--direction' => 'out', '--channel' => 'email'],
    ],
    'note' => [
        'privacy:note',
        ['text' => 'En anteckning.'],
        [],
    ],
]);
