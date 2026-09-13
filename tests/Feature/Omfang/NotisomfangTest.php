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
 * VIKTIGT OM VAD TESTERNA VISAR. Uppgiftsnotiserna har två spärrar.
 * scopeTodoFor() (issue 74) begränsar förekomsterna till de items mottagaren
 * når. Ovanpå det ligger en NIVÅGRIND (issue 75 § Beslut 8): notisen går
 * bara till den som kan bocka av uppgiften, alltså `AccessLevel::WRITE` —
 * `complete()`/`skip()` går via ItemPolicy::update(). Den som bara har
 * `read` ser uppgiften i todo-listan men kan aldrig stänga den.
 *
 * Grinden är en nivå och inte en kontogrind, så mottagargrupperna blir:
 * ägarkontots medlemmar (regel 1 ger `unrestricted(DELETE)`, som klarar
 * `write`) — oförändrat; container-bred gäst på `write`/`delete` — ny väg in
 * (34b:s Beslut 4 mjukas upp); container-bred gäst på `read`/`create` och
 * itemgrant på `read`/`create` — inget; itemgrant på `write`/`delete` — hon
 * får notiser om sitt item OCH dess ättlingar, eftersom nivån ärvs genom
 * closeOverDescendants(), men aldrig om ett syskon.
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

it('en omfångsbegränsad mottagare får en uppgiftsnotis om itemet hon når, men ingen om ett syskon', function () {
    [, $ägare, $container, $motor, $mast] = notisomfangBas();
    $mottagare = notisomfangMottagare($container, $motor, 'write');

    notisomfangUppgift($motor, 'Byt impeller', '2026-09-02');
    notisomfangUppgift($mast, 'Kontrollera riggen', '2026-09-02');

    app(GeneratesTaskNotifications::class)->handle();

    // Omfångsfiltret i scopeTodoFor() (issue 74) ger henne motorn och inte
    // masten, och hennes itemgrant på `write` klarar nivågrinden (Beslut 8).
    $hennes = Notification::query()->where('user_id', $mottagare->id)->pluck('payload');
    expect($hennes)->toHaveCount(1);
    expect($hennes->first()['item'])->toBe('Motorn');

    // Ägarkontots medlem når hela containern (regel 1) och får båda.
    $ägarens = Notification::query()->where('user_id', $ägare->id)->pluck('payload');
    expect($ägarens)->toHaveCount(2);
    expect($ägarens->pluck('item')->sort()->values()->all())->toBe(['Masten', 'Motorn']);
});

it('en mottagare med bara läsrätt på itemet får ingen uppgiftsnotis alls', function () {
    [, $ägare, $container, $motor] = notisomfangBas();
    $mottagare = notisomfangMottagare($container, $motor);

    notisomfangUppgift($motor, 'Byt impeller', '2026-09-02');

    app(GeneratesTaskNotifications::class)->handle();

    // Klart när-punkt 1–3 i issue 280: en mottagare med bara `read` på ett
    // item ser uppgiften i todo-listan men kan aldrig bocka av den —
    // complete()/skip() går via ItemPolicy::update(). Nivågrinden håller
    // henne utanför, och `read` räcker alltså inte längre.
    expect(Notification::query()->where('user_id', $mottagare->id)->count())->toBe(0);

    // Ägaren är oförändrad: regel 1 ger `unrestricted(DELETE)`.
    expect(Notification::query()->where('user_id', $ägare->id)->count())->toBe(1);
});

it('en mottagare med skrivrätt på ett item får notis även om itemets ättling', function () {
    [, , $container, $motor, $mast, $impeller] = notisomfangBas();
    $mottagare = notisomfangMottagare($container, $motor, 'write');

    // Impellern hänger under motorn, masten är ett syskon till den.
    notisomfangUppgift($impeller, 'Smörj lagret', '2026-09-02');
    notisomfangUppgift($mast, 'Kontrollera riggen', '2026-09-02');

    app(GeneratesTaskNotifications::class)->handle();

    // Nivån ärvs NEDÅT genom closeOverDescendants() (ADR-0028 regel 3), så
    // granten på motorn bär `write` hela vägen till impellern. Masten är
    // varken itemet eller en ättling och faller utanför.
    $hennes = Notification::query()->where('user_id', $mottagare->id)->pluck('payload');
    expect($hennes)->toHaveCount(1);
    expect($hennes->first()['item'])->toBe('Impellern');
});

it('ägarkontots medlemmar och en container-bred mottagare får samma notiser som före issuen', function () {
    [$ägarkonto, $ägare, $container, $motor, $mast, $impeller] = notisomfangBas();

    $medlem = User::factory()->create();
    $ägarkonto->users()->attach($medlem, ['role' => 'member']);

    // En container-bred grant UTANFÖR ägarkontot — samma läge som 34b:s
    // gästtest. Hennes nivå är `read`, och nivågrinden (Beslut 8) håller
    // henne utanför: hon ser uppgifterna i todo-listan men kan inte bocka av
    // dem. Punkten är oförändrad i sak sedan 34b.
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

it('en container-bred gäst med skrivrätt får uppgiftsnotiser', function () {
    [, , $container, $motor, $mast] = notisomfangBas();

    // Samma gäst som ovan, men på `write`. Det är den avsiktliga
    // uppmjukningen av 34b § Beslut 4 (issue 280 § Beslut 8): hon kan bocka
    // av uppgifterna, alltså ska hon få veta att de förfaller.
    $gäst = notisomfangMottagare($container, null, 'write');

    notisomfangUppgift($motor, 'Byt impeller', '2026-09-02');
    notisomfangUppgift($mast, 'Kontrollera riggen', '2026-09-02');

    app(GeneratesTaskNotifications::class)->handle();

    $hennes = Notification::query()->where('user_id', $gäst->id)->pluck('payload');
    expect($hennes)->toHaveCount(2);
    expect($hennes->pluck('item')->sort()->values()->all())->toBe(['Masten', 'Motorn']);
});

it('ingen notisrads payload nämner ett item utanför mottagarens omfång', function () {
    [, $ägare, $container, $motor, $mast] = notisomfangBas();
    $mottagare = notisomfangMottagare($container, $motor, 'write');

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

    // Ägaren får båda (regel 1); mottagaren får bara motorn — kontrollen
    // ovan är kvittot på att ingen av raderna nämner masten för henne.
    expect(Notification::query()->count())->toBe(3);
    expect(Notification::query()->where('user_id', $mottagare->id)->count())->toBe(1);
});

it('dedupe-nycklarna är oförändrade — en andra körning samma natt skapar inga nya rader', function () {
    [, , $container, $motor] = notisomfangBas();
    notisomfangMottagare($container, $motor, 'write');
    notisomfangUppgift($motor, 'Byt impeller', '2026-09-02');

    app(GeneratesTaskNotifications::class)->handle();
    // Både ägaren (regel 1) och mottagaren (itemgranten på motorn) får en
    // rad var för samma förekomst.
    $efterFörsta = Notification::query()->pluck('dedupe_key')->sort()->values()->all();
    expect($efterFörsta)->toHaveCount(2);

    app(GeneratesTaskNotifications::class)->handle();

    expect(Notification::query()->count())->toBe(2);
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

it('veckosammanfattningen till en omfångsbegränsad mottagare listar bara hennes egna poster', function () {
    Mail::fake();
    [, $ägare, $container, $motor, $mast] = notisomfangBas();
    $mottagare = notisomfangMottagare($container, $motor, 'write');

    notisomfangUppgift($motor, 'Byt impeller', '2026-09-02');
    notisomfangUppgift($mast, 'Kontrollera riggen', '2026-09-02');

    app(GeneratesTaskNotifications::class)->handle();
    app(SendsWeeklyDigest::class)->handle();

    // Ägaren når hela containern: båda posterna i ETT mejl.
    $ägarensMejl = Mail::sent(WeeklyDigestMail::class)
        ->first(fn (WeeklyDigestMail $mail): bool => $mail->hasTo($ägare->email));
    expect($ägarensMejl)->not->toBeNull();
    expect(count($ägarensMejl->items))->toBe(2);

    $ägarensHtml = $ägarensMejl->render();
    expect($ägarensHtml)->toContain('Byt impeller');
    expect($ägarensHtml)->toContain('Kontrollera riggen');

    // Den omfångsbegränsade mottagaren når bara motorn: hennes mejl nämner
    // den posten och inte syskonets (Klart när-punkt 9).
    $hennesMejl = Mail::sent(WeeklyDigestMail::class)
        ->first(fn (WeeklyDigestMail $mail): bool => $mail->hasTo($mottagare->email));
    expect($hennesMejl)->not->toBeNull();
    expect(count($hennesMejl->items))->toBe(1);

    $hennesHtml = $hennesMejl->render();
    expect($hennesHtml)->toContain('Byt impeller');
    expect($hennesHtml)->not->toContain('Kontrollera riggen');
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

    // Gästen har skrivrätt på basitemet, alltså passerar hon nivågrinden
    // (Beslut 8) och får en notisrad. Det är den enda notisraden i testet.
    $gäst = notisomfangMottagare($container, $item, 'write');

    // Värmningen skapar notisraden. Båda mätningarna träffar sedan
    // dedupe-nyckeln och gör exakt samma arbete — annars hade den andra
    // körningen varit billigare bara för att raden redan fanns.
    app(GeneratesTaskNotifications::class)->handle();

    $frågorFörEn = notisomfangFrågor(fn () => app(GeneratesTaskNotifications::class)->handle());

    // Tio containers till, var och en med en ÖPPEN förekomst som gästen NÅR:
    // hon har en itemgrant på `read` i dem, så scopeTodoFor() tar med dem och
    // deras container-id:n når forContainers() i notifyForUser(). Det är
    // skillnaden mot före den här ändringen — tio containers UTAN förekomster
    // kom aldrig fram till grinden, och testet kunde därför inte fälla att
    // omfånget löstes en gång PER FÖREKOMST i stället för i ett svep. Med
    // förekomster växte frågeantalet med fyra per container; med batchningen
    // står det still, och det är den mätningen som är hela testet.
    //
    // Nivågrinden stoppar dem samtidigt — `read` räcker inte (Beslut 8) — så
    // ingen notisrad tillkommer och frågeantalet mäter upplösningen och
    // ingenting annat. Kontot är ägarlöst med flit: en ägare hade fått tio
    // notisrader och grumlat mätningen.
    $utanÄgare = Account::factory()->create();

    Container::factory()->for($utanÄgare, 'account')->count(10)->create()
        ->each(function (Container $ny) use ($gäst, $utanÄgare): void {
            $del = Item::factory()->for($ny, 'container')->create([
                'name' => 'Del',
                'created_by_user_id' => $gäst->id,
                'created_by_account_id' => $utanÄgare->id,
            ]);

            notisomfangUppgift($del, 'Serva', '2026-09-02');

            ContainerAccess::factory()->create([
                'container_id' => $ny->id,
                'item_id' => $del->id,
                'grantee_type' => 'user',
                'grantee_id' => $gäst->id,
                'level' => 'read',
                'kind' => 'guest',
                'granted_by_user_id' => $gäst->id,
            ]);
        });

    // Kvittot på att de elva förekomsterna verkligen når grinden — annars vore
    // mätningen tyst tom den dag åtkomsten slutar lösas ut.
    expect(ScheduleOccurrence::query()
        ->todoFor($gäst, $gäst->accounts->pluck('id')->values()->all())
        ->count())->toBe(11);

    $frågorFörMånga = notisomfangFrågor(fn () => app(GeneratesTaskNotifications::class)->handle());

    expect($frågorFörMånga)->toBe($frågorFörEn);
    expect(Notification::query()->where('user_id', $gäst->id)->count())->toBe(1);
});
