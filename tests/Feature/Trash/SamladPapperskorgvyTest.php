<?php

use App\Actions\Audit\RecordAuditEvent;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\from;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\withoutVite;

/*
 * M39 · issue 319 — sidan `/trash`, den samlade papperskorgen. Se
 * [[ADR-0065 Den samlade papperskorgen]] § 4 och 6–9,
 * App\Http\Controllers\TrashOverviewController,
 * App\Http\Requests\Trash\RestoreAnyRequest,
 * resources/js/pages/Trash/Index.vue och
 * App\Actions\Trash\ListAllTrash (urvalet, issue 318).
 *
 * **Urvalet prövas i SamladPapperskorgTest (issue 318).** Den här filen
 * prövar ytan: rutterna, propsen, grinden per rad, de tre återställningarna
 * och att den gamla adressen leder vidare.
 *
 * **Varje "Klart när"-punkt har ett prov här eller i grannfilen.** De två
 * som redan har en ägare är "POST /trash/containers/restore och /api är
 * oförändrade" (ContainerpapperskorgTest) och "hela testsviten är grön"
 * (sviten själv). Klientsidans beteenden — sökfördröjningen, växelns fyra
 * lägen och sammanslagningen i Load more — prövas som källkodsprov, samma
 * form som GenomgangTest: det som går att avgöra på serversidan är formen.
 *
 * Hjälparna har prefixet `samladVy` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs. kontoMedMedlem(), beviljaAccess(),
 * papperskorgsItem() och papperskorgsBilaga() är globala i
 * tests/Support/Testhjalpare.php.
 */

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Ett konto med en ägare och en levande container.
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function samladVyKontext(): array
{
    [$konto, $ägare] = kontoMedMedlem();

    $container = Container::factory()->for($konto, 'account')->create(['name' => 'Verkstaden']);

    return [$konto, $ägare, $container];
}

/**
 * Mjukraderar en rad med kontrollerad tidpunkt.
 */
function samladVyRaderad(Item|Attachment|Container $modell, Carbon $deletedAt): void
{
    $modell->deleted_at = $deletedAt;
    $modell->save();
}

/**
 * Posterna nycklade på ULID.
 *
 * @param  array<int, array<string, mixed>>  $poster
 * @return array<string, array<string, mixed>>
 */
function samladVyPoster(array $poster): array
{
    return array_column($poster, null, 'ulid');
}

/*
 * Beslut 1 och 9: båda rutterna bakom `auth`, också den gamla adressen.
 */
it('skickar en utloggad besökare till inloggningen från /trash-rutterna', function () {
    withoutVite();

    get('/trash')->assertRedirect('/login');
    get('/trash/containers')->assertRedirect('/login');
    post('/trash/restore', ['type' => 'item', 'ulid' => '01JKX7Q3F8Z2N6M4B9T0R5V1WQ'])
        ->assertRedirect('/login');
});

/*
 * Klart när: `/trash` ritar raderna med ikon, namn, slag, var de hör hemma,
 * när, av vem och när de försvinner — ADR-0065 § 4.
 */
it('listar items, bilagor och containrar med ikon, namn, slag, plats och tider', function () {
    withoutVite();

    Carbon::setTestNow('2026-10-18 12:00:00');

    [$konto, $ägare, $container] = samladVyKontext();

    $item = papperskorgsItem($container, $konto, $ägare, [
        'name' => 'Motorn',
        'icon' => 'engine',
        'icon_color' => 'blue',
    ]);
    samladVyRaderad($item, now()->subHours(3));

    $levande = papperskorgsItem($container, $konto, $ägare, ['name' => 'Växellådan']);

    $bilaga = papperskorgsBilaga($levande, $konto, $ägare, [
        'filename' => 'faktura.pdf',
        'kind' => 'document',
    ]);
    samladVyRaderad($bilaga, now()->subHours(2));

    $raderadContainer = Container::factory()->for($konto, 'account')->create([
        'name' => 'Havsörnen',
        'icon' => 'anchor',
        'icon_color' => 'green',
    ]);
    samladVyRaderad($raderadContainer, now()->subHour());

    $retention = (int) config('files.trash_retention_days');

    actingAs($ägare)
        ->get('/trash')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Trash/Index')
            ->where('filter', ['q' => null, 'type' => null])
            ->has('entries', 3)
            ->where('entries.0.type', 'container')
            ->where('entries.0.ulid', $raderadContainer->ulid)
            ->where('entries.0.label', 'Havsörnen')
            ->where('entries.0.icon', 'anchor')
            ->where('entries.0.icon_color', 'green')
            ->where('entries.0.container', null)
            ->where('entries.0.item', null)
            ->where('entries.0.expires_at', now()->subHour()->addDays($retention)->toIso8601String())
            ->where('entries.1.type', 'attachment')
            ->where('entries.1.ulid', $bilaga->ulid)
            ->where('entries.1.label', 'faktura.pdf')
            ->where('entries.1.kind', 'document')
            ->where('entries.1.item.ulid', $levande->ulid)
            ->where('entries.1.item.name', 'Växellådan')
            ->where('entries.1.container.ulid', $container->ulid)
            ->where('entries.2.type', 'item')
            ->where('entries.2.ulid', $item->ulid)
            ->where('entries.2.label', 'Motorn')
            ->where('entries.2.icon', 'engine')
            ->where('entries.2.icon_color', 'blue')
            ->where('entries.2.container.ulid', $container->ulid)
            ->where('entries.2.container.name', 'Verkstaden')
            ->where('entries.2.item', null)
            ->where('entries.2.deleted_by', null)
            ->where('entries.2.deleted_at', now()->subHours(3)->toIso8601String())
            ->where('nextCursor', null)
            ->where("canRestore.{$item->ulid}", true)
            ->where("canRestore.{$bilaga->ulid}", true)
            ->where("canRestore.{$raderadContainer->ulid}", true)
        );
});

/*
 * Klart när: *Deleted {when} by {user}*, eller *by a former user* — ADR-0065
 * § 4 och 5.
 *
 * Namnet kommer ur den senaste `*.deleted`-händelsen. En händelse utan
 * användare — ett jobb, eller en användare som raderats — ger null, och vyn
 * formulerar *a former user*.
 */
it('visar vem som raderade ur den senaste händelsen, och null utan namn', function () {
    withoutVite();

    [$konto, $ägare, $container] = samladVyKontext();

    $item = papperskorgsItem($container, $konto, $ägare, ['name' => 'Motorn']);
    samladVyRaderad($item, now()->subHours(3));

    $levande = papperskorgsItem($container, $konto, $ägare, ['name' => 'Växellådan']);
    $bilaga = papperskorgsBilaga($levande, $konto, $ägare, ['filename' => 'faktura.pdf']);
    samladVyRaderad($bilaga, now()->subHours(2));

    $raderadContainer = Container::factory()->for($konto, 'account')->create(['name' => 'Havsörnen']);
    samladVyRaderad($raderadContainer, now()->subHour());

    $raderare = User::factory()->create(['name' => 'Raderaren']);

    app(RecordAuditEvent::class)->handle(
        action: AuditLog::ACTION_ITEM_DELETED,
        user: $raderare,
        container: $container,
        item: $item,
    );

    app(RecordAuditEvent::class)->handle(
        action: AuditLog::ACTION_ATTACHMENT_DELETED,
        user: $raderare,
        container: $container,
        item: $levande,
        subjectType: 'attachment',
        subjectUlid: $bilaga->ulid,
    );

    // Containerns händelse skrivs av ett jobb: ingen användare, alltså null.
    app(RecordAuditEvent::class)->handle(
        action: AuditLog::ACTION_CONTAINER_DELETED,
        container: $raderadContainer,
        subjectType: 'container',
        subjectUlid: $raderadContainer->ulid,
    );

    $svar = actingAs($ägare)->get('/trash')->assertOk();

    $poster = samladVyPoster($svar->viewData('page')['props']['entries']);

    expect($poster[$item->ulid]['deleted_by'])->toBe('Raderaren');
    expect($poster[$bilaga->ulid]['deleted_by'])->toBe('Raderaren');
    expect($poster[$raderadContainer->ulid]['deleted_by'])->toBeNull();
});

/*
 * Klart när: sökfältet och växeln begränsar urvalet, båda står i adressen,
 * och ett okänt `type` räknas som alla tre utan fel — ADR-0065 § 6.
 */
it('begränsar urvalet med q och type, och ett okänt type räknas som alla tre', function () {
    withoutVite();

    [$konto, $ägare, $container] = samladVyKontext();

    $item = papperskorgsItem($container, $konto, $ägare, ['name' => 'Motorn']);
    samladVyRaderad($item, now()->subHours(4));

    $levande = papperskorgsItem($container, $konto, $ägare, ['name' => 'Växellådan']);

    $bilaga = papperskorgsBilaga($levande, $konto, $ägare, ['filename' => 'motor.pdf']);
    samladVyRaderad($bilaga, now()->subHours(3));

    $raderadContainer = Container::factory()->for($konto, 'account')->create(['name' => 'Motorverkstaden']);
    samladVyRaderad($raderadContainer, now()->subHours(2));

    // Utanför sökordet.
    $annan = papperskorgsItem($container, $konto, $ägare, ['name' => 'Bromsen']);
    samladVyRaderad($annan, now()->subHour());

    actingAs($ägare)
        ->get('/trash?q=motor')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filter', ['q' => 'motor', 'type' => null])
            ->has('entries', 3)
            ->where('entries.0.ulid', $raderadContainer->ulid)
            ->where('entries.1.ulid', $bilaga->ulid)
            ->where('entries.2.ulid', $item->ulid)
        );

    actingAs($ägare)
        ->get('/trash?q=motor&type=item')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filter', ['q' => 'motor', 'type' => 'item'])
            ->has('entries', 1)
            ->where('entries.0.ulid', $item->ulid)
        );

    actingAs($ägare)
        ->get('/trash?q=motor&type=container')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filter', ['q' => 'motor', 'type' => 'container'])
            ->has('entries', 1)
            ->where('entries.0.ulid', $raderadContainer->ulid)
        );

    // Ett okänt läge är inget fel: servern normaliserar det till alla tre,
    // och vyn visar *All* eftersom `filter.type` är null.
    actingAs($ägare)
        ->get('/trash?q=motor&type=okand')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filter', ['q' => 'motor', 'type' => null])
            ->has('entries', 3)
        );
});

/*
 * Klart när: en tom papperskorg och ett filter utan träffar har var sin text.
 */
it('säger att papperskorgen är tom och skiljer ett filter utan träffar', function () {
    withoutVite();

    [, $ägare] = samladVyKontext();

    actingAs($ägare)
        ->get('/trash')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('entries', [])
            ->where('canRestore', [])
        );

    actingAs($ägare)
        ->get('/trash?q=ingenting')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('entries', [])
            ->where('filter.q', 'ingenting')
        );

    $vy = File::get(resource_path('js/pages/Trash/Index.vue'));

    expect($vy)->toContain("t('trash.empty')")
        ->toContain("t('trash.overview.empty_filtered')")
        ->toContain('v-else-if="hasFilter"');
});

/*
 * Klart när: *Restore* ritas bara när grinden släpper igenom raden, och
 * flaggan följer samma grind som rutten — ADR-0065 § 8.
 *
 * En `read`-deltagare ser listan men får ingen knapp och 403 om hon postar
 * förbi vyn. Ett fryst konto nekas skrivandet på samma sätt (regel 4).
 */
it('räknar canRestore per rad ur samma grind som återställningen', function () {
    withoutVite();

    [$konto, $ägare, $container] = samladVyKontext();

    $item = papperskorgsItem($container, $konto, $ägare, ['name' => 'Motorn']);
    samladVyRaderad($item, now()->subHour());

    $gäst = User::factory()->create();
    beviljaAccess($container, $gäst, 'read', 'guest');

    actingAs($gäst)
        ->get('/trash')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('entries', 1)
            ->where("canRestore.{$item->ulid}", false)
        );

    actingAs($gäst)
        ->post('/trash/restore', ['type' => 'item', 'ulid' => $item->ulid])
        ->assertForbidden();

    // Regel 4: ett fryst ägarkonto ser listan men får ingen knapp.
    $konto->status = 'read_only';
    $konto->save();

    actingAs($ägare)
        ->get('/trash')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('entries', 1)
            ->where("canRestore.{$item->ulid}", false)
        );

    expect(DB::table('item')->where('id', $item->id)->value('deleted_at'))->not->toBeNull();
});

/*
 * Klart när: POST /trash/restore återställer items med samma Action och grind
 * som TrashController::restore(), och leder tillbaka till /trash med samma
 * querysträng och en statuskod — ADR-0065 § 8.
 */
it('återställer ett item och leder tillbaka till /trash med filtret kvar', function () {
    withoutVite();

    [$konto, $ägare, $container] = samladVyKontext();

    $item = papperskorgsItem($container, $konto, $ägare, ['name' => 'Motorn']);
    samladVyRaderad($item, now()->subHour());

    from('/trash?q=motor&type=item')
        ->actingAs($ägare)
        ->post('/trash/restore?q=motor&type=item', ['type' => 'item', 'ulid' => $item->ulid])
        ->assertRedirect('/trash?q=motor&type=item')
        ->assertSessionHas('status', 'trash-restored');

    expect(DB::table('item')->where('id', $item->id)->value('deleted_at'))->toBeNull();
});

/*
 * Klart när: POST /trash/restore återställer bilagor med samma Action och
 * grind som TrashController::restore().
 */
it('återställer en bilaga med samma Action och grind som förut', function () {
    withoutVite();

    [$konto, $ägare, $container] = samladVyKontext();

    $item = papperskorgsItem($container, $konto, $ägare, ['name' => 'Motorn']);
    $bilaga = papperskorgsBilaga($item, $konto, $ägare, ['filename' => 'faktura.pdf']);
    samladVyRaderad($bilaga, now()->subHour());

    actingAs($ägare)
        ->post('/trash/restore', ['type' => 'attachment', 'ulid' => $bilaga->ulid])
        ->assertRedirect('/trash')
        ->assertSessionHas('status', 'trash-restored');

    expect(DB::table('attachment')->where('id', $bilaga->id)->value('deleted_at'))->toBeNull();
});

/*
 * Klart när: en bilaga vars item ligger kvar i papperskorgen ger samma
 * formulärfel som containerns rutt — `trash.parent_deleted`, aldrig 403.
 */
it('avvisar en bilaga vars item ligger kvar i papperskorgen', function () {
    withoutVite();

    [$konto, $ägare, $container] = samladVyKontext();

    $item = papperskorgsItem($container, $konto, $ägare, ['name' => 'Motorn']);
    samladVyRaderad($item, now()->subHour());

    $bilaga = papperskorgsBilaga($item, $konto, $ägare, ['filename' => 'faktura.pdf']);
    samladVyRaderad($bilaga, now()->subMinutes(30));

    actingAs($ägare)
        ->post('/trash/restore', ['type' => 'attachment', 'ulid' => $bilaga->ulid])
        ->assertSessionHasErrors('trash');

    expect(DB::table('attachment')->where('id', $bilaga->id)->value('deleted_at'))->not->toBeNull();
});

/*
 * Klart när: POST /trash/restore återställer containrar med samma Action och
 * grind som ContainerTrashController::restore().
 */
it('återställer en container med samma Action och grind som förut', function () {
    withoutVite();

    [$konto, $ägare] = samladVyKontext();

    $container = Container::factory()->for($konto, 'account')->create(['name' => 'Havsörnen']);
    samladVyRaderad($container, now()->subHour());

    from('/trash?type=container')
        ->actingAs($ägare)
        ->post('/trash/restore?type=container', ['type' => 'container', 'ulid' => $container->ulid])
        ->assertRedirect('/trash?type=container')
        ->assertSessionHas('status', 'container-restored');

    expect(DB::table('container')->where('id', $container->id)->value('deleted_at'))->toBeNull();
});

/*
 * Klart när: en ULID användaren inte får återställa ger samma svar som i de
 * befintliga rutterna — 403 för en annan användares rad, 404 för en utgången,
 * och ett valideringsfel för en rad som inte ligger i papperskorgen.
 */
it('ger samma svar som de befintliga rutterna när grinden eller uppslaget säger nej', function () {
    withoutVite();

    Carbon::setTestNow('2026-10-18 12:00:00');

    [$konto, $ägare, $container] = samladVyKontext();

    // En annan användares rad: valideringen passerar, grinden nekar.
    $annan = User::factory()->create();
    $annansContainer = Container::factory()->for(Account::factory(), 'account')->create();

    $deras = papperskorgsItem($annansContainer, Account::factory()->create(), $annan, ['name' => 'Deras']);
    samladVyRaderad($deras, now()->subHour());

    actingAs($ägare)
        ->post('/trash/restore', ['type' => 'item', 'ulid' => $deras->ulid])
        ->assertForbidden();

    // Utgången rad: uppslaget tillämpar retentionen, alltså 404.
    $utgången = papperskorgsItem($container, $konto, $ägare, ['name' => 'Utgången']);
    samladVyRaderad($utgången, now()->subDays((int) config('files.trash_retention_days') + 1));

    actingAs($ägare)
        ->post('/trash/restore', ['type' => 'item', 'ulid' => $utgången->ulid])
        ->assertNotFound();

    // En levande rad ligger inte i papperskorgen: valideringsfel på `ulid`,
    // exakt som RestoreRequest och RestoreContainerRequest svarar.
    $levande = papperskorgsItem($container, $konto, $ägare, ['name' => 'Levande']);

    actingAs($ägare)
        ->post('/trash/restore', ['type' => 'item', 'ulid' => $levande->ulid])
        ->assertSessionHasErrors('ulid');

    // Ett okänt slag är också ett valideringsfel.
    actingAs($ägare)
        ->post('/trash/restore', ['type' => 'category', 'ulid' => $deras->ulid])
        ->assertSessionHasErrors('type');

    expect(DB::table('item')->where('id', $deras->id)->value('deleted_at'))->not->toBeNull();
    expect(DB::table('item')->where('id', $utgången->id)->value('deleted_at'))->not->toBeNull();
});

/*
 * Kroppens `type` väljer radens typ — filtret i adressen gör det inte.
 *
 * `POST /trash/restore?type=container` är samma querysträng som listans
 * filter (§ 6). En klient som bara skickar `ulid` ska få ett valideringsfel
 * på `type`, inte lånas containertypen ur filtret. När kroppen bär `type`
 * vinner den över filtret.
 */
it('väljer radens typ ur kroppen och inte ur querysträngen', function () {
    withoutVite();

    [$konto, $ägare, $container] = samladVyKontext();

    $item = papperskorgsItem($container, $konto, $ägare, ['name' => 'Motorn']);
    samladVyRaderad($item, now()->subHour());

    actingAs($ägare)
        ->post('/trash/restore?type=container', ['ulid' => $item->ulid])
        ->assertSessionHasErrors('type');

    expect(DB::table('item')->where('id', $item->id)->value('deleted_at'))->not->toBeNull();

    actingAs($ägare)
        ->post('/trash/restore?type=container', ['type' => 'item', 'ulid' => $item->ulid])
        ->assertRedirect('/trash?type=container')
        ->assertSessionHas('status', 'trash-restored');

    expect(DB::table('item')->where('id', $item->id)->value('deleted_at'))->toBeNull();
});

/*
 * Klart när: containern är radens egen och måste vara levande — ADR-0065 § 3.
 *
 * Ett item i en raderad container listas aldrig för sig, och en klient som
 * postar ULID:en förbi vyn får 404, samma svar som en rad som inte finns.
 */
it('återställer inte ett item i en raderad container', function () {
    withoutVite();

    [$konto, $ägare] = samladVyKontext();

    $container = Container::factory()->for($konto, 'account')->create();
    $item = papperskorgsItem($container, $konto, $ägare, ['name' => 'Motorn']);
    samladVyRaderad($item, now()->subMinutes(30));
    samladVyRaderad($container, now()->subHour());

    actingAs($ägare)
        ->post('/trash/restore', ['type' => 'item', 'ulid' => $item->ulid])
        ->assertNotFound();

    expect(DB::table('item')->where('id', $item->id)->value('deleted_at'))->not->toBeNull();
});

/*
 * Klart när: en ULID som en delegerad åtkomst inte får återställa ger 403 för
 * en container också — grinden är `ContainerPolicy::delete()`.
 */
it('nekar en delegerad åtkomst att återställa en container', function () {
    withoutVite();

    [, , $container] = samladVyKontext();

    samladVyRaderad($container, now()->subHour());

    $mottagare = User::factory()->create();
    beviljaAccess($container, $mottagare, 'delete', 'guest');

    actingAs($mottagare)
        ->post('/trash/restore', ['type' => 'container', 'ulid' => $container->ulid])
        ->assertForbidden();

    expect(DB::table('container')->where('id', $container->id)->value('deleted_at'))->not->toBeNull();
});

/*
 * Klart när: Load more hämtar nästa sida med `cursor` — ADR-0065 § 7.
 *
 * Servern svarar med högst 50 rader och en markör; nästa anrop ger resten
 * utan dubbletter. Vyn lägger den sidan under de rader som redan står där
 * (källkodsprovet nedanför).
 */
it('ger nästa sida med markören', function () {
    withoutVite();

    Carbon::setTestNow('2026-10-18 12:00:00');

    [$konto, $ägare, $container] = samladVyKontext();

    $ulider = [];

    foreach (range(1, 51) as $i) {
        $item = papperskorgsItem($container, $konto, $ägare, ['name' => "Item $i"]);
        samladVyRaderad($item, now()->subMinutes(60 - $i));
        $ulider[] = $item->ulid;
    }

    $första = actingAs($ägare)->get('/trash')->assertOk();

    $sida = $första->viewData('page')['props'];

    expect($sida['entries'])->toHaveCount(50);
    expect($sida['nextCursor'])->not->toBeNull();

    $andra = actingAs($ägare)->get('/trash?cursor='.urlencode($sida['nextCursor']))->assertOk();

    $rest = $andra->viewData('page')['props'];

    // Senast raderad först: den sista sidan bär den äldsta raden.
    expect($rest['entries'])->toHaveCount(1);
    expect($rest['entries'][0]['ulid'])->toBe($ulider[0]);
    expect($rest['nextCursor'])->toBeNull();
});

/*
 * Klart när: `/trash/containers` leder till `/trash?type=container`, och
 * `POST /trash/containers/restore` samt `/api` är oförändrade — ADR-0065 § 9.
 */
it('leder den gamla adressen vidare och lämnar den gamla återställningen orörd', function () {
    withoutVite();

    actingAs(User::factory()->create())
        ->get('/trash/containers')
        ->assertRedirect('/trash?type=container');

    $webb = Route::getRoutes()->getByName('trash.containers.restore');
    $api = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($rutt) => $rutt->uri() === 'api/trash/containers/restore');

    expect($webb)->not->toBeNull();
    expect($webb->uri())->toBe('trash/containers/restore');
    expect($api)->not->toBeNull();
});

/*
 * Klart när: `Trash/Containers.vue` finns inte.
 */
it('har tagit bort den gamla vyn', function () {
    expect(File::exists(resource_path('js/pages/Trash/Containers.vue')))->toBeFalse();
    expect(File::exists(resource_path('js/pages/Trash/Index.vue')))->toBeTrue();
});

/*
 * Klientsidans beteenden som inte går att se i ett HTTP-svar: sökfältet
 * söker medan man skriver (300 ms, Enter direkt, `replace`, aldrig
 * `disabled`, avbryter den förra), växeln har fyra lägen, Load more lägger
 * nästa sida under de befintliga raderna, och Restore ritas bara när flaggan
 * släpper igenom raden.
 */
it('söker medan man skriver och lägger nästa sida under de befintliga raderna', function () {
    $vy = File::get(resource_path('js/pages/Trash/Index.vue'));

    // 300 ms-fönstret och Enter.
    expect($vy)->toContain('const SEARCH_DELAY_MS = 300;')
        ->toContain('setTimeout(')
        ->toContain('@input="onSearchInput"')
        ->toContain('@submit.prevent="submitSearch"');

    // Sökningen ersätter historikposten och avbryter den förra visiten.
    expect($vy)->toContain('replace: options.replace === true')
        ->toContain('replace: true, fromField: true')
        ->toContain('cancelToken.cancel()');

    // Fältet är aldrig `disabled`.
    expect($vy)->toContain('id="trash-search"');
    expect($vy)->not->toMatch('/<input(?:(?!\/>).)*disabled/s');

    // Växelns fyra lägen.
    expect($vy)->toContain("{ value: null, key: 'all' }")
        ->toContain("{ value: 'item', key: 'item' }")
        ->toContain("{ value: 'attachment', key: 'attachment' }")
        ->toContain("{ value: 'container', key: 'container' }");

    // Load more: markören, preserveScroll, sammanslagningen av rader — och
    // flaggan, som annars hade saknats på nästa sida.
    expect($vy)->toContain('cursor: cursor.value')
        ->toContain('preserveScroll: true')
        ->toContain('rows.value = [...rows.value, ...response.props.entries]')
        ->toContain('restoreFlags.value = { ...restoreFlags.value, ...response.props.canRestore }');

    // Restore bara när grinden släpper igenom, och målet bär filtret.
    // `:preserve-state="false"` remountar sidan efter POST, annars hade den
    // återställda raden stått kvar i den lokala `rows`-listan.
    expect($vy)->toContain('v-if="restoreFlags[entry.ulid] === true"')
        ->toContain('method="post"')
        ->toContain(':preserve-state="false"')
        ->toContain('/trash/restore');

    // Ikonerna: ItemIcon för item och container, bilagans typikon.
    expect($vy)->toContain(":fallback=\"entry.type === 'container' ? 'container' : 'item'\"")
        ->toContain("entry.kind === 'image' ? 'image' : 'document'");
});
