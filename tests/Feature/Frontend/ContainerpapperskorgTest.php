<?php

// rott-pa-basen: issue 77b och 83 — ordbyte i prosa (kommentar och testnamn), ingen ändring av applikationskoden; bas och head delar den.

use App\Actions\Container\CreateContainer;
use App\Actions\Container\TrashContainer;
use App\Models\Account;
use App\Models\Category;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Invitation;
use App\Models\Item;
use App\Models\Tag;
use App\Models\UsageCounter;
use App\Models\User;
use App\Support\Frontend\ActiveContainer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\from;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\withoutVite;

/*
 * Issue 62b · Papperskorgen för raderade containers och raderingsknappen som
 * fyller den. Se App\Http\Controllers\ContainerTrashController,
 * App\Http\Controllers\ContainerController::destroy(),
 * App\Actions\Container\TrashContainer, App\Actions\Trash\
 * ListTrashedContainers, App\Actions\Trash\RestoreTrashedContainer,
 * resources/js/pages/Trash/Containers.vue,
 * resources/js/pages/Containers/Edit.vue, resources/js/components/TrashRow.vue
 * och [[ADR-0008 Soft delete och papperskorg]] § Retentionstiden i MVP.
 *
 * **Att `/api` svarar bit för bit som förut prövas av
 * tests/Feature/Trash/ContainerPapperskorgTest.php**, som är grönt utan en
 * enda ändrad förväntan efter utbrytningen i Beslut 2 — en ny formulering av
 * samma sak här hade bevisat noll. Den här filen prövar i stället webbens
 * yta: knappen, bekräftelsen, raderingen, den aktiva containern, listan och
 * återställningen.
 *
 * **Två acceptanskriterier prövas inte här**, därför att de redan har en
 * ägare: `/api`:s tre svar (ContainerPapperskorgTest) och "ingen svensk
 * sträng i en .vue-fil" (SprakTest § "har inga användarvända strängar kvar i
 * Vue-komponenterna").
 *
 * Hjälparna har prefixet `containerpapperskorg` — Pest lägger alla testfiler i
 * samma namnrymd när hela sviten körs.
 */

/**
 * Ett konto med en medlem och en container. Containern är fabriksgjord och räknas
 * därför INTE i `usage_counter` — räknaren hålls i takt av skrivvägarna, inte
 * av databasen, och den som vill pröva räkningen skapar sin container genom
 * CreateContainer (se `containerpapperskorgRäknad()`).
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function containerpapperskorgKontext(string $kontostatus = 'active'): array
{
    $konto = Account::factory()->create(['status' => $kontostatus]);
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => 'owner']);
    $container = Container::factory()->for($konto, 'account')->create();

    return [$konto, $anvandare, $container];
}

/**
 * En container skapad genom den riktiga skrivvägen, så `usage_counter` står på 1 —
 * samma utgångsläge som efter ett skarpt skapande (issue 26a).
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function containerpapperskorgRäknad(): array
{
    $konto = Account::factory()->create();
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    $container = app(CreateContainer::class)->handle($anvandare, $konto, 'Havsörnen', 'boat');

    return [$konto, $anvandare, $container];
}

/**
 * Mjukraderar en container genom att sätta `deleted_at` — samma sluttillstånd som
 * raderingsrutten (SoftDeletes) men med kontrollerad tidpunkt.
 * `containerKorgMjukradera()` i tests/Feature/Trash/ContainerPapperskorgTest.php
 * gör exakt samma sak; den ligger i en annan testfil och får inte sitt eget
 * namn lånat hit.
 */
function containerpapperskorgRaderad(Container $container, ?Carbon $deletedAt = null): void
{
    $container->deleted_at = $deletedAt ?? now();
    $container->save();
}

/**
 * En container_access-rad för $mottagare.
 */
function containerpapperskorgAccess(Container $container, User $mottagare, string $level): ContainerAccess
{
    return ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => null,
        'grantee_type' => 'user',
        'grantee_id' => $mottagare->id,
        'level' => $level,
        'kind' => 'member',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);
}

/**
 * Antalet containrar kontot räknas med.
 */
function containerpapperskorgAntal(Account $konto): int
{
    return (int) UsageCounter::query()->where('account_id', $konto->id)->value('container_count');
}

afterEach(function () {
    Carbon::setTestNow();
});

/*
 * Beslut 1: tre rutter, alla bakom `auth`. En utloggad besökare skickas till
 * inloggningen och når aldrig en kontrollermetod.
 */
it('skickar en utloggad besökare till inloggningen från de tre rutterna', function () {
    withoutVite();

    [, , $container] = containerpapperskorgKontext();

    delete("/containers/{$container->ulid}")->assertRedirect('/login');
    get('/trash/containers')->assertRedirect('/login');
    post('/trash/containers/restore', ['ulid' => $container->ulid])->assertRedirect('/login');
});

/*
 * Klart när: containerns inställningssida har en raderingsknapp för den som får
 * radera, och ingen för den som inte får (Beslut 4).
 *
 * Flaggan är presentation (Beslut 6): `can.delete` räknas ur samma grind som
 * `containers.destroy` prövar.
 */
it('ritar raderingsknappen för ägaren och inte för en delegerad åtkomst', function () {
    withoutVite();

    [$konto, $ägare, $container] = containerpapperskorgKontext();

    actingAs($ägare)
        ->get("/containers/{$container->ulid}/edit")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Containers/Edit')
            ->where('can.delete', true)
        );

    // En containerbred `write`-access når inställningssidan — det är
    // ContainerPolicy::update() — men får aldrig radera: delete() kräver
    // medlemskap i ägarkontot (issue 8 § Beslut 2, regel 3).
    $deltagare = User::factory()->create();
    containerpapperskorgAccess($container, $deltagare, 'write');

    actingAs($deltagare)
        ->get("/containers/{$container->ulid}/edit")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('can.delete', false));

    // Vyn ritar knappen ur flaggan och aldrig ur något annat.
    $vy = File::get(resource_path('js/pages/Containers/Edit.vue'));

    expect($vy)->toContain('v-if="can.delete"');
});

/*
 * Klart när: raderingen bekräftas med en mening som nämner papperskorgen och
 * de 30 dagarna (Beslut 5).
 *
 * `window.confirm` med serverns mening ur `lang/`, samma mönster som 57b
 * § Beslut 8 — ingen modal komponent. Meningen bär containerns namn, säger att
 * allt följer med och att den går att återställa, och säger ALDRIG "raderas
 * permanent": raderingen är mjuk (issue 8).
 */
it('bekräftar raderingen med containerns namn, papperskorgen och de 30 dagarna', function () {
    $vy = File::get(resource_path('js/pages/Containers/Edit.vue'));

    expect($vy)->toContain("t('container.destroy.confirm', { name: props.container.name })");
    expect($vy)->toContain('router.delete(`/containers/${props.container.ulid}`)');
    expect($vy)->toContain('window.confirm(');

    $fil = require lang_path('en/ui.php');

    $mening = $fil['container']['destroy']['confirm'];

    expect($mening)->toContain(':name');
    expect($mening)->toContain('30');

    // Papperskorgen och återställningen nämns, med de engelska orden.
    expect($mening)->toContain('trash');
    expect($mening)->toContain('restored');

    // Och aldrig det osanna ordet.
    expect($mening)->not->toContain('permanent');

    expect($fil['container']['destroy']['action'])->not->toBe('');
    expect($fil['flash']['container-trashed'])->not->toBe('');
    expect($fil['flash']['container-restored'])->not->toBe('');
});

/*
 * Klart när: raderingen är mjuk — bara `deleted_at` på container-raden, inget
 * item och ingen kategori rörd (issue 8). Ingen kaskad.
 */
it('mjukraderar containern och rör ingenting i den', function () {
    withoutVite();

    [$konto, $ägare, $container] = containerpapperskorgKontext();

    $item = papperskorgsItem($container, $konto, $ägare, ['name' => 'Motorn']);
    $kategori = Category::factory()->for($container, 'container')->create(['name' => 'Elsystem']);
    $tagg = Tag::factory()->for($container, 'container')->create(['name' => 'Vinter']);
    $item->tags()->attach($tagg);
    $bilaga = papperskorgsBilaga($item, $konto, $ägare, ['filename' => 'faktura.pdf']);

    actingAs($ägare)
        ->delete("/containers/{$container->ulid}")
        ->assertRedirect('/containers')
        ->assertSessionHas('status', 'container-trashed');

    expect(DB::table('container')->where('id', $container->id)->value('deleted_at'))->not->toBeNull();

    // Innehållet mjukraderades aldrig — det ligger kvar orört och gäller igen
    // om containern återställs.
    foreach ([
        ['item', $item->id],
        ['category', $kategori->id],
        ['tag', $tagg->id],
        ['attachment', $bilaga->id],
    ] as [$tabell, $id]) {
        expect(DB::table($tabell)->where('id', $id)->value('deleted_at'))->toBeNull();
    }

    expect(DB::table('item_tag')->where('item_id', $item->id)->where('tag_id', $tagg->id)->exists())->toBeTrue();
});

/*
 * Klart när: ägarkontots `containers`-förbrukning minskar med exakt ett, en
 * gång, också vid två samtidiga raderingar (issue 26a, granskningsfynd 1).
 *
 * Den andra raderingen är samma anrop en gång till: radlåset ser att raden
 * inte längre är levande, och minskningen sker en gång. Exakt samma skydd som
 * `/api` bär sedan issue 8 — bara utbrutet (Beslut 2).
 */
it('minskar förbrukningen med exakt ett, också vid en upprepad radering', function () {
    withoutVite();

    [$konto, $ägare, $container] = containerpapperskorgRäknad();

    expect(containerpapperskorgAntal($konto))->toBe(1);

    actingAs($ägare)->delete("/containers/{$container->ulid}")->assertRedirect('/containers');

    expect(containerpapperskorgAntal($konto))->toBe(0);

    app(TrashContainer::class)->handle(
        Container::withTrashed()->whereKey($container->id)->firstOrFail(),
        $ägare,
    );

    expect(containerpapperskorgAntal($konto))->toBe(0);
});

/*
 * Klart när: en delegerad åtkomst — även `delete`-nivå — får 403 på
 * raderingen (Beslut 3).
 *
 * Att radera är förbehållet ägarkontot (issue 9a § Beslut 6), och ingen nivå
 * i laddern får radera containern ([[Konton och åtkomst]] §
 * Behörighetsregler regel 3).
 */
it('nekar en delegerad åtkomst på delete-nivå att radera containern', function () {
    withoutVite();

    [, , $container] = containerpapperskorgKontext();

    $mottagare = User::factory()->create();
    containerpapperskorgAccess($container, $mottagare, 'delete');

    actingAs($mottagare)
        ->delete("/containers/{$container->ulid}")
        ->assertForbidden();

    expect(DB::table('container')->where('id', $container->id)->value('deleted_at'))->toBeNull();
});

/*
 * Klart när: ett fruset konto kan varken radera eller återställa (regel 4).
 *
 * Båda är skrivningar, och `ContainerPolicy::delete()` bär samma kontokontroll
 * i båda riktningarna (Beslut 3).
 */
it('låter ett fruset konto varken radera eller återställa en container', function () {
    withoutVite();

    [$konto, $ägare, $container] = containerpapperskorgKontext();

    // Återställningen prövas först, medan containern ligger i papperskorgen.
    $andra = Container::factory()->for($konto, 'account')->create(['name' => 'Andra']);
    containerpapperskorgRaderad($andra);

    $konto->update(['status' => 'read_only']);

    actingAs($ägare)
        ->delete("/containers/{$container->ulid}")
        ->assertForbidden();

    expect(DB::table('container')->where('id', $container->id)->value('deleted_at'))->toBeNull();

    actingAs($ägare)
        ->post('/trash/containers/restore', ['ulid' => $andra->ulid])
        ->assertForbidden();

    expect(DB::table('container')->where('id', $andra->id)->value('deleted_at'))->not->toBeNull();
});

/*
 * Klart när: den raderade containern försvinner ur containerlistan och dess sidor ger
 * 404.
 */
it('tar bort containern ur listan och ger 404 på dess sidor', function () {
    withoutVite();

    [$konto, $ägare, $container] = containerpapperskorgKontext();

    actingAs($ägare)->delete("/containers/{$container->ulid}")->assertRedirect('/containers');

    actingAs($ägare)
        ->get('/containers')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('containers', []));

    actingAs($ägare)->get("/containers/{$container->ulid}")->assertNotFound();
    actingAs($ägare)->get("/containers/{$container->ulid}/edit")->assertNotFound();
    actingAs($ägare)->get("/containers/{$container->ulid}/trash")->assertNotFound();

    expect(Container::query()->where('account_id', $konto->id)->count())->toBe(0);
});

/*
 * Klart när: var containern aktiv rensas sessionsnyckeln, och nästa sida pekar
 * inte på den (Beslut 6).
 *
 * `App\Support\Frontend\ActiveContainer` är den enda som rör nyckeln, och den
 * anropas från kontrollern — en `/api`-radering har ingen session att röra.
 */
it('rensar den aktiva containern när den raderas, och bara då', function () {
    withoutVite();

    [$konto, $ägare, $container] = containerpapperskorgKontext();
    $annan = Container::factory()->for($konto, 'account')->create();

    // En annan container raderas först: nyckeln ska stå kvar.
    actingAs($ägare)
        ->withSession([ActiveContainer::SESSION_KEY => $annan->ulid])
        ->delete("/containers/{$container->ulid}")
        ->assertRedirect('/containers');

    expect(session(ActiveContainer::SESSION_KEY))->toBe($annan->ulid);

    actingAs($ägare)
        ->get('/containers')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('activeContainer', $annan->ulid));

    // Sedan den aktiva: nyckeln ska bort, och nästa sida visa ingen aktiv
    // container alls — den hade annars pekat på en container som inte finns.
    actingAs($ägare)
        ->withSession([ActiveContainer::SESSION_KEY => $annan->ulid])
        ->delete("/containers/{$annan->ulid}")
        ->assertRedirect('/containers');

    expect(session(ActiveContainer::SESSION_KEY))->toBeNull();

    actingAs($ägare)
        ->get('/containers')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('activeContainer', null));
});

/*
 * Klart när: `/trash/containers` listar raderade containers i konton användaren är
 * medlem i, senast raderad först, med återstående tid (Beslut 3 och 7).
 *
 * Raderna kommer ur `TrashEntryResource`, samma sex nycklar som `/api` — vyn
 * hittar inte på någon egen form.
 *
 * **Tiden är frusen, och det är kravet och inte en stilfråga.** `expires_at`
 * är `deleted_at` + retentionen, och raden raderas med ett `now()` medan
 * förväntan räknas ur ett ANDRA `now()` — efter ett helt HTTP-anrop. Faller en
 * sekundgräns däremellan skiljer de sig på sekunden, och provet är rött utan
 * att något är fel (issue 477 § Frågeräkningens förutsättning; samma frysning
 * som grannen nedanför och FiloriginTest § "länken lever i femton minuter").
 * En fryst klocka gör båda anropen till samma tidpunkt.
 */
it('listar raderade containers senast raderad först, med den återstående tiden', function () {
    withoutVite();

    Carbon::setTestNow('2026-09-02 12:00:00');

    try {
        [$konto, $ägare, $container] = containerpapperskorgKontext();

        $först = Container::factory()->for($konto, 'account')->create(['name' => 'Först']);
        containerpapperskorgRaderad($först, now()->subDays(3));

        $senast = Container::factory()->for($konto, 'account')->create(['name' => 'Senast']);
        containerpapperskorgRaderad($senast, now()->subDays(2));

        $retention = (int) config('files.trash_retention_days');

        actingAs($ägare)
            ->get('/trash/containers')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Trash/Containers')
                ->has('entries', 2)
                ->where('entries.0.type', 'container')
                ->where('entries.0.ulid', $senast->ulid)
                ->where('entries.0.label', 'Senast')
                ->where('entries.0.context', null)
                ->where('entries.0.expires_at', now()->subDays(2)->addDays($retention)->toIso8601String())
                ->where('entries.1.ulid', $först->ulid)
                ->where("canRestore.{$senast->ulid}", true)
            );
    } finally {
        Carbon::setTestNow();
    }
});

/*
 * Klart när: en container vars retention passerat listas inte och går inte att
 * återställa (Beslut 7, issue 20c § Beslut 3).
 */
it('listar inte och återställer inte en utgången container', function () {
    withoutVite();

    Carbon::setTestNow('2026-09-02 12:00:00');

    try {
        [$konto, $ägare, $container] = containerpapperskorgKontext();

        $retention = (int) config('files.trash_retention_days');

        $utgången = Container::factory()->for($konto, 'account')->create(['name' => 'Utgången']);
        containerpapperskorgRaderad($utgången, now()->subDays($retention + 1));

        $kvar = Container::factory()->for($konto, 'account')->create(['name' => 'Kvar']);
        containerpapperskorgRaderad($kvar, now()->subDays($retention - 1));

        actingAs($ägare)
            ->get('/trash/containers')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('entries', 1)
                ->where('entries.0.ulid', $kvar->ulid)
            );

        actingAs($ägare)
            ->post('/trash/containers/restore', ['ulid' => $utgången->ulid])
            ->assertNotFound();

        expect(DB::table('container')->where('id', $utgången->id)->value('deleted_at'))->not->toBeNull();
    } finally {
        Carbon::setTestNow();
    }
});

/*
 * Klart när: en container en annan användare äger syns inte, även om hon har
 * åtkomst till den (Beslut 3).
 *
 * Listan är en FRÅGA — "vilka raderade containers finns i mina konton" — och en
 * delegerad `container_access` ger varken en rad i listan eller en knapp.
 */
it('visar inte en annan användares container, ens med delegerad åtkomst', function () {
    withoutVite();

    [, $ägare] = containerpapperskorgKontext();

    $annatKonto = Account::factory()->create();
    $container = Container::factory()->for($annatKonto, 'account')->create(['name' => 'Deras']);
    containerpapperskorgRaderad($container);

    containerpapperskorgAccess($container, $ägare, 'delete');

    actingAs($ägare)
        ->get('/trash/containers')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('entries', 0)
            ->where('canRestore', [])
        );

    // Och återställningen nekas: grinden prövar radens ägarkonto.
    actingAs($ägare)
        ->post('/trash/containers/restore', ['ulid' => $container->ulid])
        ->assertForbidden();

    expect(DB::table('container')->where('id', $container->id)->value('deleted_at'))->not->toBeNull();
});

/*
 * Klart när: återställningen väcker containern, ökar förbrukningen med exakt ett,
 * och innehållet finns kvar — items, kategorier, taggar, åtkomster och
 * inbjudningar (issue 20c § Beslut 4).
 */
it('återställer containern, ökar förbrukningen och lämnar innehållet kvar', function () {
    withoutVite();

    [$konto, $ägare, $container] = containerpapperskorgRäknad();

    $item = papperskorgsItem($container, $konto, $ägare, ['name' => 'Motorn']);
    $kategori = Category::factory()->for($container, 'container')->create(['name' => 'Elsystem']);
    $tagg = Tag::factory()->for($container, 'container')->create(['name' => 'Vinter']);
    $item->tags()->attach($tagg);
    $bilaga = papperskorgsBilaga($item, $konto, $ägare, ['filename' => 'faktura.pdf']);

    [, $gäst] = containerpapperskorgKontext();
    $access = containerpapperskorgAccess($container, $gäst, 'read');

    $inbjudan = Invitation::factory()->for($container, 'container')->create([
        'invited_by_user_id' => $ägare->id,
    ]);

    actingAs($ägare)->delete("/containers/{$container->ulid}")->assertRedirect('/containers');

    expect(containerpapperskorgAntal($konto))->toBe(0);

    from('/trash/containers')
        ->actingAs($ägare)
        ->post('/trash/containers/restore', ['ulid' => $container->ulid])
        ->assertRedirect('/trash/containers')
        ->assertSessionHas('status', 'container-restored');

    expect(DB::table('container')->where('id', $container->id)->value('deleted_at'))->toBeNull();
    expect(containerpapperskorgAntal($konto))->toBe(1);

    // Innehållet mjukraderades aldrig och är åtkomligt igen genom den levande
    // containern (Beslut 7, issue 20c § Beslut 4). Inga kaskader i någondera
    // riktningen.
    expect(Item::query()->whereKey($item->id)->exists())->toBeTrue();
    expect(Category::query()->whereKey($kategori->id)->exists())->toBeTrue();
    expect(Tag::query()->whereKey($tagg->id)->exists())->toBeTrue();
    expect(ContainerAccess::query()->whereKey($access->id)->whereNull('revoked_at')->exists())->toBeTrue();
    expect(Invitation::query()->whereKey($inbjudan->id)->exists())->toBeTrue();
    expect(DB::table('item_tag')->where('item_id', $item->id)->where('tag_id', $tagg->id)->exists())->toBeTrue();

    // Och containern svarar igen: itemet står i containerns lista som om ingenting
    // hänt, för ingenting hände med det.
    actingAs($ägare)
        ->get("/containers/{$container->ulid}/items")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('items', fn ($items) => collect($items)->pluck('ulid')->contains($item->ulid))
        );
});

/*
 * Klart när: återställningen sätter inte containern som aktiv (Beslut 6).
 *
 * Att ÖPPNA en container är användarens handling (issue 83), och
 * återställningen öppnar den inte: svaret är en omdirigering till
 * papperskorgen, och `ActiveContainer` rörs därför inte.
 */
it('sätter inte den återställda containern som aktiv', function () {
    withoutVite();

    [$konto, $ägare, $container] = containerpapperskorgKontext();
    containerpapperskorgRaderad($container);

    from('/trash/containers')
        ->actingAs($ägare)
        ->post('/trash/containers/restore', ['ulid' => $container->ulid])
        ->assertRedirect('/trash/containers');

    expect(session(ActiveContainer::SESSION_KEY))->toBeNull();

    actingAs($ägare)
        ->get('/containers')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('activeContainer', null));
});

/*
 * Klart när: containerlistan länkar till papperskorgen (Beslut 8).
 *
 * Raden ligger under listan och är ALLTID synlig — den som raderat en container av
 * misstag ska hitta tillbaka utan att veta att `/trash/containers` finns.
 * Texten är konstant: en räknare hade varit en fråga per sidladdning.
 */
it('länkar till papperskorgen från containerlistan', function () {
    withoutVite();

    $index = File::get(resource_path('js/pages/Containers/Index.vue'));

    expect($index)->toContain('href="/trash/containers"');
    expect($index)->toContain("t('trash.containers.link')");

    // Länken ligger UNDER listan och bär inget villkor: den ritas också för
    // en tom lista, för den som raderat sin enda container är den som mest behöver
    // den. Låg den innanför `v-else`-grenen hade den försvunnit precis då.
    expect(strpos($index, 'href="/trash/containers"'))->toBeGreaterThan(strpos($index, '</ul>'));

    expect(trans('ui.trash.containers.link', [], 'en'))->not->toBe('');
});

/*
 * Klart när: en tom papperskorg säger att den är tom, och flaggan följer
 * samma grind som rutten.
 *
 * Radkomponenten är 62a:s, och den härleder sitt mål ur `entry.type` — det
 * är hela skillnaden mellan de två listorna (Beslut 7, issue 20c § Beslut 3).
 */
it('säger att papperskorgen är tom och återanvänder raden från 62a', function () {
    withoutVite();

    [, $ägare] = containerpapperskorgKontext();

    actingAs($ägare)
        ->get('/trash/containers')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('entries', [])
            ->where('canRestore', [])
        );

    $vy = File::get(resource_path('js/pages/Trash/Containers.vue'));

    expect($vy)->toContain("t('trash.containers.empty')");
    expect($vy)->toContain('entries.length > 0');
    expect($vy)->toContain("import TrashRow from '../../components/TrashRow.vue'");

    // Sidan skickar varken URL eller kropp — målet bor i raden, som härleder
    // det ur `entry.type`. Containerlistan och innehållslistan är därmed samma
    // anrop: bara raderna skiljer sig.
    expect($vy)->not->toContain('restore-href');
    expect($vy)->not->toContain('restore-data');

    $rad = File::get(resource_path('js/components/TrashRow.vue'));

    expect($rad)->toContain("props.entry.type === 'container'");
    expect($rad)->toContain("'/trash/containers/restore'");
    expect($rad)->toContain('`/containers/${props.containerUlid}/trash/restore`');

    // Och samma rad anropas likadant från 62a:s containerpapperskorg, vars sida
    // alltså står orörd av den här issuen.
    $innehall = File::get(resource_path('js/pages/Containers/Trash.vue'));

    expect($innehall)->toContain(':container-ulid="container.ulid"');
    expect($innehall)->not->toContain('restore-href');

    $en = require lang_path('en/ui.php');

    foreach (['title', 'heading', 'description', 'empty', 'link', 'back'] as $nyckel) {
        expect($en['trash']['containers'][$nyckel])->not->toBe('');
    }

    // Typetiketten för en raderad container, i samma uppslag som de fyra
    // andra typerna: raden läser `trash.type.<type>` och `container` är ett
    // värde ur TrashEntryResource. Se [[ADR-0032 Produktens ord]]: containern
    // heter container, och den engelska filen lånar ordet i stället för att
    // översätta det — den är därför likadan som den svenska var.
    expect($en['trash']['type']['container'])->toBe('Container');
});

/*
 * Klart när: en delegerad åtkomst får 403 också på återställningen, och
 * listan visar den inte.
 */
it('nekar en delegerad åtkomst att återställa en container', function () {
    withoutVite();

    [$konto, , $container] = containerpapperskorgKontext();
    containerpapperskorgRaderad($container);

    $mottagare = User::factory()->create();
    containerpapperskorgAccess($container, $mottagare, 'delete');

    actingAs($mottagare)
        ->post('/trash/containers/restore', ['ulid' => $container->ulid])
        ->assertForbidden();

    expect(DB::table('container')->where('id', $container->id)->value('deleted_at'))->not->toBeNull();
});

/*
 * Klart när: en ULID som inte ligger i papperskorgen är ett valideringsfel på
 * `ulid`, inte en 500 och inte en tyst ingen-verkan.
 *
 * `RestoreContainerRequest` delas med `/api` (Beslut 2), och webben behåller
 * Laravels vanliga valideringsfel ([[ADR-0020 Plattformsidentitet och
 * frontendgräns]] § Konsekvenser) — vyn renderar det på samma nyckel.
 */
it('avvisar en ulid som inte ligger i papperskorgen som ett valideringsfel', function () {
    withoutVite();

    [$konto, $ägare, $container] = containerpapperskorgKontext();

    from('/trash/containers')
        ->actingAs($ägare)
        ->post('/trash/containers/restore', ['ulid' => $container->ulid])
        ->assertSessionHasErrors('ulid');

    expect(DB::table('container')->where('id', $container->id)->value('deleted_at'))->toBeNull();

    $vy = File::get(resource_path('js/pages/Trash/Containers.vue'));

    expect($vy)->toContain('page.props.errors.ulid');
});
