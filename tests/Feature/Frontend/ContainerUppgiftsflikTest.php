<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 174 · Containerns uppgiftsflik, se
 * app/Http/Controllers/ContainerTaskController.php,
 * app/Actions/Schedule/ListTodo::forContainer() och ::completedForContainer(),
 * routes/web.php, resources/js/layouts/containerSections.js,
 * resources/js/pages/Containers/Tasks.vue och [[ADR-0050 Desktopdesignen]]
 * § 4 och 16.
 *
 * Filen bevisar tavlans fyra kolumner och de tre beslut som skiljer dem åt:
 *
 * 1. **De tre öppna kolumnerna är `/tasks` egna grupper** (Beslut 2). Samma
 *    `ListTodo::forContainer()`, samma gruppering mot användarens dag
 *    ([[ADR-0044 Användarens dag]]), samma växel för framtida uppgifter —
 *    skillnaden är containerns avgränsning och att tavlan inte klipper
 *    listan. En förekomst hamnar i samma kolumn här som där, och det prövas
 *    genom att de två svaren jämförs rad för rad och inte mot en avskrift.
 * 2. **Klart är den nya frågan** (Beslut 3): avbockade förekomster i
 *    containern, `completed_at` fallande, högst tjugo, med samma radform som
 *    de öppna plus tidsstämpeln. Avbockad betyder `status = 'completed'` —
 *    en ÖVERHOPPAD förekomst (`skipped`) står i historiken och inte här
 *    (arkitektsvar på issue 174).
 * 3. **Underhållsfiltret står i querysträngen** (Beslut 4) och avgränsar alla
 *    fyra kolumnerna till `fixed` och `interval`. Det prövas i SVARET — en
 *    klient som sållade hade visat fel tavla för den som laddar om adressen,
 *    och ett filtrerat läge ska vara en adress man kan spara och dela
 *    (issue 59a § Beslut 1).
 *
 * Därtill omfånget, som är samma regel som `/tasks` lyder under: en gäst med
 * en itemgrant ser sina items uppgifter och inga andras (issue 74 § Beslut 7),
 * och det gäller BÅDA listorna — en avbockad rad är samma uppgift som den
 * öppna var, och en glömd `where` i den ena ger varken fel eller larm.
 *
 * **Det som INTE prövas här** är det som kräver en webbläsare: att tavlan ser
 * ut som docs/Design/uppgifter_1.png, att den blir en lista under `md:`, och
 * att kryssrutan känns rätt i handen. Formen på `UiListRow` prövas i
 * YtornaTest, träffytan i GenomgangTest, och handprovet står i PR-kroppen.
 *
 * **Klockan pinnas till mitt på dagen UTC** ([[ADR-0044 Användarens dag]]),
 * samma skäl och samma grepp som ContainerOversiktTest: servern går i UTC och
 * användaren i Europe/Stockholm, och mellan klockan 22 och 24 UTC är hennes
 * datum redan i morgondagen. Ett prov som vill se skillnaden flyttar klockan
 * själv — se provet om användarens dag.
 *
 * Hjälparna har prefixet `uppgiftsflik` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::today()->setTime(12, 0));
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * En dag relativt serverns idag, som DATE-sträng.
 */
function uppgiftsflikDatum(int $dagar): string
{
    return Carbon::today()->addDays($dagar)->toDateString();
}

/**
 * Ett ägarkonto med en medlem i.
 *
 * @return array{0: Account, 1: User}
 */
function uppgiftsflikKonto(): array
{
    $konto = Account::factory()->create(['locale' => 'sv_SE']);
    $anvandare = User::factory()->create(['locale' => 'sv_SE']);
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    return [$konto, $anvandare];
}

/**
 * En container under $konto.
 */
function uppgiftsflikParm(Account $konto, string $namn = 'Havsörnen'): Container
{
    return Container::factory()->for($konto, 'account')->create(['name' => $namn]);
}

/**
 * Ett item i containern, med kända `created_by_*` — samma form som
 * oversiktItem() i ContainerOversiktTest.
 */
function uppgiftsflikItem(Container $container, string $namn): Item
{
    return Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => User::factory()->create()->id,
        'created_by_account_id' => $container->account_id,
    ]);
}

/**
 * Ett aktivt schema på itemet. `$typ` är `recurrence_type` och är hela
 * skillnaden mellan ett underhåll och en engångsuppgift (Beslut 4) — `none`
 * är återkommandetypen som betyder "ingen återkomst".
 *
 * @param  array<string, mixed>  $attribut
 */
function uppgiftsflikSchema(Item $item, string $titel, string $typ = 'interval', array $attribut = []): Schedule
{
    return Schedule::factory()->for($item, 'item')->create(array_merge([
        'title' => $titel,
        'recurrence_type' => $typ,
        'interval_unit' => 'month',
        'interval_count' => 12,
        'anchor_date' => uppgiftsflikDatum(30),
        'lead_days' => 0,
        'is_active' => true,
    ], $attribut));
}

/**
 * En öppen förekomst på schemat, med känd förfallodag.
 *
 * Raden byggs direkt i stället för genom App\Actions\Schedule\
 * OpenNextOccurrence, av samma skäl som i ContainerOversiktTest: förfallodagen
 * ska vara känd utan att räkna kalender. `visible_from` ärver INTE `due_at`:
 * en uppgift som förfaller framåt är synlig NU, och det är `visible_from` som
 * avgör det.
 */
function uppgiftsflikOppna(Schedule $schema, string $due): ScheduleOccurrence
{
    return ScheduleOccurrence::factory()->create([
        'schedule_id' => $schema->id,
        'due_at' => $due,
        'visible_from' => uppgiftsflikDatum(-30),
        'status' => 'open',
    ]);
}

/**
 * En AVBOCKAD förekomst på schemat, stängd vid en tid vi väljer — "nyast
 * först" går inte att bevisa utan ett `completed_at` vi bestämmer.
 *
 * Produktionen stänger genom App\Actions\Schedule\CloseOccurrence, som sätter
 * både `status` och tidsstämpeln; fabrikens `completed()`-state gör samma två
 * saker, och provet handlar om LÄSNINGEN.
 */
function uppgiftsflikKlar(Schedule $schema, ?Carbon $nar = null): ScheduleOccurrence
{
    return ScheduleOccurrence::factory()->completed()->create([
        'schedule_id' => $schema->id,
        'due_at' => uppgiftsflikDatum(-1),
        'visible_from' => uppgiftsflikDatum(-30),
        'completed_at' => $nar ?? now(),
    ]);
}

/**
 * En ÖVERHOPPAD förekomst — stängd utan att jobbet blev gjort. `skip` sätter
 * samma `completed_at` som avbockningen (App\Actions\Schedule\CloseOccurrence
 * steg 2), och det är hela skillnaden mellan de två raderna: `status`.
 */
function uppgiftsflikOverhoppad(Schedule $schema, ?Carbon $nar = null): ScheduleOccurrence
{
    return ScheduleOccurrence::factory()->completed()->create([
        'schedule_id' => $schema->id,
        'due_at' => uppgiftsflikDatum(-1),
        'visible_from' => uppgiftsflikDatum(-30),
        'status' => ScheduleOccurrence::STATUS_SKIPPED,
        'completed_at' => $nar ?? now(),
    ]);
}

/**
 * En mottagare UTANFÖR ägarkontot, med en grant på ETT item — den smala
 * granten är vad som gör omfånget begränsat. `$item` satt är granten
 * item-bred, osatt är den container-bred.
 */
function uppgiftsflikGast(Container $container, ?Item $item = null, string $niva = 'read'): User
{
    $gast = User::factory()->create(['locale' => 'sv_SE']);

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item?->id,
        'grantee_type' => 'user',
        'grantee_id' => $gast->id,
        'level' => $niva,
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    return $gast;
}

/**
 * Flikens adress, med en valfri querysträng.
 */
function uppgiftsflikUrl(Container $container, ?string $fraga = null): string
{
    $url = "/containers/{$container->ulid}/tasks";

    return $fraga === null ? $url : "{$url}?{$fraga}";
}

/**
 * Schematitlarna i en lista av rader, i serverns ordning.
 *
 * @param  list<array<string, mixed>>  $rader
 * @return list<string>
 */
function uppgiftsflikTitlar(array $rader): array
{
    return array_map(fn (array $rad): string => $rad['schedule']['title'], $rader);
}

/**
 * En grupp ur svaret.
 *
 * @return list<array<string, mixed>>
 */
function uppgiftsflikGrupp(TestResponse $svar, string $namn): array
{
    /** @var array<string, list<array<string, mixed>>> $grupper */
    $grupper = $svar->inertiaProps()['groups'];

    return $grupper[$namn];
}

/**
 * En karta ulid → grupp, ur `/tasks` eller ur fliken. Båda svaren har samma
 * form, så de kan jämföras rad för rad i stället för mot en avskrift av vad
 * grupperna borde vara.
 *
 * @return array<string, string>
 */
function uppgiftsflikKarta(TestResponse $svar): array
{
    $karta = [];

    foreach (['overdue', 'today', 'upcoming'] as $grupp) {
        foreach (uppgiftsflikGrupp($svar, $grupp) as $rad) {
            $karta[$rad['ulid']] = $grupp;
        }
    }

    return $karta;
}

/**
 * Källkoden med kommentarer borta, ur en sökväg relativt repots rot. Samma tre
 * slag som GenomgangTest rensar: blockkommentarer, HTML-kommentarer och
 * radkommentarer — docblocken är svenska med flit (AGENTS.md § Språk i
 * koden), och en regel som letar efter en markup eller ett `where` ska inte
 * kunna nöjas av en mening i en docblock.
 */
function uppgiftsflikKod(string $sokvag): string
{
    $kod = File::get(base_path($sokvag));
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/*
 * Klart när: en förekomst hamnar i samma grupp som på tasks.
 *
 * Beslut 2:s hela innehåll. Jämförelsen är mot `/tasks` EGET svar och inte mot
 * en avskrift av vad grupperna borde vara: två ytor som formulerar samma fråga
 * var för sig glider isär, och den ena hade glömt användarens dag eller
 * växeln. Här svarar båda ur samma `forContainer()`/`handle()`, och provet
 * fäller den dag någon ger fliken en egen gruppering.
 *
 * Raderna läggs i alla tre grupperna, så att en tavla som ritade allt i första
 * kolumnen faller — och ordningen prövas, inte bara mängden.
 */
it('en förekomst hamnar i samma grupp som på tasks', function () {
    withoutVite();

    [$konto, $anvandare] = uppgiftsflikKonto();
    $container = uppgiftsflikParm($konto);

    $motorn = uppgiftsflikItem($container, 'Motorn');

    uppgiftsflikOppna(uppgiftsflikSchema($motorn, 'Försenad'), uppgiftsflikDatum(-5));
    uppgiftsflikOppna(uppgiftsflikSchema($motorn, 'I dag'), uppgiftsflikDatum(0));
    uppgiftsflikOppna(uppgiftsflikSchema($motorn, 'Kommande'), uppgiftsflikDatum(10));

    $tasks = actingAs($anvandare)->get('/tasks')->assertOk();
    $fliken = actingAs($anvandare)->get(uppgiftsflikUrl($container))->assertOk();

    expect(uppgiftsflikKarta($tasks))->toHaveCount(3)
        ->and(uppgiftsflikKarta($fliken))->toBe(uppgiftsflikKarta($tasks));

    // Och raderna är samma rader i samma ordning: en flik som visade
    // containern men tappade sorteringen hade sett rätt ut i en jämförelse av
    // mängder.
    $ulids = fn (TestResponse $svar): array => array_merge(
        array_column(uppgiftsflikGrupp($svar, 'overdue'), 'ulid'),
        array_column(uppgiftsflikGrupp($svar, 'today'), 'ulid'),
        array_column(uppgiftsflikGrupp($svar, 'upcoming'), 'ulid'),
    );

    expect($ulids($fliken))->toBe($ulids($tasks))
        ->and(uppgiftsflikTitlar(uppgiftsflikGrupp($fliken, 'overdue')))->toBe(['Försenad'])
        ->and(uppgiftsflikTitlar(uppgiftsflikGrupp($fliken, 'today')))->toBe(['I dag'])
        ->and(uppgiftsflikTitlar(uppgiftsflikGrupp($fliken, 'upcoming')))->toBe(['Kommande']);
});

/*
 * Klart när: gruppen Idag följer användarens dag (tidszon där dagen skiljer
 * sig från UTC).
 *
 * [[ADR-0044 Användarens dag]] § Beslut 1. Klockan står på 23:30 UTC, och i
 * Stockholm är det redan den 27:e. En uppgift som förfaller den 27:e hör
 * därför till *Idag* och en som förfaller den 26:e är försenad — mot serverns
 * datum hade båda svarat fel, och den ena hade hamnat i fel kolumn.
 *
 * Provet prövar båda ytorna: fliken OCH `/tasks`. Att bara den ena följde
 * användarens dag hade varit två svar på samma fråga, och den som tittade på
 * den ena skärmen hade fått ett annat svar än den som tittade på den andra.
 */
it('gruppen Idag följer användarens dag', function () {
    withoutVite();

    $konto = Account::factory()->create(['timezone' => 'Europe/Stockholm', 'locale' => 'sv_SE']);
    $anvandare = User::factory()->create(['timezone' => 'Europe/Stockholm', 'locale' => 'sv_SE']);
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    $container = uppgiftsflikParm($konto);
    $motorn = uppgiftsflikItem($container, 'Motorn');

    Carbon::setTestNow(Carbon::parse('2026-09-26 23:30:00', 'UTC'));

    // Serverns dag är den 26:e; hennes är den 27:e.
    uppgiftsflikOppna(uppgiftsflikSchema($motorn, 'I dag för henne'), '2026-09-27');
    uppgiftsflikOppna(uppgiftsflikSchema($motorn, 'Försenad för henne'), '2026-09-26');

    foreach (['/tasks', uppgiftsflikUrl($container)] as $url) {
        $svar = actingAs($anvandare)->get($url)->assertOk();

        expect(uppgiftsflikTitlar(uppgiftsflikGrupp($svar, 'today')))->toBe(['I dag för henne'])
            ->and(uppgiftsflikTitlar(uppgiftsflikGrupp($svar, 'overdue')))->toBe(['Försenad för henne'])
            ->and(uppgiftsflikGrupp($svar, 'upcoming'))->toBe([]);
    }

    Carbon::setTestNow();
});

/*
 * Klart när: Klart visar avbockade förekomster i containern nyast först, högst
 * tjugo.
 *
 * Beslut 3, och provet fäster alla tre halvorna:
 *
 *   - **Markören är `completed_at`.** En ÖPPEN förekomst hör inte i *Klart*
 *     hur nära sin förfallodag den än ligger — de två listorna är varandras
 *     komplement.
 *   - **Ordningen är fallande.** Tjugoen rader med var sin tid, och den
 *     NYASTE först; ett prov som bara räknade raderna hade godtagit vilken
 *     ordning som helst.
 *   - **Taket är tjugo.** Den tjugoförsta raden — den äldsta — finns inte i
 *     svaret, och det prövas på ULID:n och inte på antalet: en lista som
 *     klippte bort den nyaste hade också haft tjugo rader.
 *
 * Raden bär samma fält som de öppna raderna plus `completed_at` (Beslut 3):
 * `account` och `can` ligger BREDVID resursen, precis som i de tre öppna
 * kolumnerna. En egen form för den avbockade raden hade glidit isär från dem
 * — den ena hade tappat `can`-flaggan och ritat en avbockningsknapp för den
 * som inte får bocka av.
 */
it('Klart visar avbockade förekomster i containern nyast först, högst tjugo', function () {
    withoutVite();

    [$konto, $anvandare] = uppgiftsflikKonto();
    $container = uppgiftsflikParm($konto);
    $motorn = uppgiftsflikItem($container, 'Motorn');

    // Tjugoen avbockade, en minut i sänder: den först skapade är den ÄLDSTA.
    $klara = [];

    foreach (range(0, 20) as $i) {
        $klara[] = uppgiftsflikKlar(
            uppgiftsflikSchema($motorn, "Klar {$i}"),
            now()->subMinutes($i),
        );
    }

    // En öppen förekomst hör i en annan kolumn.
    $öppen = uppgiftsflikOppna(uppgiftsflikSchema($motorn, 'Kvar att göra'), uppgiftsflikDatum(3));

    $svar = actingAs($anvandare)->get(uppgiftsflikUrl($container))->assertOk();

    $rader = $svar->inertiaProps()['completed'];

    expect($rader)->toHaveCount(20);

    // Nyast först: den först skapade (äldst) står sist, och den tjugoförsta
    // finns inte alls.
    expect(array_column($rader, 'ulid'))->toBe(array_map(
        fn (ScheduleOccurrence $rad): string => $rad->ulid,
        array_slice($klara, 0, 20),
    ));

    expect(array_column($rader, 'ulid'))->not->toContain($klara[20]->ulid)
        ->and($svar->getContent())->not->toContain($klara[20]->ulid);

    // Den öppna raden står i sin kolumn och inte i den här.
    expect(array_column($rader, 'ulid'))->not->toContain($öppen->ulid)
        ->and(uppgiftsflikTitlar(uppgiftsflikGrupp($svar, 'upcoming')))->toBe(['Kvar att göra']);

    // Samma form som de öppna raderna, plus tidsstämpeln. Nycklarna läses ur
    // den öppna radens eget svar i stället för att skrivas av här: de två
    // listorna ska bära samma fält, och en avskrift hade inte kunnat se att de
    // gled isär.
    $öppenRad = uppgiftsflikGrupp($svar, 'upcoming')[0];

    expect(array_keys($rader[0]))->toBe([...array_keys($öppenRad), 'completed_at'])
        ->and($rader[0]['completed_at'])->toBe($klara[0]->completed_at->toIso8601String())
        ->and($rader[0]['item']['name'])->toBe('Motorn')
        ->and($rader[0]['schedule']['title'])->toBe('Klar 0')
        ->and($rader[0]['can']['update'])->toBeTrue();
});

/*
 * Klart när: en överhoppad förekomst står inte i Klart (arkitektsvar på issue
 * 174).
 *
 * [[Scheman och uppgifter]] § schedule_occurrence håller `completed` och
 * `skipped` som två egna statusvärden, och samma avsnitt säger att de
 * avklarade förekomsterna är svaret på "när bytte jag impellern senast". En
 * överhoppad rad under *Klart* hade påstått ett byte som inte gjordes.
 *
 * Den överhoppade raden är den NYASTE i provet, så ett svar som bara råkade
 * klippa bort den inte klarar sig: den hade legat först om `status` inte
 * prövades. Gränsen på tjugo prövas i provet ovanför och räknas efter
 * statusfiltret.
 */
it('en överhoppad förekomst står inte i Klart', function () {
    withoutVite();

    [$konto, $anvandare] = uppgiftsflikKonto();
    $container = uppgiftsflikParm($konto);
    $motorn = uppgiftsflikItem($container, 'Motorn');

    $avbockad = uppgiftsflikKlar(uppgiftsflikSchema($motorn, 'Byt impeller'), now()->subHour());
    $overhoppad = uppgiftsflikOverhoppad(uppgiftsflikSchema($motorn, 'Byt olja'), now());

    $svar = actingAs($anvandare)->get(uppgiftsflikUrl($container))->assertOk();

    $rader = $svar->inertiaProps()['completed'];

    expect(uppgiftsflikTitlar($rader))->toBe(['Byt impeller'])
        ->and(array_column($rader, 'ulid'))->toBe([$avbockad->ulid])
        ->and(array_column($rader, 'ulid'))->not->toContain($overhoppad->ulid)
        // Namnet läcker ingen annan väg heller: en överhoppad rad hade synts
        // i svaret även om listan klippts bort den ur kolumnen.
        ->and($svar->getContent())->not->toContain('Byt olja');
});

/*
 * Klart när: underhållsfiltret visar bara återkommande scheman i alla fyra
 * kolumner.
 *
 * Beslut 4: `?maintenance=1` avgränsar till `recurrence_type` i `fixed` och
 * `interval` — `Schedule::RECURRENCE_TYPES` utom `none`. Provet lägger en
 * uppgift av varje typ i VARJE kolumn, så att ett filter som bara rörde de
 * öppna grupperna (eller bara *Klart*) faller.
 *
 * Typerna räknas upp ur modellens konstant och inte ur en avskrift: en fjärde
 * återkommandetyp ska följa med i provet den dag den läggs till.
 */
it('underhållsfiltret visar bara återkommande scheman i alla fyra kolumner', function () {
    withoutVite();

    [$konto, $anvandare] = uppgiftsflikKonto();
    $container = uppgiftsflikParm($konto);
    $motorn = uppgiftsflikItem($container, 'Motorn');

    foreach (Schedule::RECURRENCE_TYPES as $i => $typ) {
        uppgiftsflikOppna(uppgiftsflikSchema($motorn, "{$typ} försenad", $typ), uppgiftsflikDatum(-3));
        uppgiftsflikOppna(uppgiftsflikSchema($motorn, "{$typ} i dag", $typ), uppgiftsflikDatum(0));
        uppgiftsflikOppna(uppgiftsflikSchema($motorn, "{$typ} kommande", $typ), uppgiftsflikDatum(9));
        // En minut i sänder, så *Klart* har en ordning som inte beror på
        // ULID:n: nyast först är den först skapade.
        uppgiftsflikKlar(uppgiftsflikSchema($motorn, "{$typ} klar", $typ), now()->subMinutes($i));
    }

    $utan = actingAs($anvandare)->get(uppgiftsflikUrl($container))->assertOk();

    expect($utan->inertiaProps()['maintenance'])->toBeFalse()
        // Den ofiltrerade tavlan bär `none` — återkommandetypen som betyder
        // "ingen återkomst" — så filtret kan inte ha rätt av en slump.
        ->and(uppgiftsflikTitlar(uppgiftsflikGrupp($utan, 'overdue')))->toBe([
            'none försenad',
            'fixed försenad',
            'interval försenad',
        ])
        ->and(uppgiftsflikTitlar($utan->inertiaProps()['completed']))->toBe([
            'none klar',
            'fixed klar',
            'interval klar',
        ]);

    $med = actingAs($anvandare)->get(uppgiftsflikUrl($container, 'maintenance=1'))->assertOk();

    expect($med->inertiaProps()['maintenance'])->toBeTrue()
        // De tre öppna kolumnerna: `none` är borta, `fixed` och `interval` är
        // kvar i var sin.
        ->and(uppgiftsflikTitlar(uppgiftsflikGrupp($med, 'overdue')))->toBe(['fixed försenad', 'interval försenad'])
        ->and(uppgiftsflikTitlar(uppgiftsflikGrupp($med, 'today')))->toBe(['fixed i dag', 'interval i dag'])
        ->and(uppgiftsflikTitlar(uppgiftsflikGrupp($med, 'upcoming')))->toBe(['fixed kommande', 'interval kommande'])
        // Och den fjärde kolumnen lyder samma filter.
        ->and(uppgiftsflikTitlar($med->inertiaProps()['completed']))->toBe(['fixed klar', 'interval klar']);
});

/*
 * Klart när: en uppgift på ett item utanför omfånget visas inte, varken öppen
 * eller klar.
 *
 * [[ADR-0028 Åtkomst på itemnivå]] § Beslut regel 3 och issue 74 § Beslut 7:
 * en gäst når containerrutten genom sin grant och bara det item granten pekar
 * på. Att listan är avgränsad till containern räcker alltså inte — en flik som
 * visade containerns uppgifter hade namngett varje annat items uppgifter.
 *
 * **Den avbockade halvan är den lätta att glömma**, och därför står den här:
 * en glömd `where` i `completedForContainer()` ger varken fel eller larm, och
 * en rad som hunnit bli klar läcker samma namn som den öppna gjorde.
 */
it('en uppgift på ett item utanför omfånget visas inte, varken öppen eller klar', function () {
    withoutVite();

    $container = uppgiftsflikParm(Account::factory()->create());

    $mitt = uppgiftsflikItem($container, 'Motorn');
    $dolt = uppgiftsflikItem($container, 'Hemlig motor');

    uppgiftsflikOppna(uppgiftsflikSchema($mitt, 'Byt impeller'), uppgiftsflikDatum(3));
    uppgiftsflikKlar(uppgiftsflikSchema($mitt, 'Byt olja'));

    uppgiftsflikOppna(uppgiftsflikSchema($dolt, 'Hemlig uppgift'), uppgiftsflikDatum(4));
    uppgiftsflikKlar(uppgiftsflikSchema($dolt, 'Hemligt gjort'));

    $gast = uppgiftsflikGast($container, $mitt);

    $svar = actingAs($gast)->get(uppgiftsflikUrl($container))->assertOk();

    expect(uppgiftsflikTitlar(uppgiftsflikGrupp($svar, 'upcoming')))->toBe(['Byt impeller'])
        ->and(uppgiftsflikTitlar($svar->inertiaProps()['completed']))->toBe(['Byt olja'])
        ->and($svar->getContent())->not->toContain('Hemlig uppgift')
        ->and($svar->getContent())->not->toContain('Hemligt gjort')
        ->and($svar->getContent())->not->toContain('Hemlig motor');
});

/*
 * Klart när: en uppgift i en annan container visas inte.
 *
 * Beslut 2:s avgränsning. Användaren NÅR båda containrarna — det är hela
 * poängen: `/tasks` visar dem sida vid sida, och fliken visar den ena. Provet
 * lägger både en öppen och en avbockad rad i granncontainern, av samma skäl
 * som provet ovanför.
 */
it('en uppgift i en annan container visas inte', function () {
    withoutVite();

    [$konto, $anvandare] = uppgiftsflikKonto();

    $har = uppgiftsflikParm($konto, 'Havsörnen');
    $grannen = uppgiftsflikParm($konto, 'Vindilen');

    uppgiftsflikOppna(uppgiftsflikSchema(uppgiftsflikItem($har, 'Motorn'), 'Byt impeller'), uppgiftsflikDatum(3));
    uppgiftsflikKlar(uppgiftsflikSchema(uppgiftsflikItem($har, 'Ankaret'), 'Inspektera linan'));

    uppgiftsflikOppna(uppgiftsflikSchema(uppgiftsflikItem($grannen, 'Seglet'), 'Laga seglet'), uppgiftsflikDatum(4));
    uppgiftsflikKlar(uppgiftsflikSchema(uppgiftsflikItem($grannen, 'Rodret'), 'Byt lager'));

    $svar = actingAs($anvandare)->get(uppgiftsflikUrl($har))->assertOk();

    expect(uppgiftsflikTitlar(uppgiftsflikGrupp($svar, 'upcoming')))->toBe(['Byt impeller'])
        ->and(uppgiftsflikTitlar($svar->inertiaProps()['completed']))->toBe(['Inspektera linan'])
        ->and($svar->getContent())->not->toContain('Laga seglet')
        ->and($svar->getContent())->not->toContain('Byt lager')
        ->and($svar->getContent())->not->toContain($grannen->ulid);

    // Och `/tasks` visar båda: avgränsningen är flikens och inte urvalets.
    expect(uppgiftsflikKarta(actingAs($anvandare)->get('/tasks')->assertOk()))->toHaveCount(2);
});

/*
 * Klart när: en främling nekas fliken (403).
 *
 * Beslut 1: grinden är `view` på containern. 403 och inte en tom tavla — en
 * tom lista hade svarat "inget att göra" om en container hon inte når, och det
 * svaret är en osanning om någon annans container.
 */
it('en främling nekas fliken', function () {
    withoutVite();

    $container = uppgiftsflikParm(Account::factory()->create());
    uppgiftsflikOppna(uppgiftsflikSchema(uppgiftsflikItem($container, 'Motorn'), 'Byt impeller'), uppgiftsflikDatum(3));

    actingAs(User::factory()->create())
        ->get(uppgiftsflikUrl($container))
        ->assertForbidden();
});

/*
 * Klart när: snabblänkarna ritas bara för den som får öppna sidorna.
 *
 * Beslut 5. Länkarna går till `containers.calendar` och `containers.export`,
 * och flaggorna är serverns svar på SAMMA policyfråga som de två sidornas egna
 * `index()` ställer — `ContainerPolicy::view`. Provet fäster båda ändarna: att
 * flaggan är sann för den som faktiskt kommer in på målsidorna, och att vyn
 * ritar varje länk ur sin egen flagga i stället för att skriva en `<Link>` rakt
 * ut. En död genväg är värre än ingen genväg.
 *
 * Den delegerade läsaren är den intressanta halvan: hon är inte medlem i
 * ägarkontot och kommer ändå in på båda sidorna. En flagga byggd på ägarskap i
 * stället för på policyn hade släckt länkarna för henne.
 */
it('snabblänkarna ritas bara för den som får öppna sidorna', function () {
    withoutVite();

    [$konto, $agare] = uppgiftsflikKonto();
    $container = uppgiftsflikParm($konto);

    $lasare = uppgiftsflikGast($container);

    foreach ([$agare, $lasare] as $anvandare) {
        $svar = actingAs($anvandare)->get(uppgiftsflikUrl($container))->assertOk();

        expect($svar->inertiaProps()['can']['calendar'])->toBeTrue()
            ->and($svar->inertiaProps()['can']['export'])->toBeTrue();

        // Och målen svarar för samma användare — flaggan lovar ingenting som
        // inte håller.
        actingAs($anvandare)->get(route('containers.calendar', $container, false))->assertOk();
        actingAs($anvandare)->get(route('containers.export', $container, false))->assertOk();
    }

    // Adresserna är målens egna rutter, och vyn stavar dem som
    // containerSections.js gör — samma två undersidor.
    expect(route('containers.calendar', $container, false))->toBe("/containers/{$container->ulid}/calendar")
        ->and(route('containers.export', $container, false))->toBe("/containers/{$container->ulid}/export");

    $vy = uppgiftsflikKod('resources/js/pages/Containers/Tasks.vue');

    expect($vy)->toContain('`/containers/${props.container.ulid}/calendar`')
        ->toContain('`/containers/${props.container.ulid}/export`')
        ->toContain('v-if="can.calendar"')
        ->toContain('v-if="can.export"')
        ->toContain(':href="calendarUrl()"')
        ->toContain(':href="exportUrl()"');
});

/*
 * Tavlans form, så långt den går att pröva på serversidan.
 *
 * Klart när i milstolpen: *under `md:` är fliken en lista*. CSS:en kan inte
 * mätas här, men de klasser som bär formen kan läsas: rutnätet slås på över
 * brytpunkten, under den staplas sektionerna, och varje kolumn är en
 * `<section>` med sin egen rubrik — fyra rubriker i kolumnordningen är exakt
 * den lista issuen ber om, i samma markup.
 *
 * Provet fäster också att de tre öppna kolumnrubrikerna är `todo.group.*` —
 * samma grupp, samma ord, EN nyckel: en kopia under `container.tasks.*` hade
 * varit den andra sanningen om vad gruppen heter. Och att kontrollern är tunn:
 * den väljer sida, den formulerar inget `where`.
 */
it('ritar tavlan som ett rutnät över md: och en lista under', function () {
    withoutVite();

    $vy = uppgiftsflikKod('resources/js/pages/Containers/Tasks.vue');

    expect($vy)->toContain('md:grid')
        ->toContain('md:grid-cols-4')
        ->toContain('flex-col')
        ->toContain('v-for="(entries, group) in groups"')
        // Rubriken byggs ur gruppens EGET namn, så en fjärde grupp från
        // servern följer med utan att vyn skrivs om.
        ->toContain('t(`todo.group.${group}`)')
        ->toContain("t('container.tasks.done')")
        // Avbockningen är TodoRows befintliga — ingen ny skrivväg.
        ->toContain('<TodoRow');

    // Nycklarna finns i katalogen: `t()` skriver nyckeln själv på skärmen när
    // uppslaget misslyckas, och en kolumn hade då hetat `container.tasks.done`.
    foreach ([
        'container.tasks.title',
        'container.tasks.heading',
        'container.tasks.done',
        'container.tasks.shortcuts',
        'container.tasks.filter_maintenance',
    ] as $nyckel) {
        expect(trans("ui.{$nyckel}", [], 'en'))->not->toBe("ui.{$nyckel}", "{$nyckel} saknas");
    }

    // Kontrollern räknar sina fyra kolumner ur samma action som `/tasks` och
    // klipper inte de öppna listorna: tavlan är hela containern. Att den inte
    // formulerar ett eget `where` är arkitekturkravet ([[ADR-0024 Tunna
    // controllers och actions]]) och prövas här som ett källkodsprov.
    $kontroller = uppgiftsflikKod('app/Http/Controllers/ContainerTaskController.php');

    expect($kontroller)->toContain('forContainer(')
        ->toContain('completedForContainer(');

    // Den nekande halvan står för sig: `toContain()` svarar med en
    // expectation över en sträng, och ett `not` mitt i kedjan fäller phpstan
    // ("access to an undefined property ...::$not") — samma fälla som de
    // andra källkodsproven i sviten.
    expect($kontroller)->not->toMatch('/->where\(|->whereHas\(|->orderBy|->limit\(/');
});
