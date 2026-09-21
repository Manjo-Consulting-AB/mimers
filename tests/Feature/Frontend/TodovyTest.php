<?php

// rott-pa-basen: issue 77b — ordbyte i prosa (kommentar och testnamn), ingen kodändring; bas och head delar applikationskod.

use App\Http\Controllers\TodoController;
use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\OccurrenceDependency;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\withoutVite;

/*
 * Issue 64 · Todo-vyn — startsidan efter inloggning, se
 * App\Http\Controllers\TodoController, resources/js/pages/Dashboard.vue,
 * resources/js/components/TodoRow.vue, routes/web.php och lang/sv|en/ui.php.
 *
 * Filen bevisar de gränser issuen är byggd kring:
 *
 * 1. **Urvalet är `scopeTodoFor()` — vyn filtrerar ingenting** (Beslut 2).
 *    Villkoren prövas genom WEBBSIDAN: en stängd förekomst, en vars
 *    `visible_from` ligger i framtiden, en som är blockerad och en i en container
 *    användaren inte når får aldrig en rad.
 * 2. **Ordningen och grupperingen är serverns** (Beslut 3) — `due_at`
 *    stigande med `ulid` som andra nyckel, och gruppen räknas mot serverns
 *    datum, aldrig mot klientens.
 * 3. **Raden går att bocka av där den står** (Beslut 4) — 63b:s rutt, samma
 *    grind, och listan ritas om utan raden.
 * 4. **Raden säger var uppgiften hör hemma** (Beslut 5) — container, item, schema,
 *    med länkar som går rätt.
 * 5. **Tom lista säger ingenting om vad som dolts** (Beslut 6) — men skiljer
 *    på "inga containers" och "inget att göra".
 * 6. **Frågekostnaden är konstant** (Beslut 8), mätt med `DB::listen`.
 *
 * Datumen är relativa till `Carbon::today()` och inte fasta strängar: listan
 * kräver `visible_from <= idag`, så en fast dag hade gjort hela filen
 * tidsberoende — den hade fallit i morgon och klarat sig i dag.
 *
 * Att ingen svensk sträng står kvar i en Vue-komponent och att nycklarna finns
 * prövas också av tests/Feature/Frontend/SprakTest.php, som
 * läser varenda fil under resources/js; den sista testen här binder de NYA
 * nycklarna och de NYA filerna till just det testet.
 *
 * Hjälparna har prefixet `todovy` — Pest lägger alla testfiler i samma namnrymd
 * när hela sviten körs.
 */

/**
 * En dag relativt serverns idag, som DATE-sträng.
 */
function todovyDatum(int $dagar): string
{
    return Carbon::today()->addDays($dagar)->toDateString();
}

/**
 * Ett ägarkonto med en medlem i. Ingen container — den som behöver en skapar en med
 * todovyPärm().
 *
 * @return array{0: Account, 1: User}
 */
function todovyKonto(): array
{
    $konto = Account::factory()->create(['locale' => 'sv_SE']);
    $anvandare = User::factory()->create(['locale' => 'sv_SE']);
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    return [$konto, $anvandare];
}

/**
 * En container under $konto.
 */
function todovyPärm(Account $konto): Container
{
    return Container::factory()->for($konto, 'account')->create();
}

/**
 * Ett item i containern. `created_by_*` sätts sammanhängande, som i SokvyTest:
 * fabrikens egna default-skapare hade blivit två ovidkommande rader per item.
 */
function todovyItem(Container $container, string $namn): Item
{
    return Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => User::factory()->create()->id,
        'created_by_account_id' => $container->account_id,
    ]);
}

/**
 * Ett aktivt schema på itemet.
 *
 * @param  array<string, mixed>  $attribut
 */
function todovySchema(Item $item, string $due, array $attribut = []): Schedule
{
    return Schedule::factory()->for($item, 'item')->create(array_merge([
        'title' => 'Byt olja',
        'recurrence_type' => 'interval',
        'interval_unit' => 'month',
        'interval_count' => 12,
        'anchor_date' => $due,
        'lead_days' => 0,
        'is_active' => true,
    ], $attribut));
}

/**
 * En förekomstrad. Produktionen går alltid genom
 * App\Actions\Schedule\OpenNextOccurrence (se fabrikens docblock) — här byggs
 * raden direkt så att förfallodatumet är känt utan att räkna kalender.
 *
 * `visible_from` ärver INTE `due_at` som i fabriken: en uppgift som förfaller
 * framåt i tiden är synlig NU, och det är `visible_from` som avgör det.
 * Standarden är därför en månad bakåt, och ett test som vill pröva villkoret
 * sätter kolumnen explicit.
 *
 * @param  array<string, mixed>  $attribut
 */
function todovyRad(Schedule $schema, string $due, array $attribut = []): ScheduleOccurrence
{
    return ScheduleOccurrence::factory()->create(array_merge([
        'schedule_id' => $schema->id,
        'due_at' => $due,
        'visible_from' => todovyDatum(-30),
        'status' => 'open',
    ], $attribut));
}

/**
 * En färdig uppgift: ett schema med sin öppna förekomst.
 *
 * @param  array<string, mixed>  $radAttribut
 * @return array{0: Schedule, 1: ScheduleOccurrence}
 */
function todovyUppgift(Item $item, string $due, string $titel = 'Byt olja', array $radAttribut = []): array
{
    $schema = todovySchema($item, $due, ['title' => $titel]);

    return [$schema, todovyRad($schema, $due, $radAttribut)];
}

/**
 * Ett konto med en medlem, en container och ett item i den.
 *
 * @return array{0: Account, 1: User, 2: Container, 3: Item}
 */
function todovyKontext(string $itemNamn = 'Motorn'): array
{
    [$konto, $anvandare] = todovyKonto();

    $container = todovyPärm($konto);

    return [$konto, $anvandare, $container, todovyItem($container, $itemNamn)];
}

/**
 * En mottagare UTANFÖR ägarkontot, med en grant på angiven nivå — item-bred
 * när $item ges, container-bred annars.
 */
function todovyMottagare(Container $container, ?Item $item, string $niva, ?User $mottagare = null): User
{
    $mottagare ??= User::factory()->create(['locale' => 'sv_SE']);

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item?->id,
        'grantee_type' => 'user',
        'grantee_id' => $mottagare->id,
        'level' => $niva,
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    return $mottagare;
}

/**
 * Alla rader i svaret, i gruppernas ritningsordning: försenat, idag, kommande.
 *
 * Ordningen är `due_at` stigande — varje försenad rad ligger före varje rad som
 * förfaller idag, som i sin tur ligger före varje kommande.
 *
 * @return list<array<string, mixed>>
 */
function todovyRader(TestResponse $svar): array
{
    /** @var array<string, list<array<string, mixed>>> $grupper */
    $grupper = $svar->inertiaProps()['groups'];

    return array_merge($grupper['overdue'], $grupper['today'], $grupper['upcoming']);
}

/**
 * En grupp ur svaret.
 *
 * @return list<array<string, mixed>>
 */
function todovyGrupp(TestResponse $svar, string $namn): array
{
    /** @var array<string, list<array<string, mixed>>> $grupper */
    $grupper = $svar->inertiaProps()['groups'];

    return $grupper[$namn];
}

/**
 * Antalet frågor $anrop ställer, mätt i en KALL request: ett första anrop
 * värmer guarderna, kontocachen, texten och `last_active_at`, och därefter
 * rensas den scoped-bundna omfångsmemon precis före mätningen.
 *
 * Rensningen är hela poängen och den skiljer sig från SokvyTest:s motsvarighet.
 * `ResolveItemScope` är `scoped` och memoiserar per `{user, container}` i
 * drift — en ny request i produktionen har en tom memo — men i testsviten
 * överlever instansen mellan HTTP-anropen. Utan rensningen hade det uppvärmda
 * anropet lagt BÅDE det gamla och det nya antalet rader i memon, och
 * jämförelsen hade varit konstant vad kontrollern än gjorde.
 */
function todovyFrågor(Closure $anrop): int
{
    $anrop();

    app()->forgetScopedInstances();

    $frågor = 0;

    DB::listen(function ($query) use (&$frågor) {
        if (! str_contains($query->sql, 'last_active_at')) {
            $frågor++;
        }
    });

    $anrop();

    return $frågor;
}

/**
 * Avbockningens URL, som raden i listan postar till — 63b:s rutt, byggd på
 * samma fyra ULID:n som webbens svar bär.
 */
function todovyAvbockUrl(Schedule $schema, ScheduleOccurrence $rad): string
{
    $container = $schema->item->container;

    return "/containers/{$container->ulid}/items/{$schema->item->ulid}"
        ."/schedules/{$schema->ulid}/occurrences/{$rad->ulid}/complete";
}

// --- urvalet ---------------------------------------------------------------

/*
 * Beslut 1: rutten ligger bakom `auth`. En utloggad besökare skickas till
 * inloggningen och når aldrig kontrollern.
 */
it('skickar en utloggad besökare till inloggningen', function () {
    withoutVite();

    get('/dashboard')->assertRedirect('/login');
});

/*
 * Klart när: `/dashboard` visar de öppna förekomsterna användaren når, i
 * `due_at`-ordning.
 *
 * Träffarna ligger i tre containers — två egna och en delad — och skapas i omvänd
 * ordning, så att en lista som behöll skapelseordningen faller. Två av raderna
 * delar `due_at` och prövar andra nyckeln, `ulid`.
 */
it('visar de öppna förekomsterna över alla containers användaren når, i due_at-ordning', function () {
    withoutVite();

    [$konto, $anvandare, $container, $item] = todovyKontext();

    $andra = todovyPärm($konto);

    // En container under ett FRÄMMANDE konto som användaren når genom en
    // container-bred grant — "alla containers användaren når" är inte "sina egna".
    $delad = todovyPärm(Account::factory()->create());
    todovyMottagare($delad, null, 'read', $anvandare);

    todovyUppgift($item, todovyDatum(90), 'Byt impeller');

    // En AVBOCKAD förekomst hör inte i listan, hur nära den än ligger.
    [, $avbockad] = todovyUppgift(todovyItem($andra, 'Ankaret'), todovyDatum(30), 'Inspektera linan');
    $avbockad->status = 'completed';
    $avbockad->completed_at = now();
    $avbockad->save();

    // Samma förfallodag, i skapelseordning: `ulid` stigande är den andra
    // nyckeln, och ULID:n är monoton i tiden.
    [, $först] = todovyUppgift(todovyItem($delad, 'Backen'), todovyDatum(60), 'Smörj backen');
    [, $senare] = todovyUppgift(todovyItem($delad, 'Bogen'), todovyDatum(60), 'Spänn bogen');

    $svar = actingAs($anvandare)->get('/dashboard')->assertOk();

    $rader = todovyRader($svar);

    expect(array_column($rader, 'due_at'))->toBe([
        todovyDatum(60),
        todovyDatum(60),
        todovyDatum(90),
    ]);

    // Andra nyckeln: samma datum, stigande ULID — alltså skapelseordningen.
    expect(array_slice(array_column($rader, 'ulid'), 0, 2))->toBe([$först->ulid, $senare->ulid]);

    // Båda containerna med en öppen uppgift syns — också den delade. Containern vars
    // enda förekomst är avbockad gör det inte.
    $pärmar = array_column(array_column($rader, 'container'), 'ulid');

    expect($pärmar)->toContain($container->ulid, $delad->ulid);
    expect($pärmar)->not->toContain($andra->ulid);
    expect($svar->getContent())->not->toContain($avbockad->ulid);
});

/*
 * Klart när: en förekomst vars `visible_from` ligger i framtiden inte syns.
 *
 * Villkoret är `scopeTodoFor()`:s (issue 24 § Beslut 3) och prövas här genom
 * webbsidan — vyn får aldrig en egen filtrering att glömma det i.
 */
it('visar inte en förekomst vars visible_from ligger i framtiden', function () {
    withoutVite();

    [, $anvandare, , $item] = todovyKontext();

    todovyUppgift($item, todovyDatum(30), 'Synlig nu');

    $doldSchema = todovySchema($item, todovyDatum(60), ['title' => 'Dold än']);
    $dold = todovyRad($doldSchema, todovyDatum(60), ['visible_from' => todovyDatum(30)]);

    $svar = actingAs($anvandare)->get('/dashboard')->assertOk();

    expect(array_column(array_column(todovyRader($svar), 'schedule'), 'title'))
        ->toBe(['Synlig nu'])
        ->and($svar->getContent())->not->toContain($dold->ulid);
});

/*
 * Klart när: en blockerad förekomst inte syns.
 *
 * Ett öppet beroende gör uppgiften omöjlig att bocka av, och den som inte går
 * att göra nu hör inte i listan över vad man ska göra (villkor tre i
 * `scopeTodoFor`). Riktningen är den lagrade: raden med `occurrence_id` är den
 * som VÄNTAR.
 */
it('visar inte en blockerad förekomst', function () {
    withoutVite();

    [, $anvandare, , $item] = todovyKontext();

    [, $först] = todovyUppgift($item, todovyDatum(30), 'Byt impeller');
    [, $väntar] = todovyUppgift($item, todovyDatum(31), 'Provkör motorn');

    OccurrenceDependency::factory()->create([
        'occurrence_id' => $väntar->id,
        'depends_on_occurrence_id' => $först->id,
    ]);

    $svar = actingAs($anvandare)->get('/dashboard')->assertOk();

    expect(array_column(todovyRader($svar), 'ulid'))->toBe([$först->ulid])
        ->and($svar->getContent())->not->toContain($väntar->ulid);
});

/*
 * Klart när: en förekomst i en container användaren inte når aldrig syns.
 *
 * Det är hela läckagetestet. Både ULID:n och itemets namn prövas i
 * svarskroppen — ett svar som ser rätt ut men bär en rad för mycket är precis
 * felet, och en glömd `where` ger inget fel och inget larm.
 *
 * Den främmande radens `visible_from` ligger i det förflutna: det ENDA som
 * håller den borta är åtkomsten.
 */
it('visar aldrig en förekomst ur en container användaren inte når', function () {
    withoutVite();

    [, $anvandare, , $egetItem] = todovyKontext();
    todovyUppgift($egetItem, todovyDatum(30), 'Byt impeller');

    $främmande = todovyPärm(Account::factory()->create());
    todovyUppgift(todovyItem($främmande, 'Hemlig motor'), todovyDatum(30), 'Hemlig uppgift');

    $svar = actingAs($anvandare)->get('/dashboard')->assertOk();

    expect(todovyRader($svar))->toHaveCount(1)
        ->and($svar->getContent())->not->toContain('Hemlig uppgift')
        ->and($svar->getContent())->not->toContain('Hemlig motor')
        ->and($svar->getContent())->not->toContain($främmande->ulid);
});

/*
 * Klart när: en omfångsbegränsad mottagare ser bara uppgifter på items hon
 * når.
 *
 * Hon når containern genom en itemgrant men bara det itemet — den som når containern
 * når inte nödvändigtvis allt i den ([[ADR-0028 Åtkomst på itemnivå]] § Beslut
 * regel 3). Todo-listan är en TOPPNIVÅvy och hade annars namngett varje annat
 * items uppgifter i containern (issue 74 § Beslut 7).
 */
it('ger en omfångsbegränsad mottagare bara uppgifter på de items hon når', function () {
    withoutVite();

    $pärm = todovyPärm(Account::factory()->create());

    $mitt = todovyItem($pärm, 'Motorn');
    $dolt = todovyItem($pärm, 'Hemlig motor');

    todovyUppgift($mitt, todovyDatum(30), 'Byt impeller');
    todovyUppgift($mitt, todovyDatum(31), 'Byt olja');
    todovyUppgift($dolt, todovyDatum(32), 'Hemlig uppgift');

    $mottagare = todovyMottagare($pärm, $mitt, 'read');

    $svar = actingAs($mottagare)->get('/dashboard')->assertOk();

    expect(todovyRader($svar))->toHaveCount(2)
        ->and($svar->getContent())->not->toContain('Hemlig uppgift')
        ->and($svar->getContent())->not->toContain('Hemlig motor');
});

// --- grupperingen ----------------------------------------------------------

/*
 * Klart när: raderna grupperas i försenat, idag och kommande, räknat på
 * serverns datum.
 *
 * Serverns klocka flyttas till ett känt datum, så grupperingen prövas mot
 * fasta tal i stället för mot den dag sviten råkar köras.
 */
it('grupperar raderna i försenat, idag och kommande efter serverns datum', function () {
    withoutVite();

    Carbon::setTestNow('2026-06-15 10:00:00');

    [, $anvandare, , $item] = todovyKontext();

    // Alla tre är synliga idag — grupperingen är det som skiljer dem.
    $synlig = ['visible_from' => '2026-06-01'];

    [, $försenad] = todovyUppgift($item, '2026-06-10', 'Byt impeller', $synlig);
    [, $idag] = todovyUppgift($item, '2026-06-15', 'Byt olja', $synlig);
    [, $kommande] = todovyUppgift($item, '2026-06-20', 'Byt filter', $synlig);

    $svar = actingAs($anvandare)->get('/dashboard')->assertOk();

    expect(array_column(todovyGrupp($svar, 'overdue'), 'ulid'))->toBe([$försenad->ulid])
        ->and(array_column(todovyGrupp($svar, 'today'), 'ulid'))->toBe([$idag->ulid])
        ->and(array_column(todovyGrupp($svar, 'upcoming'), 'ulid'))->toBe([$kommande->ulid]);

    Carbon::setTestNow();
});

/*
 * Klart när: klientens klocka inte kan flytta en rad mellan grupperna.
 *
 * Två halvor. Den första: gruppen räknas om när SERVERNS datum flyttas fram —
 * raden som var "idag" är i morgon försenad, utan att något i raden ändrats.
 * Den andra: vyn jämför aldrig ett datum mot sin egen klocka; den ritar de tre
 * listor den fick, i den ordning de kom. En `computed` som jämförde `due_at`
 * mot `Date.now()` hade fallit på den andra halvan.
 */
it('låter serverns datum styra gruppen, inte klientens', function () {
    withoutVite();

    Carbon::setTestNow('2026-06-15 10:00:00');

    [, $anvandare, , $item] = todovyKontext();

    todovyUppgift($item, '2026-06-15', 'Byt olja', ['visible_from' => '2026-06-01']);

    actingAs($anvandare)->get('/dashboard')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('groups.today', 1)
            ->has('groups.overdue', 0)
    );

    // Ett dygn senare är samma rad försenad.
    Carbon::setTestNow('2026-06-16 10:00:00');

    actingAs($anvandare)->get('/dashboard')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('groups.today', 0)
            ->has('groups.overdue', 1)
    );

    Carbon::setTestNow();

    // Kommentarerna bort före kontrollen: det är KODEN som inte får jämföra
    // datum, och docblocken talar med flit om vilken klocka den inte läser.
    $vy = File::get(resource_path('js/pages/Dashboard.vue'));
    $vy = (string) preg_replace('#/\*.*?\*/#s', '', $vy);
    $vy = (string) preg_replace('#<!--.*?-->#s', '', $vy);

    expect($vy)->toContain('v-for="(entries, group) in groups"');
    expect($vy)->not->toContain('new Date');
    expect($vy)->not->toContain('Date.now');
});

// --- raden -----------------------------------------------------------------

/*
 * Klart när: varje rad visar container, item och schema, och länkarna går rätt.
 *
 * Alla tre behövs (Beslut 5): "Byt impeller" utan "Motorn" och "Havsörnen" går
 * inte att handla på när man har fyra containers. Länkarna prövas mot de href
 * ruttnamnen faktiskt ger — en vy som länkar till en påhittad adress hade
 * annars sett rätt ut i en strukturell kontroll.
 *
 * **Containernamnet går till itemlistan** (issue 89 · [[ADR-0039 Containerns
 * översikt]] § Konsekvenser). Det gjorde det före flytten också, men då sammanföll
 * listans adress med containerns egen; nu är de två, och `containers.show` — som
 * står kvar i raden ovan — pekar på översikten. Vyns href prövas därför för sig.
 */
it('visar container, item och schema med länkar som går rätt', function () {
    withoutVite();

    [, $anvandare, $container, $item] = todovyKontext();
    [$schema] = todovyUppgift($item, todovyDatum(30), 'Byt impeller');

    expect(route('containers.show', $container, false))->toBe("/containers/{$container->ulid}")
        ->and(route('containers.items.show', [$container, $item], false))
        ->toBe("/containers/{$container->ulid}/items/{$item->ulid}")
        ->and(route('containers.items.schedules.show', [$container, $item, $schema], false))
        ->toBe("/containers/{$container->ulid}/items/{$item->ulid}/schedules/{$schema->ulid}");

    actingAs($anvandare)->get('/dashboard')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('groups.upcoming.0.item.name', 'Motorn')
            ->where('groups.upcoming.0.container.name', $container->name)
            ->where('groups.upcoming.0.schedule.title', 'Byt impeller')
    );

    $rad = File::get(resource_path('js/components/TodoRow.vue'));

    expect($rad)->toContain('${props.entry.container.ulid}/items/${props.entry.item.ulid}')
        ->toContain("t('todo.due', { date: due })")
        ->toContain('{{ entry.item.name }}')
        ->toContain('{{ entry.container.name }}')
        ->toContain('{{ entry.schedule.title }}');

    // Containernamnet går till ITEMLISTAN, inte till containerns egen URL —
    // den svarar med översikten sedan issue 89 · [[ADR-0039 Containerns
    // översikt]] § Konsekvenser. Utan `/items` hade raden sett rätt ut i en
    // strukturell kontroll och landat fel i drift.
    expect($rad)->toContain(':href="`/containers/${entry.container.ulid}/items`"');
});

/*
 * Klart när: en uppgift kan bockas av från listan, och listan ritas om utan
 * den.
 *
 * Avbockningen är 63b:s rutt rakt av (Beslut 4): samma grind, samma
 * FormRequest, samma Action. Kontot som postas är det servern räknade fram —
 * containerns ägarkonto, eftersom användaren är medlem i det.
 *
 * Nästa förekomst öppnas i samma transaktion av `CloseOccurrence`, med
 * `visible_from = due_at`, och ligger därför i framtiden: listan blir tom och
 * den avbockade raden syns inte.
 */
it('bockar av en uppgift från listan och ritar om utan den', function () {
    withoutVite();

    [$konto, $anvandare, , $item] = todovyKontext();
    [$schema, $rad] = todovyUppgift($item, todovyDatum(30), 'Byt impeller');

    $svar = actingAs($anvandare)->get('/dashboard')->assertOk();

    // Förvalet är serverns (Beslut 4): containerns ägarkonto när hon är medlem.
    expect(todovyRader($svar)[0]['account'])->toBe($konto->ulid);

    actingAs($anvandare)->from('/dashboard')
        ->post(todovyAvbockUrl($schema, $rad), ['account' => $konto->ulid])
        ->assertRedirect('/dashboard')
        ->assertSessionHas('status', 'occurrence-completed');

    expect($rad->fresh()->status)->toBe('completed');

    $efter = actingAs($anvandare)->get('/dashboard')->assertOk();

    expect(todovyRader($efter))->toBe([])
        ->and($efter->getContent())->not->toContain($rad->ulid);
});

/*
 * Klart när: en användare utan rätt att bocka av ser ingen knapp och får 403
 * om hon postar ändå.
 *
 * Grinden är itemets `update` (issue 71 § Beslut 5, 63b § Beslut 1): att bocka
 * av ändrar en förekomst som redan finns. Flaggan är presentation; rutten
 * auktoriserar ändå.
 */
it('ritar ingen avbockningsknapp för en läsare och ger 403 om hon postar ändå', function () {
    withoutVite();

    [$konto, , $container, $item] = todovyKontext();
    [$schema, $rad] = todovyUppgift($item, todovyDatum(30), 'Byt impeller');

    $lasare = todovyMottagare($container, null, 'read');

    $rader = todovyRader(actingAs($lasare)->get('/dashboard')->assertOk());

    expect($rader)->toHaveCount(1)
        ->and($rader[0]['can']['update'])->toBeFalse();

    // Knappen är presentation; grinden är Gate::authorize('update', item).
    actingAs($lasare)->from('/dashboard')
        ->post(todovyAvbockUrl($schema, $rad), ['account' => $konto->ulid])
        ->assertForbidden();

    expect($rad->fresh()->status)->toBe('open');

    $vy = File::get(resource_path('js/components/TodoRow.vue'));

    expect($vy)->toContain('v-if="entry.can.update"');
});

/*
 * Klart när: kontot som postas är mottagarens EGET, inte containerns.
 *
 * Regeln är 63b § Beslut 4: containerns ägarkonto när användaren är medlem i det,
 * annars hennes första konto. En `write`-mottagare utanför ägarkontot får
 * alltså inte containerns konto som förval — det är ett konto hon inte får skriva
 * i, och rutten hade svarat 403.
 */
it('ger en mottagare utanför ägarkontot sitt eget konto som förval', function () {
    withoutVite();

    [, , $container, $item] = todovyKontext();
    todovyUppgift($item, todovyDatum(30), 'Byt impeller');

    $mottagare = todovyMottagare($container, null, 'write');

    $hennesKonto = Account::factory()->create(['locale' => 'sv_SE']);
    $hennesKonto->users()->attach($mottagare, ['role' => 'owner']);

    $rader = todovyRader(actingAs($mottagare)->get('/dashboard')->assertOk());

    expect($rader)->toHaveCount(1)
        ->and($rader[0]['account'])->toBe($hennesKonto->ulid)
        ->and($rader[0]['account'])->not->toBe($container->account->ulid)
        ->and($rader[0]['can']['update'])->toBeTrue();
});

// --- de tomma lägena -------------------------------------------------------

/*
 * Klart när: tom lista utan containers och tom lista utan uppgifter ger olika
 * meningar.
 *
 * En användare med ett konto men ingen container får `hasContainers = false`, en
 * med en container men inga uppgifter `true`. Grupperna är tomma i båda fallen —
 * det är meningen, och länken till att skapa en container, som skiljer dem.
 */
it('skiljer en tom lista utan containers från en tom lista utan uppgifter', function () {
    withoutVite();

    [, $utanPärmar] = todovyKonto();

    // Containern finns, uppgifterna gör det inte: ett item utan schema.
    [, $medPärm] = todovyKontext();

    $utan = actingAs($utanPärmar)->get('/dashboard')->assertOk();
    $med = actingAs($medPärm)->get('/dashboard')->assertOk();

    expect($utan->inertiaProps()['hasContainers'])->toBeFalse()
        ->and($med->inertiaProps()['hasContainers'])->toBeTrue();

    $vy = File::get(resource_path('js/pages/Dashboard.vue'));

    expect($vy)->toContain("t('todo.empty.nothing')")
        ->toContain("t('todo.empty.no_containers')")
        ->toContain('href="/containers/create"');

    $sv = require lang_path('en/ui.php');
    $en = require lang_path('en/ui.php');

    expect($sv['todo']['empty']['no_containers'])->not->toBe($sv['todo']['empty']['nothing'])
        ->and($en['todo']['empty']['no_containers'])->not->toBe($en['todo']['empty']['nothing']);
});

/*
 * Klart när: en omfångsbegränsad mottagare med tom lista får exakt samma svar
 * som en ägare vars uppgifter är gjorda — ingen av dem nämner dolda rader
 * (Beslut 6, issue 73 § Beslut 6, issue 74).
 *
 * Den som når en container men ingenting i den får ordagrant samma props som den
 * som gjort allt: samma tomma grupper, samma `hasContainers`, och ingen rad i
 * svarskroppen som röjer att det fanns uppgifter att dölja.
 */
it('ger en mottagare utan synliga uppgifter samma tomma svar som en färdig ägare', function () {
    withoutVite();

    // Ägaren: en container vars enda uppgift redan är avbockad.
    [, $ägare, , $egetItem] = todovyKontext();
    [, $avbockad] = todovyUppgift($egetItem, todovyDatum(30), 'Byt impeller');
    $avbockad->status = 'completed';
    $avbockad->completed_at = now();
    $avbockad->save();

    // Mottagaren: en itemgrant på ett item HELT utan uppgifter, i en container där
    // ett annat item har en öppen uppgift hon inte når.
    $främmande = todovyPärm(Account::factory()->create());
    $hennes = todovyItem($främmande, 'Motorn');
    todovyUppgift(todovyItem($främmande, 'Hemlig motor'), todovyDatum(30), 'Hemlig uppgift');

    $mottagare = todovyMottagare($främmande, $hennes, 'read');

    $ägarensSvar = actingAs($ägare)->get('/dashboard')->assertOk();
    $mottagarensSvar = actingAs($mottagare)->get('/dashboard')->assertOk();

    expect($mottagarensSvar->inertiaProps()['groups'])->toBe($ägarensSvar->inertiaProps()['groups'])
        ->and($mottagarensSvar->inertiaProps()['hasContainers'])->toBeTrue()
        ->and($ägarensSvar->inertiaProps()['hasContainers'])->toBeTrue()
        ->and($mottagarensSvar->getContent())->not->toContain('Hemlig uppgift')
        ->and($mottagarensSvar->getContent())->not->toContain('Hemlig motor');

    // Och meningarna bär varken ett tal eller en parameter — ingenting att
    // räkna ut omfånget ur.
    $sv = require lang_path('en/ui.php');
    $en = require lang_path('en/ui.php');

    foreach ([$sv, $en] as $fil) {
        foreach ($fil['todo']['empty'] as $nyckel => $mening) {
            expect($mening)->not->toMatch('/\d/', "todo.empty.{$nyckel} bär ett tal")
                ->and($mening)->not->toContain(':');
        }
    }
});

// --- kostnaden -------------------------------------------------------------

/*
 * Klart när: listan kostar ett konstant antal frågor oavsett antal rader, mätt
 * med DB::listen (Beslut 8).
 *
 * Raderna läggs i NYA containers, ett värstingfall för en `can`-flagga som hade
 * kostat en omfångsupplösning per container: kontrollern värmer omfånget i ett
 * anrop för de containers listan bär, `schedule.item.container.account` laddas i
 * förväg, och `with()`:en gör att raderna själva inte kostar något.
 */
it('kostar ett konstant antal frågor oavsett antal rader', function () {
    withoutVite();

    [$konto, $anvandare, , $item] = todovyKontext();

    todovyUppgift($item, todovyDatum(30), 'Byt impeller');

    actingAs($anvandare);

    $url = '/dashboard';

    $medEtt = todovyFrågor(function () use ($url) {
        get($url)->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page->has('groups.upcoming', 1)
        );
    });

    foreach (range(2, 10) as $i) {
        $pärm = todovyPärm($konto);
        todovyUppgift(todovyItem($pärm, "Item $i"), todovyDatum(30), "Uppgift $i");
    }

    $medTio = todovyFrågor(function () use ($url) {
        get($url)->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page->has('groups.upcoming', 10)
        );
    });

    expect($medTio)->toBe($medEtt);
});

// --- API:et rörs inte ------------------------------------------------------

/*
 * Klart när: `/api/todo` svarar exakt som förut.
 *
 * Webben LÅNAR listan; den bygger ingen egen. Resursen fick inga nya nycklar
 * av `account` och `can` — de ligger BREDVID den i webbens props, samma
 * uppdelning som SearchController gör med containern, och `/api` har inte bett om
 * dem.
 */
it('lämnar /api/todo orört och lägger webbens nycklar bredvid resursen', function () {
    withoutVite();

    [, $anvandare, , $item] = todovyKontext();
    todovyUppgift($item, todovyDatum(30), 'Byt impeller');

    $token = $anvandare->createToken('api');

    $api = getJson('/api/todo', ['Authorization' => "Bearer {$token->plainTextToken}"])
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.schedule.title', 'Byt impeller')
        ->assertJsonPath('data.0.item.name', 'Motorn');

    $apiRaden = $api->json('data.0');

    expect(array_keys($apiRaden))->toBe([
        'ulid',
        'due_at',
        'visible_from',
        'overdue',
        'schedule',
        'item',
        'container',
    ]);

    // Samma rad i webbens svar, med de två nycklarna bredvid — aldrig inuti.
    $rad = todovyRader(actingAs($anvandare)->get('/dashboard')->assertOk())[0];

    expect($rad['ulid'])->toBe($apiRaden['ulid'])
        ->and(array_keys($rad))->toBe([...array_keys($apiRaden), 'account', 'can']);
});

// --- språket ---------------------------------------------------------------

/*
 * Klart när: ingen svensk sträng står kvar i en `.vue`-fil; varje ny nyckel
 * finns på `sv` och `en`.
 *
 * Den första halvan vaktas av SprakTest, som läser varje fil under
 * resources/js. Här prövas den andra: nycklarna under `todo`, nyckel för
 * nyckel, och att gruppnycklarna är desamma som kontrollerns grupper.
 */
it('har varje todo-nyckel och ingen svensk sträng i vyn', function () {
    $sv = require lang_path('en/ui.php');
    $en = require lang_path('en/ui.php');

    expect(array_keys($en['todo']))->toBe(array_keys($sv['todo']))
        ->and(array_keys($en['todo']['group']))->toBe(array_keys($sv['todo']['group']))
        ->and(array_keys($en['todo']['empty']))->toBe(array_keys($sv['todo']['empty']));

    foreach ($sv['todo'] as $nyckel => $varde) {
        if (is_array($varde)) {
            continue;
        }

        expect(trim($varde))->not->toBe('', "todo.{$nyckel} är")
            ->and(trim($en['todo'][$nyckel]))->not->toBe('', "todo.{$nyckel} är tom på en");
    }

    // Gruppnycklarna är kontrollerns egna konstanter — ingen egen uppräkning i
    // JavaScript som kan glida ifrån serverns.
    expect(array_keys($sv['todo']['group']))->toBe([
        TodoController::GROUP_OVERDUE,
        TodoController::GROUP_TODAY,
        TodoController::GROUP_UPCOMING,
    ]);

    // Ingen svensk sträng utanför kommentar i de två nya komponenterna.
    foreach (['pages/Dashboard.vue', 'components/TodoRow.vue'] as $fil) {
        $kod = File::get(resource_path("js/{$fil}"));
        $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
        $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);
        $kod = (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);

        expect($kod)->not->toMatch('/[åäöÅÄÖ]/u', "svensk text utanför kommentar i {$fil}");
    }
});
