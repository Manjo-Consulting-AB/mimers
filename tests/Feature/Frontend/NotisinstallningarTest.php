<?php

use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Support\Notification\NotificationPreferences;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\from;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patch;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\put;
use function Pest\Laravel\withoutVite;

/*
 * Issue 65a · Notisinställningarna — kanalvalet, veckosammanfattningen och de
 * tysta timmarna. Se resources/js/pages/Settings/Notifications.vue,
 * resources/js/components/NotificationPreferenceRow.vue,
 * resources/js/components/QuietHoursForm.vue och
 * App\Http\Controllers\Settings\NotificationSettingsController.
 *
 * Backendflödena i sig prövas av tests/Feature/Notis/PreferensYtaTest.php —
 * den filen äger `/api`, förvalen och parregeln för de tysta timmarna. Den
 * här filen prövar SIDAN ovanpå dem: att rutterna renderar rätt komponent,
 * att listan kommer ur NotificationPreferences::types() i konstanternas
 * ordning, att bara de typer användaren ändrat får rader, och att ett
 * valideringsfel hamnar på sitt eget fält utan att röra något annat.
 *
 * Det serverhalvan inte kan avgöra är vad vyn renderar: att en rad har TRE
 * lägen och att kombinationen "avstängd men i sammanfattningen" inte går att
 * klicka fram är en egenskap hos radio-gruppen i komponenten, och den prövas
 * mot samma modul klienten importerar (notificationPresentation.js), i node.
 *
 * Filens "Klart när"-punkter motsvarar var sitt test nedan.
 */

/**
 * Användarens preferensrader som en karta typ => rad.
 *
 * @return array<string, NotificationPreference>
 */
function notisRader(User $anvandare): array
{
    return NotificationPreference::query()
        ->where('user_id', $anvandare->id)
        ->get()
        ->keyBy('type')
        ->all();
}

/**
 * Ett klockslag ur kolumnen som `H:i`. MySQLs TIME bär `22:00:00` och sqlite
 * `22:00` — samma skillnad som Api\QuietHoursController::timeToApi()
 * normaliserar bort, och testerna ska inte hänga på vilken databas som kör.
 */
function notisKlockslag(?string $tid): ?string
{
    return $tid === null ? null : substr($tid, 0, 5);
}

/**
 * Kör resources/js/components/notificationPresentation.js i node och läser
 * tillbaka vad modulen svarar, samma teknik som korTranslate i SprakTest.
 *
 * @return array{modes: list<string>, values: array<string, array{enabled: bool, digest: bool}>, roundtrip: array<string, string>}
 */
function notisLagen(): array
{
    $skript = implode("\n", [
        "const { pathToFileURL } = await import('node:url');",
        'const m = await import(pathToFileURL('
            .json_encode(resource_path('js/components/notificationPresentation.js'), JSON_UNESCAPED_SLASHES).').href);',
        'process.stdout.write(JSON.stringify({',
        '  modes: m.MODES,',
        '  values: Object.fromEntries(m.MODES.map((mode) => [mode, m.valuesFor(mode)])),',
        '  roundtrip: Object.fromEntries(m.MODES.map((mode) => [mode, m.modeOf(m.valuesFor(mode))])),',
        '}));',
    ]);

    $rader = [];
    $kod = 0;

    exec('node --input-type=module -e '.escapeshellarg($skript).' 2>&1', $rader, $kod);

    expect($kod)->toBe(0, implode("\n", $rader));

    return json_decode(implode("\n", $rader), true, flags: JSON_THROW_ON_ERROR);
}

it('skickar en utloggad besökare till inloggningen från alla tre rutterna', function () {
    withoutVite();

    get('/settings/notifications')->assertRedirect('/login');
    put('/settings/notifications', ['preferences' => []])->assertRedirect('/login');
    patch('/settings/notifications/quiet-hours', [])->assertRedirect('/login');
});

/*
 * Beslut 1: listan kommer ur NotificationPreferences::types(), i
 * konstanternas ordning — samma lista som GET /api/me/notification-preferences
 * svarar med (31b § Beslut 2). Vyn känner inte till typerna, den ritar dem.
 */
it('renderar Settings/Notifications med varje typ i konstanternas ordning', function () {
    withoutVite();

    actingAs(User::factory()->create())
        ->get('/settings/notifications')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Settings/Notifications')
            ->has('preferences', 7)
            ->where('preferences', fn ($rader) => collect($rader)->pluck('type')->all()
                === app(NotificationPreferences::class)->types())
            ->where('preferences', fn ($rader) => collect($rader)->every(
                fn (array $rad) => $rad['channel'] === NotificationDelivery::CHANNEL_EMAIL,
            ))
            // Första ledet är konstantordningen, inte en sortering: `task.due`
            // deklareras före `task.overdue` i App\Models\Notification.
            ->where('preferences.0.type', Notification::TYPE_TASK_DUE)
        );
});

/*
 * 31a § Beslut 4: en webhook är en integration ett KONTO registrerat med
 * egna `event_types`. Den frågar aldrig den här tabellen och listas därför
 * inte bland preferenserna — vyn får inte erbjuda en kanal som inte finns.
 */
it('listar inte kanalen webhook bland preferenserna', function () {
    withoutVite();

    actingAs(User::factory()->create())
        ->get('/settings/notifications')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('preferences', fn ($rader) => collect($rader)->pluck('channel')->unique()->all()
                === [NotificationDelivery::CHANNEL_EMAIL])
        );
});

/*
 * Beslut 3: märkningen kommer ur `is_default`, som skiljer "värdet kommer ur
 * en rad" från "värdet kommer ur koden". En typ användaren aldrig rört bär
 * förvalet; en rad ersätter förvalet i sin helhet.
 */
it('märker en orörd typ som standard och en rörd typ som sparad', function () {
    withoutVite();

    $anvandare = User::factory()->create();

    NotificationPreference::factory()->create([
        'user_id' => $anvandare->id,
        'type' => Notification::TYPE_QUOTA_WARNING,
        'channel' => NotificationDelivery::CHANNEL_EMAIL,
        'enabled' => false,
        'digest' => false,
    ]);

    actingAs($anvandare)
        ->get('/settings/notifications')
        ->assertInertia(fn (AssertableInertia $page) => $page
            // Den rörda raden: värdet ur raden, ingen standardmärkning.
            ->where('preferences', fn ($rader) => collect($rader)
                ->firstWhere('type', Notification::TYPE_QUOTA_WARNING)['is_default'] === false)
            // Uppgiftspåminnelsen är orörd och bär förvalet enabled + digest.
            ->where('preferences', fn ($rader) => collect($rader)
                ->firstWhere('type', Notification::TYPE_TASK_DUE)['is_default'] === true)
            ->where('preferences', fn ($rader) => collect($rader)
                ->firstWhere('type', Notification::TYPE_TASK_DUE)['digest'] === true)
            ->where('preferences', fn ($rader) => collect($rader)
                ->firstWhere('type', Notification::TYPE_LOAN_DUE)['digest'] === false)
        );
});

/*
 * Beslut 3 och 31b § Beslut 4: PUT är en upsert av en DELMÄNGD. Vyn skickar
 * bara de typer användaren ändrat, och en typ som inte nämns får ingen rad —
 * en saknad rad fortsätter betyda förvalet i kod (31a § Beslut 2).
 */
it('sparar ett ändrat läge och ger inga rader för de typer som inte ändrats', function () {
    withoutVite();

    $anvandare = User::factory()->create();
    actingAs($anvandare);

    from('/settings/notifications')
        ->put('/settings/notifications', [
            'preferences' => [[
                'type' => Notification::TYPE_TASK_DUE,
                'channel' => NotificationDelivery::CHANNEL_EMAIL,
                'enabled' => true,
                'digest' => false,
            ]],
        ])
        ->assertRedirect('/settings/notifications')
        ->assertSessionHas('status', 'notification-preferences-updated');

    $rader = notisRader($anvandare);

    expect(array_keys($rader))->toBe([Notification::TYPE_TASK_DUE]);
    expect($rader[Notification::TYPE_TASK_DUE]->enabled)->toBeTrue();
    expect($rader[Notification::TYPE_TASK_DUE]->digest)->toBeFalse();

    // Och sidan visar det nya värdet: raden är inte längre ett förval.
    actingAs($anvandare->fresh())
        ->get('/settings/notifications')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('preferences', fn ($rader) => collect($rader)
                ->firstWhere('type', Notification::TYPE_TASK_DUE)['is_default'] === false)
            ->where('preferences', fn ($rader) => collect($rader)
                ->firstWhere('type', Notification::TYPE_TASK_DUE)['digest'] === false)
            ->where('preferences', fn ($rader) => collect($rader)
                ->firstWhere('type', Notification::TYPE_LOAN_DUE)['is_default'] === true)
        );
});

/*
 * Beslut 3, ordagrant: "en typ som sätts till samma värde som förvalet får
 * ändå en rad: användaren har uttryckt en åsikt, och ett framtida ändrat
 * förval ska inte köra över den."
 *
 * Kroppen här är EXAKT uppgiftspåminnelsens förval (enabled + digest ur
 * koden, inga rader i tabellen): vyn jämför rört mot orört och aldrig värdet
 * mot förvalet, och servern får inte dedupa bort en rad som ser ut som
 * förvalet. Utan raden finns ingen åsikt att skydda den dag förvalet ändras,
 * och märkningen "standard" fortsätter påstå att användaren inget valt.
 */
it('skapar en rad även när valet är samma som förvalet', function () {
    withoutVite();

    $anvandare = User::factory()->create();
    $forval = app(NotificationPreferences::class);

    $enabled = $forval->isEnabled($anvandare, Notification::TYPE_TASK_DUE, NotificationDelivery::CHANNEL_EMAIL);
    $digest = $forval->digest($anvandare, Notification::TYPE_TASK_DUE, NotificationDelivery::CHANNEL_EMAIL);

    expect($enabled)->toBeTrue();
    expect($digest)->toBeTrue();

    actingAs($anvandare);

    from('/settings/notifications')
        ->put('/settings/notifications', [
            'preferences' => [[
                'type' => Notification::TYPE_TASK_DUE,
                'channel' => NotificationDelivery::CHANNEL_EMAIL,
                'enabled' => $enabled,
                'digest' => $digest,
            ]],
        ])
        ->assertRedirect('/settings/notifications')
        ->assertSessionHas('status', 'notification-preferences-updated');

    $rader = notisRader($anvandare);

    expect(array_keys($rader))->toBe([Notification::TYPE_TASK_DUE]);
    expect($rader[Notification::TYPE_TASK_DUE]->enabled)->toBeTrue();
    expect($rader[Notification::TYPE_TASK_DUE]->digest)->toBeTrue();

    // Värdet är nu användarens och inte kodens: standardmärkningen ska bort.
    actingAs($anvandare->fresh())
        ->get('/settings/notifications')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('preferences', fn ($rader) => collect($rader)
                ->firstWhere('type', Notification::TYPE_TASK_DUE)['is_default'] === false)
        );
});

/*
 * 31b § Beslut 4, ordagrant: samma kropp två gånger ger samma rader och samma
 * svar. Utan upserten hade den andra sparningen skapat en dubblett eller
 * fallit på UNIQUE (user_id, type, channel).
 */
it('ger samma rader och samma svar när samma sparning görs två gånger', function () {
    withoutVite();

    $anvandare = User::factory()->create();
    actingAs($anvandare);

    $kropp = ['preferences' => [[
        'type' => Notification::TYPE_LOAN_DUE,
        'channel' => NotificationDelivery::CHANNEL_EMAIL,
        'enabled' => true,
        'digest' => true,
    ]]];

    $forsta = from('/settings/notifications')->put('/settings/notifications', $kropp);
    $raderEfterForsta = notisRader($anvandare);

    $andra = from('/settings/notifications')->put('/settings/notifications', $kropp);

    $forsta->assertRedirect('/settings/notifications')->assertSessionHas('status', 'notification-preferences-updated');
    $andra->assertRedirect('/settings/notifications')->assertSessionHas('status', 'notification-preferences-updated');

    expect(notisRader($anvandare))->toEqual($raderEfterForsta);
    expect(NotificationPreference::query()->where('user_id', $anvandare->id)->count())->toBe(1);
});

/*
 * Beslut 2, och det enda stället servern inte kan avgöra: att varje rad har
 * TRE lägen och att kombinationen "avstängd men i sammanfattningen" inte går
 * att klicka fram. Modulen är klientens enda översättning mellan läget och
 * kolumnparet, och den prövas därför i node — mot samma fil vyn importerar.
 *
 * Att `modeOf()` läser en rad med `enabled = false, digest = true` som
 * "aldrig" är avsiktligt: `/api` hindrar kombinationen från att skrivas, men
 * en handredigerad rad kan bära den, och en avstängd typ skickar inget mejl
 * oavsett vad `digest` står på.
 */
it('har tre lägen, och avstängd-i-sammanfattningen går inte att välja', function () {
    $lagen = notisLagen();

    expect($lagen['modes'])->toBe(['direct', 'digest', 'never']);

    expect($lagen['values']['direct'])->toBe(['enabled' => true, 'digest' => false]);
    expect($lagen['values']['digest'])->toBe(['enabled' => true, 'digest' => true]);
    expect($lagen['values']['never'])->toBe(['enabled' => false, 'digest' => false]);

    foreach ($lagen['values'] as $varden) {
        expect($varden['enabled'])->toBeBool();
        expect($varden['digest'])->toBeBool();
        expect($varden['enabled'] === false && $varden['digest'] === true)->toBeFalse();
    }

    expect($lagen['roundtrip'])->toBe(['direct' => 'direct', 'digest' => 'digest', 'never' => 'never']);
});

/*
 * Beslut 4: de tysta timmarna. `<input type="time">` ger `H:i`, och det är
 * exakt vad UpdateQuietHoursRequest vill ha — kontrollern normaliserar
 * TIME-kolumnens `22:00:00` tillbaka till `22:00`.
 */
it('sparar, ändrar och tömmer ett tyst fönster', function () {
    withoutVite();

    $anvandare = User::factory()->create();
    actingAs($anvandare);

    from('/settings/notifications')
        ->patch('/settings/notifications/quiet-hours', [
            'quiet_hours_start' => '22:00',
            'quiet_hours_end' => '07:00',
        ])
        ->assertRedirect('/settings/notifications')
        ->assertSessionHas('status', 'quiet-hours-updated');

    actingAs($anvandare->fresh())
        ->get('/settings/notifications')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('quietHoursStart', '22:00')
            ->where('quietHoursEnd', '07:00')
        );

    from('/settings/notifications')
        ->patch('/settings/notifications/quiet-hours', [
            'quiet_hours_start' => '23:30',
            'quiet_hours_end' => '06:15',
        ])
        ->assertRedirect('/settings/notifications');

    expect(notisKlockslag($anvandare->fresh()->quiet_hours_start))->toBe('23:30');

    // Tomma fält är svaret "inga tysta timmar", inte ett saknat värde.
    // Kroppen bär TOM STRÄNG och inte null: det är vad ett <input type="time">
    // skickar när användaren tömmer det, och ConvertEmptyStringsToNull gör
    // den till null innan reglerna prövas. Skulle det ledet försvinna vore
    // "töm fältet" en valideringsmiss i stället för ett svar.
    from('/settings/notifications')
        ->patch('/settings/notifications/quiet-hours', [
            'quiet_hours_start' => '',
            'quiet_hours_end' => '',
        ])
        ->assertRedirect('/settings/notifications')
        ->assertSessionHasNoErrors();

    $anvandare->refresh();

    expect($anvandare->quiet_hours_start)->toBeNull();
    expect($anvandare->quiet_hours_end)->toBeNull();

    actingAs($anvandare)
        ->get('/settings/notifications')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('quietHoursStart', null)
            ->where('quietHoursEnd', null)
        );
});

/*
 * Beslut 4: fönstret får passera midnatt — 22:00–07:00 är det NORMALA fallet,
 * inte ett fel. Värdena sparas och visas som de står, i den ordning de
 * skrevs: start efter slut är avsikten.
 */
it('sparar ett fönster som passerar midnatt och visar det som avsett', function () {
    withoutVite();

    $anvandare = User::factory()->create();
    actingAs($anvandare);

    from('/settings/notifications')
        ->patch('/settings/notifications/quiet-hours', [
            'quiet_hours_start' => '22:00',
            'quiet_hours_end' => '07:00',
        ])
        ->assertRedirect('/settings/notifications');

    actingAs($anvandare->fresh())
        ->get('/settings/notifications')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('quietHoursStart', '22:00')
            ->where('quietHoursEnd', '07:00')
        );

    // Tolkningen bor i App\Support\Notification\QuietHours, och där är
    // 22:00–07:00 ett fönster över midnatt och inget "start === slut".
    expect($anvandare->fresh()->quiet_hours_start)->not->toBe($anvandare->fresh()->quiet_hours_end);
});

/*
 * Beslut 4: sidan skriver ut vilken tidszon talen gäller i. `$user->timezone`
 * är nullbar sedan issue 3 och betyder "följ kontot" — vyn hittar aldrig på
 * ett värde i det fallet utan säger att zonen följer kontot och länkar till
 * profilen, som äger kolumnen sedan 53c.
 */
it('skickar användarens tidszon, och null när den följer kontot', function () {
    withoutVite();

    actingAs(User::factory()->create(['timezone' => 'Europe/Oslo']))
        ->get('/settings/notifications')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('timezone', 'Europe/Oslo'));

    actingAs(User::factory()->create(['timezone' => null]))
        ->get('/settings/notifications')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('timezone', null));
});

/*
 * Beslut 4: tidszonen ändras INTE härifrån, fastän UpdateQuietHoursRequest
 * tillåter fältet — profilen äger det sedan 53c, och två ställen att ändra
 * samma sak är ett ställe för mycket. `Arr::only()` i kontrollern är vad som
 * gör regeln till kod i stället för till en kommentar: en kropp med
 * `timezone` passerar valideringen och lämnar kolumnen orörd.
 */
it('lämnar tidszonen orörd när de tysta timmarna sparas', function () {
    withoutVite();

    $anvandare = User::factory()->create(['timezone' => 'Europe/Stockholm']);
    actingAs($anvandare);

    from('/settings/notifications')
        ->patch('/settings/notifications/quiet-hours', [
            'quiet_hours_start' => '22:00',
            'quiet_hours_end' => '07:00',
            'timezone' => 'Europe/Oslo',
        ])
        ->assertRedirect('/settings/notifications');

    $anvandare->refresh();

    expect($anvandare->timezone)->toBe('Europe/Stockholm');
    expect(notisKlockslag($anvandare->quiet_hours_start))->toBe('22:00');
});

/*
 * Beslut 6: ett valideringsfel hamnar på sitt eget fält, och en felaktig tid
 * nollställer inte preferenserna på samma sida. De två formulären har var sin
 * felpåse — det är hela skälet att sidan har två rutter (Beslut 1).
 */
it('ger ett felaktigt klockslag ett fältfel och nollställer ingenting', function () {
    withoutVite();

    $anvandare = User::factory()->create();
    actingAs($anvandare);

    from('/settings/notifications')
        ->patch('/settings/notifications/quiet-hours', [
            'quiet_hours_start' => '22:00',
            'quiet_hours_end' => '07:00',
        ])
        ->assertRedirect('/settings/notifications');

    from('/settings/notifications')
        ->put('/settings/notifications', [
            'preferences' => [[
                'type' => Notification::TYPE_TASK_DUE,
                'channel' => NotificationDelivery::CHANNEL_EMAIL,
                'enabled' => false,
                'digest' => false,
            ]],
        ])
        ->assertRedirect('/settings/notifications');

    $svar = from('/settings/notifications')
        ->patch('/settings/notifications/quiet-hours', [
            'quiet_hours_start' => '25:00',
            'quiet_hours_end' => '07:00',
        ]);

    $svar->assertRedirect('/settings/notifications');
    $svar->assertSessionHasErrors('quiet_hours_start');

    // Varken fönstret eller preferenserna rördes av det avvisade anropet.
    $anvandare->refresh();

    expect(notisKlockslag($anvandare->quiet_hours_start))->toBe('22:00');
    expect(notisKlockslag($anvandare->quiet_hours_end))->toBe('07:00');

    $rader = notisRader($anvandare);

    expect($rader[Notification::TYPE_TASK_DUE]->enabled)->toBeFalse();
    expect(array_keys($rader))->toBe([Notification::TYPE_TASK_DUE]);
});

/*
 * Bara ett halvt fönster är inget fönster (31b § Beslut 5), och felet ska
 * hamna på det fält som SAKNAS — där användaren kan göra något åt saken.
 */
it('kräver båda sidorna av fönstret och pekar på den som saknas', function () {
    withoutVite();

    $anvandare = User::factory()->create();
    actingAs($anvandare);

    $svar = from('/settings/notifications')
        ->patch('/settings/notifications/quiet-hours', ['quiet_hours_start' => '22:00']);

    $svar->assertRedirect('/settings/notifications');
    $svar->assertSessionHasErrors('quiet_hours_end');

    expect($anvandare->fresh()->quiet_hours_start)->toBeNull();
});

/*
 * "Klart när": /api/me/notification-preferences och /api/me/quiet-hours
 * svarar som förut. Sidan delar båda FormRequests med `/api` och bygger sin
 * egen lista — den här raden är det som fångar att den ena ytan ändrades när
 * den andra byggdes. Bearer-token, som i tests/Feature/Notis/PreferensYtaTest.
 */
it('lämnar /api-rutterna oförändrade', function () {
    [, $anvandare, $headers] = kontoMedMedlem();

    $svar = getJson('/api/me/notification-preferences', $headers)->assertOk();

    expect($svar->json('data'))->toHaveCount(7);
    expect(collect($svar->json('data'))->pluck('type')->all())
        ->toBe(app(NotificationPreferences::class)->types());
    expect(collect($svar->json('data'))->every(fn (array $rad) => $rad['is_default'] === true))->toBeTrue();

    $tysta = patchJson('/api/me/quiet-hours', [
        'quiet_hours_start' => '22:00',
        'quiet_hours_end' => '07:00',
    ], $headers)->assertOk();

    // Normaliseringen `22:00:00` → `22:00` ligger kvar i API:et.
    $tysta->assertJson([
        'quiet_hours_start' => '22:00',
        'quiet_hours_end' => '07:00',
        'timezone' => $anvandare->timezone,
    ]);
});

/*
 * Navigationen växer i listan, inte i layouten (den strukturella kontrollen
 * ligger i SakerhetsvyTest). Här prövas att den nya posten finns och att dess
 * etikett är formulerad på båda språken — en sida ingen kan navigera till är
 * en sida ingen hittar (Beslut 1).
 */
it('har Notiser i inställningsnavigationen', function () {
    $sektioner = File::get(resource_path('js/layouts/settingsSections.js'));

    expect($sektioner)->toContain("href: '/settings/notifications'");

    foreach (['sv', 'en'] as $locale) {
        $mening = trans('ui.settings.nav.notifications', [], $locale);

        expect($mening)->not->toBe('ui.settings.nav.notifications', "settings.nav.notifications saknas på {$locale}");
        expect(trim($mening))->not->toBe('');
    }
});

/*
 * Beslut 7: ingen sträng i JavaScript, och typernas namn är inga undantag.
 * Varje typ behöver ett läsbart namn och en förklaring — en rad som heter
 * `schedule_occurrence_due` är en rad ingen ställer in. Saknas en nyckel syns
 * nyckeln själv, aldrig en tom rad (issue 52 § Beslut 4).
 */
it('har ett läsbart namn och en förklaring för varje typ, på båda språken', function () {
    foreach (app(NotificationPreferences::class)->types() as $typ) {
        foreach (['label', 'description'] as $nyckel) {
            foreach (['sv', 'en'] as $locale) {
                $mening = trans("ui.notifications.type.{$typ}.{$nyckel}", [], $locale);

                expect($mening)->not->toBe(
                    "ui.notifications.type.{$typ}.{$nyckel}",
                    "notifications.type.{$typ}.{$nyckel} saknas på {$locale}",
                );
                expect(trim($mening))->not->toBe('');
            }
        }
    }

    // Den engelska texten är en översättning, inte en kopia.
    expect(trans('ui.notifications.type.'.Notification::TYPE_TASK_DUE.'.label', [], 'sv'))
        ->not->toBe(trans('ui.notifications.type.'.Notification::TYPE_TASK_DUE.'.label', [], 'en'));
});

it('har sidans texter på båda språken och läser dem ur lang/', function () {
    $nycklar = [
        'notifications.heading',
        'notifications.intro',
        'notifications.types_heading',
        'notifications.digest_default',
        'notifications.default_badge',
        'notifications.mode.direct.label',
        'notifications.mode.digest.label',
        'notifications.mode.never.label',
        'notifications.submit',
        'notifications.quiet_hours.heading',
        'notifications.quiet_hours.start',
        'notifications.quiet_hours.end',
        'notifications.quiet_hours.empty_note',
        'notifications.quiet_hours.midnight_note',
        'notifications.quiet_hours.delays_note',
        'notifications.quiet_hours.timezone',
        'notifications.quiet_hours.timezone_follows_account',
        'notifications.quiet_hours.timezone_link',
        'notifications.quiet_hours.submit',
        'flash.notification-preferences-updated',
        'flash.quiet-hours-updated',
    ];

    foreach (['sv', 'en'] as $locale) {
        foreach ($nycklar as $nyckel) {
            $mening = trans("ui.{$nyckel}", [], $locale);

            expect($mening)->not->toBe("ui.{$nyckel}", "{$nyckel} saknas på {$locale}");
            expect(trim($mening))->not->toBe('');
        }
    }

    // Vyns enda väg till text går genom t(); en nyckel som finns men inte
    // läses är en text ingen ser. Sidan och dess två komponenter prövas var
    // för sig — de tre filerna bär var sin del av texten.
    $sida = File::get(resource_path('js/pages/Settings/Notifications.vue'));

    foreach (['notifications.digest_default', 'notifications.types_heading', 'notifications.submit'] as $nyckel) {
        expect($sida)->toContain($nyckel);
    }

    expect(File::get(resource_path('js/components/NotificationPreferenceRow.vue')))
        ->toContain('notifications.type.${preference.type}.label')
        ->toContain('notifications.mode.${option}.label')
        ->toContain('notifications.default_badge');

    expect(File::get(resource_path('js/components/QuietHoursForm.vue')))
        ->toContain('notifications.quiet_hours.delays_note')
        ->toContain('notifications.quiet_hours.timezone_link')
        ->toContain('notifications.quiet_hours.empty_note');
});

/*
 * Beslut 5: tysta timmar fördröjer, de tar inte bort. Sidan MÅSTE säga det —
 * annars stänger användaren av notiser i tron att hon stänger av ljudet.
 * Meningen är en egen nyckel, och den ska skilja sig från tomt-fönster-texten
 * intill: de två svarar på olika frågor.
 */
it('säger att tysta timmar fördröjer notisen i stället för att ta bort den', function () {
    foreach (['sv', 'en'] as $locale) {
        $forsening = trans('ui.notifications.quiet_hours.delays_note', [], $locale);
        $tomt = trans('ui.notifications.quiet_hours.empty_note', [], $locale);

        expect($forsening)->not->toBe($tomt);
        expect(trim($forsening))->not->toBe('');
    }

    expect(File::get(resource_path('js/components/QuietHoursForm.vue')))
        ->toContain('notifications.quiet_hours.delays_note');
});
