<?php

use App\Models\Account;
use App\Models\Notification;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Lang;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\from;
use function Pest\Laravel\get;
use function Pest\Laravel\patch;
use function Pest\Laravel\post;
use function Pest\Laravel\withoutVite;

/*
 * Issue 65b · Kontots webhooks — ytan som registrerar, listar, stänger av och
 * tar bort endpoints, och som visar hemligheten en enda gång. Se
 * App\Http\Controllers\WebhookEndpointController,
 * resources/js/pages/Settings/Webhooks.vue,
 * resources/js/components/WebhookEndpointForm.vue,
 * resources/js/components/WebhookEndpointRow.vue och
 * resources/js/components/WebhookEventTypeField.vue.
 *
 * Registret i sig — `/api`-rutterna, SSRF-valideringens alla orsaker,
 * krypteringen och leveransen — prövas av
 * tests/Feature/Notis/WebhookRegistreringTest.php (37a) och
 * tests/Feature/Notis/WebhookleveransTest.php (37b), som är gröna utan en
 * enda ändrad förväntan. Den här filen prövar SIDAN ovanpå registret.
 *
 * Filen prövar fyra saker som bara webben har: att hemligheten visas en gång
 * och inte går att se igen, att adress och händelsetyper går att ändra EFTERÅT
 * utan att hemligheten roteras (Beslut 3), att plangrinden blir ett FÄLTLAG
 * och aldrig en rå JSON-kropp (Beslut 5), och att kontot kommer ur fältet
 * `account` och prövas mot policyn — aldrig mot användarens första konto
 * (Beslut 1).
 *
 * "Klart när" i issuen motsvaras var sitt test nedan, med undantag för
 * "ingen svensk sträng står kvar i en .vue-fil; varje ny nyckel finns på sv
 * och en" — den vaktas av tests/Feature/Frontend/SprakTest.php, som läser
 * varje fil under resources/js/ och jämför språkfilerna nyckel för nyckel.
 * Att hemligheten renderas som text och aldrig i en `<a href>` prövas dessutom
 * mot komponenten i tests/Feature/Frontend/KalenderfeedvyTest.php, som delar
 * resources/js/components/SecretOnce.vue med den här sidan.
 *
 * Hjälparna har prefixet `webhookvy` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs, och tests/Feature/Notis har redan
 * `webhookProKonto`, `webhookRad` och `webhookKonto`.
 */

/**
 * Ett gratiskonto med en medlem i angiven roll. Free-planen kräver ingen
 * fixture — planraderna kommer ur migrationen (issue 25 § Beslut 2) — och är
 * utgångsläget för plan- och behörighetstesterna.
 *
 * Ett konto med rollen `member` som enda medlem är orealistiskt, men precis
 * vad behörighetstestet behöver: grinden läser bara medlemskapet och rollen.
 *
 * @return array{0: Account, 1: User}
 */
function webhookvyKonto(string $roll = 'owner'): array
{
    $konto = Account::factory()->create();
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => $roll]);

    return [$konto, $anvandare];
}

/**
 * Samma konto, men på Pro — webhooks är Pro-funktionen (issue 27 § Beslut 6).
 *
 * @return array{0: Account, 1: User}
 */
function webhookvyProKonto(string $roll = 'owner'): array
{
    [$konto, $anvandare] = webhookvyKonto($roll);

    $pro = Plan::where('code', 'pro')->firstOrFail();
    Subscription::factory()->for($konto)->for($pro)->create();

    return [$konto, $anvandare];
}

/**
 * En endpoint skapad direkt, förbi kontrollern — för de tillstånd rutten
 * aldrig producerar (en avstängd endpoint, en räknare över taket).
 */
function webhookvyRad(Account $account, array $attribut = []): WebhookEndpoint
{
    return WebhookEndpoint::factory()->for($account, 'account')->create($attribut);
}

/**
 * Sidans props för ett konto, så att ett test kan läsa dem som en array i
 * stället för genom AssertableInertia — samma teknik som sprakEgenskaper() i
 * SprakTest.
 *
 * @return array<string, mixed>
 */
function webhookvyProps(User $anvandare, Account $konto): array
{
    /** @var array{props: array<string, mixed>} $sida */
    $sida = actingAs($anvandare)
        ->get("/settings/webhooks?account={$konto->ulid}")
        ->assertOk()
        ->viewData('page');

    return $sida['props'];
}

/*
 * Beslut 1: fyra rutter, alla bakom `auth`.
 */
it('skickar en utloggad besökare till inloggningen från webhookrutterna', function () {
    withoutVite();

    [$konto] = webhookvyKonto();
    $rad = webhookvyRad($konto);

    get('/settings/webhooks')->assertRedirect('/login');
    post('/settings/webhooks')->assertRedirect('/login');
    patch("/settings/webhooks/{$rad->ulid}")->assertRedirect('/login');
    delete("/settings/webhooks/{$rad->ulid}")->assertRedirect('/login');
});

/*
 * Klart när: `/settings/webhooks` listar kontots endpoints, med kontoväljare
 * för den som är medlem i flera konton.
 *
 * Väljaren listar ALLA konton användaren är med i (Beslut 1) — att utelämna
 * ett konto vore att dölja en knapp — och `?account=` avgör vilket som visas.
 * `eventTypes` kommer ur App\Models\WebhookEndpoint::EVENT_TYPES som prop, så
 * att listan inte kan skrivas av i JavaScript.
 */
it('listar kontots endpoints och väljer konto ur querysträngen', function () {
    withoutVite();

    [$konto, $anvandare] = webhookvyProKonto();
    $annat = Account::factory()->create(['name' => 'Andra varvet']);
    $annat->users()->attach($anvandare, ['role' => 'owner']);

    $rad = webhookvyRad($konto);

    actingAs($anvandare)
        ->get("/settings/webhooks?account={$konto->ulid}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Settings/Webhooks')
            ->has('accounts', 2)
            ->where('account.ulid', $konto->ulid)
            ->has('endpoints', 1)
            ->where('endpoints.0.ulid', $rad->ulid)
            ->where('eventTypes', WebhookEndpoint::EVENT_TYPES)
            ->where('planNotice', null)
            ->where('secret', null)
        );

    actingAs($anvandare)
        ->get("/settings/webhooks?account={$annat->ulid}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('account.ulid', $annat->ulid)
            ->has('endpoints', 0)
        );
});

/*
 * Klart när: en endpoint kan skapas, och hemligheten visas EN gång med en
 * förklaring av vad den används till.
 *
 * Hemligheten i klartext finns i svaret på skapandet och ingen annanstans:
 * kolumnen är krypterad (modellens cast), resursen bär den inte, och nästa
 * sidvisning har `secret = null`. Testet läser tillbaka kolumnen genom
 * modellen och jämför — det är beviset för att det som visades är det som
 * signerar leveranserna.
 */
it('skapar en webhook och visar hemligheten en gång', function () {
    withoutVite();

    [$konto, $anvandare] = webhookvyProKonto();

    actingAs($anvandare)
        ->post('/settings/webhooks', [
            'account' => $konto->ulid,
            'url' => 'https://example.com/notiser',
            'event_types' => [Notification::TYPE_TASK_DUE, Notification::TYPE_QUOTA_WARNING],
        ])
        ->assertRedirect("/settings/webhooks?account={$konto->ulid}");

    $rad = WebhookEndpoint::query()->sole();
    expect($rad->account_id)->toBe($konto->id)
        ->and($rad->url)->toBe('https://example.com/notiser')
        ->and($rad->event_types)->toBe([Notification::TYPE_TASK_DUE, Notification::TYPE_QUOTA_WARNING])
        ->and($rad->is_active)->toBeTrue()
        ->and($rad->consecutive_failures)->toBe(0);

    $props = webhookvyProps($anvandare, $konto);

    expect($props['secret'])->toBeString()->toHaveLength(64)
        ->and($rad->refresh()->secret)->toBe($props['secret']);

    // En gång: nästa visning är en vanlig visning.
    $andra = webhookvyProps($anvandare, $konto);

    expect($andra['secret'])->toBeNull()
        ->and(json_encode($andra, JSON_THROW_ON_ERROR))->not->toContain($props['secret']);
});

/*
 * Klart när: hemligheten finns inte i listan efteråt och ändras inte av
 * PATCH.
 *
 * Raden är exakt App\Http\Resources\WebhookEndpointResource plus
 * `disabledBySystem`, som kontrollern lägger bredvid (Beslut 8) — nyckellistan
 * prövas därför hel, så att ett fält som råkar bära hemligheten inte kan
 * smyga in utan att testet faller.
 */
it('bär aldrig hemligheten i listan och ändrar den inte av PATCH', function () {
    withoutVite();

    [$konto, $anvandare] = webhookvyProKonto();
    $rad = webhookvyRad($konto);
    $hemlighet = $rad->secret;

    $props = webhookvyProps($anvandare, $konto);

    expect(array_keys($props['endpoints'][0]))->toBe([
        'ulid', 'url', 'event_types', 'is_active', 'created_at', 'updated_at', 'disabledBySystem',
    ]);
    expect(json_encode($props, JSON_THROW_ON_ERROR))->not->toContain($hemlighet);

    $sida = "/settings/webhooks?account={$konto->ulid}";

    from($sida)->actingAs($anvandare)
        ->patch("/settings/webhooks/{$rad->ulid}", [
            'account' => $konto->ulid,
            'is_active' => false,
        ])
        ->assertRedirect($sida);

    $rad->refresh();

    expect($rad->is_active)->toBeFalse()
        ->and($rad->secret)->toBe($hemlighet);
});

/*
 * Klart när: adressen och händelsetyperna går att ändra EFTERÅT, utan att
 * hemligheten rörs (Beslut 3).
 *
 * Redigeringen finns för hemlighetens skull: en ny endpoint är den enda vägen
 * till en ny hemlighet, så "ta bort och skapa ny" hade tvingat mottagarsidan
 * (n8n, Zapier, en egen mottagare) att konfigureras om för en ändring som
 * `PATCH` redan stöder. Båda fälten prövas i samma anrop — det är så webbens
 * redigeringsformulär skickar dem — och `secret` läses tillbaka genom modellen
 * för att bevisa att den står still.
 */
it('ändrar adress och händelsetyper utan att röra hemligheten', function () {
    withoutVite();

    [$konto, $anvandare] = webhookvyProKonto();
    $rad = webhookvyRad($konto);
    $hemlighet = $rad->secret;

    $sida = "/settings/webhooks?account={$konto->ulid}";

    from($sida)->actingAs($anvandare)
        ->patch("/settings/webhooks/{$rad->ulid}", [
            'account' => $konto->ulid,
            'url' => 'https://example.org/ny-adress',
            'event_types' => [Notification::TYPE_LOAN_DUE, Notification::TYPE_QUOTA_WARNING],
        ])
        ->assertRedirect($sida);

    $rad->refresh();

    expect($rad->url)->toBe('https://example.org/ny-adress')
        ->and($rad->event_types)->toBe([Notification::TYPE_LOAN_DUE, Notification::TYPE_QUOTA_WARNING])
        ->and($rad->secret)->toBe($hemlighet);

    // Listan visar det nya värdet och bär fortfarande ingen hemlighet.
    $props = webhookvyProps($anvandare, $konto);

    expect($props['endpoints'][0]['url'])->toBe('https://example.org/ny-adress')
        ->and($props['endpoints'][0]['event_types'])->toBe([Notification::TYPE_LOAN_DUE, Notification::TYPE_QUOTA_WARNING])
        ->and(json_encode($props, JSON_THROW_ON_ERROR))->not->toContain($hemlighet);
});

/*
 * Klart när: `event_types` kräver minst en — även vid uppdatering.
 *
 * Regeln är UpdateWebhookEndpointRequest:s `min:1`, samma gräns som
 * StoreWebhookEndpointRequest bär, och vyn har ingen egen: felet ska komma
 * från samma ställe i båda lägena (Beslut 6). Att anropet nekas i sin helhet —
 * och inte bara fältet — bevisas av att adressen står kvar oförändrad.
 */
it('kräver minst en händelsetyp även vid uppdatering', function () {
    withoutVite();

    [$konto, $anvandare] = webhookvyProKonto();
    $rad = webhookvyRad($konto);

    $sida = "/settings/webhooks?account={$konto->ulid}";

    from($sida)->actingAs($anvandare)
        ->patch("/settings/webhooks/{$rad->ulid}", [
            'account' => $konto->ulid,
            'url' => 'https://example.org/ny-adress',
            'event_types' => [],
        ])
        ->assertSessionHasErrors('event_types');

    $rad->refresh();

    expect($rad->url)->toBe('https://example.com/notiser')
        ->and($rad->event_types)->toBe([Notification::TYPE_TASK_DUE]);
});

/*
 * Klart när: en URL som servern avvisar ger ett fältfel på `url` med serverns
 * mening — också i redigeringsläget (Beslut 7).
 *
 * `UpdateWebhookEndpointRequest` prövar inte SSRF; den här kontrollern anropar
 * samma UrlSafetyValidator som vid skapandet, och samma kod översätts till
 * samma mening. `127.0.0.1` är en IP-literal, så testet frågar aldrig nätet.
 */
it('ger ett fältfel på url för en osäker adress även vid uppdatering', function () {
    withoutVite();

    [$konto, $anvandare] = webhookvyProKonto();
    $rad = webhookvyRad($konto);

    $sida = "/settings/webhooks?account={$konto->ulid}";

    from($sida)->actingAs($anvandare)
        ->patch("/settings/webhooks/{$rad->ulid}", [
            'account' => $konto->ulid,
            'url' => 'https://127.0.0.1/notiser',
            'event_types' => [Notification::TYPE_TASK_DUE],
        ])
        ->assertSessionHasErrors([
            'url' => 'Adressen går inte att använda: den pekar på ett internt nät.',
        ]);

    expect($rad->refresh()->url)->toBe('https://example.com/notiser');
});

/*
 * Klart när: ett konto utan `webhooks` i planen ser ytan med en mening om
 * planen, och får ett fältfel — inte en rå JSON-kropp — vid POST.
 *
 * Ytan ritas: listan finns, formuläret har sina händelsetyper, och meningen är
 * serverns. Fältfelet bär SAMMA mening (Beslut 5) — den formuleras en gång i
 * kontrollern och skickas både som prop och som fel, så de två kan inte glida
 * isär. Ett `Entitlements::assertFeature()` som fick svara rakt ut hade gett
 * användaren `{"error":{"code":"plan.feature_unavailable"}}` på skärmen.
 */
it('ritar ytan för ett gratiskonto och svarar med ett fältfel på plan', function () {
    withoutVite();

    [$konto, $anvandare] = webhookvyKonto();
    webhookvyRad($konto);

    $props = webhookvyProps($anvandare, $konto);

    expect($props['planNotice'])->toBe('Webhooks kräver planen Pro.')
        ->and($props['eventTypes'])->toBe(WebhookEndpoint::EVENT_TYPES)
        ->and($props['endpoints'])->toHaveCount(1);

    $sida = "/settings/webhooks?account={$konto->ulid}";

    $svar = from($sida)->actingAs($anvandare)->post('/settings/webhooks', [
        'account' => $konto->ulid,
        'url' => 'https://example.com/notiser',
        'event_types' => [Notification::TYPE_TASK_DUE],
    ]);

    $svar->assertRedirect($sida)
        ->assertSessionHasErrors(['plan' => 'Webhooks kräver planen Pro.']);

    expect(WebhookEndpoint::query()->count())->toBe(1);

    // Samma grind på PATCH (Beslut 5).
    $rad = WebhookEndpoint::query()->sole();

    from($sida)->actingAs($anvandare)
        ->patch("/settings/webhooks/{$rad->ulid}", [
            'account' => $konto->ulid,
            'is_active' => false,
        ])
        ->assertRedirect($sida)
        ->assertSessionHasErrors(['plan' => 'Webhooks kräver planen Pro.']);

    expect($rad->refresh()->is_active)->toBeTrue();
});

/*
 * Klart när: samma konto kan fortfarande lista och radera befintliga
 * endpoints.
 *
 * Läsning och radering är inte plangrindade (Beslut 5, och 37a § Beslut 5 på
 * `/api`): ett konto som nedgraderats ska kunna se och ta bort det det har,
 * annars är nedgraderingen en fälla.
 */
it('låter ett gratiskonto lista och radera det den har', function () {
    withoutVite();

    [$konto, $anvandare] = webhookvyKonto();
    $rad = webhookvyRad($konto);

    actingAs($anvandare)
        ->get("/settings/webhooks?account={$konto->ulid}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('endpoints', 1)
            ->where('endpoints.0.ulid', $rad->ulid)
        );

    $sida = "/settings/webhooks?account={$konto->ulid}";

    from($sida)->actingAs($anvandare)
        ->delete("/settings/webhooks/{$rad->ulid}?account={$konto->ulid}")
        ->assertRedirect($sida);

    expect(WebhookEndpoint::query()->count())->toBe(0);
});

/*
 * Klart när: en användare som inte får hantera kontots webhooks får 403.
 *
 * Grinden är App\Policies\AccountPolicy::manageWebhooks() — `owner` eller
 * `admin` — och den prövas mot det konto anropet gäller, på alla fyra
 * rutterna, läsning inräknad (37a § Beslut 4).
 */
it('ger 403 för en medlem på alla fyra rutterna', function () {
    withoutVite();

    [$konto, $anvandare] = webhookvyProKonto('member');
    $rad = webhookvyRad($konto);

    actingAs($anvandare)->get('/settings/webhooks')->assertForbidden();

    actingAs($anvandare)->post('/settings/webhooks', [
        'account' => $konto->ulid,
        'url' => 'https://example.com/notiser',
        'event_types' => [Notification::TYPE_TASK_DUE],
    ])->assertForbidden();

    actingAs($anvandare)
        ->patch("/settings/webhooks/{$rad->ulid}", ['account' => $konto->ulid, 'is_active' => false])
        ->assertForbidden();

    actingAs($anvandare)
        ->delete("/settings/webhooks/{$rad->ulid}?account={$konto->ulid}")
        ->assertForbidden();

    $rad->refresh();

    expect($rad->is_active)->toBeTrue()
        ->and(WebhookEndpoint::query()->count())->toBe(1);
});

/*
 * Beslut 1: kontot kommer ur fältet `account`, och endpointen prövas mot det.
 *
 * Det här är webbens motsvarighet till `/api`:s `scopeBindings()` — rutten
 * `/settings/webhooks/{webhook}` har ingen `{account}`-parameter, så
 * kontrollern jämför `account_id`. Utan kontrollen kunde en förvaltare av
 * konto A skicka sitt eget ULID och ändra konto B:s endpoint.
 */
it('svarar 404 för en endpoint som hör till ett annat konto', function () {
    withoutVite();

    [$konto, $anvandare] = webhookvyProKonto();
    $annat = Account::factory()->create();
    $rad = webhookvyRad($annat);

    actingAs($anvandare)
        ->patch("/settings/webhooks/{$rad->ulid}", ['account' => $konto->ulid, 'is_active' => false])
        ->assertNotFound();

    actingAs($anvandare)
        ->delete("/settings/webhooks/{$rad->ulid}?account={$konto->ulid}")
        ->assertNotFound();

    $rad->refresh();

    expect($rad->is_active)->toBeTrue()
        ->and(WebhookEndpoint::query()->count())->toBe(1);
});

/*
 * Klart när: `event_types` kräver minst en och visas med läsbara namn.
 *
 * Kravet är serverns — `min:1` i StoreWebhookEndpointRequest — och meningen är
 * den användaren möter; vyn har ingen egen regel (Beslut 6). Namnen och
 * förklaringarna ligger i `lang/` under nycklar som följer typnamnet, och
 * testet prövar att VARJE typ i konstanten har båda på båda språken: en ny typ
 * som glöms i språkfilen ska falla här i stället för att synas som
 * `webhook.event_type.…` i gränssnittet.
 */
it('kräver minst en händelsetyp och har läsbara namn för varje typ', function () {
    withoutVite();

    [$konto, $anvandare] = webhookvyProKonto();

    from('/settings/webhooks')->actingAs($anvandare)->post('/settings/webhooks', [
        'account' => $konto->ulid,
        'url' => 'https://example.com/notiser',
        'event_types' => [],
    ])->assertSessionHasErrors('event_types');

    expect(WebhookEndpoint::query()->count())->toBe(0);

    foreach (['sv', 'en'] as $locale) {
        App::setLocale($locale);

        foreach (WebhookEndpoint::EVENT_TYPES as $typ) {
            expect(Lang::has("ui.webhook.event_type.{$typ}.label"))->toBeTrue("label saknas för {$typ} på {$locale}")
                ->and(Lang::has("ui.webhook.event_type.{$typ}.description"))->toBeTrue("description saknas för {$typ} på {$locale}");
        }
    }
});

/*
 * Klart när: en URL som servern avvisar ger ett fältfel på `url` med serverns
 * mening.
 *
 * Sidan gör ingen egen kontroll av privata intervall, `localhost` eller
 * metadatatjänster (Beslut 7) — den här kontrollern anropar samma
 * UrlSafetyValidator som `/api`, och orsaken är en kod som översätts till ett
 * led och sedan in i meningen. `127.0.0.1` är en IP-literal, så testet frågar
 * aldrig nätet.
 */
it('ger ett fältfel på url med serverns mening för en osäker adress', function () {
    withoutVite();

    [$konto, $anvandare] = webhookvyProKonto();

    from('/settings/webhooks')->actingAs($anvandare)->post('/settings/webhooks', [
        'account' => $konto->ulid,
        'url' => 'https://127.0.0.1/notiser',
        'event_types' => [Notification::TYPE_TASK_DUE],
    ])->assertSessionHasErrors([
        'url' => 'Adressen går inte att använda: den pekar på ett internt nät.',
    ]);

    expect(WebhookEndpoint::query()->count())->toBe(0);
});

/*
 * Klart när: en endpoint som systemet stängt av visas som avstängd av
 * systemet och går att slå på igen.
 *
 * Skillnaden mot en rad användaren själv stängt av är hela poängen (Beslut 8).
 * Servern avgör den med tröskeln ur config/notiser.php, och samma tröskel
 * läses här — en endpoint som är AKTIV kan aldrig ha nått den, så `>=` betyder
 * "systemet gjorde det". Återaktiveringen nollställer räknaren (37a § Beslut
 * 3), annars stängs raden av igen efter ett enda fel.
 */
it('märker en endpoint som systemet stängt av och låter den slås på igen', function () {
    withoutVite();

    [$konto, $anvandare] = webhookvyProKonto();

    $tak = (int) config('notiser.webhook.deactivate_after_failures', 20);

    $system = webhookvyRad($konto, ['is_active' => false, 'consecutive_failures' => $tak]);
    $manuell = webhookvyRad($konto, ['is_active' => false, 'consecutive_failures' => 0]);

    $props = webhookvyProps($anvandare, $konto);
    $rader = collect($props['endpoints'])->keyBy('ulid');

    expect($rader[$system->ulid]['disabledBySystem'])->toBeTrue()
        ->and($rader[$system->ulid]['is_active'])->toBeFalse()
        ->and($rader[$manuell->ulid]['disabledBySystem'])->toBeFalse();

    $sida = "/settings/webhooks?account={$konto->ulid}";

    from($sida)->actingAs($anvandare)
        ->patch("/settings/webhooks/{$system->ulid}", [
            'account' => $konto->ulid,
            'is_active' => true,
        ])
        ->assertRedirect($sida);

    $system->refresh();

    expect($system->is_active)->toBeTrue()
        ->and($system->consecutive_failures)->toBe(0);
});
