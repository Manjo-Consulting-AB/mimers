<?php

use App\Actions\Notification\CreateNotification;
use App\Actions\Schedule\MoveSchedule;
use App\Exceptions\Api\ApiException;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\CostEntry;
use App\Models\Item;
use App\Models\Notification;
use App\Models\Schedule;
use App\Models\ScheduleDependency;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use Illuminate\Support\Facades\Lang;

use function Pest\Laravel\actingAs;

/*
 * M27 · issue 240 — flytten av en uppgift. Se
 * App\Actions\Schedule\MoveSchedule, App\Http\Controllers\ScheduleController
 * ::move() och [[ADR-0053 Flytt och kopiering]] § 6 och § 7.
 *
 * Filen prövar de fem gränserna:
 *
 * 1. **Inom samma container** byts `schedule.item_id` och ingenting annat —
 *    förekomsterna, både den öppna och historiken, följer med (§ 6).
 * 2. **Till en annan container** får uppgiften ett nytt hem, och svaret
 *    landar på dess sida där.
 * 3. **Beroenden nekar flytten mellan containrar** — på schema- och
 *    förekomstnivå och i båda riktningarna (§ 6) — men tillåter den inom
 *    containern.
 * 4. **Den öppna förekomstens notiser frigörs** (§ 6, issue 222).
 * 5. **Grindarna är `delete` på källan och `create` på målet** (§ 2), och
 *    loggarna nämner aldrig motparten (§ 7).
 *
 * Uppgifterna skapas och stängs via webbrutterna — samma yta som användaren
 * möter — så att förekomsterna öppnas och historiken byggs av produktionens
 * egna vägar (OpenNextOccurrence och CloseOccurrence). Själva flytten går
 * genom den nya rutten och actionen.
 *
 * Hjälparna har prefixet `flyttUppgift` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Ett konto med en medlem i given typ.
 *
 * @return array{0: Account, 1: User}
 */
function flyttUppgiftKonto(string $typ = 'personal'): array
{
    $konto = Account::factory()->create(['type' => $typ]);
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    return [$konto, $anvandare];
}

function flyttUppgiftItem(Container $container, Account $konto, User $anvandare, string $namn = 'Motorn'): Item
{
    return Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $konto->id,
    ]);
}

/**
 * Ett konto, en medlem, en container och ett item under den.
 *
 * @return array{0: Account, 1: User, 2: Container, 3: Item}
 */
function flyttUppgiftKontext(string $typ = 'personal'): array
{
    [$konto, $anvandare] = flyttUppgiftKonto($typ);
    $container = Container::factory()->for($konto, 'account')->create(['name' => 'Varvet Norr']);
    $item = flyttUppgiftItem($container, $konto, $anvandare);

    return [$konto, $anvandare, $container, $item];
}

function flyttUppgiftSchemaUrl(Container $container, Item $item, Schedule $schema): string
{
    return "/containers/{$container->ulid}/items/{$item->ulid}/schedules/{$schema->ulid}";
}

/**
 * Skapar ett schema via webben — samma väg som användaren, så att den första
 * förekomsten öppnas av App\Actions\Schedule\OpenNextOccurrence.
 *
 * @param  array<string, mixed>  $kropp
 */
function flyttUppgiftSkapa(User $anvandare, Container $container, Item $item, array $kropp): Schedule
{
    actingAs($anvandare)
        ->post("/containers/{$container->ulid}/items/{$item->ulid}/schedules", $kropp)
        ->assertRedirect();

    return Schedule::query()->orderByDesc('id')->firstOrFail();
}

/**
 * Bockar av den öppna förekomsten via webben, så att nästa öppnas av
 * CloseOccurrence och historiken byggs av produktionen.
 */
function flyttUppgiftStäng(
    User $anvandare,
    Account $konto,
    Container $container,
    Item $item,
    Schedule $schema,
    ScheduleOccurrence $förekomst,
): void {
    actingAs($anvandare)
        ->post(
            flyttUppgiftSchemaUrl($container, $item, $schema)."/occurrences/{$förekomst->ulid}/complete",
            ['account' => $konto->ulid],
        )
        ->assertRedirect();
}

function flyttUppgiftPost(Container $container, Item $item, Schedule $schema, Item $mål, User $anvandare)
{
    return actingAs($anvandare)->post(
        flyttUppgiftSchemaUrl($container, $item, $schema).'/move',
        ['target' => $mål->ulid],
    );
}

/**
 * Ett giltigt schema att flytta: ett intervall som öppnar en ny förekomst vid
 * varje avbockning, så att ett test kan bygga historik av fler än en rad.
 *
 * @return array<string, mixed>
 */
function flyttUppgiftKropp(string $titel = 'Byt olja'): array
{
    return [
        'title' => $titel,
        'recurrence_type' => 'interval',
        'interval_unit' => 'month',
        'interval_count' => 1,
        'anchor_date' => '2026-07-01',
    ];
}

/**
 * Ett item i en ANNAN container, i ett annat konto, där $anvandare är medlem
 * — så att hon har `create` på målet.
 *
 * Medlemskapet läggs till innan något anrop som löser upp omfånget:
 * App\Actions\Access\ResolveItemScope cachar användarens konton på
 * User-instansen, så en medlemsrad som skrivs efter första
 * behörighetsprövningen syns inte i samma test.
 *
 * @return array{0: Container, 1: Item}
 */
function flyttUppgiftMål(User $anvandare, string $itemNamn = 'Pumpen'): array
{
    [$annatKonto, $annanAgare] = flyttUppgiftKonto('organisation');
    $annatKonto->users()->attach($anvandare, ['role' => 'member']);
    $container = Container::factory()->for($annatKonto, 'account')->create(['name' => 'Kunden Syd']);
    $item = flyttUppgiftItem($container, $annatKonto, $annanAgare, $itemNamn);

    return [$container, $item];
}

// --- Beslut 1: flytten och historiken --------------------------------------

/*
 * Klart när: en öppen och två avklarade förekomster följer med när uppgiften
 * flyttas inom containern — samma schema, samma ULID:er, samma historik.
 */
it('flyttar inom containern med historiken', function () {
    [$konto, $anvandare, $container, $item] = flyttUppgiftKontext();
    $mål = flyttUppgiftItem($container, $konto, $anvandare, 'Reservdelen');

    $schema = flyttUppgiftSkapa($anvandare, $container, $item, flyttUppgiftKropp());

    flyttUppgiftStäng($anvandare, $konto, $container, $item, $schema, $schema->openOccurrence()->sole());
    flyttUppgiftStäng($anvandare, $konto, $container, $item, $schema, $schema->openOccurrence()->sole());

    expect($schema->occurrences()->count())->toBe(3);
    expect($schema->occurrences()->where('status', 'completed')->count())->toBe(2);

    $öppen = $schema->openOccurrence()->sole();

    flyttUppgiftPost($container, $item, $schema, $mål, $anvandare)
        ->assertSessionHas('status', 'schedule-moved');

    $flyttad = $schema->fresh();

    // Samma rad, nytt item — ULID:n står kvar, så ingenting pekar fel.
    expect($flyttad->item_id)->toBe($mål->id);
    expect($flyttad->ulid)->toBe($schema->ulid);

    // Alla tre förekomsterna hänger kvar på schemat, och den öppna är samma
    // rad som förut (§ 6).
    expect($flyttad->occurrences()->count())->toBe(3);
    expect($flyttad->occurrences()->where('status', 'completed')->count())->toBe(2);
    expect($flyttad->openOccurrence()->sole()->ulid)->toBe($öppen->ulid);

    // En rad i loggen inom containern, med bägge itemens ULID:er (§ 7).
    $rad = AuditLog::query()->where('action', AuditLog::ACTION_SCHEDULE_MOVED)->sole();

    expect($rad->meta)->toBe(['from_item' => $item->ulid, 'to_item' => $mål->ulid]);
    expect($rad->item_id)->toBe($mål->id);
    expect($rad->subject_id)->toBe($schema->ulid);
});

// --- Beslut 1: till en annan container --------------------------------------

/*
 * Klart när: uppgiften flyttar till ett item i en annan container, och
 * förekomsterna följer med dit.
 */
it('flyttar till en annan container', function () {
    [$konto, $anvandare, $container, $item] = flyttUppgiftKontext('organisation');
    [$annanContainer, $mål] = flyttUppgiftMål($anvandare);

    $schema = flyttUppgiftSkapa($anvandare, $container, $item, flyttUppgiftKropp());
    $öppen = $schema->openOccurrence()->sole();

    flyttUppgiftPost($container, $item, $schema, $mål, $anvandare)
        ->assertSessionHas('status', 'schedule-moved');

    $flyttad = $schema->fresh();

    expect($flyttad->item_id)->toBe($mål->id);
    expect($flyttad->openOccurrence()->sole()->ulid)->toBe($öppen->ulid);
    expect($flyttad->occurrences()->count())->toBe(1);
    expect($flyttad->item->container_id)->toBe($annanContainer->id);
});

// --- Beslut 1: beroenden nekar mellan containrar ---------------------------

/*
 * Klart när: en uppgift med ett beroende — på schema- eller förekomstnivå och
 * i någon riktning — nekas flytt till en annan container med 422
 * `schedule.has_dependencies` (§ 6). De fyra fallen är de fyra raderna
 * `hasDependencies()` läser.
 */
it('nekar flytt till en annan container när uppgiften har ett beroende', function (string $fall) {
    [$konto, $anvandare, $container, $item] = flyttUppgiftKontext('organisation');
    [, $mål] = flyttUppgiftMål($anvandare);

    $schema = flyttUppgiftSkapa($anvandare, $container, $item, flyttUppgiftKropp());
    $öppen = $schema->openOccurrence()->sole();

    // Motparten ligger i SAMMA container — beroendet är giltigt där det
    // skrevs, och det är just därför flytten UT nekas.
    $motpart = flyttUppgiftSkapa($anvandare, $container, $item, flyttUppgiftKropp('Serva motorn'));
    $motpartFörekomst = $motpart->openOccurrence()->sole();

    if ($fall === 'schema_vantar') {
        ScheduleDependency::factory()->create([
            'schedule_id' => $schema->id,
            'depends_on_schedule_id' => $motpart->id,
        ]);
    } elseif ($fall === 'schema_motpart') {
        ScheduleDependency::factory()->create([
            'schedule_id' => $motpart->id,
            'depends_on_schedule_id' => $schema->id,
        ]);
    } elseif ($fall === 'forekomst_vantar') {
        skapaBeroende($öppen, $motpartFörekomst);
    } else {
        skapaBeroende($motpartFörekomst, $öppen);
    }

    $svar = flyttUppgiftPost($container, $item, $schema, $mål, $anvandare);

    $svar->assertSessionHasErrors('schedule');
    expect(session('errors')->get('schedule')[0])
        ->toBe(Lang::get('ui.error.schedule.has_dependencies', [], 'en'));

    // Ingenting rördes: uppgiften ligger kvar på källan.
    expect($schema->fresh()->item_id)->toBe($item->id);

    // Och kastet bär KODEN, som är kontraktet mot /api (issue 247).
    $undantag = null;

    try {
        app(MoveSchedule::class)->handle($schema->fresh(), $mål, $anvandare);
    } catch (ApiException $e) {
        $undantag = $e;
    }

    expect($undantag)->not->toBeNull();
    expect($undantag->errorCode())->toBe('schedule.has_dependencies');
})->with([
    'schemat väntar på en motpart' => 'schema_vantar',
    'en motpart väntar på schemat' => 'schema_motpart',
    'förekomsten väntar på en motpart' => 'forekomst_vantar',
    'en motpart väntar på förekomsten' => 'forekomst_motpart',
]);

/*
 * Klart när: inom containern är beroenden tillåtna — de pekar på `id`, och
 * `id` står kvar när bara `item_id` byts (§ 6).
 */
it('tillåter flytt inom containern med beroenden', function () {
    [$konto, $anvandare, $container, $item] = flyttUppgiftKontext();
    $mål = flyttUppgiftItem($container, $konto, $anvandare, 'Reservdelen');

    $schema = flyttUppgiftSkapa($anvandare, $container, $item, flyttUppgiftKropp());
    $motpart = flyttUppgiftSkapa($anvandare, $container, $item, flyttUppgiftKropp('Serva motorn'));

    $beroende = ScheduleDependency::factory()->create([
        'schedule_id' => $schema->id,
        'depends_on_schedule_id' => $motpart->id,
    ]);

    flyttUppgiftPost($container, $item, $schema, $mål, $anvandare)
        ->assertSessionHas('status', 'schedule-moved');

    expect($schema->fresh()->item_id)->toBe($mål->id);
    expect(ScheduleDependency::query()->whereKey($beroende->id)->exists())->toBeTrue();
});

// --- Beslut 1: notiserna och kostnadsraderna --------------------------------

/*
 * Klart när: den öppna förekomstens oskickade notiser frigörs — raden står
 * kvar, men `dedupe_key` nollas, så nästa körning påminner på den nya
 * platsen (§ 6, issue 222).
 */
it('frigör notiserna för den öppna förekomsten', function () {
    [$konto, $anvandare, $container, $item] = flyttUppgiftKontext();
    $mål = flyttUppgiftItem($container, $konto, $anvandare, 'Reservdelen');

    $schema = flyttUppgiftSkapa($anvandare, $container, $item, flyttUppgiftKropp());
    $öppen = $schema->openOccurrence()->sole();

    $notis = app(CreateNotification::class)->handle(
        type: Notification::TYPE_TASK_DUE,
        account: $konto,
        user: $anvandare,
        container: $container,
        subject: $öppen,
        dedupeKey: 'task.due:'.$öppen->ulid.':'.$anvandare->ulid,
    );

    expect($notis->dedupe_key)->not->toBeNull();

    flyttUppgiftPost($container, $item, $schema, $mål, $anvandare)
        ->assertSessionHas('status', 'schedule-moved');

    // Nyckeln är nollad — och raden finns kvar: outboxen är händelseloggen.
    expect($notis->fresh()->dedupe_key)->toBeNull();
    expect(Notification::query()->whereKey($notis->id)->exists())->toBeTrue();
});

/*
 * Klart när: kostnadsraderna stannar på källan — de bokfördes där och hänger
 * på itemet, inte på schemat (§ 6).
 */
it('kostnadsraderna stannar på källan', function () {
    [$konto, $anvandare, $container, $item] = flyttUppgiftKontext('organisation');
    [, $mål] = flyttUppgiftMål($anvandare);

    $schema = flyttUppgiftSkapa($anvandare, $container, $item, flyttUppgiftKropp());

    $kostnad = CostEntry::factory()->create([
        'item_id' => $item->id,
        'container_id' => $container->id,
    ]);

    flyttUppgiftPost($container, $item, $schema, $mål, $anvandare)
        ->assertSessionHas('status', 'schedule-moved');

    expect($schema->fresh()->item_id)->toBe($mål->id);
    expect($kostnad->fresh()->item_id)->toBe($item->id);
    expect($kostnad->fresh()->container_id)->toBe($container->id);
});

// --- Beslut 2: grindarna ----------------------------------------------------

/*
 * Klart när: `delete` på källan krävs — en write-mottagare får lägga till men
 * inte flytta bort, för en flytt tar bort något därifrån (§ 2).
 */
it('kräver delete på källan', function () {
    [$konto, $agare, $container, $item] = flyttUppgiftKontext('organisation');
    $mål = flyttUppgiftItem($container, $konto, $agare, 'Reservdelen');

    $schema = flyttUppgiftSkapa($agare, $container, $item, flyttUppgiftKropp());

    // En write-mottagare på källan: `create` < `write` < `delete` i laddern,
    // så hon får skapa i containern men inte flytta bort (§ 2).
    [$mottagareKonto, $mottagare] = flyttUppgiftKonto();
    beviljaAccess($container, $mottagare, 'write', 'guest');

    flyttUppgiftPost($container, $item, $schema, $mål, $mottagare)->assertForbidden();

    expect($schema->fresh()->item_id)->toBe($item->id);
});

/*
 * Klart när: `create` på målet krävs — ägaren får flytta bort, men målet
 * ligger i en främmande container där hon bara får LÄSA (§ 2).
 */
it('kräver create på målet', function () {
    [$konto, $agare, $container, $item] = flyttUppgiftKontext();
    $schema = flyttUppgiftSkapa($agare, $container, $item, flyttUppgiftKropp());

    [$frammandeKonto, $frammandeAgare] = flyttUppgiftKonto('organisation');
    $frammande = Container::factory()->for($frammandeKonto, 'account')->create(['name' => 'Kunden Syd']);
    $mål = flyttUppgiftItem($frammande, $frammandeKonto, $frammandeAgare, 'Pumpen');
    beviljaAccess($frammande, $agare, 'read', 'guest');

    flyttUppgiftPost($container, $item, $schema, $mål, $agare)->assertForbidden();

    expect($schema->fresh()->item_id)->toBe($item->id);
});

// --- Beslut 2 och 3: svaret och loggen --------------------------------------

/*
 * Klart när: svaret är en redirect till schemats sida på det NYA itemet — inte
 * tillbaka till källan, där uppgiften inte längre finns. Flashkoden är
 * `schedule-moved`.
 */
it('redirectar till schemats sida på det nya itemet', function () {
    [$konto, $anvandare, $container, $item] = flyttUppgiftKontext('organisation');
    [$annanContainer, $mål] = flyttUppgiftMål($anvandare);

    $schema = flyttUppgiftSkapa($anvandare, $container, $item, flyttUppgiftKropp());

    flyttUppgiftPost($container, $item, $schema, $mål, $anvandare)
        ->assertRedirect(route('containers.items.schedules.show', [$annanContainer, $mål, $schema]))
        ->assertSessionHas('status', 'schedule-moved');
});

/*
 * Klart när: flytten mellan containrar skriver två rader — en i vardera
 * loggen — och ingen av dem nämner motparten (§ 7).
 */
it('loggarna nämner inte den andra containern', function () {
    [, $aAnvandare, $aContainer, $aItem] = flyttUppgiftKontext('organisation');
    [$bContainer, $bItem] = flyttUppgiftMål($aAnvandare);

    $schema = flyttUppgiftSkapa($aAnvandare, $aContainer, $aItem, flyttUppgiftKropp());

    flyttUppgiftPost($aContainer, $aItem, $schema, $bItem, $aAnvandare)
        ->assertSessionHas('status', 'schedule-moved');

    // Två rader, en i vardera loggen (§ 7).
    $rader = AuditLog::query()
        ->where('action', AuditLog::ACTION_SCHEDULE_MOVED)
        ->orderBy('id')
        ->get();

    expect($rader)->toHaveCount(2);

    $ut = $rader->firstWhere('container_id', $aContainer->id);
    $in = $rader->firstWhere('container_id', $bContainer->id);

    expect($ut)->not->toBeNull();
    expect($in)->not->toBeNull();

    // `direction` är det enda som skiljer raderna åt — ingen ULID och inget
    // namn för motparten (§ 7).
    expect($ut->meta)->toBe(['direction' => 'out']);
    expect($in->meta)->toBe(['direction' => 'in']);

    // Raden hör till sitt eget items container, och SUBJEKTET är schemat —
    // aldrig motpartens item.
    expect($ut->item_id)->toBe($aItem->id);
    expect($in->item_id)->toBe($bItem->id);
    expect($ut->subject_id)->toBe($schema->ulid);
    expect($in->subject_id)->toBe($schema->ulid);

    foreach ([$ut, $in] as $rad) {
        $text = json_encode($rad->meta).$rad->subject_id;

        expect($text)->not->toContain($aContainer->ulid);
        expect($text)->not->toContain($bContainer->ulid);
        expect($text)->not->toContain('Varvet Norr');
        expect($text)->not->toContain('Kunden Syd');
    }
});
