<?php

use App\Actions\User\DeleteUser;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\CalendarFeed;
use App\Models\Category;
use App\Models\Container;
use App\Models\CostEntry;
use App\Models\Export;
use App\Models\Invitation;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\Loan;
use App\Models\Notification as NotificationModel;
use App\Models\ScheduleOccurrence;
use App\Models\StoredFile;
use App\Models\Tag;
use App\Models\User;
use App\Notifications\MagicLinkNotification;
use App\Support\Auth\MagicLinkBroker;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\assertGuest;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\withHeaders;
use function Pest\Laravel\withSession;

/*
 * Issue 579 · Vad personraderingen gör sett utifrån. Se
 * [[M22 Redo för testare]] § 579 och [[ADR-0045 Radering av konto och
 * person]] § Beslut 3.
 *
 * **Varför en egen fil.** tests/Feature/Konto/PersonraderingTest.php prövar
 * tabellerna en och en; det här provet följer personen efteråt — en annan
 * flik, ett API-token, en ny inloggning, en ny registrering på samma adress.
 * `DeleteUser` raderar `sessions`-raderna, men det som faktiskt loggar ut en
 * annan flik är att `user`-raden är borta, oavsett sessionsdrivrutin. Här står
 * det som ett prov och inte som ett antagande.
 *
 * Proven anropar `app(DeleteUser::class)->handle($person)` direkt och aldrig
 * länken: länken och dess kontroller är issue 161 (#578) och prövas i
 * tests/Feature/Konto/PersonraderingslankTest.php.
 *
 * Hjälparna i PersonraderingTest och PersonraderingsytaTest nås inte säkert
 * härifrån — Pest laddar en fils funktioner först när filen körs, och en
 * delfelkörning kraschar då på "Call to undefined function". De få som
 * behövs står därför nedan, med prefixet `efterverkan`. Hjälparna i
 * tests/Support/Testhjalpare.php anropas som vanligt; de laddas av
 * autoloadern och är alltid tillgängliga.
 */

/**
 * Ett konto med personen som enda medlem — kontot som raderas.
 */
function efterverkanEgetKonto(User $person): Account
{
    $konto = Account::factory()->create();
    $konto->users()->attach($person, ['role' => 'owner']);

    return $konto;
}

function efterverkanContainer(Account $konto): Container
{
    return Container::factory()->for($konto, 'account')->create();
}

/**
 * Sessionsnyckeln webbvakten loggar in under, alltså
 * `Auth::guard('web')->getName()`. Instanskontrollen finns bara för att
 * `AuthManager::guard()` svarar `Guard|StatefulGuard` och kontraktet inte
 * har `getName()` — formen är densamma, och en hårdkodad nyckel hade varit
 * en avskrift av ramverkets.
 */
function efterverkanSessionsnyckel(): string
{
    $vakt = Auth::guard('web');

    if (! $vakt instanceof SessionGuard) {
        throw new RuntimeException('Webbvakten är ingen sessionsvakt.');
    }

    return $vakt->getName();
}

/**
 * Länken ur inloggningsmejlet. Tokenet finns bara där — `MagicLinkBroker`
 * sparar hashen och ingenting annat.
 */
function efterverkanInloggningslänk(User $person): string
{
    $url = null;

    Notification::assertSentTo(
        $person,
        MagicLinkNotification::class,
        function (MagicLinkNotification $notis) use (&$url): bool {
            $url = $notis->url;

            return true;
        }
    );

    if ($url === null) {
        throw new RuntimeException('Ingen inloggningslänk hittades.');
    }

    return $url;
}

// --- En annan session -----------------------------------------------------

/*
 * Klart när: en session som bär den raderade personens id leder `GET
 * /dashboard` till `/login`.
 *
 * Sessionen skrivs som ett värde i requesten och inte med `actingAs()`:
 * `actingAs()` sätter personen direkt på vakten och går förbi uppslagningen
 * som en riktig session gör. Det är uppslagningen som är provet — sessionen
 * bär ett id, och efter raderingen finns ingen rad att slå upp.
 *
 * Det första anropet är inte dekoration: det bevisar att nyckelns form är
 * rätt. Utan det hade provet passerat också på en felstavad sessionsnyckel,
 * alltså av fel skäl.
 */
it('en session som bär den raderade personens id leder till inloggningen', function () {
    $person = User::factory()->create();
    efterverkanEgetKonto($person);

    $session = [efterverkanSessionsnyckel() => $person->id];

    withSession($session)->get('/dashboard')->assertOk();

    app(DeleteUser::class)->handle($person);

    // Vakten ligger kvar i behållaren testet igenom och cachar personen den
    // slog upp; i drift är varje request en egen process. Utan rensningen
    // svarar det andra anropet som det första — se
    // tests/Support/Testhjalpare.php § somAnvandare().
    auth()->forgetGuards();

    withSession($session)->get('/dashboard')->assertRedirect('/login');

    assertGuest();
});

it('en session som bär en persons id behåller sin inloggning efter en annans radering', function () {
    $person = User::factory()->create();
    $kvarvarande = User::factory()->create();
    efterverkanEgetKonto($person);
    efterverkanEgetKonto($kvarvarande);

    app(DeleteUser::class)->handle($person);

    withSession([efterverkanSessionsnyckel() => $kvarvarande->id])
        ->get('/dashboard')
        ->assertOk();
});

// --- API-token ------------------------------------------------------------

/*
 * Klart när: ett API-token som skapats före raderingen ger 401 efteråt.
 *
 * Sanctums RequestGuard cachar den uppslagna användaren, och i ett test delar
 * alla anrop samma behållare — därför `forgetGuards()` mellan de två anropen,
 * se tests/Support/Testhjalpare.php § somAnvandare(). Utan den hade det andra
 * anropet tyst svarat som det första och 401:an uteblivit.
 */
it('ett api-token som skapats före raderingen ger 401 efteråt', function () {
    $person = User::factory()->create();
    $konto = efterverkanEgetKonto($person);
    efterverkanContainer($konto);

    $token = $person->createToken('api');
    $headers = ['Authorization' => "Bearer {$token->plainTextToken}"];

    withHeaders($headers)->getJson('/api/containers')->assertOk();

    app(DeleteUser::class)->handle($person);

    auth()->forgetGuards();

    withHeaders($headers)->getJson('/api/containers')->assertUnauthorized();
});

// --- Magic link -----------------------------------------------------------

/*
 * Klart när: en inloggningslänk som utfärdats före raderingen loggar inte in
 * efteråt.
 */
it('en inloggningslänk som utfärdats före raderingen loggar inte in efteråt', function () {
    Notification::fake();

    $person = User::factory()->create(['email' => 'efterverkan@exempel.se']);
    $konto = efterverkanEgetKonto($person);
    efterverkanContainer($konto);

    MagicLinkBroker::issue($person->email);

    $länk = efterverkanInloggningslänk($person);

    // Länken är utfärdad och gäller — den finns som rad för adressen.
    expect(DB::table('magic_link_token')->where('email', $person->email)->count())->toBe(1);

    app(DeleteUser::class)->handle($person);

    get($länk)->assertForbidden();

    assertGuest();
});

/*
 * Klart när: en ny magic link-begäran för adressen skapar ingen rad i
 * `magic_link_token`.
 *
 * Det första anropet bevisar att begäran skapar en rad så länge personen
 * finns; det andra att den slutar göra det när hon är borta. Skillnaden är
 * hela provet — ett ensamt anrop efter raderingen hade varit grönt också för
 * en adress som aldrig funnits.
 */
it('en ny magic link-begäran för adressen skapar ingen rad', function () {
    Notification::fake();

    $person = User::factory()->create(['email' => 'efterverkan@exempel.se']);
    efterverkanEgetKonto($person);

    post('/login/magic-link', ['email' => $person->email]);

    expect(DB::table('magic_link_token')->where('email', $person->email)->count())->toBe(1);

    app(DeleteUser::class)->handle($person);

    expect(DB::table('magic_link_token')->where('email', $person->email)->count())->toBe(0);

    post('/login/magic-link', ['email' => $person->email]);

    expect(DB::table('magic_link_token')->where('email', $person->email)->count())->toBe(0);
});

// --- Ny registrering på samma adress --------------------------------------

/*
 * Klart när: en ny registrering med samma adress ger ett konto utan någon av
 * de gamla containrarna.
 *
 * `user`-raden raderas på riktigt och har ingen `deleted_at` (ADR-0045
 * § Beslut 3), så adressen är ledig. Den nya personen är någon annan: nytt
 * id, nytt konto, och ingenting av det gamla följer med.
 */
it('en ny registrering med samma adress ger ett konto utan de gamla containrarna', function () {
    $person = User::factory()->create(['email' => 'efterverkan@exempel.se']);
    $konto = efterverkanEgetKonto($person);
    $container = efterverkanContainer($konto);

    app(DeleteUser::class)->handle($person);

    post('/register', [
        'name' => 'Ny Person',
        'email' => 'efterverkan@exempel.se',
        'password' => 'hast-och-bil-2026',
    ])->assertRedirect('/dashboard');

    $ny = User::query()->where('email', 'efterverkan@exempel.se')->sole();

    expect($ny->id)->not->toBe($person->id)
        ->and(Container::withTrashed()->whereKey($container->id)->exists())->toBeFalse();

    $nyaKonton = DB::table('account_user')->where('user_id', $ny->id)->pluck('account_id');

    expect($nyaKonton)->toHaveCount(1)
        ->and(DB::table('container')->whereIn('account_id', $nyaKonton)->count())->toBe(0);
});

// --- Ett fyllt konto ------------------------------------------------------

/**
 * Personen är enda medlem i ett konto vars container bär en av varje slag av
 * vardagligt innehåll: ett item med en bilaga, en containerbild, en kategori
 * och en tagg, en relation mellan två items, ett schema med en stängd och en
 * öppen förekomst, en kostnadsrad, ett lån, en favorit, en besöksrad, en
 * notis, en export, en kalenderlänk och en återkallad inbjudan.
 *
 * Inbjudan är ÅTERKALLAD och inte väntande: en väntande räknas som en aktiv
 * medlem och spärrar hela raderingen (ADR-0045 § Beslut 3), och provet ska
 * visa att raderingen går igenom — inte att spärren fungerar.
 *
 * @return array{0: Account, 1: Container}
 */
function efterverkanFylltKonto(User $person): array
{
    $konto = efterverkanEgetKonto($person);
    $container = efterverkanContainer($konto);

    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $person->id,
        'created_by_account_id' => $konto->id,
    ]);

    // Itemets bilaga.
    Attachment::factory()->for($item, 'item')->create([
        'stored_file_id' => StoredFile::factory()->create(['byte_size' => 2048, 'reference_count' => 1])->id,
        'uploaded_by_user_id' => $person->id,
        'billed_account_id' => $konto->id,
    ]);

    // Containerns bild: en bilaga med `container_id` och utan item.
    $bild = Attachment::factory()->create([
        'item_id' => null,
        'container_id' => $container->id,
        'stored_file_id' => StoredFile::factory()->create(['byte_size' => 4096, 'reference_count' => 1])->id,
        'filename' => 'bild.png',
        'kind' => 'image',
        'uploaded_by_user_id' => $person->id,
        'billed_account_id' => $konto->id,
    ]);

    $container->cover_attachment_id = $bild->id;
    $container->save();

    Category::factory()->for($container, 'container')->create();
    Tag::factory()->for($container, 'container')->create();

    // En relation mellan två items i samma container.
    $andra = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $person->id,
        'created_by_account_id' => $konto->id,
    ]);

    ItemLink::factory()->create([
        'from_item_id' => $item->id,
        'to_item_id' => $andra->id,
        'relation' => 'parent',
    ]);

    // Schemat öppnas genom den enda vägen in i schedule_occurrence som finns
    // också i produktionen (issue 22 § Beslut 1) — den öppna förekomsten.
    [$schema] = oppnaForekomst($item);

    ScheduleOccurrence::factory()->for($schema, 'schedule')->completed()->create([
        'completed_by_user_id' => $person->id,
        'completed_by_account_id' => $konto->id,
    ]);

    CostEntry::factory()->for($item, 'item')->create([
        'container_id' => $container->id,
        'created_by_user_id' => $person->id,
        'created_by_account_id' => $konto->id,
    ]);

    Loan::factory()->for($item, 'item')->create();

    DB::table('favorite')->insert([
        'user_id' => $person->id,
        'item_id' => $item->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('recent_visit')->insert([
        'user_id' => $person->id,
        'item_id' => $item->id,
        'visited_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    NotificationModel::factory()->create([
        'account_id' => $konto->id,
        'user_id' => $person->id,
    ]);

    Export::factory()->create([
        'container_id' => $container->id,
        'requested_by_user_id' => $person->id,
    ]);

    CalendarFeed::factory()->create([
        'container_id' => $container->id,
        'user_id' => $person->id,
    ]);

    Invitation::factory()->create([
        'container_id' => $container->id,
        'status' => 'revoked',
        'invited_by_user_id' => $person->id,
    ]);

    return [$konto, $container];
}

/*
 * Klart när: en person som är enda medlem i ett konto fyllt enligt listan
 * ovan raderas, och `user`, `account` och containern är borta.
 *
 * Provet följer inte tabellerna en och en — det gör PersonraderingTest. Det
 * som prövas här är att raderingen GÅR IGENOM med ett konto som ser ut som
 * ett verkligt: varje rad är en främmandenyckel (ON DELETE RESTRICT), och en
 * enda kvarglömd rad rullar tillbaka hela transaktionen.
 */
it('en person som är enda medlem i ett fyllt konto raderas med kontot och containern', function () {
    $person = User::factory()->create();
    [$konto, $container] = efterverkanFylltKonto($person);

    app(DeleteUser::class)->handle($person);

    expect(User::query()->whereKey($person->id)->exists())->toBeFalse()
        ->and(Account::query()->whereKey($konto->id)->exists())->toBeFalse()
        ->and(Container::withTrashed()->whereKey($container->id)->exists())->toBeFalse()
        ->and(Item::withTrashed()->where('container_id', $container->id)->exists())->toBeFalse()
        ->and(Attachment::withTrashed()->where('container_id', $container->id)->exists())->toBeFalse()
        ->and(Category::withTrashed()->where('container_id', $container->id)->exists())->toBeFalse()
        ->and(Tag::withTrashed()->where('container_id', $container->id)->exists())->toBeFalse()
        ->and(Loan::withTrashed()->exists())->toBeFalse()
        ->and(DB::table('calendar_feed')->where('container_id', $container->id)->exists())->toBeFalse()
        ->and(DB::table('invitation')->where('container_id', $container->id)->exists())->toBeFalse()
        ->and(DB::table('export')->where('container_id', $container->id)->exists())->toBeFalse();
});

// --- Exporten (proven som var röda före ändringen i DeleteAccount) ---------

/*
 * Klart när: personen i det fyllda kontot raderas.
 *
 * **Det här är provet som var rött före ändringen i `DeleteAccount`.**
 * `export.container_id` är ON DELETE RESTRICT, och ingen action tog raden —
 * `PurgeContainer` känner inte tabellen och `DeleteUser` nollställer bara
 * `requested_by_user_id`. Utan raden i `DeleteAccount` föll
 * `forceDelete()` på containern med ett främmandenyckelfel, och hela
 * personraderingen rullades tillbaka.
 */
it('en export på kontots egen container raderas med containern, och filen med den', function () {
    Storage::fake('files');

    $person = User::factory()->create();
    $konto = efterverkanEgetKonto($person);
    $container = efterverkanContainer($konto);

    Storage::disk('files')->put('exports/arkivet.zip', 'innehall');

    $export = Export::factory()->create([
        'container_id' => $container->id,
        'requested_by_user_id' => $person->id,
        'status' => Export::STATUS_READY,
        'storage_path' => 'exports/arkivet.zip',
        'byte_size' => 8,
    ]);

    app(DeleteUser::class)->handle($person);

    expect(DB::table('export')->where('id', $export->id)->exists())->toBeFalse()
        // Artefakten med: raden var den enda pekaren, och en kvarglömd fil
        // hade ingen kvar att städa den.
        ->and(Storage::disk('files')->exists('exports/arkivet.zip'))->toBeFalse();
});

/*
 * Gränsen för ändringen: bara exporterna på kontots EGNA containers
 * försvinner. En export i någon annans container står kvar och får sin
 * författare nollställd (ADR-0045 § Beslut 2) — det är skillnaden mellan
 * `DeleteAccount`s containerinnehåll och `DeleteUser`s personliga rader.
 */
it('en export i någon annans container står kvar med författaren nollställd', function () {
    $person = User::factory()->create();
    efterverkanEgetKonto($person);

    $agare = Account::factory()->create();
    $container = efterverkanContainer($agare);

    $export = Export::factory()->create([
        'container_id' => $container->id,
        'requested_by_user_id' => $person->id,
    ]);

    app(DeleteUser::class)->handle($person);

    expect(DB::table('export')->where('id', $export->id)->exists())->toBeTrue()
        ->and(DB::table('export')->where('id', $export->id)->value('requested_by_user_id'))->toBeNull()
        ->and(Container::query()->whereKey($container->id)->exists())->toBeTrue();
});
