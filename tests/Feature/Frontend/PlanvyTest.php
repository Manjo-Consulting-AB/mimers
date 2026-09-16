<?php

use App\Actions\Plan\ReadPlanUsage;
use App\Actions\Plan\StartDowngrade;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\Item;
use App\Models\Plan;
use App\Models\StoredFile;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\withoutVite;

/*
 * Issue 66a · Plansidan — planen, förbrukningen mot gränserna och
 * nedgraderingens pris, se App\Http\Controllers\Settings\PlanController,
 * App\Actions\Plan\ReadPlanUsage, resources/js/pages/Settings/Plan.vue,
 * [[Planer och kvoter]] och [[ADR-0009 Kvoter och livscykel]].
 *
 * Filen prövar SIDAN ovanpå rättighetslagret. Gränserna, kontrollpunkterna och
 * räknaren prövas av tests/Feature/Plan/PlanTest.php (25),
 * tests/Feature/Kvot/* (26a/26b, 27a/27b, 28) och tests/Feature/Frontend/
 * TakgransTest.php (53a) — alla gröna utan en enda ändrad förväntan.
 *
 * "Klart när" i issuen motsvaras var sitt test nedan, med undantag för "ingen
 * svensk sträng står kvar i en .vue-fil; varje ny nyckel finns på sv och en",
 * som vaktas av tests/Feature/Frontend/SprakTest.php — den läser varje fil
 * under resources/js/ och jämför språkfilerna nyckel för nyckel.
 *
 * Hjälparna har prefixet `planvy` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs, och tests/Feature/Plan har redan
 * `planKonto`, `planMedlem` och liknande.
 */

/**
 * Ett konto och en medlem i angiven roll. Free-planen kräver ingen fixture —
 * planraderna kommer ur migrationen (issue 25 § Beslut 2) — och är
 * utgångsläget för gränstesterna.
 *
 * @return array{0: Account, 1: User}
 */
function planvyKonto(string $roll = 'owner'): array
{
    $konto = Account::factory()->create();
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => $roll]);

    return [$konto, $anvandare];
}

/**
 * Samma konto, men på Pro — 25 GB utrymme, obegränsat antal pärmar och
 * funktionerna på (issue 25 § Beslut 2).
 */
function planvyPro(Account $konto): Account
{
    Subscription::factory()
        ->for($konto)
        ->for(Plan::where('code', 'pro')->firstOrFail())
        ->create();

    return $konto;
}

/**
 * Räknarraden för ett konto, satt direkt: sidan läser `usage_counter` och
 * ingenting annat (Beslut 8), så testerna måste kunna styra talet utan att
 * gå genom en uppladdning.
 */
function planvyRaknare(Account $konto, int $storageBytes, int $containers = 0): void
{
    UsageCounter::factory()->create([
        'account_id' => $konto->id,
        'storage_bytes' => $storageBytes,
        'container_count' => $containers,
    ]);
}

/**
 * En bilaga på ett item i kontots pärm, med en stored_file av exakt storlek
 * och en given skapartid — förhandsvisningen sorterar på `created_at`
 * fallande, så testerna måste kunna styra ordningen.
 */
function planvyBilaga(Account $konto, User $anvandare, int $byteSize, Carbon $skapad): Attachment
{
    $container = Container::factory()->for($konto, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $konto->id,
    ]);

    $storedFile = StoredFile::factory()->create(['byte_size' => $byteSize]);

    return Attachment::factory()->for($item, 'item')->create([
        'stored_file_id' => $storedFile->id,
        'uploaded_by_user_id' => $anvandare->id,
        'billed_account_id' => $konto->id,
        'created_at' => $skapad,
        'updated_at' => $skapad,
    ]);
}

/**
 * Sidans props, så att ett test kan läsa dem som en array i stället för genom
 * AssertableInertia — samma teknik som webhookvyProps() i WebhookvyTest.
 *
 * @return array<string, mixed>
 */
function planvyProps(User $anvandare, ?Account $konto = null): array
{
    $url = '/settings/plan'.($konto === null ? '' : "?account={$konto->ulid}");

    /** @var array{props: array<string, mixed>} $sida */
    $sida = actingAs($anvandare)->get($url)->assertOk()->viewData('page');

    return $sida['props'];
}

/**
 * Ett antal gigabyte i byte — gränserna i planerna är binära (1 GB = 1 GiB).
 */
function planvyGb(float $antal): int
{
    return (int) round($antal * 1024 * 1024 * 1024);
}

/**
 * En nyckel finns på båda språken och är inte tom. Samma kontroll som
 * WebhookvyTest gör för händelsetyperna, och skälet är detsamma: en nyckel som
 * bara finns på svenska syns som en nyckel i den engelska vyn.
 */
function planvyNyckel(string $nyckel): void
{
    foreach (['sv', 'en'] as $locale) {
        $mening = trans($nyckel, [], $locale);

        expect($mening)->not->toBe($nyckel, "{$nyckel} saknas på {$locale}");
        expect(trim((string) $mening))->not->toBe('', "{$nyckel} är tom på {$locale}");
    }
}

/*
 * Beslut 1: en rutt, bakom `auth`.
 */
it('skickar en utloggad besökare till inloggningen från plansidan', function () {
    withoutVite();

    get('/settings/plan')->assertRedirect('/login');
});

/*
 * Klart när: `/settings/plan` visar det valda kontots plan med namn, pris och
 * period.
 *
 * Namnet slås upp på `code` i lang/ (Beslut 9), priset formateras av servern
 * och gratiskontot säger "kostnadsfritt" i stället för "€0.00" — talet är rätt
 * och meningen fel.
 */
it('visar det valda kontots plan med namn, pris och period', function () {
    withoutVite();

    [$konto, $anvandare] = planvyKonto();

    $props = planvyProps($anvandare, $konto);

    expect($props['plan']['code'])->toBe('free')
        ->and($props['plan']['period'])->toBe('year')
        ->and($props['plan']['price'])->toBe(trans('ui.plan.price_free', [], 'sv'));

    planvyNyckel('ui.plan.names.free');
    planvyNyckel('ui.plan.price_free');
    planvyNyckel('ui.plan.period.year');

    planvyPro($konto);

    $pro = planvyProps($anvandare, $konto);

    expect($pro['plan']['code'])->toBe('pro')
        ->and($pro['plan']['period'])->toBe('year')
        ->and($pro['plan']['price'])->toBe('€49.00');
});

/*
 * Klart när: alla nio nycklar i `plan.limits` visas, fyra som förbrukning och
 * fem som ingår/ingår inte (Beslut 4).
 *
 * Fyra numeriska nycklar läses ur planen, och de fem funktionerna frågas
 * genom `Entitlements::assertFeature()` — samma metod grinden nekar med. Varje
 * nyckel har en etikett på båda språken, och en ny rad i planens JSON som
 * glöms i språkfilen faller här i stället för att synas som en nyckel.
 */
it('visar de fyra numeriska gränserna och de fem funktionerna', function () {
    withoutVite();

    [$konto, $anvandare] = planvyKonto();
    planvyRaknare($konto, 0, 1);

    $props = planvyProps($anvandare, $konto);

    expect($props['usage']['containers'])->toBe(['used' => 1, 'limit' => 1])
        ->and($props['usage']['storage']['limitBytes'])->toBe(planvyGb(1))
        ->and($props['usage']['maxFile']['limitBytes'])->toBe(10 * 1024 * 1024)
        ->and($props['usage']['sharedUsersPerContainer']['limit'])->toBe(1);

    expect(array_column($props['features'], 'key'))->toBe(ReadPlanUsage::FEATURES);
    expect(array_column($props['features'], 'included'))->toBe([false, false, false, false, false]);

    foreach (ReadPlanUsage::FEATURES as $funktion) {
        planvyNyckel("ui.plan.features.{$funktion}");
    }

    foreach (['containers', 'storage_bytes', 'max_file_bytes', 'shared_users_per_container'] as $nyckel) {
        planvyNyckel("ui.plan.limits.{$nyckel}");
    }

    // Och att sidan faktiskt LÄSER dem: en nyckel som finns men inte används är
    // en varning ingen ser.
    $vy = File::get(resource_path('js/pages/Settings/Plan.vue'));

    foreach (['containers', 'storage_bytes', 'max_file_bytes', 'shared_users_per_container'] as $nyckel) {
        expect($vy)->toContain("plan.limits.{$nyckel}");
    }

    expect($vy)->toContain('plan.features.');

    // Pro: funktionerna på, och de obegränsade gränserna som `null`.
    planvyPro($konto);

    $pro = planvyProps($anvandare, $konto);

    expect(array_column($pro['features'], 'included'))->toBe([true, true, true, true, true])
        ->and($pro['usage']['containers']['limit'])->toBeNull()
        ->and($pro['usage']['sharedUsersPerContainer']['limit'])->toBeNull();
});

/*
 * Klart när: `null` som gräns visas som obegränsat, aldrig som noll eller full
 * stapel (Beslut 4).
 *
 * `null` betyder obegränsat, och den enda buggen som spelar roll på den här
 * sidan är att göra det till ett tal. Pro:s tak för pärmar och delade
 * användare är `null`; ett tak som saknas ger ingen andel och därmed ingen
 * stapel — en full stapel mot en gräns som inte finns vore påhittad.
 *
 * Planens JSON ändras direkt och inte genom tests/Support/Testhjalpare.php:s
 * `sättPlangräns()`, som tar `int|bool` och inte kan uttrycka obegränsat.
 */
it('visar en obegränsad gräns som obegränsat och aldrig som en stapel', function () {
    withoutVite();

    [$konto, $anvandare] = planvyKonto();
    planvyPro($konto);
    planvyRaknare($konto, planvyGb(2), 4);

    $props = planvyProps($anvandare, $konto);

    // Pro:s utrymme är 25 GB och förbrukningen 2 GB: stapeln finns och är
    // 8 procent, inte full.
    expect($props['usage']['storage']['percent'])->toBe(8)
        ->and($props['usage']['storage']['limitLabel'])->toBe('25 GB')
        ->and($props['usage']['storage']['usedLabel'])->toBe('2 GB');

    // Ett plan utan tak på utrymmet: ingen andel, ingen etikett att rita en
    // stapel mot — och förbrukningen står kvar som byten.
    $pro = Plan::where('code', 'pro')->firstOrFail();
    $limits = $pro->limits;
    $limits['storage_bytes'] = null;
    $pro->update(['limits' => $limits]);

    $utanTak = planvyProps($anvandare, $konto);

    expect($utanTak['usage']['storage']['limitBytes'])->toBeNull()
        ->and($utanTak['usage']['storage']['limitLabel'])->toBeNull()
        ->and($utanTak['usage']['storage']['percent'])->toBeNull()
        ->and($utanTak['usage']['storage']['usedBytes'])->toBe(planvyGb(2))
        ->and($utanTak['usage']['maxFile']['limitLabel'])->toBe('64 MB');

    planvyNyckel('ui.plan.unlimited');
});

/*
 * Klart när: utrymmet visas i läsbara byten och som andel av taket.
 *
 * Bytena formateras av servern med Number::fileSize() (Beslut 4) — samma
 * formatering som kvotfelmeningarna i 60a och som
 * resources/js/components/attachmentPresentation.js speglar. Ett halvt tak
 * ska bli 50 procent och "512 MB av 1 GB".
 */
it('visar utrymmet i läsbara byten och som andel av taket', function () {
    withoutVite();

    [$konto, $anvandare] = planvyKonto();
    planvyRaknare($konto, planvyGb(0.5));

    $props = planvyProps($anvandare, $konto);

    expect($props['usage']['storage']['usedBytes'])->toBe(planvyGb(0.5))
        ->and($props['usage']['storage']['usedLabel'])->toBe('512 MB')
        ->and($props['usage']['storage']['limitLabel'])->toBe('1 GB')
        ->and($props['usage']['storage']['percent'])->toBe(50)
        ->and($props['usage']['maxFile']['limitLabel'])->toBe('10 MB');
});

/*
 * Klart när: förbrukningen kommer ur `usage_counter` och räknas inte om i
 * kontrollern (Beslut 8).
 *
 * Kontot har en räknare på 2 GB och INGA bilagor alls. En `SUM(byte_size)` i
 * kontrollern hade visat noll, eller varit en andra sanning vid sidan av
 * räknaren — och räknaren är den kontrollerna faktiskt nekar med.
 */
it('läser förbrukningen ur räknaren och räknar aldrig om den', function () {
    withoutVite();

    [$konto, $anvandare] = planvyKonto();
    planvyPro($konto);
    planvyRaknare($konto, planvyGb(2));

    expect(Attachment::query()->count())->toBe(0);

    $props = planvyProps($anvandare, $konto);

    expect($props['usage']['storage']['usedBytes'])->toBe(planvyGb(2))
        ->and($props['usage']['storage']['usedLabel'])->toBe('2 GB');
});

/*
 * Klart när: en användare som är medlem i flera konton kan byta konto, och
 * siffrorna följer med (Beslut 1 och 2).
 *
 * Väljaren listar ALLA hennes konton — att utelämna ett vore att dölja en
 * knapp — och `?account=` avgör vilket svar sidan visar. Utan parameter visas
 * det första, och `account` säger alltid vilket konto siffrorna gäller.
 */
it('byter konto och låter siffrorna följa med', function () {
    withoutVite();

    [$ena, $anvandare] = planvyKonto();
    $andra = Account::factory()->create(['name' => 'Andra varvet']);
    $andra->users()->attach($anvandare, ['role' => 'member']);

    planvyRaknare($ena, planvyGb(0.25), 1);
    planvyRaknare($andra, planvyGb(0.75), 3);

    $props = planvyProps($anvandare, $ena);

    expect($props['accounts'])->toHaveCount(2)
        ->and($props['account']['ulid'])->toBe($ena->ulid)
        ->and($props['usage']['storage']['usedLabel'])->toBe('256 MB')
        ->and($props['usage']['containers']['used'])->toBe(1);

    $props = planvyProps($anvandare, $andra);

    expect($props['account']['ulid'])->toBe($andra->ulid)
        ->and($props['usage']['storage']['usedLabel'])->toBe('768 MB')
        ->and($props['usage']['containers']['used'])->toBe(3);

    actingAs($anvandare)->get('/settings/plan')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('Settings/Plan')
            ->has('accounts', 2)
            ->has('plan')
            ->has('status')
            ->has('usage')
            ->has('features', 5)
    );
});

/*
 * Klart när: ett konto användaren inte är medlem i ger 403 (Beslut 2).
 *
 * Grinden är App\Policies\AccountPolicy::viewStorage(), som betyder exakt "får
 * se kontots förbrukning". Den prövas mot DET valda kontot — aldrig mot
 * användarens första — så en handskriven ULID till någon annans konto varken
 * blir en tom sida eller visar den användarens egna siffror. Ett ULID som inte
 * finns alls är 404, som varje annan rutt med en `{account}`-parameter.
 */
it('ger 403 för ett konto användaren inte är medlem i', function () {
    withoutVite();

    [$mitt, $anvandare] = planvyKonto();
    [$annans] = planvyKonto();

    actingAs($anvandare)
        ->get("/settings/plan?account={$annans->ulid}")
        ->assertForbidden();

    actingAs($anvandare)
        ->get('/settings/plan?account=01JZZZZZZZZZZZZZZZZZZZZZZZ')
        ->assertNotFound();

    // Och hennes eget konto svarar som vanligt.
    actingAs($anvandare)
        ->get("/settings/plan?account={$mitt->ulid}")
        ->assertOk();
});

/*
 * Klart när: ett `read_only`-konto visar orsaken och antalet dagar kvar av
 * fristen, räknat på serverns datum (Beslut 5).
 *
 * Fristen är tre månader och räknas ut av App\Actions\Plan\StartDowngrade —
 * sidan läser `grace_until` och räknar aldrig om den. Tiden står still i
 * testet, så talet är exakt 90 dygn: 15 januari till 15 april 2026.
 */
it('visar orsaken och antalet dagar kvar av fristen', function () {
    withoutVite();

    Carbon::setTestNow('2026-01-15 10:00:00');

    [$konto, $anvandare] = planvyKonto();
    planvyPro($konto);
    planvyRaknare($konto, planvyGb(30));

    (new StartDowngrade)->handle($konto, 'over_quota');

    $props = planvyProps($anvandare, $konto);

    expect($props['status']['code'])->toBe('read_only')
        ->and($props['status']['reason'])->toBe('over_quota')
        ->and($props['status']['graceDaysLeft'])->toBe(90);

    // Kontot behåller sin plan medan fristen löper: nedgraderingen är steg 1,
    // och gränsen krymper först när bilagorna faktiskt raderas (issue 28).
    expect($props['plan']['code'])->toBe('pro');

    // En dag kvar är singular, och vyn väljer nyckel på talet — 62a:s två
    // pluralnycklar, som issuen pekar ut (Beslut 5).
    $konto->subscription->update(['grace_until' => now()->addDay()]);

    expect(planvyProps($anvandare, $konto)['status']['graceDaysLeft'])->toBe(1);

    planvyNyckel('ui.plan.status.over_quota');
    planvyNyckel('ui.plan.status.payment_failed');
    planvyNyckel('ui.plan.status.inactivity');

    $vy = File::get(resource_path('js/pages/Settings/Plan.vue'));

    expect($vy)->toContain('plan.status.')
        ->and($vy)->toContain('trash.expires.day')
        ->and($vy)->toContain('trash.expires.days');

    Carbon::setTestNow();
});

/*
 * Klart när: ett konto utan prenumeration kraschar inte och visar gratisnivåns
 * gränser.
 *
 * `Account::currentPlan()` faller tillbaka på free (issue 25 § Beslut 6), och
 * sidan läser gratisplanens fyra gränser och de fem funktionerna utan att någon
 * prenumerationsrad finns. Ett `read_only`-konto utan prenumeration har ingen
 * frist att räkna på — `graceDaysLeft` är `null`, och vyn utelämnar raden.
 */
it('kraschar inte för ett konto utan prenumeration', function () {
    withoutVite();

    [$konto, $anvandare] = planvyKonto();

    $konto->update(['status' => 'read_only', 'read_only_reason' => 'payment_failed']);

    $props = planvyProps($anvandare, $konto);

    expect($props['plan']['code'])->toBe('free')
        ->and($props['status']['reason'])->toBe('payment_failed')
        ->and($props['status']['graceDaysLeft'])->toBeNull()
        ->and($props['usage']['containers']['limit'])->toBe(1)
        ->and($props['usage']['storage']['limitBytes'])->toBe(planvyGb(1));
});

/*
 * Klart när: nedgraderingens fem steg förklaras, inklusive att items och
 * kostnadsrader aldrig raderas (Beslut 6).
 *
 * Texten är statisk och bor i lang/ under `plan.downgrade.*`. Att meningen
 * finns och att vyn läser den prövas här; att den engelska sidan inte visar
 * svenska vaktas av SprakTest.
 */
it('förklarar nedgraderingens fem steg och att items och kostnadsrader aldrig raderas', function () {
    foreach (['freeze', 'choose', 'grace', 'purge', 'restore'] as $steg) {
        planvyNyckel("ui.plan.downgrade.steps.{$steg}");
    }

    planvyNyckel('ui.plan.downgrade.kept.items');
    planvyNyckel('ui.plan.downgrade.kept.costs');
    planvyNyckel('ui.plan.downgrade.heading');
    planvyNyckel('ui.plan.downgrade.intro');

    $vy = File::get(resource_path('js/pages/Settings/Plan.vue'));

    foreach (['freeze', 'choose', 'grace', 'purge', 'restore'] as $steg) {
        expect($vy)->toContain("plan.downgrade.steps.{$steg}");
    }

    expect($vy)->toContain('plan.downgrade.kept.items')
        ->and($vy)->toContain('plan.downgrade.kept.costs');

    // Nyast först står i texten om steg 4 — det är ordningen raderingen
    // faktiskt använder, och förhandsvisningen räknar i samma ordning.
    expect(trans('ui.plan.downgrade.steps.purge', [], 'sv'))->toContain('nyast först');
});

/*
 * Klart när: ett konto över gratisgränsen får en konkret förhandsvisning — hur
 * mycket över, och hur många bilagor som skulle gå nyast först (Beslut 7).
 *
 * Kontot ligger på Pro med 3 GB i räknaren, alltså 2 GB över gratisplanens 1
 * GB. Bilagorna är 0,5 + 0,5 + 1 + 1 GB, äldst först — nyast först behövs tre
 * av dem för att komma under: 0,5 + 0,5 + 1 = 2 GB, och den äldsta gigabyten
 * står kvar. Sorterade på storlek fallande hade svaret blivit två, så talet
 * bevisar både ordningen och att räkningen stannar när kontot ryms.
 */
it('förhandsvisar vad en nedgradering skulle kosta, nyast först', function () {
    withoutVite();

    Carbon::setTestNow('2026-09-16 12:00:00');

    [$konto, $anvandare] = planvyKonto();
    planvyPro($konto);
    planvyRaknare($konto, planvyGb(3));

    planvyBilaga($konto, $anvandare, planvyGb(1), now()->subDays(4));
    planvyBilaga($konto, $anvandare, planvyGb(1), now()->subDays(3));
    planvyBilaga($konto, $anvandare, planvyGb(0.5), now()->subDays(2));
    planvyBilaga($konto, $anvandare, planvyGb(0.5), now()->subDay());

    $props = planvyProps($anvandare, $konto);

    expect($props['downgrade'])->toBe([
        'freeStorageLabel' => '1 GB',
        'overBytes' => planvyGb(2),
        'overLabel' => '2 GB',
        'attachmentsToRemove' => 3,
    ]);

    Carbon::setTestNow();
});

/*
 * Klart när: ett konto under gränsen får beskedet att allt ryms, utan siffror
 * om radering (Beslut 7).
 *
 * Både ett konto som ryms med marginal och ett som ligger precis på gränsen:
 * `used <= limit` ryms, för en fil som exakt fyller kvoten går igenom
 * (Entitlements::assertStorageWithinLimit()).
 */
it('säger att allt ryms när kontot ligger under gränsen', function () {
    withoutVite();

    [$konto, $anvandare] = planvyKonto();
    planvyRaknare($konto, planvyGb(0.5));

    expect(planvyProps($anvandare, $konto)['downgrade'])->toBeNull();

    UsageCounter::query()->where('account_id', $konto->id)->update(['storage_bytes' => planvyGb(1)]);

    expect(planvyProps($anvandare, $konto)['downgrade'])->toBeNull();

    planvyNyckel('ui.plan.downgrade.preview.fits');
    planvyNyckel('ui.plan.downgrade.preview.over');
    planvyNyckel('ui.plan.downgrade.preview.remove_one');
    planvyNyckel('ui.plan.downgrade.preview.remove_many');
});

/*
 * Klart när: förhandsvisningen skriver ingenting — inga rader ändras, ingen
 * räknare rörs (Beslut 7 och 8).
 *
 * Sidan är EN GET. Räknaren står still, bilagorna är kvar och ingen av dem är
 * mjukraderad efter anropet — förhandsvisningen beskriver ett urval, den gör
 * det inte. En sida som råkade gallra när den visades vore den dyraste buggen
 * i hela nedgraderingen.
 */
it('rör ingenting när förhandsvisningen ritas', function () {
    withoutVite();

    [$konto, $anvandare] = planvyKonto();
    planvyRaknare($konto, planvyGb(3));

    planvyBilaga($konto, $anvandare, planvyGb(1.5), now()->subDays(2));
    planvyBilaga($konto, $anvandare, planvyGb(1.5), now()->subDay());

    $raderFore = DB::table('attachment')->orderBy('id')->get(['id', 'deleted_at']);
    $raknareFore = DB::table('usage_counter')->where('account_id', $konto->id)->value('storage_bytes');

    $props = planvyProps($anvandare, $konto);

    expect($props['downgrade']['attachmentsToRemove'])->toBe(2);

    expect(DB::table('attachment')->orderBy('id')->get(['id', 'deleted_at'])->toArray())
        ->toEqual($raderFore->toArray());
    expect(DB::table('usage_counter')->where('account_id', $konto->id)->value('storage_bytes'))
        ->toEqual($raknareFore);
    expect(DB::table('usage_counter')->where('account_id', $konto->id)->value('updated_at'))
        ->not->toBeNull();
});

/*
 * Klart när: sidan syns i inställningsnavigeringen och länkar till
 * städningsytan (Beslut 1 och 7).
 *
 * Navigationen renderas ur resources/js/layouts/settingsSections.js — en rad
 * där och ingen ändring i layouten — och etiketten är formulerad på båda
 * språken. Länken till städningsytan ligger i förhandsvisningen: den som
 * ligger över gränsen ska kunna välja själv (66b) i stället för att läsa vad
 * som annars händer.
 */
it('syns i inställningsnavigationen och länkar till städningsytan', function () {
    $sektioner = File::get(resource_path('js/layouts/settingsSections.js'));

    expect($sektioner)->toContain("key: 'plan', href: '/settings/plan'");

    planvyNyckel('ui.settings.nav.plan');

    $vy = File::get(resource_path('js/pages/Settings/Plan.vue'));

    expect($vy)->toContain('<SettingsLayout>')
        ->and($vy)->toContain('href="/settings/storage"');

    planvyNyckel('ui.plan.downgrade.preview.cleanup_link');
});
