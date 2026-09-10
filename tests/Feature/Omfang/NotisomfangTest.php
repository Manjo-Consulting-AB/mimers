<?php

use App\Actions\Access\ResolveItemScope;
use App\Console\GeneratesLoanNotifications;
use App\Console\GeneratesQuotaWarnings;
use App\Console\GeneratesTaskNotifications;
use App\Console\SendsWeeklyDigest;
use App\Mail\WeeklyDigestMail;
use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\Loan;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\Plan;
use App\Models\ScheduleOccurrence;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use App\Support\Access\AccessLevel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/*
 * Issue 75 · Notisgeneratorerna respekterar omfång. Se
 * App\Console\GeneratesTaskNotifications, App\Console\GeneratesLoanNotifications,
 * App\Console\GeneratesQuotaWarnings, App\Console\SendsWeeklyDigest,
 * [[Notiser]] § Grundprincipen och [[ADR-0028 Åtkomst på itemnivå]] §
 * Konsekvenser ("Notisgeneratorerna — en omfångsbegränsad mottagare får inte
 * notiser om items hon inte når").
 *
 * VIKTIGT OM VAD TESTERNA VISAR. Uppgifts- och utlåningsnotiserna har redan
 * TVÅ spärrar i samma riktning: omfångsfiltret i scopeTodoFor() (issue 74)
 * och 34b:s kontogrind — notifyForUser() loopar `$user->accounts` och
 * begränsar förekomsterna till containers som de kontona ÄGER. Den som äger
 * containern når hela den (ResolveItemScope regel 1), så en omfångsbegränsad
 * mottagare når aldrig ens generatorernas fråga. Det syns i Klart när-punkt 1
 * och 3: de kan inte uppfyllas utan att kontogrinden rivs, och det finns ett
 * befintligt test ("en gäst med delegerad åtkomst får ingen uppgiftsnotis")
 * som håller den på plats. Issuens Frågor och antaganden tar upp motsägelsen.
 *
 * Fixturen är omfångsupplösningens (issue 70):
 *
 *   Vindil (ägarkontot)
 *   ├── Motorn ── Impellern
 *   └── Masten
 *
 * Hjälparna är namnrymda (`notisomfang*`) för att inte krocka med de andra
 * Omfang-filerna — Pest delar global namnrymd mellan testfilerna.
 * kontoMedMedlem() och oppnaForekomst() är globala i
 * tests/Support/Testhjalpare.php.
 */

beforeEach(function () {
    // Förekomsterna jämför visible_from och due_at mot DAGENS datum, och
    // utlåningsfönstret är due_at <= idag + 3 dagar (76 § Beslut 1). Utan en
    // fryst tid beror utfallet på klockslaget när sviten körs.
    Carbon::setTestNow('2026-09-02 10:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Ägarkontot, ägaren och containern Vindil med delarna i ordningen
 * [$account, $ägare, $container, $motor, $mast, $impeller]. Motorn är
 * förälder till impellern; masten är ett syskon till motorn och ligger
 * utanför en grant på motorn.
 *
 * @return array{0: Account, 1: User, 2: Container, 3: Item, 4: Item, 5: Item}
 */
function notisomfangBas(): array
{
    [$account, $ägare] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create(['name' => 'Vindil']);

    $item = fn (string $namn): Item => Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => $ägare->id,
        'created_by_account_id' => $account->id,
    ]);

    $motor = $item('Motorn');
    $mast = $item('Masten');
    $impeller = $item('Impellern');

    // from = förälder, to = barn — samma kanoniska riktning som LinkItems.
    notisomfangKant($motor, $impeller);

    return [$account, $ägare, $container, $motor, $mast, $impeller];
}

/**
 * En `parent`-kant skriven DIREKT i tabellen, förbi LinkItems.
 */
function notisomfangKant(Item $förälder, Item $barn): void
{
    ItemLink::query()->insert([
        'from_item_id' => $förälder->id,
        'to_item_id' => $barn->id,
        'relation' => 'parent',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * En mottagare UTANFÖR ägarkontot, med ett eget konto (så hon finns i
 * generatorernas användarloop) och en grant på $item — item-bred när $item
 * ges, container-bred annars.
 */
function notisomfangMottagare(Container $container, ?Item $item, string $nivå = 'read', string $kind = 'guest'): User
{
    [, $user] = kontoMedMedlem();

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item?->id,
        'grantee_type' => 'user',
        'grantee_id' => $user->id,
        'level' => $nivå,
        'kind' => $kind,
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    return $user;
}

/**
 * En öppen förekomst på itemet — skapad genom OpenNextOccurrence, den enda
 * vägen in i schedule_occurrence också i produktionen.
 */
function notisomfangUppgift(Item $item, string $titel, string $due): ScheduleOccurrence
{
    [, $occurrence] = oppnaForekomst($item, [
        'title' => $titel,
        'anchor_date' => $due,
        'lead_days' => 0,
    ]);

    return $occurrence;
}

/**
 * Antalet frågor $anrop ställer. Memon i ResolveItemScope är per request i
 * drift men överlever mellan anropen i sviten, så den töms inför varje
 * mätning — annars mättes cachen, inte frågekostnaden. Samma mönster som
 * ListningsfilterTest.
 */
function notisomfangFrågor(Closure $anrop): int
{
    app(ResolveItemScope::class)->flush();

    $frågor = 0;
    DB::listen(function () use (&$frågor): void {
        $frågor++;
    });

    $anrop();

    return $frågor;
}

// --- Uppgiftsnotiserna -----------------------------------------------------

it('en omfångsbegränsad mottagare får ingen uppgiftsnotis — varken om itemet hon når eller om ett syskon', function () {
    [, $ägare, $container, $motor, $mast] = notisomfangBas();
    $mottagare = notisomfangMottagare($container, $motor);

    notisomfangUppgift($motor, 'Byt impeller', '2026-09-02');
    notisomfangUppgift($mast, 'Kontrollera riggen', '2026-09-02');

    app(GeneratesTaskNotifications::class)->handle();

    // Omfångsfiltret i scopeTodoFor() (issue 74) ger henne motorn och inte
    // masten — men hon når inte ens generatorns fråga: 34b:s kontogrind
    // begränsar förekomsterna till containers hennes EGET konto äger. Båda
    // spärrarna pekar åt samma håll, och noll rader är svaret.
    expect(Notification::query()->where('user_id', $mottagare->id)->count())->toBe(0);

    // Ägarkontots medlem når hela containern (regel 1) och får båda.
    $ägarens = Notification::query()->where('user_id', $ägare->id)->pluck('payload');
    expect($ägarens)->toHaveCount(2);
    expect($ägarens->pluck('item')->sort()->values()->all())->toBe(['Masten', 'Motorn']);
});

it('ägarkontots medlemmar och en container-bred mottagare får samma notiser som före issuen', function () {
    [$ägarkonto, $ägare, $container, $motor, $mast, $impeller] = notisomfangBas();

    $medlem = User::factory()->create();
    $ägarkonto->users()->attach($medlem, ['role' => 'member']);

    // En container-bred grant UTANFÖR ägarkontot — samma läge som 34b:s
    // gästtest. Kontogrinden ger henne inga påminnelser, och den här issuen
    // rör inte det beslutet.
    $gäst = notisomfangMottagare($container, null);

    notisomfangUppgift($motor, 'Byt impeller', '2026-09-02');
    notisomfangUppgift($mast, 'Kontrollera riggen', '2026-09-02');
    notisomfangUppgift($impeller, 'Smörj lagret', '2026-09-02');

    app(GeneratesTaskNotifications::class)->handle();

    // Ägaren och medlemmen når hela containern och får alla tre — inklusive
    // ättlingen, som följer med den container-breda nivån.
    foreach ([$ägare, $medlem] as $mottagare) {
        $rader = Notification::query()->where('user_id', $mottagare->id)->pluck('payload');
        expect($rader)->toHaveCount(3);
        expect($rader->pluck('item')->sort()->values()->all())
            ->toBe(['Impellern', 'Masten', 'Motorn']);
    }

    expect(Notification::query()->where('user_id', $gäst->id)->count())->toBe(0);
});

it('ingen notisrads payload nämner ett item utanför mottagarens omfång', function () {
    [, $ägare, $container, $motor, $mast] = notisomfangBas();
    $mottagare = notisomfangMottagare($container, $motor);

    notisomfangUppgift($motor, 'Byt impeller', '2026-09-02');
    notisomfangUppgift($mast, 'Kontrollera riggen', '2026-09-02');

    app(GeneratesTaskNotifications::class)->handle();

    // Kvittot på att URVALET är rätt: payloaden bär itemets namn med flit
    // (Beslut 6) och maskeras inte. Varje skapad rads namn måste därför
    // ligga inom mottagarens omfång — det är den kontrollen som fångar en
    // framtida generator som börjar skicka till en omfångsbegränsad mottagare
    // utan att filtrera.
    $resolver = app(ResolveItemScope::class);

    foreach (Notification::query()->get() as $notis) {
        $namn = $notis->payload['item'];
        $item = Item::query()
            ->where('container_id', $notis->container_id)
            ->where('name', $namn)
            ->firstOrFail();

        $omfång = $resolver->handle(
            User::query()->findOrFail($notis->user_id),
            Container::query()->findOrFail($notis->container_id),
        );

        expect($omfång->allows($item->id, AccessLevel::READ))->toBeTrue(
            "Notisen till användare {$notis->user_id} nämner {$namn}, som ligger utanför mottagarens omfång."
        );
    }

    // Bara ägaren får rader i den här fixturen: mottagaren når motorn men
    // aldrig generatorns fråga.
    expect(Notification::query()->count())->toBe(2);
    expect(Notification::query()->where('user_id', $mottagare->id)->exists())->toBeFalse();
});

it('dedupe-nycklarna är oförändrade — en andra körning samma natt skapar inga nya rader', function () {
    [, , $container, $motor] = notisomfangBas();
    notisomfangMottagare($container, $motor);
    notisomfangUppgift($motor, 'Byt impeller', '2026-09-02');

    app(GeneratesTaskNotifications::class)->handle();
    $efterFörsta = Notification::query()->pluck('dedupe_key')->sort()->values()->all();

    app(GeneratesTaskNotifications::class)->handle();

    expect(Notification::query()->count())->toBe(1);
    expect(Notification::query()->pluck('dedupe_key')->sort()->values()->all())->toBe($efterFörsta);
});

// --- Utlåningsnotiserna (Beslut 4: undersökning, ingen ändring) ------------

it('en omfångsbegränsad mottagare får ingen loan.due för ett lån i containern', function () {
    [$ägarkonto, $ägare, $container, $motor] = notisomfangBas();

    // loan_reminders kräver Pro (76 § Beslut 6).
    Subscription::factory()
        ->for($ägarkonto, 'account')
        ->for(Plan::query()->where('code', 'pro')->firstOrFail(), 'plan')
        ->create();

    $mottagare = notisomfangMottagare($container, $motor);

    Loan::factory()->for($motor, 'item')->create([
        'borrower_name' => 'Anna Andersson',
        'due_at' => '2026-09-05',
        'returned_at' => null,
    ]);

    app(GeneratesLoanNotifications::class)->handle();

    // Beslut 4:s undersökning: notifyForUser() loopar `$user->accounts` och
    // notifyLoansForAccount() frågar på containers vars account_id är
    // kontots. En delegerad mottagare, vars åtkomst kommer via
    // container_access och inte via ägarskap, träffas aldrig av den frågan —
    // ingen ändring behövdes, och det här testet är hela leveransen.
    expect(Notification::query()->where('user_id', $mottagare->id)->count())->toBe(0);
    expect(Notification::query()->where('user_id', $ägare->id)->count())->toBe(1);
});

// --- Kvotvarningarna (Beslut 3: kontonivå, ingen ändring) -----------------

it('en omfångsbegränsad mottagare får ingen kvotvarning', function () {
    [$ägarkonto, $ägare, $container, $motor] = notisomfangBas();
    $mottagare = notisomfangMottagare($container, $motor);

    $gräns = $ägarkonto->planLimit('storage_bytes');
    UsageCounter::factory()->create([
        'account_id' => $ägarkonto->id,
        'storage_bytes' => (int) ($gräns * 0.9),
    ]);

    app(GeneratesQuotaWarnings::class)->handle();

    // Kvoten är kontots, inte pärmens (Beslut 3): mottagarna är ägarkontots
    // owner/admin, och en delegerad mottagare är per definition inte medlem
    // i det kontot. Ingen rad till henne, oavsett omfång.
    expect(Notification::query()->where('user_id', $mottagare->id)->count())->toBe(0);

    $notis = Notification::query()->firstOrFail();
    expect($notis->type)->toBe(Notification::TYPE_QUOTA_WARNING);
    expect($notis->user_id)->toBe($ägare->id);
});

// --- Veckosammanfattningen (Beslut 5: följdverkan, verifieras) ------------

it('veckosammanfattningen skickas inte alls till en mottagare vars omfång saknar händelser', function () {
    Mail::fake();
    [, $ägare, $container, $motor, $mast] = notisomfangBas();
    $mottagare = notisomfangMottagare($container, $motor);

    // Bara det DOLDA itemet har en händelse: mottagarens omfång är tomt.
    notisomfangUppgift($mast, 'Kontrollera riggen', '2026-09-02');

    app(GeneratesTaskNotifications::class)->handle();

    // Omfånget saknar händelser → generatorn skapar ingen rad → ingen
    // väntande digest-leverans → userIdsWithPendingDigest() tar inte med
    // henne. Det är följdverkan Beslut 5 beskriver.
    expect(Notification::query()->where('user_id', $mottagare->id)->count())->toBe(0);
    expect(NotificationDelivery::query()
        ->whereHas('notification', fn ($query) => $query->where('user_id', $mottagare->id))
        ->count())->toBe(0);

    app(SendsWeeklyDigest::class)->handle();

    // Ägaren får sin sammanfattning; mottagaren får ingenting — inte ett
    // tomt mejl som säger "inget hände".
    Mail::assertSent(WeeklyDigestMail::class, 1);
    expect(Mail::sent(WeeklyDigestMail::class)->first()->hasTo($ägare->email))->toBeTrue();
    Mail::assertNotSent(
        WeeklyDigestMail::class,
        fn (WeeklyDigestMail $mail): bool => $mail->hasTo($mottagare->email),
    );
});

it('veckosammanfattningen listar ägarens poster och når inte den omfångsbegränsade mottagaren', function () {
    Mail::fake();
    [, $ägare, $container, $motor, $mast] = notisomfangBas();
    $mottagare = notisomfangMottagare($container, $motor);

    notisomfangUppgift($motor, 'Byt impeller', '2026-09-02');
    notisomfangUppgift($mast, 'Kontrollera riggen', '2026-09-02');

    app(GeneratesTaskNotifications::class)->handle();
    app(SendsWeeklyDigest::class)->handle();

    // Ägaren når hela containern: båda posterna i ETT mejl. Den
    // omfångsbegränsade mottagaren får inget alls — hennes omfång kan inte
    // bära en enda händelse, se filens huvudkommentar. Att hon skulle få ett
    // mejl med bara sina egna poster är Klart när-punkt 9, och den kan inte
    // uppfyllas: se huvudkommentaren och PR:ens Frågor och antaganden.
    $mejl = Mail::sent(WeeklyDigestMail::class)->first();
    expect($mejl)->not->toBeNull();
    expect($mejl->hasTo($ägare->email))->toBeTrue();
    expect(count($mejl->items))->toBe(2);

    $html = $mejl->render();
    expect($html)->toContain('Byt impeller');
    expect($html)->toContain('Kontrollera riggen');

    Mail::assertNotSent(
        WeeklyDigestMail::class,
        fn (WeeklyDigestMail $mail): bool => $mail->hasTo($mottagare->email),
    );
});

// --- Memon och frågekostnaden (Beslut 2) ----------------------------------

it('en körning över femtio användare lämnar memon i ResolveItemScope tömd', function () {
    // Femtio mottagare med en förekomst var — samma form som produktionens
    // nattliga svep, i en enda chunk.
    $första = null;

    for ($i = 0; $i < 50; $i++) {
        [$konto, $ägare] = kontoMedMedlem();
        $container = Container::factory()->for($konto, 'account')->create();
        $item = Item::factory()->for($container, 'container')->create([
            'name' => 'Del '.$i,
            'created_by_user_id' => $ägare->id,
            'created_by_account_id' => $konto->id,
        ]);
        notisomfangUppgift($item, 'Serva', '2026-09-02');

        $första ??= [$ägare, $container];
    }

    // Värm memon på den instans generatorn får injicerad ur containern.
    app(ResolveItemScope::class)->handle($första[0], $första[1]);

    app(GeneratesTaskNotifications::class)->handle();

    // Efter körningen ska memon vara tom: en ny upplösning är en cachemiss
    // och kostar frågor. Utan flush() per användare hade den varit kvar och
    // kostat noll — memon bär annars varje användares omfång i varje
    // container hen når, samtidigt, resten av natten.
    $frågor = 0;
    DB::listen(function () use (&$frågor): void {
        $frågor++;
    });

    app(ResolveItemScope::class)->handle($första[0], $första[1]);

    expect($frågor)->toBeGreaterThan(0);
});

it('kostar ett konstant antal frågor per användare, oavsett hur många containers och items hon når', function () {
    [$konto, $ägare] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'name' => 'Motorn',
        'created_by_user_id' => $ägare->id,
        'created_by_account_id' => $konto->id,
    ]);
    notisomfangUppgift($item, 'Byt impeller', '2026-09-02');

    // Värmningen skapar notisraden. Båda mätningarna träffar sedan
    // dedupe-nyckeln och gör exakt samma arbete — annars hade den andra
    // körningen varit billigare bara för att raden redan fanns.
    app(GeneratesTaskNotifications::class)->handle();

    $frågorFörEn = notisomfangFrågor(fn () => app(GeneratesTaskNotifications::class)->handle());

    // Tio containers till med tio items var — fortfarande EN förekomst, så
    // användaren når 111 items i stället för 1. Upplösningen ska kosta
    // lika många frågor: det är kravet, och skälet till att nattjobbet inte
    // växer med kundstocken (issue 70 § Beslut 2).
    Container::factory()->for($konto, 'account')->count(10)->create()
        ->each(function (Container $ny) use ($ägare, $konto): void {
            Item::factory()->for($ny, 'container')->count(10)->create([
                'name' => 'Del',
                'created_by_user_id' => $ägare->id,
                'created_by_account_id' => $konto->id,
            ]);
        });

    $frågorFörMånga = notisomfangFrågor(fn () => app(GeneratesTaskNotifications::class)->handle());

    expect($frågorFörMånga)->toBe($frågorFörEn);
});
