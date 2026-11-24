<?php

use App\Actions\Account\DeleteAccount;
use App\Actions\Privacy\OpenPrivacyRequest;
use App\Actions\User\DeleteUser;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\PrivacyRequest;
use App\Models\PrivacyRequestEvent;
use App\Models\SecurityLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/*
 * Issue 481 · M87 Integritetsbegäranden, ärendet och intaget. Se
 * App\Actions\Privacy\OpenPrivacyRequest, App\Console\Commands\Privacy\
 * PrivacyOpen och PrivacyShow, [[ADR-0095 Integritetsbegäranden]] § 1–§ 5 och
 * [[Konton och åtkomst]] § privacy_request.
 *
 * Varje "Klart när"-punkt i issuen motsvarar ett namngivet test här.
 *
 * **Kommandona prövas genom Artisan och utdata som text**, som
 * tests/Feature/Voucher/KommandonTest.php: det är just utdatan som är
 * kontraktet — referensen och fristen skrivs ut för den som ska ge dem
 * vidare. Actionen prövas dessutom direkt, utan konsolen, eftersom `/contact`
 * ska kunna anropa den (ADR-0095 § 2).
 *
 * Hjälparna har prefixet `integritet` — Pests funktioner är globala och hela
 * sviten körs i en process.
 *
 * Tiden är fryst: fristen räknas från mottagningsdagen och får inte ligga i
 * framtiden, så ett fast datum i ett test hade ändrat betydelse den dag
 * sviten körs före det. Samma grepp som tests/Feature/SenasteAktivitetTest.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-06-01 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Kör `privacy:open` med intagets obligatoriska värden och de överstyrningar
 * testet vill ha. Returnerar [utgångskod, utdata].
 *
 * @param  array<string, mixed>  $overstyr
 * @return array{0: int, 1: string}
 */
function integritetOppna(array $overstyr = []): array
{
    $utgång = Artisan::call('privacy:open', array_merge([
        'type' => 'access',
        '--name' => 'Kim Nilsson',
        '--email' => 'kim@example.com',
        '--description' => 'Vill veta vilka uppgifter som finns om mig.',
        '--by' => 'Anna Handläggare',
    ], $overstyr));

    return [$utgång, Artisan::output()];
}

/** Ingenting får ha skrivits i någon av ärendets två tabeller. */
function integritetIngaRader(): void
{
    expect(PrivacyRequest::query()->count())->toBe(0);
    expect(PrivacyRequestEvent::query()->count())->toBe(0);
}

/*
 * Klart när: privacy:open skapar ett ärende med status received, prioritet
 * normal, roll undetermined och en händelse created med actor lika med --by,
 * och skriver ut referensen och fristen.
 */
it('privacy:open skapar ärendet med förvalen och skriver ut referens och frist', function () {
    [$utgång, $utdata] = integritetOppna(['--received' => '2026-05-10']);

    expect($utgång)->toBe(Command::SUCCESS);

    $ärende = PrivacyRequest::query()->sole();

    expect($ärende->type)->toBe('access')
        ->and($ärende->status)->toBe('received')
        ->and($ärende->priority)->toBe('normal')
        ->and($ärende->role)->toBe('undetermined')
        ->and($ärende->requester_name)->toBe('Kim Nilsson')
        ->and($ärende->requester_email)->toBe('kim@example.com')
        ->and($ärende->description)->toBe('Vill veta vilka uppgifter som finns om mig.')
        ->and($ärende->received_at->format('Y-m-d'))->toBe('2026-05-10')
        ->and($ärende->deadline_at->format('Y-m-d'))->toBe('2026-06-10')
        ->and($ärende->extended_months)->toBe(0);

    $händelse = PrivacyRequestEvent::query()->sole();

    expect($händelse->kind)->toBe('created')
        ->and($händelse->actor)->toBe('Anna Handläggare')
        ->and($händelse->privacy_request_id)->toBe($ärende->id);

    expect($utdata)->toContain($ärende->reference);
    expect($utdata)->toContain('2026-06-10');
});

/*
 * Klart när: referensen matchar ^PR-[0-9A-HJKMNP-TV-Z]{8}$ och är unik.
 */
it('ger en unik referens i Crockfords base32', function () {
    foreach (range(1, 5) as $gång) {
        [$utgång] = integritetOppna();

        expect($utgång)->toBe(Command::SUCCESS);
    }

    $referenser = PrivacyRequest::query()->pluck('reference');

    expect($referenser)->toHaveCount(5);

    foreach ($referenser as $referens) {
        expect($referens)->toMatch('/^PR-[0-9A-HJKMNP-TV-Z]{8}$/');
    }

    expect($referenser->unique())->toHaveCount(5);
});

/*
 * Klart när: mottaget 2026-01-31 ger fristen 2026-02-28, och mottaget
 * 2026-03-15 ger 2026-04-15.
 */
it('räknar fristen som en kalendermånad utan överflöd', function () {
    integritetOppna(['--received' => '2026-01-31']);

    expect(PrivacyRequest::query()->sole()->deadline_at->format('Y-m-d'))
        ->toBe('2026-02-28');

    integritetOppna(['--received' => '2026-03-15']);

    $sista = PrivacyRequest::query()->orderByDesc('id')->firstOrFail();

    expect($sista->deadline_at->format('Y-m-d'))->toBe('2026-04-15');
});

/*
 * Klart när: en okänd typ ger exit 1 och ingen rad i någon av tabellerna.
 */
it('nekar en okänd typ utan att skriva något', function () {
    [$utgång, $utdata] = integritetOppna(['type' => 'finns-inte']);

    expect($utgång)->toBe(Command::FAILURE);
    expect($utdata)->toContain('Okänd typ');

    integritetIngaRader();
});

/*
 * Klart när: ett ogiltigt --received ger exit 1 och ingen rad i någon av
 * tabellerna. 2026-02-30 finns inte, men PHP räknar tyst om den till 2 mars.
 */
it('nekar ett ogiltigt mottagningsdatum utan att skriva något', function () {
    [$utgång, $utdata] = integritetOppna(['--received' => '2026-02-30']);

    expect($utgång)->toBe(Command::FAILURE);
    expect($utdata)->toContain('Y-m-d');

    integritetIngaRader();
});

/*
 * Klart när: ett framtida --received ger exit 1 och ingen rad i någon av
 * tabellerna.
 */
it('nekar en mottagningsdag i framtiden utan att skriva något', function () {
    [$utgång, $utdata] = integritetOppna(['--received' => '2026-06-02']);

    expect($utgång)->toBe(Command::FAILURE);
    expect($utdata)->toContain('framtiden');

    integritetIngaRader();
});

/*
 * Klart när: ett saknat --by ger exit 1 och ingen rad i någon av tabellerna.
 */
it('nekar ett saknat --by utan att skriva något', function () {
    $utgång = Artisan::call('privacy:open', [
        'type' => 'access',
        '--name' => 'Kim Nilsson',
        '--email' => 'kim@example.com',
        '--description' => 'Vill veta.',
    ]);

    expect($utgång)->toBe(Command::FAILURE);
    expect(Artisan::output())->toContain('--by');

    integritetIngaRader();
});

/*
 * Klart när: en okänd --account/--user ger exit 1 och ingen rad i någon av
 * tabellerna.
 */
it('nekar en okänd konto- eller personkoppling utan att skriva något', function (string $flagga) {
    [$utgång, $utdata] = integritetOppna([$flagga => (string) Str::ulid()]);

    expect($utgång)->toBe(Command::FAILURE);
    expect($utdata)->toContain('ULID');

    integritetIngaRader();
})->with(['--account', '--user']);

/*
 * Klart när: privacy:show skriver ut ärendets uppgifter och händelserna i
 * tidsordning, och lägger till en händelse viewed med handläggaren.
 */
it('privacy:show visar ärendet, händelserna i tidsordning och skriver viewed', function () {
    integritetOppna(['--received' => '2026-05-10']);

    $ärende = PrivacyRequest::query()->sole();

    // Senare än `created`, så att tidsordningen syns i utskriften.
    Carbon::setTestNow('2026-06-02 09:00:00');

    $utgång = Artisan::call('privacy:show', [
        'reference' => $ärende->reference,
        '--by' => 'Bo Granskare',
    ]);
    $utdata = Artisan::output();

    expect($utgång)->toBe(Command::SUCCESS);

    // Ärendets uppgifter och fristen står i utskriften.
    expect($utdata)->toContain($ärende->reference);
    expect($utdata)->toContain('access');
    expect($utdata)->toContain('2026-05-10');
    expect($utdata)->toContain('2026-06-10');
    expect($utdata)->toContain('Kim Nilsson');

    $händelser = PrivacyRequestEvent::query()->orderBy('id')->get();

    expect($händelser)->toHaveCount(2);
    expect($händelser[0]->kind)->toBe('created');
    expect($händelser[1]->kind)->toBe('viewed');
    expect($händelser[1]->actor)->toBe('Bo Granskare');

    $skapad = strpos($utdata, 'created');
    $visad = strpos($utdata, 'viewed');

    expect($skapad)->not->toBeFalse();
    expect($visad)->not->toBeFalse();
    expect((int) $skapad)->toBeLessThan((int) $visad);
});

/*
 * Klart när: att öppna och visa ett ärende skriver ingen rad i audit_log
 * eller security_log. Ägarkontot läser händelseloggen, och en begäran från en
 * låntagare eller medarbetare får inte synas där (ADR-0095 § 3).
 */
it('skriver inget till audit_log eller security_log när ärendet öppnas och visas', function () {
    integritetOppna();

    $ärende = PrivacyRequest::query()->sole();

    Artisan::call('privacy:show', [
        'reference' => $ärende->reference,
        '--by' => 'Bo Granskare',
    ]);

    expect(AuditLog::query()->count())->toBe(0);
    expect(SecurityLog::query()->count())->toBe(0);
});

/*
 * Klart när: OpenPrivacyRequest prövas direkt, utan konsolen.
 */
it('kan öppna ett ärende genom actionen utan konsolen', function () {
    $ärende = app(OpenPrivacyRequest::class)->handle(
        type: 'portability',
        requesterName: 'Kim Nilsson',
        requesterEmail: 'kim@example.com',
        description: 'Vill ha ut mina uppgifter.',
        actor: 'Anna Handläggare',
        receivedAt: Carbon::parse('2026-05-10'),
    );

    expect($ärende->exists)->toBeTrue()
        ->and($ärende->type)->toBe('portability')
        ->and($ärende->status)->toBe('received')
        ->and($ärende->deadline_at->format('Y-m-d'))->toBe('2026-06-10');

    expect(PrivacyRequestEvent::query()->sole()->kind)->toBe('created');
});

it('förvalar mottagningsdagen till i dag när actionen anropas utan dag', function () {
    $ärende = app(OpenPrivacyRequest::class)->handle(
        type: 'access',
        requesterName: 'Kim Nilsson',
        requesterEmail: 'kim@example.com',
        description: 'Vill veta.',
        actor: 'Anna Handläggare',
    );

    expect($ärende->received_at->format('Y-m-d'))->toBe('2026-06-01')
        ->and($ärende->deadline_at->format('Y-m-d'))->toBe('2026-07-01');
});

it('nekar en okänd typ i actionen och skriver ingenting', function () {
    expect(fn () => app(OpenPrivacyRequest::class)->handle(
        type: 'finns-inte',
        requesterName: 'Kim Nilsson',
        requesterEmail: 'kim@example.com',
        description: 'Vill veta.',
        actor: 'Anna Handläggare',
    ))->toThrow(InvalidArgumentException::class);

    integritetIngaRader();
});

/*
 * Klart när: ett ärende kopplat till ett konto och en person finns kvar med
 * sina account_id och user_id efter att DeleteUser och DeleteAccount körts.
 */
it('överlever DeleteAccount med konto- och personkopplingen kvar', function () {
    $person = User::factory()->create();
    $konto = Account::factory()->create();
    $konto->users()->attach($person, ['role' => 'owner']);

    $ärende = app(OpenPrivacyRequest::class)->handle(
        type: 'erasure',
        requesterName: $person->name,
        requesterEmail: $person->email,
        description: 'Vill bli raderad.',
        actor: 'Anna Handläggare',
        accountUlid: $konto->ulid,
        userUlid: $person->ulid,
    );

    $kontoId = $konto->id;
    $personId = $person->id;

    app(DeleteAccount::class)->handle($konto, DeleteAccount::REASON_OWNER_DELETED);

    expect(Account::query()->find($kontoId))->toBeNull();

    $kvar = PrivacyRequest::query()->findOrFail($ärende->id);

    expect($kvar->account_id)->toBe($kontoId)
        ->and($kvar->user_id)->toBe($personId);
});

it('överlever DeleteUser med konto- och personkopplingen kvar', function () {
    $person = User::factory()->create();
    $konto = Account::factory()->create();
    $konto->users()->attach($person, ['role' => 'owner']);

    $ärende = app(OpenPrivacyRequest::class)->handle(
        type: 'erasure',
        requesterName: $person->name,
        requesterEmail: $person->email,
        description: 'Vill bli raderad.',
        actor: 'Anna Handläggare',
        accountUlid: $konto->ulid,
        userUlid: $person->ulid,
    );

    $kontoId = $konto->id;
    $personId = $person->id;

    app(DeleteUser::class)->handle($person);

    expect(User::query()->find($personId))->toBeNull();
    expect(Account::query()->find($kontoId))->toBeNull();

    $kvar = PrivacyRequest::query()->findOrFail($ärende->id);

    expect($kvar->account_id)->toBe($kontoId)
        ->and($kvar->user_id)->toBe($personId);
});
