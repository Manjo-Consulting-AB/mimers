<?php

use App\Models\PrivacyRequest;
use App\Models\PrivacyRequestEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

/*
 * Issue 483 · M87 Integritetsbegäranden, fristen, förlängningen och
 * privacy:list. Se App\Actions\Privacy\ExtendPrivacyRequestDeadline och
 * RestartPrivacyRequestDeadline, kommandona under App\Console\Commands\Privacy,
 * [[ADR-0095 Integritetsbegäranden]] § 5 och § 6 och [[Konton och åtkomst]]
 * § privacy_request.
 *
 * Varje "Klart när"-punkt i issuen motsvarar ett namngivet test här.
 * Kommandona prövas genom Artisan och utdata som text, som
 * tests/Feature/Integritet/ArendetTest.php: det är utdatan och raderna i
 * tabellerna som är kontraktet.
 *
 * Hjälparna har prefixet `frist` — Pests funktioner är globala och de andra
 * integritetsproven definierar redan `integritet*` och `sv*`.
 *
 * Tiden är fryst och flyttas inne i proven: en förlängning prövas mot den
 * ursprungliga fristen och dagar kvar räknas från i dag, så ett test som
 * lutar sig mot en riktig klocka hade ändrat betydelse varje dag.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-06-01 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Ett ärende att förlänga, räkna om eller lista. Vägen dit prövas i
 * ArendetTest; den här issuen handlar om vad som händer efteråt.
 *
 * @param  array<string, mixed>  $attribut
 */
function fristArende(array $attribut = []): PrivacyRequest
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
function fristKor(string $kommando, array $argument, array $val = []): array
{
    $utgång = Artisan::call($kommando, array_merge($argument, $val));

    return [$utgång, Artisan::output()];
}

/**
 * Ett ärende som mottogs 2026-03-15 och har den ursprungliga fristen
 * 2026-04-15. Tiden ska vara fryst före 2026-04-15 när det förlängs.
 *
 * @param  array<string, mixed>  $attribut
 */
function fristMarsArende(array $attribut = []): PrivacyRequest
{
    return fristArende(array_merge([
        'received_at' => Carbon::parse('2026-03-15')->startOfDay(),
        'deadline_at' => Carbon::parse('2026-04-15')->startOfDay(),
        'extended_months' => 0,
    ], $attribut));
}

/**
 * Tre ärenden för listan: ett med frist kvar, ett som passerat och ett
 * avslutat. Används av listproven.
 *
 * @return array{0: PrivacyRequest, 1: PrivacyRequest, 2: PrivacyRequest}
 */
function fristListUnderlag(): array
{
    $senare = fristArende([
        'received_at' => Carbon::parse('2026-05-10')->startOfDay(),
        'deadline_at' => Carbon::parse('2026-06-10')->startOfDay(),
        'status' => 'in_progress',
        'priority' => 'high',
        'assignee' => 'Bo Handläggare',
    ]);

    $tidigare = fristArende([
        'received_at' => Carbon::parse('2026-04-30')->startOfDay(),
        'deadline_at' => Carbon::parse('2026-05-30')->startOfDay(),
        'status' => 'received',
    ]);

    $avslutad = fristArende([
        'received_at' => Carbon::parse('2026-04-05')->startOfDay(),
        'deadline_at' => Carbon::parse('2026-05-05')->startOfDay(),
        'status' => 'completed',
        'closed_at' => Carbon::parse('2026-05-04')->startOfDay(),
    ]);

    return [$senare, $tidigare, $avslutad];
}

/*
 * Klart när: mottaget 2026-03-15 och förlängt med 2 ger fristen 2026-06-15
 * och extended_months 2. Ytterligare en förlängning ger exit 1.
 */
it('förlänger med 2 månader och vägrar en tredje', function () {
    Carbon::setTestNow('2026-03-20 12:00:00');

    $ärende = fristMarsArende();

    [$utgång, $utdata] = fristKor('privacy:extend', [
        'reference' => $ärende->reference,
        'months' => '2',
    ], [
        '--reason' => 'Kompletterande uppgifter behövs.',
        '--by' => 'Anna Handläggare',
    ]);

    expect($utgång)->toBe(Command::SUCCESS);

    $ärende->refresh();

    expect($ärende->extended_months)->toBe(2)
        ->and($ärende->deadline_at->format('Y-m-d'))->toBe('2026-06-15');

    $händelse = PrivacyRequestEvent::query()->sole();

    expect($händelse->kind)->toBe('deadline_changed')
        ->and($händelse->actor)->toBe('Anna Handläggare')
        ->and($händelse->body)->toBe('Kompletterande uppgifter behövs.')
        ->and($händelse->data)->toMatchArray([
            'kind' => 'extended',
            'from' => '2026-04-15',
            'to' => '2026-06-15',
            'months' => 2,
            'reason' => 'Kompletterande uppgifter behövs.',
        ]);

    expect($utdata)->toContain('2026-06-15');
    expect($utdata)->toContain('privacy:communicate');

    [$utgång, $utdata] = fristKor('privacy:extend', [
        'reference' => $ärende->reference,
        'months' => '1',
    ], [
        '--reason' => 'Ännu en komplettering.',
        '--by' => 'Anna Handläggare',
    ]);

    expect($utgång)->toBe(Command::FAILURE);
    expect($utdata)->toContain('högst');

    $ärende->refresh();

    expect($ärende->extended_months)->toBe(2)
        ->and($ärende->deadline_at->format('Y-m-d'))->toBe('2026-06-15');

    expect(PrivacyRequestEvent::query()->count())->toBe(1);
});

/*
 * Klart när: 1 följt av 1 går, och 1 följt av 2 ger exit 1.
 */
it('tillåter 1 följt av 1 men inte 1 följt av 2', function () {
    Carbon::setTestNow('2026-03-20 12:00:00');

    $ärende = fristMarsArende();

    [$utgång] = fristKor('privacy:extend', [
        'reference' => $ärende->reference,
        'months' => '1',
    ], ['--reason' => 'Första förlängningen.', '--by' => 'Anna Handläggare']);

    expect($utgång)->toBe(Command::SUCCESS);

    [$utgång] = fristKor('privacy:extend', [
        'reference' => $ärende->reference,
        'months' => '1',
    ], ['--reason' => 'Andra förlängningen.', '--by' => 'Anna Handläggare']);

    expect($utgång)->toBe(Command::SUCCESS);

    $ärende->refresh();

    expect($ärende->extended_months)->toBe(2)
        ->and($ärende->deadline_at->format('Y-m-d'))->toBe('2026-06-15');

    // 1 + 2 hade blivit tre månader sammanlagt och ska vägras.
    $annat = fristMarsArende();

    fristKor('privacy:extend', [
        'reference' => $annat->reference,
        'months' => '1',
    ], ['--reason' => 'Första förlängningen.', '--by' => 'Anna Handläggare']);

    [$utgång, $utdata] = fristKor('privacy:extend', [
        'reference' => $annat->reference,
        'months' => '2',
    ], ['--reason' => 'Andra förlängningen.', '--by' => 'Anna Handläggare']);

    expect($utgång)->toBe(Command::FAILURE);
    expect($utdata)->toContain('högst');

    $annat->refresh();

    expect($annat->extended_months)->toBe(1)
        ->and($annat->deadline_at->format('Y-m-d'))->toBe('2026-05-15');
});

/*
 * Klart när: en förlängning när klockan passerat den ursprungliga fristen
 * ger exit 1, också när fristen redan förlängts.
 */
it('vägrar en förlängning när den ursprungliga fristen passerat', function (bool $redanFörlängd) {
    Carbon::setTestNow('2026-03-20 12:00:00');

    $ärende = fristMarsArende();

    if ($redanFörlängd) {
        [$utgång] = fristKor('privacy:extend', [
            'reference' => $ärende->reference,
            'months' => '1',
        ], ['--reason' => 'Första förlängningen.', '--by' => 'Anna Handläggare']);

        expect($utgång)->toBe(Command::SUCCESS);

        $ärende->refresh();

        expect($ärende->deadline_at->format('Y-m-d'))->toBe('2026-05-15');
    }

    // Dagen efter den ursprungliga fristen 2026-04-15.
    Carbon::setTestNow('2026-04-16 09:00:00');

    [$utgång, $utdata] = fristKor('privacy:extend', [
        'reference' => $ärende->reference,
        'months' => '1',
    ], ['--reason' => 'För sen förlängning.', '--by' => 'Anna Handläggare']);

    expect($utgång)->toBe(Command::FAILURE);
    expect($utdata)->toContain('ursprungliga');

    $ärende->refresh();

    expect($ärende->extended_months)->toBe($redanFörlängd ? 1 : 0)
        ->and($ärende->deadline_at->format('Y-m-d'))
        ->toBe($redanFörlängd ? '2026-05-15' : '2026-04-15');

    expect(PrivacyRequestEvent::query()->count())->toBe($redanFörlängd ? 1 : 0);
})->with([
    'utan tidigare förlängning' => [false],
    'med tidigare förlängning' => [true],
]);

/*
 * Klart när: privacy:deadline --from= räknar fristen som dagen plus en
 * månad plus extended_months.
 */
it('räknar om fristen från en ny dag med förlängningen inräknad', function () {
    Carbon::setTestNow('2026-05-20 12:00:00');

    $ärende = fristArende([
        'received_at' => Carbon::parse('2026-03-15')->startOfDay(),
        'deadline_at' => Carbon::parse('2026-05-15')->startOfDay(),
        'extended_months' => 1,
    ]);

    [$utgång, $utdata] = fristKor('privacy:deadline', [
        'reference' => $ärende->reference,
    ], [
        '--from' => '2026-05-10',
        '--reason' => 'Kompletterande uppgifter för identiteten kom in.',
        '--by' => 'Anna Handläggare',
    ]);

    expect($utgång)->toBe(Command::SUCCESS);

    $ärende->refresh();

    // 2026-05-10 plus en månad plus den beviljade månaden.
    expect($ärende->deadline_at->format('Y-m-d'))->toBe('2026-07-10')
        ->and($ärende->extended_months)->toBe(1);

    $händelse = PrivacyRequestEvent::query()->sole();

    expect($händelse->kind)->toBe('deadline_changed')
        ->and($händelse->actor)->toBe('Anna Handläggare')
        ->and($händelse->data)->toMatchArray([
            'kind' => 'restarted',
            'from' => '2026-05-15',
            'to' => '2026-07-10',
            'from_date' => '2026-05-10',
            'reason' => 'Kompletterande uppgifter för identiteten kom in.',
        ]);

    expect($utdata)->toContain('2026-05-15');
    expect($utdata)->toContain('2026-07-10');
});

/*
 * Klart när: en dag före mottagandet eller i framtiden ger exit 1.
 */
it('vägrar räkna om fristen från en dag före mottagandet eller i framtiden', function (string $från, string $ord) {
    $ärende = fristArende([
        'received_at' => Carbon::parse('2026-05-15')->startOfDay(),
        'deadline_at' => Carbon::parse('2026-06-15')->startOfDay(),
    ]);

    [$utgång, $utdata] = fristKor('privacy:deadline', [
        'reference' => $ärende->reference,
    ], [
        '--from' => $från,
        '--reason' => 'Prövning av dagen.',
        '--by' => 'Anna Handläggare',
    ]);

    expect($utgång)->toBe(Command::FAILURE);
    expect($utdata)->toContain($ord);

    $ärende->refresh();

    expect($ärende->deadline_at->format('Y-m-d'))->toBe('2026-06-15');

    expect(PrivacyRequestEvent::query()->count())->toBe(0);
})->with([
    'före mottagandet' => ['2026-05-14', 'mottagandet'],
    'i framtiden' => ['2026-06-02', 'framtiden'],
]);

/*
 * Klart när: båda kommandona kräver --reason och --by. Ett saknat värde ger
 * exit 1 och ingen händelse.
 */
it('kräver --reason och --by i båda kommandona', function (string $kommando, array $argument, array $val, string $ord) {
    $ärende = fristMarsArende();

    [$utgång, $utdata] = fristKor(
        $kommando,
        array_merge(['reference' => $ärende->reference], $argument),
        $val,
    );

    expect($utgång)->toBe(Command::FAILURE);
    expect($utdata)->toContain($ord);

    expect(PrivacyRequestEvent::query()->count())->toBe(0);
})->with([
    'extend utan --reason' => [
        'privacy:extend',
        ['months' => '1'],
        ['--by' => 'Anna Handläggare'],
        '--reason',
    ],
    'extend utan --by' => [
        'privacy:extend',
        ['months' => '1'],
        ['--reason' => 'Ett skäl.'],
        '--by',
    ],
    'deadline utan --reason' => [
        'privacy:deadline',
        [],
        ['--from' => '2026-03-20', '--by' => 'Anna Handläggare'],
        '--reason',
    ],
    'deadline utan --by' => [
        'privacy:deadline',
        [],
        ['--from' => '2026-03-20', '--reason' => 'Ett skäl.'],
        '--by',
    ],
]);

/*
 * Klart när: privacy:list visar bara öppna ärenden sorterade på frist, och
 * overdue för ett ärende vars frist passerat.
 */
it('listar bara öppna ärenden sorterade på frist med overdue', function () {
    [$senare, $tidigare, $avslutad] = fristListUnderlag();

    [$utgång, $utdata] = fristKor('privacy:list', []);

    expect($utgång)->toBe(Command::SUCCESS);

    expect($utdata)->toContain($senare->reference);
    expect($utdata)->toContain($tidigare->reference);
    expect($utdata)->not->toContain($avslutad->reference);

    // Den tidigaste fristen först.
    $först = strpos($utdata, $tidigare->reference);
    $sedan = strpos($utdata, $senare->reference);

    expect($först)->not->toBeFalse();
    expect($sedan)->not->toBeFalse();
    expect((int) $först)->toBeLessThan((int) $sedan);

    // Fristen 2026-05-30 är två dagar före den frysta dagen 2026-06-01.
    expect($utdata)->toContain('overdue 2 days');

    // Kolumnerna ur issuen.
    expect($utdata)->toContain('2026-06-10');
    expect($utdata)->toContain('in_progress');
    expect($utdata)->toContain('high');
    expect($utdata)->toContain('Bo Handläggare');
});

/*
 * Klart när: --all tar med de avslutade ärendena.
 */
it('tar med avslutade ärenden med --all', function () {
    [, , $avslutad] = fristListUnderlag();

    [$utgång, $utdata] = fristKor('privacy:list', [], ['--all' => true]);

    expect($utgång)->toBe(Command::SUCCESS);
    expect($utdata)->toContain($avslutad->reference);
});

/*
 * Klart när: listans utskrift innehåller inte ärendets namn, e-postadress
 * eller beskrivning, och listan skriver ingen händelse.
 */
it('visar inte namn, e-postadress eller beskrivning och skriver ingen händelse', function () {
    $ärende = fristArende([
        'requester_name' => 'Unikt Namn Xyzzy',
        'requester_email' => 'unikt-xyzzy@example.com',
        'description' => 'Unik beskrivning xyzzy som inte får synas.',
    ]);

    [$utgång, $utdata] = fristKor('privacy:list', []);

    expect($utgång)->toBe(Command::SUCCESS);
    expect($utdata)->toContain($ärende->reference);

    expect($utdata)->not->toContain('Unikt Namn Xyzzy');
    expect($utdata)->not->toContain('unikt-xyzzy@example.com');
    expect($utdata)->not->toContain('Unik beskrivning xyzzy som inte får synas.');

    // Listan är påminnelsen och läses utan spår; privacy:show är läsningen
    // som loggas (ADR-0095 § 3 och § 6).
    expect(PrivacyRequestEvent::query()->count())->toBe(0);
});

/*
 * `--status=` visar bara den statusen, också en avslutad sådan utan --all.
 * En okänd status är ett fel.
 */
it('filtrerar på --status och nekar en okänd status', function () {
    [, , $avslutad] = fristListUnderlag();

    [$utgång, $utdata] = fristKor('privacy:list', [], ['--status' => 'completed']);

    expect($utgång)->toBe(Command::SUCCESS);
    expect($utdata)->toContain($avslutad->reference);

    [$utgång, $utdata] = fristKor('privacy:list', [], ['--status' => 'finns-inte']);

    expect($utgång)->toBe(Command::FAILURE);
    expect($utdata)->toContain('Okänd status');
});
