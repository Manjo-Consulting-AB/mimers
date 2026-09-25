<?php

use App\Console\ReportsAbuseSignals;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\Export;
use App\Models\Item;
use App\Models\Notification as NotificationModel;
use App\Models\Plan;
use App\Models\SecurityLog;
use App\Models\StoredFile;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Notifications\MagicLinkNotification;
use App\Support\Notification\UrlSafetyValidator;
use App\Support\Security\DeviceName;
use App\Support\Security\IpGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\get;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withServerVariables;

/*
 * Issue 113 · Säkerhetsloggen. Se App\Actions\Security\RecordSecurityEvent,
 * App\Models\SecurityLog, App\Support\Security\IpGroup,
 * App\Support\Security\DeviceName och [[ADR-0043 Tre loggar]]
 * § Säkerhetsloggen.
 *
 * Varje "Klart när"-punkt i issuen motsvarar ett namngivet test här.
 *
 * Loggen är en tabell och inte en yta: testerna läser den med DB::table och
 * inte genom en resurs, för det finns ingen resurs (issue 117 bygger
 * användarens vy). Hjälparna heter sakerhets* för att inte krocka med
 * missbruk* i MissbruksrapportTest, sparr* i RattsligSparrTest eller de
 * globala i tests/Support/Testhjalpare.php — Pests funktioner är globala och
 * hela sviten körs i en process.
 *
 * Storage::fake('files') i beforeEach: två av punkterna rör nedladdningar,
 * och inga bytes får hamna i den riktiga storage/files/ när sviten körs.
 * Queue::fake() sätts per test, inte här: en köad export ska inte packa en
 * påse i onödan, men notiserna går genom Notification::fake och inte genom
 * kön.
 */

beforeEach(function () {
    Storage::fake('files');
});

/**
 * Alla loggrader, äldst först.
 *
 * @return list<object>
 */
function sakerhetsRader(): array
{
    return DB::table('security_log')->orderBy('id')->get()->all();
}

/**
 * Hela tabellen som JSON — den form de två "ingen rad bär …"-punkterna
 * prövar. Läser ALLA kolumner, även de som skrivs av actionen, så ett
 * framtida fält som bär något det inte ska fångas av samma påstående.
 */
function sakerhetsJson(): string
{
    return json_encode(sakerhetsRader(), JSON_THROW_ON_ERROR);
}

/**
 * Antal rader per handling — "exakt en rad" prövas som en hel fördelning,
 * inte som ett antal här och där.
 *
 * @return array<string, int>
 */
function sakerhetsFördelning(): array
{
    $fördelning = DB::table('security_log')->pluck('action')->countBy()->all();

    ksort($fördelning);

    return $fördelning;
}

/**
 * Den enda raden för en handling. Kastar om där är noll eller fler än en.
 */
function sakerhetsRad(string $action): SecurityLog
{
    return SecurityLog::query()->where('action', $action)->sole();
}

/**
 * En rad ur [[Registerförteckning]] för $tabell. Samma form som
 * RattsligSparrTest:s sparrRegisterrad — filen läses som text, och exakt en
 * rad förväntas.
 */
function sakerhetsRegisterrad(string $tabell): string
{
    $rader = array_values(array_filter(
        file(base_path('docs/Registerförteckning.md')) ?: [],
        fn (string $rad) => str_starts_with($rad, '|') && str_contains($rad, "`{$tabell}`"),
    ));

    if (count($rader) !== 1) {
        throw new RuntimeException("Väntade exakt en rad för {$tabell}, hittade ".count($rader).'.');
    }

    return trim($rader[0]);
}

/**
 * Begär en magic link och läser ut den färdiga URL:en ur den fejkade
 * notifikationen — token skickas bara i mejlet, aldrig i HTTP-svaret.
 * Samma hjälpare som MagicLinkTest:s begärLänkOchFångaUrl, med eget namn.
 *
 * `$api` väljer ytan som BEGÄR länken; själva länken pekar alltid på
 * webbens consume-rutt, och API:t löser in samma parametrar (issue 18).
 */
function sakerhetsBegärLänk(string $email, bool $api = false): string
{
    $user = User::query()->where('email', $email)->firstOrFail();

    $svar = postJson($api ? '/api/login/magic-link' : '/login/magic-link', ['email' => $email]);

    $api ? $svar->assertNoContent(202) : $svar->assertRedirect();

    $fångadUrl = null;

    Notification::assertSentTo(
        $user,
        MagicLinkNotification::class,
        function (MagicLinkNotification $notification) use (&$fångadUrl): bool {
            $fångadUrl = $notification->url;

            return true;
        }
    );

    return $fångadUrl;
}

/**
 * Ett Pro-konto med en medlem och ett Sanctum-headerpar — webhooks kräver
 * planen, och samma uppsättning som WebhookRegistreringTest:s
 * webhookProKonto().
 *
 * @return array{0: Account, 1: User, 2: array<string, string>}
 */
function sakerhetsProKonto(): array
{
    [$account, $user, $headers] = kontoMedMedlem();

    Subscription::factory()->for($account)->for(Plan::where('code', 'pro')->firstOrFail())->create();

    return [$account, $user, $headers];
}

/**
 * En bilaga på ett item, belastad på $account — den form storage-ytan tar
 * bort och den form en nedladdning gäller.
 *
 * @return array{0: Item, 1: Attachment}
 */
function sakerhetsBilaga(Container $container, Account $account, User $user): array
{
    $item = Item::factory()->for($container, 'container')->create();

    return [$item, gallringBilaga($item, $account, $user)];
}

/**
 * En bilaga med byten på den fejkade disken, redo att laddas ner.
 *
 * @return array{0: Item, 1: Attachment, 2: StoredFile}
 */
function sakerhetsNedladdning(Container $container): array
{
    $storedFile = StoredFile::factory()->create(['byte_size' => 12]);
    Storage::disk('files')->put($storedFile->storage_path, 'filens byten');

    $item = Item::factory()->for($container, 'container')->create();
    $attachment = Attachment::factory()->for($item, 'item')->create([
        'stored_file_id' => $storedFile->id,
    ]);

    return [$item, $attachment, $storedFile];
}

/**
 * En `ready`-export med sin artefakt på den fejkade disken.
 */
function sakerhetsFärdigExport(Container $container, User $user): Export
{
    $export = Export::factory()->create([
        'container_id' => $container->id,
        'requested_by_user_id' => $user->id,
        'status' => Export::STATUS_READY,
        'expires_at' => now()->addDays(7),
    ]);

    $export->storage_path = 'exports/'.$container->ulid.'/'.$export->ulid.'.zip';
    $export->save();

    Storage::disk('files')->put($export->storage_path, 'zip-byten');

    return $export;
}

it('varje uppräknad handling skriver exakt en rad via webben och via API:t', function () {
    Notification::fake();
    Queue::fake();

    // DNS-uppslaget i SSRF-valideringen slås aldrig mot nätet i sviten
    // (issue 37a § Att se upp med).
    app()->instance(UrlSafetyValidator::class, new UrlSafetyValidator(
        fn (string $host): array => ['93.184.216.34'],
    ));

    // ---- Misslyckad inloggning, webben och API:t ----------------------
    // Först: båda är gästrutter, och en inloggad session hade skickats till
    // /dashboard av `guest`-middleware i stället för att prövas.
    $felWebb = User::factory()->create(['password_hash' => 'ratt-losenord']);
    postJson('/login', ['email' => $felWebb->email, 'password' => 'fel-losenord'])
        ->assertStatus(422);

    $felApi = User::factory()->create(['password_hash' => 'ratt-losenord']);
    postJson('/api/login', ['email' => $felApi->email, 'password' => 'fel-losenord'])
        ->assertStatus(422);

    // ---- Inlöst magic link, webben och API:t --------------------------
    $länkWebb = User::factory()->create(['password_hash' => null]);
    get(sakerhetsBegärLänk($länkWebb->email))->assertRedirect(route('dashboard'));

    // Sessionen är nu inloggad, och consume-rutten ligger i `guest`-gruppen:
    // utan utloggningen hade nästa steg mötts av en omdirigering i stället
    // för av rutten. Utloggningen behövs också av ett skäl som inte syns på
    // rutten: en session som är inloggad vinner över ett bearer-token i
    // `/api`-anropen nedan (EnsureFrontendRequestsAreStateful), så
    // tokenstegen måste mötas av en tom session.
    postJson('/logout')->assertRedirect(route('welcome'));

    // ---- Lyckad inloggning, webben ------------------------------------
    $inloggadWebb = User::factory()->create(['password_hash' => 'ratt-losenord']);
    postJson('/login', ['email' => $inloggadWebb->email, 'password' => 'ratt-losenord'])
        ->assertRedirect(route('dashboard'));
    postJson('/logout')->assertRedirect(route('welcome'));

    // ---- API:t ---------------------------------------------------------
    // Identiteten sätts med actingAs() och inte med ett bearer-token:
    // Sanctums guard minns den första användare den löste i en testprocess,
    // så ett andra token i samma test autentiserar fortfarande den förste.
    // actingAs() slår igenom på varje ny request, och rutten och
    // kontrollern — det som prövas här — är desamma.
    $inloggadApi = User::factory()->create(['password_hash' => 'ratt-losenord']);
    postJson('/api/login', ['email' => $inloggadApi->email, 'password' => 'ratt-losenord'])
        ->assertOk();

    $länkApi = User::factory()->create(['password_hash' => null]);
    $parametrar = [];
    parse_str((string) parse_url(sakerhetsBegärLänk($länkApi->email, api: true), PHP_URL_QUERY), $parametrar);
    postJson('/api/login/magic-link/consume', $parametrar)->assertOk();

    $aktiverarApi = User::factory()->create();
    actingAs($aktiverarApi)->postJson('/api/totp')->assertOk();
    actingAs($aktiverarApi)
        ->postJson('/api/totp/confirm', ['code' => totpKodFör($aktiverarApi->fresh()->totp_secret)])
        ->assertNoContent();

    [$stängerAvApi, $stängerAvApiHemlighet] = användareMedBekräftadTotp();
    actingAs($stängerAvApi)
        ->deleteJson('/api/totp', ['code' => totpKodFör($stängerAvApiHemlighet)])
        ->assertNoContent();

    [$koderApi] = användareMedBekräftadTotp();
    actingAs($koderApi)->postJson('/api/totp/recovery-codes')->assertOk();

    [$bjuderApi, $bjuderApiMedlem] = kontoMedMedlem();
    $bjuderApiContainer = Container::factory()->for($bjuderApi, 'account')->create();
    actingAs($bjuderApiMedlem)
        ->postJson("/api/containers/{$bjuderApiContainer->ulid}/invitations", [
            'email' => 'andra@exempel.se',
            'level' => 'read',
        ])->assertCreated();

    [$exportApiKonto, $exportApiMedlem] = kontoMedMedlem();
    $exportApiContainer = Container::factory()->for($exportApiKonto, 'account')->create();
    actingAs($exportApiMedlem)
        ->postJson("/api/containers/{$exportApiContainer->ulid}/exports")
        ->assertStatus(202);

    [$webhookApiKonto, $webhookApiMedlem] = sakerhetsProKonto();
    actingAs($webhookApiMedlem)
        ->postJson("/api/accounts/{$webhookApiKonto->ulid}/webhooks", [
            'url' => 'https://example.com/notiser',
            'event_types' => [NotificationModel::TYPE_TASK_DUE],
        ])->assertCreated();

    $webhookApiRad = WebhookEndpoint::query()->where('account_id', $webhookApiKonto->id)->sole();
    actingAs($webhookApiMedlem)
        ->deleteJson("/api/accounts/{$webhookApiKonto->ulid}/webhooks/{$webhookApiRad->ulid}")
        ->assertNoContent();

    [$tömmerApiKonto, $tömmerApiMedlem] = kontoMedMedlem();
    $tömmerApiContainer = Container::factory()->for($tömmerApiKonto, 'account')->create();
    [, $tömmerApiBilaga] = sakerhetsBilaga($tömmerApiContainer, $tömmerApiKonto, $tömmerApiMedlem);
    actingAs($tömmerApiMedlem)
        ->deleteJson("/api/accounts/{$tömmerApiKonto->ulid}/storage", [
            'attachments' => [$tömmerApiBilaga->ulid],
        ])->assertOk();

    // ---- Webben, alla steg med en inloggad session --------------------
    $aktiverarWebb = User::factory()->create();
    actingAs($aktiverarWebb)->postJson('/totp')->assertRedirect();
    actingAs($aktiverarWebb)
        ->postJson('/totp/confirm', ['code' => totpKodFör($aktiverarWebb->fresh()->totp_secret)])
        ->assertRedirect();

    [$stängerAvWebb, $stängerAvWebbHemlighet] = användareMedBekräftadTotp();
    actingAs($stängerAvWebb)
        ->deleteJson('/totp', ['code' => totpKodFör($stängerAvWebbHemlighet)])
        ->assertRedirect();

    [$koderWebb] = användareMedBekräftadTotp();
    actingAs($koderWebb)->postJson('/totp/recovery-codes')->assertRedirect();

    [$bjuderWebb, $bjuderWebbMedlem] = kontoMedMedlem();
    $bjuderWebbContainer = Container::factory()->for($bjuderWebb, 'account')->create();
    actingAs($bjuderWebbMedlem)->post("/containers/{$bjuderWebbContainer->ulid}/invitations", [
        'email' => 'forsta@exempel.se',
        'level' => 'read',
    ])->assertRedirect();

    [$exportWebbKonto, $exportWebb] = kontoMedMedlem();
    $exportWebbContainer = Container::factory()->for($exportWebbKonto, 'account')->create();
    actingAs($exportWebb)->post("/containers/{$exportWebbContainer->ulid}/export")->assertRedirect();

    // ---- Lösenordsbytet -------------------------------------------------
    // Bara webben: /api har ingen lösenordsrutt (issuens omfångsruta), och
    // bytet är en kontohändelse som kräver en session.
    $byter = User::factory()->create(['password_hash' => 'gammalt-losenord']);
    actingAs($byter)->put('/settings/security/password', [
        'current_password' => 'gammalt-losenord',
        'password' => 'nytt-losenord-2026',
        'password_confirmation' => 'nytt-losenord-2026',
    ])->assertRedirect();

    // ---- Hämtad export -------------------------------------------------
    // Bara webben har en nedladdningsrutt (routes/web.php § /exports/…).
    [$hämtarExportKonto, $hämtarExport] = kontoMedMedlem();
    $hämtarExportContainer = Container::factory()->for($hämtarExportKonto, 'account')->create();
    actingAs($hämtarExport)->get(
        '/exports/'.sakerhetsFärdigExport($hämtarExportContainer, $hämtarExport)->ulid.'/download'
    )->assertOk();

    [$webhookWebbKonto, $webhookWebb] = sakerhetsProKonto();
    actingAs($webhookWebb)->post('/settings/webhooks', [
        'account' => $webhookWebbKonto->ulid,
        'url' => 'https://example.com/notiser',
        'event_types' => [NotificationModel::TYPE_TASK_DUE],
    ])->assertRedirect();

    $webhookWebbRad = WebhookEndpoint::query()->where('account_id', $webhookWebbKonto->id)->sole();
    actingAs($webhookWebb)->delete(
        "/settings/webhooks/{$webhookWebbRad->ulid}?account={$webhookWebbKonto->ulid}"
    )->assertRedirect();

    [$tömmerWebbKonto, $tömmerWebb] = kontoMedMedlem();
    $tömmerWebbContainer = Container::factory()->for($tömmerWebbKonto, 'account')->create();
    [, $tömmerWebbBilaga] = sakerhetsBilaga($tömmerWebbContainer, $tömmerWebbKonto, $tömmerWebb);
    actingAs($tömmerWebb)->delete("/settings/storage/{$tömmerWebbKonto->ulid}", [
        'attachments' => [$tömmerWebbBilaga->ulid],
    ])->assertRedirect();

    // ---- Nedladdning ur någon annans container ------------------------
    $ägare = Account::factory()->create();
    $gäst = User::factory()->create();
    $främmandeContainer = Container::factory()->for($ägare, 'account')->create();
    [, $främmandeBilaga] = sakerhetsNedladdning($främmandeContainer);
    beviljaAccess($främmandeContainer, $gäst, 'read', 'guest');
    actingAs($gäst)->get("/files/{$främmandeBilaga->ulid}")->assertOk();

    // ---- Och ingenting mer --------------------------------------------
    // Fördelningen är påståendet: varje uppräknad handling finns, och var
    // och en av dem exakt så många gånger som ytorna kräver. En handling
    // som skrev två rader för samma klick syns här, och det gör en
    // handling som glömdes bort också.
    expect(sakerhetsFördelning())->toBe([
        SecurityLog::ACTION_ATTACHMENT_DOWNLOADED => 1,
        SecurityLog::ACTION_LOGIN => 2,
        SecurityLog::ACTION_LOGIN_FAILED => 2,
        SecurityLog::ACTION_MAGIC_LINK => 2,
        // Alfabetiskt, som `ksort()` i sakerhetsFördelning() lägger dem.
        SecurityLog::ACTION_PASSWORD_CHANGED => 1,
        SecurityLog::ACTION_RECOVERY_CODES => 2,
        SecurityLog::ACTION_TOTP_DISABLED => 2,
        SecurityLog::ACTION_TOTP_ENABLED => 2,
        SecurityLog::ACTION_EXPORT_DOWNLOADED => 1,
        SecurityLog::ACTION_EXPORT_REQUESTED => 2,
        SecurityLog::ACTION_INVITATION_CREATED => 2,
        SecurityLog::ACTION_STORAGE_EMPTIED => 2,
        SecurityLog::ACTION_WEBHOOK_CREATED => 2,
        SecurityLog::ACTION_WEBHOOK_DELETED => 2,
    ]);
});

it('en misslyckad inloggning mot en okänd adress skriver en rad utan användare och utan adress', function () {
    $okänd = 'ingen-har-den-har-adressen@exempel.se';

    postJson('/login', ['email' => $okänd, 'password' => 'vad-som-helst'])
        ->assertStatus(422);

    $rad = sakerhetsRad(SecurityLog::ACTION_LOGIN_FAILED);

    expect($rad->user_id)->toBeNull()
        ->and($rad->account_id)->toBeNull()
        ->and($rad->meta)->toBe([])
        ->and(sakerhetsJson())->not->toContain($okänd);
});

it('en nedladdning ur användarens egen container skriver ingen rad, en ur någon annans skriver en', function () {
    // Egen container: användaren är medlem i ägarkontot.
    [$egetKonto, $medlem] = kontoMedMedlem();
    $eget = Container::factory()->for($egetKonto, 'account')->create();
    [, $egenBilaga] = sakerhetsNedladdning($eget);

    actingAs($medlem)->get("/files/{$egenBilaga->ulid}")->assertOk();

    expect(sakerhetsRader())->toBe([]);

    // Någon annans container: en gäst med läsnivå når filen, och det är
    // just det läckaget loggen finns för.
    $ägare = Account::factory()->create();
    $gäst = User::factory()->create();
    $främmande = Container::factory()->for($ägare, 'account')->create();
    [, $främmandeBilaga] = sakerhetsNedladdning($främmande);
    beviljaAccess($främmande, $gäst, 'read', 'guest');

    actingAs($gäst)->get("/files/{$främmandeBilaga->ulid}")->assertOk();

    $rad = sakerhetsRad(SecurityLog::ACTION_ATTACHMENT_DOWNLOADED);

    expect($rad->user_id)->toBe($gäst->id)
        ->and($rad->account_id)->toBe($ägare->id)
        ->and($rad->meta['container'])->toBe($främmande->ulid)
        ->and($rad->meta['attachment'])->toBe($främmandeBilaga->ulid)
        // Filnamnet är användarens text och följer aldrig med (ADR
        // § Händelseloggen).
        ->and(sakerhetsJson())->not->toContain($främmandeBilaga->filename);
});

it('en nedladdning som slutar i 404 skriver ingen rad', function () {
    $ägare = Account::factory()->create();
    $gäst = User::factory()->create();
    $främmande = Container::factory()->for($ägare, 'account')->create();
    [, $främmandeBilaga] = sakerhetsNedladdning($främmande);
    beviljaAccess($främmande, $gäst, 'read', 'guest');

    // Okänt värde och en variant som saknas: båda ger 404 innan några byten
    // rör sig. Grinden släpper igenom, så det är variantvalideringen — inte
    // behörigheten — som stoppar, och ett 404 är ingen nedladdning.
    actingAs($gäst)->get("/files/{$främmandeBilaga->ulid}?variant=okant")->assertNotFound();
    actingAs($gäst)->get("/files/{$främmandeBilaga->ulid}?variant=thumb")->assertNotFound();

    expect(sakerhetsRader())->toBe([]);
});

it('ingen rad bär lösenord, kod, token eller e-postadress', function () {
    Notification::fake();

    $lösenord = 'hemligt-losenord-4821';
    $adress = 'hemlig-avsandare@exempel.se';

    $user = User::factory()->create(['email' => $adress, 'password_hash' => $lösenord]);
    postJson('/login', ['email' => $adress, 'password' => $lösenord])->assertRedirect();
    postJson('/logout')->assertRedirect(route('welcome'));

    // En aktivering OCH en avstängning, så båda tvåfaktorraderna prövas, och
    // en kodgenerering: de tre raderna är de som ligger närmast ett
    // hemlighet i tiden.
    $aktiverar = User::factory()->create();
    actingAs($aktiverar)->postJson('/totp')->assertRedirect();
    $aktiverarKod = totpKodFör($aktiverar->fresh()->totp_secret);
    actingAs($aktiverar)->postJson('/totp/confirm', ['code' => $aktiverarKod])->assertRedirect();

    [$koder, $koderHemlighet] = användareMedBekräftadTotp();
    actingAs($koder)->postJson('/totp/recovery-codes')->assertRedirect();
    $återställningskoder = session('recovery_codes');

    deleteJson('/api/totp', ['code' => totpKodFör($koderHemlighet)], [
        'Authorization' => 'Bearer '.$koder->createToken('api')->plainTextToken,
    ])->assertNoContent();

    // Lösenordsbytet (issue 129): det gamla och det nya lösenordet passerar
    // samma request, och ingendera har någonstans att göra i raden. Koden
    // prövas av `$aktiverarKod` ovan — samma väg genom TwoFactorChallenge.
    $byterGammalt = 'gammalt-hemligt-7712';
    $byterNytt = 'nytt-hemligt-9930';
    $byter = User::factory()->create(['password_hash' => $byterGammalt]);

    actingAs($byter)->put('/settings/security/password', [
        'current_password' => $byterGammalt,
        'password' => $byterNytt,
        'password_confirmation' => $byterNytt,
    ])->assertRedirect();

    $loggen = sakerhetsJson();

    expect($loggen)->not->toContain($lösenord)
        ->and($loggen)->not->toContain($adress)
        ->and($loggen)->not->toContain($aktiverarKod)
        ->and($loggen)->not->toContain($byterGammalt)
        ->and($loggen)->not->toContain($byterNytt)
        ->and($koderHemlighet)->not->toBeEmpty();

    foreach ($återställningskoder as $kod) {
        expect($loggen)->not->toContain($kod);
    }

    // Starkare än en delsträngsprövning: raderna för tvåfaktor och
    // återställningskoder bär ingen data alls. Ett token har ingenstans att
    // göra i en `meta` som är tom.
    expect(sakerhetsRad(SecurityLog::ACTION_TOTP_ENABLED)->meta)->toBe([])
        ->and(sakerhetsRad(SecurityLog::ACTION_TOTP_DISABLED)->meta)->toBe([])
        ->and(sakerhetsRad(SecurityLog::ACTION_RECOVERY_CODES)->meta)->toBe([])
        // Lösenordsraden bär ett enda fält, och det säger bara om ett
        // lösenord fanns FÖRE bytet (issue 129).
        ->and(sakerhetsRad(SecurityLog::ACTION_PASSWORD_CHANGED)->meta)->toBe(['had_password' => true]);
});

it('ingen rad bär en rå IP-adress eller en rå webbläsarsträng', function () {
    $ip = '203.0.113.50';
    $webbläsare = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 '
        .'(KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    withServerVariables(['REMOTE_ADDR' => $ip]);

    $user = User::factory()->create(['password_hash' => 'ratt-losenord']);
    postJson('/login', ['email' => $user->email, 'password' => 'ratt-losenord'], [
        'User-Agent' => $webbläsare,
    ])->assertRedirect();

    $rad = sakerhetsRad(SecurityLog::ACTION_LOGIN);
    $loggen = sakerhetsJson();

    expect($loggen)->not->toContain($ip)
        ->and($loggen)->not->toContain('Mozilla')
        ->and($loggen)->not->toContain('AppleWebKit')
        // Pseudonymen är vad raden bär i stället, och enheten är tolkad.
        ->and($rad->ip_group)->toBe(IpGroup::from($ip))
        ->and($rad->device_name)->toBe('Chrome · macOS');
});

it('samma IP-adress ger samma ip_group i loggen som i missbruksrapporten', function () {
    $ip = '203.0.113.60';

    // Rapporten listar en adress först över tröskeln i config/missbruk.php.
    foreach (range(1, (int) config('missbruk.ip_account_min')) as $i) {
        Account::factory()->create(['registration_ip' => $ip]);
    }

    withServerVariables(['REMOTE_ADDR' => $ip]);

    $user = User::factory()->create(['password_hash' => 'ratt-losenord']);
    postJson('/login', ['email' => $user->email, 'password' => 'ratt-losenord'])->assertRedirect();

    $urLoggen = SecurityLog::query()
        ->where('action', SecurityLog::ACTION_LOGIN)
        ->sole()
        ->ip_group;

    $urRapporten = (new ReportsAbuseSignals)->handle()['accounts_per_registration_ip'];

    // Samma formel, alltså samma grupp — det är hela skälet till att den
    // bröts ut till App\Support\Security\IpGroup.
    expect($urRapporten)->toHaveCount(1)
        ->and($urRapporten[0]['ip_group'])->toBe($urLoggen);
});

it('enhetsnamnet tolkas ur webbläsarsträngen', function () {
    expect(DeviceName::from(
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) '
        .'Chrome/119.0.0.0 Safari/537.36 Edg/119.0.0.0'
    ))->toBe('Edge · Windows')
        ->and(DeviceName::from(
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:121.0) Gecko/20100101 Firefox/121.0'
        ))->toBe('Firefox · macOS')
        ->and(DeviceName::from(
            'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) '
            .'Chrome/119.0.0.0 Mobile Safari/537.36'
        ))->toBe('Chrome · Android')
        ->and(DeviceName::from(
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_1 like Mac OS X) AppleWebKit/605.1.15 '
            .'(KHTML, like Gecko) Version/17.1 Mobile/15E148 Safari/604.1'
        ))->toBe('Safari · iOS')
        // Ingen sträng alls och en okänd klient ger null, inte en gissning —
        // issue 117 renderar det som *okänd enhet*.
        ->and(DeviceName::from(null))->toBeNull()
        ->and(DeviceName::from('   '))->toBeNull()
        ->and(DeviceName::from('NågotViInteKanner'))->toBeNull();
});

it('den rättsliga spärrens kommandon skriver en rad', function () {
    $logg = Log::spy();
    $account = Account::factory()->create();

    artisan('legal-hold:place', [
        'account' => $account->ulid,
        'case' => 'AR-2026-0042',
        'reason' => 'Anmälan enligt DSA artikel 16, under utredning.',
    ])->assertSuccessful();

    $placerad = sakerhetsRad(SecurityLog::ACTION_LEGAL_HOLD_PLACED);

    expect($placerad->account_id)->toBe($account->id)
        // Ingen användare och ingen pseudonym: kommandot körs från serverns
        // kommandorad, utan request.
        ->and($placerad->user_id)->toBeNull()
        ->and($placerad->ip_group)->toBeNull()
        ->and($placerad->device_name)->toBeNull()
        // Ärendenumret är en identifierare och följer med; anledningen är
        // fritext och står i `legal_hold.reason` (ADR § Händelseloggen).
        ->and($placerad->meta)->toBe(['case_number' => 'AR-2026-0042']);

    artisan('legal-hold:lift', ['account' => $account->ulid])->assertSuccessful();

    $hävd = sakerhetsRad(SecurityLog::ACTION_LEGAL_HOLD_LIFTED);

    expect($hävd->account_id)->toBe($account->id)
        ->and($hävd->meta)->toBe(['holds' => 1]);

    // Raden flyttade hit, den dubblerades inte: applikationsloggen får
    // ingenting (issue 113 § Klart när — "byter från applikationsloggen").
    $logg->shouldNotHaveReceived('info');
});

it('Registerförteckningen har en rad för security_log', function () {
    $rad = sakerhetsRegisterrad('security_log');

    expect($rad)->toContain('Säkerhetslogg')
        ->and($rad)->toContain('[[ADR-0043 Tre loggar]]')
        ->and($rad)->toContain('Berättigat intresse')
        ->and($rad)->toContain('12 månader');
});
