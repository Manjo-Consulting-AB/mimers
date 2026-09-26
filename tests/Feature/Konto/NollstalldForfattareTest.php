<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Jobs\BuildContainerExport;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\CostEntry;
use App\Models\Export;
use App\Models\Invitation;
use App\Models\Item;
use App\Models\OwnershipTransfer;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use ZipArchive;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\getJson;
use function Pest\Laravel\withoutVite;

/*
 * Issue 142 · Författarkolumnerna blir nullbara. Se
 * [[ADR-0045 Radering av konto och person]] § Beslut 2 och
 * database/migrations/2026_09_26_010000_make_author_columns_nullable.php.
 *
 * Filen prövar de tre sakerna issuen består av:
 *
 * 1. **Schemat.** Varje kolumn i ADR:ens tabell är nullbar och har kvar sin
 *    främmande nyckel med `ON DELETE RESTRICT`. `schedule_occurrence`är med i
 *    tabellen men redan nullbar — den prövas därför bara för att nyckeln ska
 *    stå kvar, inte för nullbarheten.
 * 2. **Migreringen rör inga rader.** `up()` körs en andra gång ovanpå rader
 *    som redan finns, och varje författarvärde jämförs före och efter — på
 *    sqlite, den enda dialekt sviten har. Vad provet visar är att `change()`:s
 *    tabellombyggnad bevarar raderna. Hur MariaDB utför samma steg är inte
 *    prövat någonstans: .github/workflows/migreringar.yml kör filen mot en tom
 *    databas, vilket är vad dess egen kommentar säger att jobbet bevisar.
 * 3. **Läsarna.** Varje yta som bär en författare svarar `null` — eller
 *    *Removed user* där meningen annars blir obegriplig — i stället för att
 *    falla på en relation som inte finns.
 *
 * Skrivvägarna rörs inte av issuen och prövas därför inte här: `CreateItem`,
 * `StoreAttachment`, `CloseOccurrence` och de andra sätter fortfarande
 * författaren, och ingenting nollställer något. Det är 143 och 144.
 *
 * Hjälparna har prefixet `nollstalld` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * ADR-0045 § Beslut 2:s tabell, som `tabell => [kolumner]`. Ändras den
 * här listan ska ADR:en och migreringen ändras med den.
 *
 * @return array<string, list<string>>
 */
function nollstalldaKolumner(): array
{
    return [
        'item' => ['created_by_user_id', 'created_by_account_id'],
        'attachment' => ['uploaded_by_user_id'],
        'schedule_occurrence' => ['completed_by_user_id', 'completed_by_account_id'],
        'cost_entry' => ['created_by_user_id', 'created_by_account_id'],
        'container_access' => ['granted_by_user_id'],
        'invitation' => ['invited_by_user_id'],
        'ownership_transfer' => ['initiated_by_user_id'],
        'export' => ['requested_by_user_id'],
    ];
}

/**
 * Varje författarvärde i varje tabell, sorterat på löpnummer så att två
 * avläsningar går att jämföra rakt av. Det är den här avläsningen
 * migreringsprovet jämför före och efter.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function nollstalldForfattare(?string $anslutning = null): array
{
    $db = DB::connection($anslutning);
    $avläsning = [];

    foreach (nollstalldaKolumner() as $tabell => $kolumner) {
        $avläsning[$tabell] = $db->table($tabell)
            ->orderBy('id')
            ->get($kolumner)
            ->map(fn (object $rad): array => (array) $rad)
            ->all();
    }

    return $avläsning;
}

/**
 * Är kolumnen nullbar på den anslutningen? Läses ur schemat och inte ur en
 * modell — `change()` rör kolumnen, inte modellens docblock.
 */
function nollstalldNullbar(string $anslutning, string $tabell, string $kolumn): bool
{
    $schema = collect(Schema::connection($anslutning)->getColumns($tabell))->keyBy('name');

    return $schema[$kolumn]['nullable'];
}

/**
 * Ett konto med en medlem, en container och ett item — den minsta kontext
 * som alla ytorna i filen delar.
 *
 * @return array{0: Account, 1: User, 2: Container, 3: Item}
 */
function nollstalldKontext(): array
{
    [$konto, $anvandare] = kontoMedMedlem();

    $container = Container::factory()->for($konto, 'account')->create();

    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $konto->id,
    ]);

    return [$konto, $anvandare, $container, $item];
}

/*
 * Klart när: alla kolumner i tabellen är nullbara och har kvar sin främmande
 * nyckel med RESTRICT.
 *
 * Provet läser schemat och inte en modell: en `->nullable()` som tappats i
 * migreringen syns inte i någon modells docblock, och en nyckel som bytts
 * mot `SET NULL` hade varit en radering databasen hittar på själv — exakt det
 * ADR-0045 § Beslut 2 förbjuder.
 */
it('gör varje författarkolumn nullbar med nyckeln kvar på RESTRICT', function () {
    foreach (nollstalldaKolumner() as $tabell => $kolumner) {
        $schema = collect(Schema::getColumns($tabell))->keyBy('name');
        $nycklar = collect(Schema::getForeignKeys($tabell))
            ->keyBy(fn (array $nyckel): string => $nyckel['columns'][0]);

        foreach ($kolumner as $kolumn) {
            expect($schema[$kolumn]['nullable'])->toBeTrue("{$tabell}.{$kolumn} är inte nullbar");
            expect($nycklar->has($kolumn))->toBeTrue("{$tabell}.{$kolumn} saknar främmande nyckel");
            expect(strtoupper($nycklar[$kolumn]['on_delete']))
                ->toBe('RESTRICT', "{$tabell}.{$kolumn} tappade RESTRICT");
        }
    }
});

/*
 * Klart när: migreringen ändrar ingen befintlig rad.
 *
 * Provet kör den RIKTIGA migreringen en gång till ovanpå rader som redan står
 * där: hela schemat byggs upp på en egen sqlite-anslutning, rader skrivs in,
 * och sedan rullas sista steget tillbaka och körs igen — `down()` och `up()`
 * en andra gång, med rader i tabellerna. Varje författarvärde jämförs före och
 * efter.
 *
 * **Egen anslutning, och det är hela poängen.** Testsvitens
 * RefreshDatabase-transaktion gör att sqlite inte kan stänga av främmande
 * nycklar, och då faller `change()`:s tabellommbyggnad på att `item` har barn.
 * Utanför transaktionen går den igenom, precis som `php artisan migrate` gör.
 *
 * Provet kör på sqlite, den enda dialekt sviten har. Att samma migreringsfil
 * går igenom på MariaDB — dialekten som faktiskt tar emot den — visas av
 * .github/workflows/migreringar.yml; det jobbet kör `migrate --force` mot en
 * tom databas och säger inget om `up()` ovanpå rader som redan finns. Att
 * raderna bevaras genom steget är alltså visat här, på sqlite — inte på
 * MariaDB.
 */
it('kör migreringen över befintliga rader utan att röra ett enda värde', function () {
    $tidigare = DB::getDefaultConnection();

    config(['database.connections.nollstalld' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]]);

    artisan('migrate', ['--database' => 'nollstalld', '--force' => true]);

    // Modellerna och fabrikerna skriver till den egna anslutningen så länge
    // raderna byggs upp; den riktiga anslutningen står orörd och
    // RefreshDatabase kan rulla tillbaka sin transaktion som vanligt.
    DB::setDefaultConnection('nollstalld');

    try {
        [$konto, $anvandare, $container, $item] = nollstalldKontext();

        $storedFil = StoredFile::factory()->create();

        Attachment::factory()->create([
            'item_id' => $item->id,
            'stored_file_id' => $storedFil->id,
            'uploaded_by_user_id' => $anvandare->id,
            'billed_account_id' => $konto->id,
        ]);

        CostEntry::factory()->create([
            'container_id' => $container->id,
            'item_id' => $item->id,
            'created_by_user_id' => $anvandare->id,
            'created_by_account_id' => $konto->id,
        ]);

        ContainerAccess::factory()->create([
            'container_id' => $container->id,
            'granted_by_user_id' => $anvandare->id,
        ]);

        Invitation::factory()->create([
            'container_id' => $container->id,
            'invited_by_user_id' => $anvandare->id,
        ]);

        OwnershipTransfer::factory()->create([
            'container_id' => $container->id,
            'from_account_id' => $container->account_id,
            'initiated_by_user_id' => $anvandare->id,
        ]);

        Export::factory()->create([
            'container_id' => $container->id,
            'requested_by_user_id' => $anvandare->id,
        ]);

        $före = nollstalldForfattare('nollstalld');

        expect($före['item'])->not->toBeEmpty();
    } finally {
        DB::setDefaultConnection($tidigare);
    }

    // Rulla tillbaka sista steget — `down()` sätter tillbaka NOT NULL — och
    // kör `up()` igen. Det är samma DDL som produktionen körde, en gång till,
    // ovanpå rader som redan finns.
    artisan('migrate:rollback', ['--database' => 'nollstalld', '--step' => 1, '--force' => true]);

    // Kolumnen är NOT NULL igen: utan det här hade provet varit nöjt med att
    // `rollback` gjorde ingenting, och jämförelsen efteråt hade bevisat noll.
    expect(nollstalldNullbar('nollstalld', 'item', 'created_by_account_id'))
        ->toBeFalse('rollback körde inte — jämförelsen nedan är tom');

    artisan('migrate', ['--database' => 'nollstalld', '--force' => true]);

    expect(nollstalldNullbar('nollstalld', 'item', 'created_by_account_id'))->toBeTrue();

    expect(nollstalldForfattare('nollstalld'))->toBe($före);
});

/**
 * Klockans lista, hämtad som klienten hämtar den: en partiell omladdning av
 * den optionala proppen. `pendingInvitations` är `Inertia::optional` och
 * följer inte med en vanlig sidladdning.
 *
 * @return array<int, array<string, mixed>>
 */
function nollstalldKlockan(User $anvandare): array
{
    $svar = actingAs($anvandare)->get('/dashboard', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(Request::create('/')),
        'X-Inertia-Partial-Component' => 'Dashboard',
        'X-Inertia-Partial-Data' => 'pendingInvitations',
    ]);

    $svar->assertOk();

    return $svar->json('props.pendingInvitations');
}

/*
 * Klart när: varje resurs som bär en författare ger `null` när kolumnen är
 * `NULL`, i både webben och /api.
 */
it('svarar null för ett item vars tillskrivna konto är borta', function () {
    [, $anvandare, $container, $item] = nollstalldKontext();

    $token = $anvandare->createToken('api');
    $headers = ['Authorization' => "Bearer {$token->plainTextToken}"];

    $item->created_by_account_id = null;
    $item->save();

    getJson("/api/containers/{$container->ulid}/items/{$item->ulid}", $headers)
        ->assertOk()
        ->assertJsonPath('data.created_by_account', null);
});

it('svarar null för en kostnadsrad vars tillskrivna konto är borta', function () {
    [, $anvandare, $container, $item] = nollstalldKontext();

    $kostnad = CostEntry::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item->id,
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => null,
    ]);

    $token = $anvandare->createToken('api');
    $headers = ['Authorization' => "Bearer {$token->plainTextToken}"];

    getJson("/api/containers/{$container->ulid}/items/{$item->ulid}/costs", $headers)
        ->assertOk()
        ->assertJsonPath('data.0.ulid', $kostnad->ulid)
        ->assertJsonPath('data.0.created_by_account', null);
});

/*
 * Klart när: varje resurs som bär en författare ger `null` när kolumnen är
 * `NULL` — också på `/api`, och också för de två resurser som får sin
 * författare via ett ULID-uppslag i kontrollern (`ContainerAccessResource`
 * och `InvitationResource`) i stället för via en relation.
 */
it('svarar null för beviljaren och inbjudaren i /api', function () {
    [, $anvandare, $container] = nollstalldKontext();

    $atkomst = ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'granted_by_user_id' => null,
    ]);

    $inbjudan = Invitation::factory()->create([
        'container_id' => $container->id,
        'invited_by_user_id' => null,
    ]);

    $token = $anvandare->createToken('api');
    $headers = ['Authorization' => "Bearer {$token->plainTextToken}"];

    getJson("/api/containers/{$container->ulid}/accesses", $headers)
        ->assertOk()
        ->assertJsonPath('data.0.ulid', $atkomst->ulid)
        ->assertJsonPath('data.0.granted_by', null);

    getJson("/api/containers/{$container->ulid}/invitations", $headers)
        ->assertOk()
        ->assertJsonPath('data.0.ulid', $inbjudan->ulid)
        ->assertJsonPath('data.0.invited_by', null);
});

/*
 * Klart när: delningsvyn renderar utan fel med en nollställd författare.
 *
 * Webbvyn visar *Removed user*, inte en ULID och inte en tom rad: beviljaren
 * är borta, åtkomsten står kvar, och raden ska gå att läsa.
 */
it('renderar delningsvyn med en nollställd beviljare', function () {
    withoutVite();

    [$konto, $anvandare, $container] = nollstalldKontext();

    $mottagare = User::factory()->create(['name' => 'Sambon']);

    $rad = ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'grantee_type' => 'user',
        'grantee_id' => $mottagare->id,
        'level' => 'read',
        'kind' => 'member',
        'granted_by_user_id' => null,
    ]);

    actingAs($anvandare)->get("/containers/{$container->ulid}/sharing")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('accesses', 1)
            ->where('accesses.0.ulid', $rad->ulid)
            ->where('accesses.0.granted_by', null)
            ->where('grantedByNames', [])
        );

    // Meningen som ersätter namnet bor i lang-filen, och är densamma som
    // `grantedByLabel()` faller tillbaka på — vyn hittar den, den är inte
    // bara oanvänd.
    expect(trans('ui.sharing.accesses.granted_by_unknown', [], 'en'))->toBe('Removed user');
});

/*
 * Klart när: förekomstvyn renderar utan fel med en nollställd författare.
 *
 * `schedule_occurrence` var nullbar redan före den här issuen; provet vaktar
 * att vyn och resursen fortsätter svara `null` i stället för att läsa
 * `completedByAccount->name` rakt av.
 */
it('renderar förekomstvyn med en nollställd avbockare', function () {
    withoutVite();

    [, $anvandare, $container, $item] = nollstalldKontext();

    $schema = Schedule::factory()->for($item, 'item')->create([
        'title' => 'Serva motorn',
        'recurrence_type' => 'interval',
        'interval_unit' => 'month',
        'interval_count' => 12,
        'anchor_date' => '2027-05-05',
    ]);

    $forekomst = ScheduleOccurrence::factory()->for($schema, 'schedule')->create([
        'due_at' => '2026-01-01',
        'visible_from' => '2025-12-01',
        'status' => 'completed',
        'completed_at' => '2026-01-01 09:00:00',
        'completed_by_user_id' => null,
        'completed_by_account_id' => null,
    ]);

    $svar = actingAs($anvandare)
        ->get("/containers/{$container->ulid}/items/{$item->ulid}/schedules/{$schema->ulid}")
        ->assertOk();

    $forekomster = collect($svar->viewData('page')['props']['occurrences'])->keyBy('ulid');

    expect($forekomster[$forekomst->ulid]['completed_by_account'])->toBeNull();

    $token = $anvandare->createToken('api');
    $headers = ['Authorization' => "Bearer {$token->plainTextToken}"];

    getJson("/api/containers/{$container->ulid}/items/{$item->ulid}/schedules/{$schema->ulid}/occurrences", $headers)
        ->assertOk()
        ->assertJsonPath('data.0.completed_by_account', null);
});

/*
 * Klart när: klockans inbjudningar renderar utan fel med en nollställd
 * författare.
 *
 * `inviter` är en text i proppen — meningen runt den ligger i lang-filen och
 * interpolerar `:inviter`, så ett `null` hade lämnat ett hål. Texten står i
 * stället för namnet.
 */
it('visar Removed user i klockan när inbjudaren är borta', function () {
    [, , $container] = nollstalldKontext();

    $mottagare = User::factory()->create(['email' => 'mottagare@exempel.se']);

    Invitation::factory()->create([
        'container_id' => $container->id,
        'email' => $mottagare->email,
        'status' => 'pending',
        'expires_at' => now()->addDays(Invitation::TTL_DAYS),
        'invited_by_user_id' => null,
    ]);

    $klockan = nollstalldKlockan($mottagare);

    expect($klockan)->toHaveCount(1)
        ->and($klockan[0]['inviter'])->toBe('Removed user')
        ->and($klockan[0]['container'])->toBe($container->name);
});

/*
 * Klart när: inbjudningarna renderar utan fel med en nollställd författare —
 * samma regel på sidan som i klockan, och samma text.
 */
it('visar Removed user på inbjudningssidan när inbjudaren är borta', function () {
    withoutVite();

    [, , $container] = nollstalldKontext();

    $mottagare = User::factory()->create(['email' => 'mottagare@exempel.se']);

    Invitation::factory()->create([
        'container_id' => $container->id,
        'email' => $mottagare->email,
        'status' => 'pending',
        'expires_at' => now()->addDays(Invitation::TTL_DAYS),
        'invited_by_user_id' => null,
    ]);

    actingAs($mottagare)->get('/invitations')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('state', 'pending')
            ->has('invitations', 1)
            ->where('invitations.0.inviter', 'Removed user')
        );
});

/*
 * Klart när: exporten renderar utan fel med en nollställd författare.
 *
 * Beställaren är den enda som definierar exportens omfång (issue 74
 * § Beslut 8), och en nollställd beställare har ingen rad att lösa det ur.
 * Bygget faller inte och läcker ingenting: filen blir tom på items, och
 * containerns metadata står kvar.
 */
it('exporterar utan att falla när beställaren är borta', function () {
    Storage::fake('files');

    [, , $container, $item] = nollstalldKontext();

    $export = Export::factory()->create([
        'container_id' => $container->id,
        'requested_by_user_id' => null,
        'status' => Export::STATUS_PENDING,
    ]);

    (new BuildContainerExport($export))->handle();

    $export->refresh();

    expect($export->status)->toBe(Export::STATUS_READY);
    expect($export->storage_path)->not->toBeNull();

    $zip = new ZipArchive;
    expect($zip->open(Storage::disk('files')->path($export->storage_path)))->toBeTrue();

    $payload = json_decode($zip->getFromName('container.json'), true);
    $zip->close();

    expect($payload['items'])->toBe([]);
    expect($payload['container']['ulid'])->toBe($container->ulid);

    // Itemet finns och är oskadat — det är omfånget som är tomt, inte
    // containern.
    expect($item->fresh())->not->toBeNull();
});
