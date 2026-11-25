<?php

use App\Actions\Privacy\RecordPrivacyVerification;
use App\Actions\User\DeleteUser;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\LegalHold;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\PrivacyRequest;
use App\Models\PrivacyRequestAttachment;
use App\Models\PrivacyRequestEvent;
use App\Models\SecurityLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/*
 * Issue 488 · M87 Integritetsbegäranden, insamlingen för artikel 15. Se
 * App\Actions\Privacy\CollectPrivacyData, AttachPrivacyAttachment och
 * kommandot PrivacyCollect, [[ADR-0095 Integritetsbegäranden]] § 12,
 * [[ADR-0043 Tre loggar]] § Den rättsliga spärren och [[Registerförteckning]].
 *
 * Varje "Klart när"-punkt i issuen motsvarar ett namngivet test här.
 * Kommandot prövas genom Artisan och utdata som text — det är utdatan, raden
 * i tabellen och filen på disken som är kontraktet — som
 * tests/Feature/Integritet/BilagorTest.php.
 *
 * Disken `privacy` fejkas: ingen sammanställning får hamna i den riktiga
 * storage-katalogen. Hjälparna har prefixet `insamling` — Pests funktioner är
 * globala och hela sviten körs i en process.
 *
 * Tiden är fryst: tidpunkterna i filen jämförs i utdatan.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-06-01 12:00:00');

    Storage::fake('privacy');
});

afterEach(function () {
    Carbon::setTestNow();
});

/** Ett konto att koppla personen och ärendet till. */
function insamlingKonto(array $attribut = []): Account
{
    return Account::factory()->create($attribut);
}

/** En person som medlem i kontot. */
function insamlingPerson(Account $konto, array $attribut = []): User
{
    $person = User::factory()->create($attribut);

    $konto->users()->attach($person, ['role' => 'owner']);

    return $person;
}

/**
 * Ett ärende kopplat till personen. Verifieringen skrivs genom actionen, så
 * vägen dit är densamma som i handläggningen (issue 482).
 */
function insamlingArende(User $person, ?Account $konto = null, bool $verifierad = true): PrivacyRequest
{
    $arende = PrivacyRequest::factory()->create([
        'user_id' => $person->id,
        'account_id' => $konto?->id,
        'status' => PrivacyRequest::STATUS_IN_PROGRESS,
    ]);

    if ($verifierad) {
        app(RecordPrivacyVerification::class)->handle(
            request: $arende,
            subject: 'identity',
            method: 'account_email',
            result: 'verified',
            note: null,
            actor: 'Anna Handläggare',
        );
    }

    return $arende;
}

/**
 * Kör privacy:collect och returnerar [utgångskod, utdata].
 *
 * @return array{0: int, 1: string}
 */
function insamlingKor(PrivacyRequest $arende, string $by = 'Anna Handläggare'): array
{
    $utgång = Artisan::call('privacy:collect', [
        'reference' => $arende->reference,
        '--by' => $by,
    ]);

    return [$utgång, Artisan::output()];
}

/** Den enda bilagan på ärendet. */
function insamlingBilaga(): PrivacyRequestAttachment
{
    return PrivacyRequestAttachment::query()->sole();
}

/** Filen på disken `privacy` som en sträng. */
function insamlingFil(PrivacyRequest $arende): string
{
    $bilaga = insamlingBilaga();

    return Storage::disk('privacy')->get($arende->ulid.'/'.$bilaga->ulid);
}

/**
 * Den tolkade sammanställningen.
 *
 * @return array<string, mixed>
 */
function insamlingData(PrivacyRequest $arende): array
{
    return json_decode(insamlingFil($arende), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Alla nycklar som heter `id` eller slutar på `_id`, djupt i strukturen, med
 * sina värden. Sökningen är strukturell: en kort siffra som råkar stå i en
 * tidpunkt ska inte fällas.
 *
 * @return list<array{0: string, 1: mixed}>
 */
function insamlingIdNycklar(mixed $varde): array
{
    if (! is_array($varde)) {
        return [];
    }

    $fynd = [];

    foreach ($varde as $nyckel => $barn) {
        if (is_string($nyckel) && ($nyckel === 'id' || str_ends_with($nyckel, '_id'))) {
            $fynd[] = [$nyckel, $barn];
        }

        $fynd = array_merge($fynd, insamlingIdNycklar($barn));
    }

    return $fynd;
}

/*
 * Klart när: privacy:collect på ett verifierat ärende ger en bilaga med en
 * nyckel per källa ovan och en lista not_included, och en händelse action
 * med operation: exported.
 */
it('sammanställer en nyckel per källa, not_included och händelsen action', function () {
    $konto = insamlingKonto(['registration_ip' => '203.0.113.7']);
    $person = insamlingPerson($konto, ['name' => 'Sara Registrerad']);
    $arende = insamlingArende($person, $konto);

    [$utgång, $utdata] = insamlingKor($arende);

    expect($utgång)->toBe(Command::SUCCESS);

    $bilaga = insamlingBilaga();

    expect($bilaga->privacy_request_id)->toBe($arende->id)
        ->and($bilaga->original_name)->toBe('insamling-'.$arende->reference.'.json')
        ->and(Storage::disk('privacy')->exists($arende->ulid.'/'.$bilaga->ulid))->toBeTrue()
        ->and($utdata)->toContain('Bilaga: '.$bilaga->ulid);

    $data = insamlingData($arende);

    expect(array_keys($data))->toBe([
        'user',
        'account',
        'sessions',
        'security_log',
        'audit_log',
        'email_change',
        'password_change',
        'user_deletion',
        'dismissed_tip',
        'overview_panel',
        'recent_visit',
        'attachment_open',
        'agent',
        'agent_account_grant',
        'agent_container_grant',
        'notification',
        'notification_preference',
        'help_feedback',
        'not_included',
    ]);

    expect($data['user']['email'])->toBe($person->email)
        ->and($data['user'])->not->toHaveKey('id')
        ->and($data['account'][0]['ulid'])->toBe($konto->ulid)
        ->and($data['account'][0])->not->toHaveKey('id')
        ->and($data['account'][0]['registration_ip'])->toBe('203.0.113.7')
        ->and($data['account'][0]['account_user']['role'])->toBe('owner')
        ->and($data['account'][0]['account_user'])->not->toHaveKey('id')
        ->and($data['not_included'])->toBe([
            'user_content',
            'sentry',
            'mailgun',
            'backups',
            'server_logs',
        ]);

    $handelse = PrivacyRequestEvent::query()
        ->where('kind', 'action')
        ->sole();

    expect($handelse->actor)->toBe('Anna Handläggare')
        ->and($handelse->data)->toMatchArray([
            'system' => 'database',
            'operation' => 'exported',
            'categories' => config('privacy.collection_categories'),
        ]);

    // Bilagan skrevs genom AttachPrivacyAttachment, alltså med samma
    // attachment_added-händelse som privacy:attach.
    expect(PrivacyRequestEvent::query()->where('kind', 'attachment_added')->exists())->toBeTrue();
});

/*
 * Klart när: filen innehåller inte password_hash, totp_secret,
 * remember_token, någon token_hash, ip_group, audit-radernas meta eller
 * notisernas innehåll. Provet söker i den råa filen efter kända värden.
 */
it('lämnar aldrig hemligheterna eller andras innehåll i filen', function () {
    $konto = insamlingKonto();
    $person = insamlingPerson($konto);
    $arende = insamlingArende($person, $konto);

    $lösenordshash = (string) DB::table('user')->where('id', $person->id)->value('password_hash');

    DB::table('user')->where('id', $person->id)->update([
        'totp_secret' => 'TOTP-HEMLIGHET-MARKOR',
    ]);

    $epostToken = hash('sha256', 'E-POST-TOKEN-MARKOR');
    $lösenordToken = hash('sha256', 'LÖSENORD-TOKEN-MARKOR');
    $raderingToken = hash('sha256', 'RADERING-TOKEN-MARKOR');
    $lösenordetsHash = 'LÖSENORDETS-HASH-MARKOR';

    DB::table('email_change')->insert([
        'user_id' => $person->id,
        'new_email' => 'ny-adress@example.test',
        'token_hash' => $epostToken,
        'expires_at' => now()->addHour(),
        'confirmed_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('password_change')->insert([
        'user_id' => $person->id,
        'password_hash' => $lösenordetsHash,
        'token_hash' => $lösenordToken,
        'expires_at' => now()->addHour(),
        'confirmed_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('user_deletion')->insert([
        'user_id' => $person->id,
        'token_hash' => $raderingToken,
        'expires_at' => now()->addHour(),
        'confirmed_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $ipGrupp = 'IPGROUP-MARKOR12';

    SecurityLog::factory()->create([
        'account_id' => $konto->id,
        'user_id' => $person->id,
        'action' => SecurityLog::ACTION_LOGIN,
        'ip_group' => $ipGrupp,
        'meta' => ['enhet' => 'Firefox'],
    ]);

    AuditLog::factory()->create([
        'account_id' => $konto->id,
        'user_id' => $person->id,
        'action' => AuditLog::ACTION_ITEM_CREATED,
        'subject_type' => 'item',
        'subject_id' => 'SUBJEKT-MARKOR-ABCDEFGHIJ',
        'meta' => ['intern' => 'AUDIT-META-MARKOR'],
    ]);

    $notis = Notification::factory()->create([
        'account_id' => $konto->id,
        'user_id' => $person->id,
        'type' => Notification::TYPE_TASK_DUE,
        'payload' => ['hemlighet' => 'NOTIS-INNEHALL-MARKOR'],
    ]);

    NotificationDelivery::factory()->create([
        'notification_id' => $notis->id,
        'channel' => NotificationDelivery::CHANNEL_IN_APP,
        'status' => NotificationDelivery::STATUS_SENT,
        'sent_at' => now(),
        'read_at' => now(),
    ]);

    [$utgång] = insamlingKor($arende);

    expect($utgång)->toBe(Command::SUCCESS);

    $fil = insamlingFil($arende);
    $data = insamlingData($arende);

    // Kända värden får inte förekomma i den RÅA filen.
    foreach ([
        $lösenordshash,
        'TOTP-HEMLIGHET-MARKOR',
        $epostToken,
        $lösenordToken,
        $raderingToken,
        $lösenordetsHash,
        $ipGrupp,
        'AUDIT-META-MARKOR',
        'SUBJEKT-MARKOR-ABCDEFGHIJ',
        'NOTIS-INNEHALL-MARKOR',
    ] as $förbjudet) {
        expect($fil)->not->toContain($förbjudet);
    }

    // `remember_token` finns inte som kolumn, men nyckeln får aldrig dyka upp.
    expect($data['user'])->not->toHaveKey('password_hash');
    expect($data['user'])->not->toHaveKey('totp_secret');
    expect($data['user'])->not->toHaveKey('remember_token');

    $hemligaNycklar = collect(array_keys($data['user']))
        ->filter(fn (string $nyckel): bool => str_ends_with($nyckel, '_hash')
            || str_ends_with($nyckel, '_secret'));

    expect($hemligaNycklar)->toBeEmpty();

    // Säkerhetsloggen har kvar `meta` men aldrig `ip_group`.
    expect($data['security_log'][0])->not->toHaveKey('ip_group');
    expect($data['security_log'][0]['meta'])->toBe(['enhet' => 'Firefox']);

    // Händelseloggen bär bara handling, tid och agent — agenten som `ulid`
    // under `agent`, aldrig som löpnummer.
    expect(array_keys($data['audit_log'][0]))->toBe(['action', 'created_at', 'agent'])
        ->and($data['audit_log'][0]['agent'])->toBeNull();

    // Ändringsraderna bär tidpunkterna och den nya adressen — inget mer.
    expect($data['email_change'][0])->toHaveKey('new_email');
    expect($data['email_change'][0])->not->toHaveKey('token_hash');
    expect($data['password_change'][0])->not->toHaveKey('token_hash');
    expect($data['password_change'][0])->not->toHaveKey('password_hash');
    expect($data['user_deletion'][0])->not->toHaveKey('token_hash');

    // Notisen bär typen, tidpunkterna och leveransens kanal och läst-läge.
    expect($data['notification'][0])->not->toHaveKey('payload');
    expect($data['notification'][0]['deliveries'][0]['channel'])->toBe('in_app');
    expect($data['notification'][0]['deliveries'][0])->toHaveKey('read_at');
});

/*
 * Klart när (fynd 4): filen lämnas ut till den registrerade, så inget
 * löpnummer får följa med (AGENTS.md § Databaskonventioner). Raden
 * identifieras av sin `ulid`, och en meningsfull främmande nyckel av den
 * refererade radens `ulid` under ett namn utan `_id`. Provet söker
 * strukturellt efter nycklarna, inte efter siffran som sträng: en kort siffra
 * kan dyka upp av en slump.
 */
it('bär aldrig ett löpnummer under en id-nyckel', function () {
    $konto = insamlingKonto();
    $person = insamlingPerson($konto);
    $arende = insamlingArende($person, $konto);

    // Rader ur tabellerna utan egen `ulid`, så att `rows()` prövas.
    DB::table('dismissed_tip')->insert([
        'user_id' => $person->id,
        'tip_key' => 'valkommen',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('notification_preference')->insert([
        'user_id' => $person->id,
        'type' => 'task.due',
        'channel' => 'email',
        'enabled' => true,
        'digest' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    [$utgång] = insamlingKor($arende);

    expect($utgång)->toBe(Command::SUCCESS);

    $idNycklar = insamlingIdNycklar(insamlingData($arende));

    // Ingen rad bär sitt eget `id` — raden identifieras av sin `ulid`.
    expect(array_filter($idNycklar, fn (array $fynd): bool => $fynd[0] === 'id'))->toBeEmpty();

    // Och personens löpnummer står aldrig under en nyckel som heter `id`
    // eller slutar på `_id`.
    foreach ($idNycklar as [$nyckel, $varde]) {
        expect($varde)->not->toBe($person->id, "Nyckeln {$nyckel} bär personens id.");
    }
});

/*
 * Klart när: en annan persons rader i samma container eller i säkerhetsloggen
 * finns inte med.
 */
it('tar inte med en annan persons rader i samma container eller säkerhetslogg', function () {
    $konto = insamlingKonto();
    $person = insamlingPerson($konto);
    $container = Container::factory()->create(['account_id' => $konto->id]);
    $arende = insamlingArende($person, $konto);

    $annan = User::factory()->create();
    $konto->users()->attach($annan, ['role' => 'member']);

    AuditLog::factory()->create([
        'account_id' => $konto->id,
        'user_id' => $person->id,
        'container_id' => $container->id,
        'action' => AuditLog::ACTION_ITEM_CREATED,
    ]);

    AuditLog::factory()->create([
        'account_id' => $konto->id,
        'user_id' => $annan->id,
        'container_id' => $container->id,
        'action' => AuditLog::ACTION_ITEM_UPDATED,
        'meta' => ['intern' => 'ANNAN-PERSONS-MARKOR'],
    ]);

    SecurityLog::factory()->create([
        'account_id' => $konto->id,
        'user_id' => $person->id,
        'action' => SecurityLog::ACTION_LOGIN,
    ]);

    SecurityLog::factory()->create([
        'account_id' => $konto->id,
        'user_id' => $annan->id,
        'action' => SecurityLog::ACTION_LOGIN_FAILED,
        'meta' => ['intern' => 'ANNAN-PERSONS-MARKOR'],
    ]);

    [$utgång] = insamlingKor($arende);

    expect($utgång)->toBe(Command::SUCCESS);

    $data = insamlingData($arende);
    $fil = insamlingFil($arende);

    expect(array_column($data['audit_log'], 'action'))->toBe([AuditLog::ACTION_ITEM_CREATED])
        ->and(array_column($data['security_log'], 'action'))->toBe([SecurityLog::ACTION_LOGIN]);

    foreach ([
        AuditLog::ACTION_ITEM_UPDATED,
        SecurityLog::ACTION_LOGIN_FAILED,
        'ANNAN-PERSONS-MARKOR',
    ] as $förbjudet) {
        expect($fil)->not->toContain($förbjudet);
    }
});

/*
 * Klart när: med en gällande rättslig spärr på kontot innehåller filen inte
 * strängen legal_hold, ärendenumret eller anledningen. Kommandot skriver en
 * varning på skärmen.
 */
it('nämner aldrig den rättsliga spärren i filen men varnar på skärmen', function () {
    $konto = insamlingKonto();
    $person = insamlingPerson($konto);
    $arende = insamlingArende($person, $konto);

    $ärendenummer = 'DSA-2026-0042';
    $anledning = 'Utredning av anmälan enligt DSA artikel 16.';

    LegalHold::factory()->create([
        'account_id' => $konto->id,
        'case_number' => $ärendenummer,
        'reason' => $anledning,
    ]);

    SecurityLog::factory()->create([
        'account_id' => $konto->id,
        'user_id' => $person->id,
        'action' => SecurityLog::ACTION_LOGIN,
    ]);

    // Spärrens egen rad i säkerhetsloggen bär ärendenumret i `meta` och
    // får aldrig följa med, även när den har personens `user_id`.
    SecurityLog::factory()->create([
        'account_id' => $konto->id,
        'user_id' => $person->id,
        'action' => SecurityLog::ACTION_LEGAL_HOLD_PLACED,
        'meta' => ['case_number' => $ärendenummer],
    ]);

    [$utgång, $utdata] = insamlingKor($arende);

    expect($utgång)->toBe(Command::SUCCESS)
        ->and($utdata)->toContain('Legal hold active');

    $fil = insamlingFil($arende);
    $data = insamlingData($arende);

    foreach (['legal_hold', $ärendenummer, $anledning] as $förbjudet) {
        expect($fil)->not->toContain($förbjudet);
    }

    expect(array_column($data['security_log'], 'action'))->toBe([SecurityLog::ACTION_LOGIN]);
});

/*
 * Klart när: en gällande rättslig spärr på personens konto varnar även när
 * ärendet saknar `account_id`. `privacy_request.account_id` är valfritt, och
 * sammanställningen hämtar personens konton via `account_user`.
 */
it('varnar när spärren gäller personens konto och ärendet saknar konto', function () {
    $konto = insamlingKonto();
    $person = insamlingPerson($konto);
    $arende = insamlingArende($person);

    $ärendenummer = 'DSA-2026-0043';
    $anledning = 'Utredning utan koppling till ärendets konto.';

    LegalHold::factory()->create([
        'account_id' => $konto->id,
        'case_number' => $ärendenummer,
        'reason' => $anledning,
    ]);

    [$utgång, $utdata] = insamlingKor($arende);

    expect($utgång)->toBe(Command::SUCCESS)
        ->and($utdata)->toContain('Legal hold active');

    $fil = insamlingFil($arende);

    foreach (['legal_hold', $ärendenummer, $anledning] as $förbjudet) {
        expect($fil)->not->toContain($förbjudet);
    }
});

/*
 * Klart när: en gällande rättslig spärr på ett annat av personens konton än
 * ärendets varnar ändå. Ärendets `account_id` pekar på ett konto utan spärr,
 * men personen är medlem i det spärrade kontot.
 */
it('varnar när spärren gäller ett annat av personens konton än ärendets', function () {
    $sparratKonto = insamlingKonto();
    $person = insamlingPerson($sparratKonto);
    $annatKonto = insamlingKonto();

    $annatKonto->users()->attach($person, ['role' => 'member']);

    $arende = insamlingArende($person, $annatKonto);

    $ärendenummer = 'DSA-2026-0044';
    $anledning = 'Utredning som gäller personens andra konto.';

    LegalHold::factory()->create([
        'account_id' => $sparratKonto->id,
        'case_number' => $ärendenummer,
        'reason' => $anledning,
    ]);

    [$utgång, $utdata] = insamlingKor($arende);

    expect($utgång)->toBe(Command::SUCCESS)
        ->and($utdata)->toContain('Legal hold active');

    $fil = insamlingFil($arende);

    foreach (['legal_hold', $ärendenummer, $anledning] as $förbjudet) {
        expect($fil)->not->toContain($förbjudet);
    }
});

/*
 * Klart när: utan koppling till en person, och utan en verifierad identitet,
 * ger kommandot exit 1 och ingen bilaga.
 */
it('vägrar utan koppling till en person', function () {
    // Verifieringen finns, men ärendet är inte kopplat till personen.
    $arende = PrivacyRequest::factory()->create(['user_id' => null]);

    app(RecordPrivacyVerification::class)->handle(
        request: $arende,
        subject: 'identity',
        method: 'account_email',
        result: 'verified',
        note: null,
        actor: 'Anna Handläggare',
    );

    [$utgång, $utdata] = insamlingKor($arende);

    expect($utgång)->toBe(Command::FAILURE)
        ->and($utdata)->toContain('kopplat till en person');

    expect(PrivacyRequestAttachment::query()->count())->toBe(0)
        ->and(Storage::disk('privacy')->allFiles())->toBeEmpty()
        ->and(PrivacyRequestEvent::query()->where('kind', 'action')->exists())->toBeFalse();
});

it('vägrar utan en verifierad identitet', function (string $ämne, string $metod, string $resultat) {
    $konto = insamlingKonto();
    $person = insamlingPerson($konto);
    $arende = PrivacyRequest::factory()->create([
        'user_id' => $person->id,
        'account_id' => $konto->id,
    ]);

    app(RecordPrivacyVerification::class)->handle(
        request: $arende,
        subject: $ämne,
        method: $metod,
        result: $resultat,
        note: null,
        actor: 'Anna Handläggare',
    );

    [$utgång, $utdata] = insamlingKor($arende);

    expect($utgång)->toBe(Command::FAILURE)
        ->and($utdata)->toContain('Identiteten är inte verifierad');

    expect(PrivacyRequestAttachment::query()->count())->toBe(0)
        ->and(Storage::disk('privacy')->allFiles())->toBeEmpty()
        ->and(PrivacyRequestEvent::query()->where('kind', 'action')->exists())->toBeFalse();
})->with([
    'ombud i stället för identitet' => ['authority', 'power_of_attorney', 'verified'],
    'otillräcklig' => ['identity', 'account_email', 'insufficient'],
    'misslyckad' => ['identity', 'account_email', 'failed'],
]);

it('vägrar när verifieringen saknas helt', function () {
    $konto = insamlingKonto();
    $person = insamlingPerson($konto);
    $arende = insamlingArende($person, $konto, verifierad: false);

    [$utgång, $utdata] = insamlingKor($arende);

    expect($utgång)->toBe(Command::FAILURE)
        ->and($utdata)->toContain('Identiteten är inte verifierad');

    expect(PrivacyRequestAttachment::query()->count())->toBe(0)
        ->and(Storage::disk('privacy')->allFiles())->toBeEmpty();
});

/*
 * Klart när: efter DeleteUser ger kommandot en fil med user som null och
 * säkerhetsloggens rader kvar.
 */
it('sammanställer en raderad person med user som null och säkerhetsloggen kvar', function () {
    $konto = insamlingKonto();
    $person = insamlingPerson($konto);
    $arende = insamlingArende($person, $konto);

    SecurityLog::factory()->create([
        'account_id' => $konto->id,
        'user_id' => $person->id,
        'action' => SecurityLog::ACTION_LOGIN,
    ]);

    // En person till, så att den raderades id inte kan återanvändas som
    // högsta id i sqlite-sviten.
    User::factory()->create();

    app(DeleteUser::class)->handle($person);

    expect(User::query()->whereKey($person->id)->exists())->toBeFalse();

    [$utgång] = insamlingKor($arende);

    expect($utgång)->toBe(Command::SUCCESS);

    $data = insamlingData($arende);
    $handlingar = array_column($data['security_log'], 'action');

    expect($data['user'])->toBeNull()
        ->and($handlingar)->toContain(SecurityLog::ACTION_LOGIN)
        ->and($handlingar)->toContain(SecurityLog::ACTION_USER_DELETED);
});

/*
 * Klart när: ingen fil blir kvar utanför disken privacy.
 *
 * Provet jämför katalogträdet före och efter: kommandot får skapa exakt EN
 * fil, och den ska ligga på disken privacy. En temporär fil som blev kvar
 * hade synts som en andra ny fil.
 */
it('lämnar ingen fil utanför disken privacy', function () {
    $konto = insamlingKonto();
    $person = insamlingPerson($konto);
    $arende = insamlingArende($person, $konto);

    $rot = storage_path('framework/testing');

    $före = collect(File::allFiles($rot))
        ->map(fn (SplFileInfo $fil): string => $fil->getPathname())
        ->all();

    [$utgång] = insamlingKor($arende);

    expect($utgång)->toBe(Command::SUCCESS);

    $efter = collect(File::allFiles($rot))
        ->map(fn (SplFileInfo $fil): string => $fil->getPathname())
        ->all();

    $nya = array_values(array_diff($efter, $före));

    expect($nya)->toHaveCount(1)
        ->and($nya[0])->toContain('disks/privacy');

    // Och den ligger på ärendets och bilagans ulid, utan filändelse i
    // sökvägen — som privacy:attach.
    $bilaga = insamlingBilaga();

    expect(Storage::disk('privacy')->allFiles())->toBe([$arende->ulid.'/'.$bilaga->ulid]);
});

it('kräver --by', function () {
    $konto = insamlingKonto();
    $person = insamlingPerson($konto);
    $arende = insamlingArende($person, $konto);

    $utgång = Artisan::call('privacy:collect', ['reference' => $arende->reference]);
    $utdata = Artisan::output();

    expect($utgång)->toBe(Command::FAILURE)
        ->and($utdata)->toContain('--by');

    expect(PrivacyRequestAttachment::query()->count())->toBe(0);
});
