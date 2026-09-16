<?php

use App\Jobs\BuildContainerExport;
use App\Models\Account;
use App\Models\Container;
use App\Models\Export;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\withoutVite;

/*
 * Issue 67c · Webbens exportyta — knappen, väntan, nedladdningen och de sju
 * dagarna. Se App\Http\Controllers\ExportController,
 * App\Http\Resources\ExportResource, resources/js/pages/Containers/Export.vue,
 * resources/js/components/ExportRow.vue,
 * resources/js/components/exportPresentation.js och
 * resources/js/layouts/containerSections.js.
 *
 * **Två acceptanskriterier prövas inte här**, därför att de redan har en
 * ägare: "`/api`:s tre exportrutter svarar som förut" (tests/Feature/Export/
 * ExportTest.php — den här issuen rör varken Api\ExportController eller
 * BuildContainerExport, så den filen är grön utan en enda ändrad förväntan)
 * och nedladdningens egen grind (tests/Feature/Export/ExportNedladdningTest.php,
 * som redan prövar att en `read`-deltagare kan ladda ner och att en främling
 * får 403). "Ingen svensk sträng står kvar i en .vue-fil; varje ny nyckel finns
 * på sv och en" ägs av tests/Feature/Frontend/SprakTest.php § "har inga
 * användarvända strängar kvar i Vue-komponenterna" och § "har samma nycklar på
 * båda språken". Den här filen prövar i stället exportytans EGNA nycklar och
 * att grinden är densamma på sidan och på beställningen.
 *
 * **Pollningen prövas som kod och inte som beteende.** Sviten kör ingen
 * webbläsare, så det som går att pröva är att vyn ber om rätt sak: en
 * delladdning av `exports`, att den slutar när ingen rad är öppen, och att
 * timern rivs när sidan lämnas. Samma form som PapperskorgsvyTest:s
 * modulkontroller av trashPresentation.js.
 *
 * Hjälparna har prefixet `exportvy` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

beforeEach(function () {
    Storage::fake('files');
    config(['files.internal_redirect' => false]);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Ett konto med en ägare och en pärm.
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function exportvyKontext(): array
{
    [$konto, $ägare] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();

    return [$konto, $ägare, $container];
}

/**
 * En exportrad i ett givet tillstånd. Standarden är den rad kontrollern
 * skapar: en väntande beställning utan artefakt, precis som
 * Database\Factories\ExportFactory.
 *
 * `$createdAt` sätts uttryckligen där ett test prövar ordningen — listan
 * sorteras `created_at` fallande, och kolumnen har sekundupplösning.
 *
 * @param  array<string, mixed>  $attribut
 */
function exportvyRad(Container $container, User $ägare, array $attribut = []): Export
{
    return Export::factory()->create(array_merge([
        'container_id' => $container->id,
        'requested_by_user_id' => $ägare->id,
    ], $attribut));
}

/**
 * En färdig export vars artefakt ligger på den fejkade disken, så att
 * nedladdningsrutten (41b) faktiskt levererar något.
 *
 * @param  array<string, mixed>  $attribut
 */
function exportvyFärdig(Container $container, User $ägare, ?Carbon $expiresAt = null, array $attribut = []): Export
{
    $export = exportvyRad($container, $ägare, array_merge([
        'status' => Export::STATUS_READY,
        'byte_size' => 4096,
        'expires_at' => $expiresAt ?? now()->addDays((int) config('files.export_retention_days')),
    ], $attribut));

    $export->storage_path = 'exports/'.$container->ulid.'/'.$export->ulid.'.zip';
    $export->save();

    Storage::disk('files')->put($export->storage_path, 'zip-byten');

    return $export;
}

function exportvyUrl(Container $container): string
{
    return "/containers/{$container->ulid}/export";
}

/*
 * Beslut 1: två rutter, båda bakom `auth`. En utloggad besökare skickas till
 * inloggningen och når aldrig en kontrollermetod.
 */
it('skickar en utloggad besökare till inloggningen från exportrutterna', function () {
    withoutVite();

    [, , $container] = exportvyKontext();

    get(exportvyUrl($container))->assertRedirect('/login');
    post(exportvyUrl($container))->assertRedirect('/login');
});

/*
 * Klart när: `/containers/{c}/export` visar pärmens exporter, nyast först.
 *
 * Raderna är ExportResource, samma sex nycklar som `/api` svarar med — vyn
 * hittar inte på någon egen form. `storage_path` och `failure_reason` följer
 * aldrig med.
 */
it('visar pärmens exporter med nyaste först', function () {
    withoutVite();

    Carbon::setTestNow('2026-09-16 12:00:00');

    [, $ägare, $container] = exportvyKontext();

    $äldst = exportvyRad($container, $ägare, [
        'status' => Export::STATUS_READY,
        'byte_size' => 2048,
        'expires_at' => now()->addDays(3),
        'storage_path' => 'exports/hemlig.zip',
        'created_at' => now()->subDay(),
    ]);

    $nyast = exportvyRad($container, $ägare, [
        'status' => Export::STATUS_PENDING,
        'created_at' => now(),
    ]);

    actingAs($ägare)
        ->get(exportvyUrl($container))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Containers/Export')
            ->where('container.ulid', $container->ulid)
            ->has('exports', 2)
            ->where('exports.0.ulid', $nyast->ulid)
            ->where('exports.0.status', Export::STATUS_PENDING)
            ->where('exports.0.byte_size', null)
            ->where('exports.1.ulid', $äldst->ulid)
            ->where('exports.1.byte_size', 2048)
            ->where('exports.1.expires_at', now()->addDays(3)->toIso8601String())
            ->missing('exports.1.storage_path')
        );
});

/*
 * Klart när: en export kan beställas, svaret är omedelbart och raden visas
 * som pågående.
 *
 * Beställningen svarar en omdirigering med en flash-kod och raden finns
 * direkt — jobbet köas och packar i bakgrunden. `Queue::fake()` hindrar
 * sviten från att bygga en riktig ZIP.
 */
it('beställer en export, svarar omedelbart och lämnar raden som pågående', function () {
    withoutVite();

    Queue::fake();

    [, $ägare, $container] = exportvyKontext();

    actingAs($ägare)
        ->post(exportvyUrl($container))
        ->assertRedirect(exportvyUrl($container))
        ->assertSessionHas('status', 'export-requested');

    $export = Export::query()->firstOrFail();

    expect($export->container_id)->toBe($container->id)
        ->and($export->requested_by_user_id)->toBe($ägare->id)
        ->and($export->status)->toBe(Export::STATUS_PENDING)
        ->and($export->byte_size)->toBeNull()
        ->and($export->expires_at)->toBeNull();

    Queue::assertPushed(BuildContainerExport::class, fn (BuildContainerExport $jobb): bool => $jobb->export->is($export));
});

/*
 * Klart när: en andra beställning medan en export packas ger en begriplig
 * mening som pekar på den pågående — ingen andra rad, ingen JSON-kropp.
 *
 * Raden ligger i listan strax under knappen, och meningen säger det. Det är
 * samma tillstånd som stänger knappen, så den som postar förbi vyn får veta
 * varför — inte en rå felkod.
 */
it('avvisar en andra beställning medan en export packas, utan en andra rad', function () {
    withoutVite();

    Queue::fake();

    [, $ägare, $container] = exportvyKontext();

    exportvyRad($container, $ägare, ['status' => Export::STATUS_RUNNING]);

    actingAs($ägare)
        ->post(exportvyUrl($container))
        ->assertSessionHasErrors(['export' => trans('ui.error.export.already_running')]);

    expect(Export::query()->count())->toBe(1);

    Queue::assertNothingPushed();
});

/*
 * Klart när: en ny beställning när den förra är klar går igenom.
 *
 * Innehållet ändras, och användaren ska kunna ta en ny påse — också när den
 * förra är färdig och ännu inte gallrad.
 */
it('släpper igenom en ny beställning när den förra är klar', function () {
    withoutVite();

    Queue::fake();

    [, $ägare, $container] = exportvyKontext();

    exportvyFärdig($container, $ägare);

    actingAs($ägare)
        ->post(exportvyUrl($container))
        ->assertRedirect(exportvyUrl($container));

    expect(Export::query()->count())->toBe(2);
    expect(Export::query()->orderByDesc('id')->firstOrFail()->status)->toBe(Export::STATUS_PENDING);
});

/*
 * Klart när: en misslyckad export visas som misslyckad och går att beställa
 * om.
 *
 * Statusen är radens eget värde ur ExportResource, och vyn ritar den som
 * misslyckad — `failed` är varken `ready` eller en öppen rad, så knappen är
 * öppen igen direkt.
 */
it('visar en misslyckad export som misslyckad och låter den beställas om', function () {
    withoutVite();

    Queue::fake();

    [, $ägare, $container] = exportvyKontext();

    exportvyRad($container, $ägare, ['status' => Export::STATUS_FAILED, 'failure_reason' => 'zip: disken full']);

    actingAs($ägare)
        ->get(exportvyUrl($container))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('exports.0.status', Export::STATUS_FAILED)
            // Undantagsmeddelandet exponeras aldrig — det är en intern
            // detalj, se ExportResource.
            ->missing('exports.0.failure_reason')
        );

    actingAs($ägare)->post(exportvyUrl($container))->assertRedirect(exportvyUrl($container));

    expect(Export::query()->count())->toBe(2);
});

/*
 * Klart när: en användare utan åtkomst till pärmen får 403 på sidan och på
 * beställningen.
 *
 * Grinden är `view` på pärmen, och den prövas på pärmen i rutten — inte mot
 * en flagga i vyn (Beslut 2).
 */
it('ger 403 för en användare utan åtkomst, på sidan och på beställningen', function () {
    withoutVite();

    [, , $container] = exportvyKontext();
    $utomstående = User::factory()->create();

    actingAs($utomstående)->get(exportvyUrl($container))->assertForbidden();
    actingAs($utomstående)->post(exportvyUrl($container))->assertForbidden();

    expect(Export::query()->count())->toBe(0);
});

/*
 * Klart när: en `read`-deltagare kan beställa och ladda ner.
 *
 * Exporten är fri på alla nivåer med flit (Beslut 2): den som får läsa
 * pärmen får ta ut den. Nedladdningen går till den befintliga
 * `/exports/{export}/download` (41b) — den här issuen lägger ingen egen
 * leveransrutt, och rutten egna tester ligger i ExportNedladdningTest.
 */
it('låter en read-deltagare beställa och ladda ner', function () {
    withoutVite();

    Queue::fake();

    [, , $container] = exportvyKontext();
    $gäst = User::factory()->create();
    beviljaAccess($container, $gäst, 'read', 'guest');

    $färdig = exportvyFärdig($container, $gäst);

    actingAs($gäst)
        ->get(exportvyUrl($container))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('exports', 1));

    actingAs($gäst)->post(exportvyUrl($container))->assertRedirect(exportvyUrl($container));

    expect(Export::query()->count())->toBe(2);

    $svar = actingAs($gäst)->get("/exports/{$färdig->ulid}/download");

    $svar->assertOk();
    expect($svar->streamedContent())->toBe('zip-byten');

    // Anropet ovan bevisar att rutten svarar; mallen bevisar att sidan pekar
    // dit. Länken är en vanlig `<a>` mot `/exports/{ulid}/download`, grindad
    // på `downloadable` — rätt rad får den, en utgången eller misslyckad får
    // den inte (Beslut 5).
    $rad = File::get(resource_path('js/components/ExportRow.vue'));

    expect($rad)->toContain('v-if="downloadable"');
    expect($rad)->toContain('`/exports/${row.ulid}/download`');
});

/*
 * Klart när: varje färdig export visar storlek och återstående dagar, räknat
 * på serverns datum — och `byte_size` som null visas inte som noll.
 *
 * Servern skickar talen; formateringen och valet av pluralnyckel bor i
 * resources/js/components/exportPresentation.js (och `formatByteSize`, som
 * speglar serverns Number::fileSize(), i attachmentPresentation.js).
 */
it('skickar storlek och utgångstid, och utelämnar en storlek som saknas', function () {
    withoutVite();

    Carbon::setTestNow('2026-09-16 12:00:00');

    [, $ägare, $container] = exportvyKontext();

    exportvyFärdig($container, $ägare, now()->addDays(7), ['byte_size' => 1048576, 'created_at' => now()->subMinute()]);
    exportvyRad($container, $ägare, ['byte_size' => null, 'created_at' => now()]);

    actingAs($ägare)
        ->get(exportvyUrl($container))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('exports.0.byte_size', null)
            ->where('exports.1.byte_size', 1048576)
            ->where('exports.1.expires_at', now()->addDays(7)->toIso8601String())
        );

    // Vyn ritar ingen storleksrad alls när talet saknas: `formatByteSize`
    // svarar null för ett värde som inte är ett tal, och raden grindas på det
    // — aldrig "0 B" (Beslut 6).
    $rad = File::get(resource_path('js/components/ExportRow.vue'));

    expect($rad)->toContain('formatByteSize(props.row.byte_size)');
    expect($rad)->toContain('v-if="size !== null"');
    expect($rad)->not->toContain('0 B');
});

/*
 * Klart när: en utgången export visas utan nedladdningslänk.
 *
 * Två vägar leder dit, och båda prövas: kolumnen `expired`, som gallringen
 * (41b) sätter, och en `ready` rad vars `expires_at` passerat innan
 * gallringen hunnit fram. Servern svarar 404 på nedladdningen i båda fallen —
 * vyn ritar därför ingen länk som hade varit en återvändsgränd.
 */
it('visar en utgången export utan nedladdningslänk', function () {
    withoutVite();

    Carbon::setTestNow('2026-09-16 12:00:00');

    [, $ägare, $container] = exportvyKontext();

    $gallrad = exportvyFärdig($container, $ägare, now()->subDay(), [
        'status' => Export::STATUS_EXPIRED,
        'created_at' => now()->subDays(8),
    ]);

    $passerad = exportvyFärdig($container, $ägare, now()->subHour(), [
        'created_at' => now()->subDays(9),
    ]);

    actingAs($ägare)
        ->get(exportvyUrl($container))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('exports.0.status', Export::STATUS_EXPIRED)
            // Kolumnen står kvar på `ready` för den passerade raden — det är
            // vyn som läser tiden och ritar den som utgången.
            ->where('exports.1.status', Export::STATUS_READY)
            ->where('exports.1.expires_at', now()->subHour()->toIso8601String())
        );

    actingAs($ägare)->get("/exports/{$gallrad->ulid}/download")->assertNotFound();

    $modul = File::get(resource_path('js/components/exportPresentation.js'));

    expect($modul)->toContain('! isExpiredExport(row, now)');
    expect($modul)->toContain("if (row.status === 'expired')");
    expect($modul)->toContain('new Date(row.expires_at).getTime() <= now.getTime()');
});

/*
 * Klart när: sidan uppdaterar statusen tills raden är klar eller misslyckad,
 * och slutar då — och: den laddar bara om statusen.
 *
 * Ingen `setInterval` som lever vidare efter att sidan lämnats, ingen
 * websocket och inget paket (Beslut 3): en timer som armar sig efter varje
 * svar, en delladdning av `exports`, och en rivning i `onUnmounted`.
 */
it('laddar bara om exporter, slutar när ingen rad är öppen och river timern', function () {
    $sida = File::get(resource_path('js/pages/Containers/Export.vue'));

    expect($sida)->toContain("router.reload({ only: ['exports']");
    expect($sida)->toContain('onFinish: schedulePoll');
    expect($sida)->toContain('hasOpenRow');
    expect($sida)->toContain('onUnmounted');
    expect($sida)->toContain('clearTimeout');
    expect($sida)->not->toContain('setInterval');

    // "Öppen" är `pending` och `running` — samma villkor håller knappen
    // stängd och pollningen igång.
    $modul = File::get(resource_path('js/components/exportPresentation.js'));

    expect($modul)->toContain("row.status === 'pending' || row.status === 'running'");

    // Knappen är avstängd medan en rad packas (Beslut 4), och förklaringen
    // står bredvid den.
    expect($sida)->toContain(':disabled="form.processing || hasOpenRow"');
    expect($sida)->toContain("t('export.running_notice')");
});

/*
 * Beslut 5 och 7: raden väljer mening på den återstående tiden — sista dygnet
 * skrivs som "gallras idag", en dag kvar i singular — och sidan säger vad
 * påsen innehåller innan man beställer.
 *
 * Valet av nyckel bor i exportPresentation.js, för `t()` har ingen
 * pluralisering (issue 52 § Beslut 4) och en mall går inte att pröva.
 */
it('väljer mening på den återstående tiden och säger vad påsen innehåller', function () {
    $modul = File::get(resource_path('js/components/exportPresentation.js'));

    expect($modul)->toContain("t('export.expires.today')");
    expect($modul)->toContain("t('export.expires.day')");
    expect($modul)->toContain("t('export.expires.days', { days })");
    // Trösklarna: mindre än ett dygn är "idag" och aldrig "0 dagar", exakt
    // ett dygn är singular.
    expect($modul)->toContain('days < 1');
    expect($modul)->toContain('days === 1');

    $sv = require lang_path('sv/ui.php');
    $en = require lang_path('en/ui.php');

    foreach (['today', 'day', 'days'] as $nyckel) {
        expect($sv['export']['expires'][$nyckel])->not->toBe('');
        expect($en['export']['expires'][$nyckel])->not->toBe('');
    }

    expect($sv['export']['expires']['today'])->not->toContain('0');
    expect($sv['export']['expires']['days'])->toContain(':days');

    // Vad påsen omfattar står på sidan, inte bara i API:ets dokumentation
    // (Beslut 7).
    expect($sv['export']['intro'])->not->toBe('');
    expect($en['export']['intro'])->not->toBe('');

    $sida = File::get(resource_path('js/pages/Containers/Export.vue'));

    expect($sida)->toContain("t('export.intro')");

    // Varje status kolumnen kan ha har en mening på båda språken — annars
    // visar listan en rå status för någon.
    foreach (['pending', 'running', 'ready', 'failed', 'expired'] as $status) {
        expect($sv['export']['status'][$status])->not->toBe('');
        expect($en['export']['status'][$status])->not->toBe('');
    }
});

/*
 * Klart när: sidan syns i pärmens navigering.
 *
 * Navigationen renderas ur containerSections.js, så en ny sida är en ny rad
 * där — och texten formuleras på servern ur `container.nav.<key>` på båda
 * språken. Exporten ligger i navigeringen och inte bakom en inställning: den
 * är fri på alla planer, och en utgång ingen hittar är samma sak som en
 * inlåsning.
 */
it('har en rad i pärmens navigering på båda språken', function () {
    $sektioner = File::get(resource_path('js/layouts/containerSections.js'));

    expect($sektioner)->toContain("{ key: 'export', href: (ulid) => `/containers/\${ulid}/export` }");

    $sv = require lang_path('sv/ui.php');
    $en = require lang_path('en/ui.php');

    expect($sv['container']['nav']['export'])->not->toBe('');
    expect($en['container']['nav']['export'])->not->toBe('');
});
