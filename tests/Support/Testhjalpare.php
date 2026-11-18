<?php

// rott-pa-basen: hjälparfil utan egna tester - ren flytt av delade Pest-hjälpare, ingen acceptanskriterie-yta att vara röd på basen.

/*
 * Globala Pest-hjälpare som mer än en testfil anropar.
 *
 * Pest binder alla funktioner i tests/ till samma globala namnrymd, men bara
 * när HELA sviten körs — `php artisan test` laddar då varenda testfil, och en
 * hjälpare deklarerad i en fil "råkar" vara synlig i en annan. Kör man i
 * stället en katalog eller ett filter (`php artisan test tests/Feature/Notis`,
 * `--filter=NågotTest`) laddas bara de filerna, och ett anrop till en
 * hjälpare som bor i en annan katalog kraschar med
 * "Call to undefined function".
 *
 * Den här filen laddas av Composers autoloader (autoload-dev.files i
 * composer.json) INNAN något test körs, oavsett hur delmängden väljs — så
 * funktionerna här är alltid tillgängliga. Den löser bara det generella
 * problemet: en hjälpare som bara en enda testfil använder hör hemma kvar
 * i den filen, närheten till testet är en fördel.
 *
 * Flyttad hit i samband med att katalogvis testkörning gjordes möjlig — se
 * PR #197 (observationen), PR #196 (ett exempel på hur dubblering blev
 * lösningen innan den här filen fanns) och PR #211 (sammanfattningen: "den
 * missvisande delfelkörningen kostade mer än den gav"), samt
 * docs/Process/Lärdomar.md § Observerat.
 *
 * Varje funktion nedan är en ren flytt — namn, signatur och beteende är
 * oförändrade — med undantag för somAnvandare(), som är ny här och hör till
 * testinfrastrukturen i stället för till en enskild testfil. Ursprungsfilen
 * står i funktionens docblock.
 */

use App\Actions\Attachment\PurgeAttachment;
use App\Actions\Schedule\OpenNextOccurrence;
use App\Actions\Trash\PurgeContainer;
use App\Actions\Trash\PurgeContent;
use App\Console\PurgesExpiredTrash;
use App\Mail\NotificationMail;
use App\Models\Account;
use App\Models\Agent;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Invitation;
use App\Models\Item;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\OccurrenceDependency;
use App\Models\OwnershipTransfer;
use App\Models\Plan;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\StoredFile;
use App\Models\Subscription;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use PragmaRX\Google2FA\Google2FA;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\withoutVite;
use function Pest\Laravel\withToken;

// --- Testinfrastruktur --------------------------------------------------

/**
 * Loggar in $user — efter att först ha glömt guardernas cache — och
 * returnerar `actingAs($user)`, så att den kan kedjas precis som `actingAs()`
 * kan:
 *
 *     somAnvandare($b)->getJson('/api/containers');
 *
 * Anropas i stället för `actingAs()` varje gång ett test byter användare
 * mellan två anrop. `actingAs()` sätter webbguarden, och Sanctums
 * RequestGuard cachar den autentiserade användaren efter första uppslaget.
 * Guarden ligger kvar i applikationsbehållaren testet igenom — i drift är
 * varje request en egen process, men i ett test delar alla anrop samma — så
 * ett andra anrop som en annan användare annars svarar tyst som den första.
 * `forgetGuards()` kastar de uppslagna guarderna, och nästa anrop slår upp
 * användaren på nytt.
 *
 * Fyra issues felsökte samma sak var för sig (337, 345, 451, 452) innan
 * förklaringen hamnade här. Beteendet är Sanctums och är rätt i drift; det är
 * bara testerna som delar en process. Se
 * tests/Feature/Testinfrastruktur/IdentitetsbyteTest.php, som prövar både
 * hjälparen och felet utan den.
 *
 * Gäller anropet en bearer-token i stället för `actingAs()`, använd
 * medToken().
 */
function somAnvandare(User $user): TestCase
{
    auth()->forgetGuards();

    return actingAs($user);
}

/**
 * Glömmer guardernas cache och returnerar `withToken($token)`, så att den
 * kan kedjas: `medToken($b)->getJson('/api/containers')`.
 *
 * Används i stället för `withToken()` när ett test anropar API:et med olika
 * användares tokens i följd. Bakgrunden står i somAnvandare().
 */
function medToken(string $token): TestCase
{
    auth()->forgetGuards();

    return withToken($token);
}

// --- tests/Feature/Container ------------------------------------------

/**
 * Ett konto med en medlem i angiven roll, plus ett Sanctum-headerpar för
 * medlemmen. Den mest delade hjälparen i sviten — 40 testfiler anropar den.
 *
 * Ursprungligen i tests/Feature/Container/ContainerCrudTest.php.
 *
 * @return array{0: Account, 1: User, 2: array<string, string>} [$account, $user, $headers]
 */
function kontoMedMedlem(string $roll = 'owner'): array
{
    $account = Account::factory()->create();
    $user = User::factory()->create();
    $account->users()->attach($user, ['role' => $roll]);

    $token = $user->createToken('api');
    $headers = ['Authorization' => "Bearer {$token->plainTextToken}"];

    return [$account, $user, $headers];
}

/**
 * Skapar en container_access-rad. $grantee är antingen en User
 * (grantee_type = user, dvs `member`/`guest`) eller ett Account
 * (grantee_type = account, dvs `managed`), se issue 9a § Beslut 5.
 * `granted_by_user_id` är obligatorisk (§ Att se upp med) men vem det är
 * spelar ingen roll för de här testerna, så en fristående användare skapas
 * åt raden.
 *
 * Ursprungligen i tests/Feature/Container/ContainerAtkomstTest.php.
 */
function beviljaAccess(
    Container $container,
    User|Account $grantee,
    string $level,
    string $kind,
    ?Carbon $expiresAt = null,
    ?Carbon $revokedAt = null,
): ContainerAccess {
    return ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'grantee_type' => $grantee instanceof User ? 'user' : 'account',
        'grantee_id' => $grantee->id,
        'level' => $level,
        'kind' => $kind,
        'expires_at' => $expiresAt,
        'revoked_at' => $revokedAt,
        'granted_by_user_id' => User::factory()->create()->id,
    ]);
}

/**
 * Skapar en invitation-rad direkt, förbi API:et — för de tester som
 * behöver ett utgångsläge rutten själv aldrig producerar (en utgången
 * `pending`-rad, en `accepted`). `invited_by_user_id` är obligatorisk (FK)
 * men vem det är spelar ingen roll här, så en fristående användare skapas
 * åt raden, precis som beviljaAccess() ovan gör.
 *
 * Ursprungligen i tests/Feature/Container/InbjudanTest.php.
 */
function bjudInRad(
    Container $container,
    string $email,
    string $status = 'pending',
    ?Carbon $expiresAt = null,
): Invitation {
    return Invitation::factory()->create([
        'container_id' => $container->id,
        'email' => $email,
        'status' => $status,
        'expires_at' => $expiresAt ?? now()->addDays(Invitation::TTL_DAYS),
        'invited_by_user_id' => User::factory()->create()->id,
    ]);
}

// --- tests/Feature/Agarbyte ----------------------------------------------

/**
 * Skapar en ägarbytesrad direkt i databasen, för de tester som behöver ett
 * utgångsläge rutten själv aldrig producerar (39b:s accept-tester, och de
 * undantagna items som 39b testas med). `from_account_id` och
 * `initiated_by_user_id` får förnuftiga defaultvärden; fabrikens egen default
 * sätter en mottagarväg (to_account) så raden uppfyller CHECK-villkoret.
 *
 * 39a har en egen `ägarbyteRad()` i tests/Feature/Agarbyte/AgarbyteTest.php
 * med samma form; den här hjälparen har ett eget namn för att de två filerna
 * ska kunna läsas separat utan att kollidera när hela sviten körs.
 */
function skapaÄgarbyteRad(Container $container, array $attribut = []): OwnershipTransfer
{
    return OwnershipTransfer::factory()->create(array_merge([
        'container_id' => $container->id,
        'from_account_id' => $container->account_id,
        'initiated_by_user_id' => User::factory()->create()->id,
    ], $attribut));
}

// --- tests/Feature/Auth -------------------------------------------------

/**
 * Skapar en användare med en REDAN bekräftad TOTP, utan att gå via
 * aktiveringsrutterna (som hör till issue #19 och inte rörs här) —
 * samma resultat som ett lyckat App\Support\Auth\TotpBroker::confirm(),
 * satt direkt på modellen.
 *
 * Ursprungligen i tests/Feature/Auth/TotpInloggningTest.php.
 *
 * @return array{0: User, 1: string} [$user, $secret]
 */
function användareMedBekräftadTotp(): array
{
    $user = User::factory()->create(['password_hash' => 'ratt-losenord']);

    $secret = (new Google2FA)->generateSecretKey();
    $user->totp_secret = $secret;
    $user->totp_confirmed_at = now();
    $user->save();

    return [$user, $secret];
}

/**
 * Beräknar en giltig kod för $secret rakt av mot Google2FA, precis som en
 * autentiseringsapp skulle göra — testerna "läser inte" hemligheten ur
 * broker-koden, de simulerar en app som har den inlästa.
 *
 * Ursprungligen i tests/Feature/Auth/TotpAktiveringTest.php.
 */
function totpKodFör(string $secret): string
{
    return (new Google2FA)->getCurrentOtp($secret);
}

// --- tests/Feature/Uppgift ----------------------------------------------

/**
 * Ett konto med en medlem, en container ägd av kontot och ett item i
 * containern. Itemets `created_by_*` sätts till medlemmen, precis som
 * SchemaCrudTest gör, så raderna är sammanhängande.
 *
 * Ursprungligen i tests/Feature/Uppgift/ForekomstTest.php.
 *
 * @return array{0: Account, 1: User, 2: array<string, string>, 3: Container, 4: Item}
 */
function skapaForekomstKontext(string $namn = 'Flotten'): array
{
    [$account, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);

    return [$account, $user, $headers, $container, $item];
}

/**
 * En sammanhängande kropp för POST /schedules, interval som standard. Varje
 * fält kan överstyras.
 *
 * Ursprungligen i tests/Feature/Uppgift/ForekomstTest.php.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function forekomstSchemaKropp(array $overrides = []): array
{
    return array_merge([
        'title' => 'Byt impeller',
        'recurrence_type' => 'interval',
        'interval_unit' => 'month',
        'interval_count' => 12,
        'anchor_date' => '2027-05-05',
    ], $overrides);
}

/**
 * Skapar ett aktivt schema under $item och öppnar dess första förekomst genom
 * App\Actions\Schedule\OpenNextOccurrence — den enda vägen in i
 * schedule_occurrence också i produktionen (issue 22 § Beslut 1). Återkommandetypen
 * är `interval` med `anchor_date` = 2027-05-05 om inte $overrides säger något
 * annat, så förekomstens `due_at` är förutsägbar.
 *
 * Ursprungligen i tests/Feature/Uppgift/ForekomstBeroendeTest.php.
 *
 * **Dagen** är containerns ägarkontos, `account.timezone` — hjälparen har
 * ingen användare, och det är reserven [[ADR-0044 Användarens dag]]
 * § Beslut 2 pekar ut för den som stänger eller skapar utan en. Den slår
 * bara igenom för `fixed` med ett `anchor_date` bakom oss; `interval` och
 * `none` öppnar på `anchor_date` oavsett dag.
 *
 * @param  array<string, mixed>  $overrides
 * @return array{0: Schedule, 1: ScheduleOccurrence}
 */
function oppnaForekomst(Item $item, array $overrides = []): array
{
    $schedule = Schedule::factory()->for($item, 'item')->create(array_merge([
        'title' => 'Serva motorn',
        'recurrence_type' => 'interval',
        'interval_unit' => 'month',
        'interval_count' => 12,
        'anchor_date' => '2027-05-05',
    ], $overrides));

    $kontoTidszon = $item->container->account->timezone;
    $idag = Carbon::parse(Carbon::now($kontoTidszon)->toDateString());

    $occurrence = app(OpenNextOccurrence::class)->handle($schedule, $idag);

    if ($occurrence === null) {
        throw new RuntimeException('Öppnade ingen förekomst för ett aktivt schema med anchor_date.');
    }

    return [$schedule, $occurrence];
}

/**
 * Skriver en beroenderad direkt i tabellen — vad $väntande väntar på $motpart.
 * Testerna som prövar själva ytan (POST) anropar endpointen; de som prövar
 * spärren eller listan bygger raden direkt här för att hålla varje test
 * fokuserat.
 *
 * Ursprungligen i tests/Feature/Uppgift/ForekomstBeroendeTest.php.
 */
function skapaBeroende(ScheduleOccurrence $väntande, ScheduleOccurrence $motpart): void
{
    $dependency = new OccurrenceDependency;
    $dependency->occurrence_id = $väntande->id;
    $dependency->depends_on_occurrence_id = $motpart->id;
    $dependency->save();
}

/**
 * Kroppen för complete/skip — `account` obligatorisk, `completion_note` med
 * bara när testet vill ha den.
 *
 * Ursprungligen i tests/Feature/Uppgift/AvslutTest.php.
 *
 * @return array<string, mixed>
 */
function avslutKropp(Account $account, ?string $note = null): array
{
    $kropp = ['account' => $account->ulid];

    if ($note !== null) {
        $kropp['completion_note'] = $note;
    }

    return $kropp;
}

// --- tests/Feature/Trash --------------------------------------------------

/**
 * Skapar ett item direkt i containern, med $user/$account som skapare —
 * fabrikens egna default-skapare hade annars skapat två ovidkommande
 * användare/konton per item.
 *
 * Ursprungligen i tests/Feature/Trash/PapperskorgTest.php.
 */
function papperskorgsItem(Container $container, Account $account, User $user, array $attribut = []): Item
{
    return Item::factory()->for($container, 'container')->create(array_merge([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ], $attribut));
}

/**
 * Skapar en bilaga direkt på itemet.
 *
 * Ursprungligen i tests/Feature/Trash/PapperskorgTest.php.
 */
function papperskorgsBilaga(Item $item, Account $account, User $user, array $attribut = []): Attachment
{
    return Attachment::factory()->for($item, 'item')->create(array_merge([
        'uploaded_by_user_id' => $user->id,
        'billed_account_id' => $account->id,
    ], $attribut));
}

/**
 * Ett konto, en användare och en container — skaparen på varje item måste
 * finnas, och fabrikens egna default-skapare hade annars skapat ovidkommande
 * konton per item.
 *
 * Ursprungligen i tests/Feature/Trash/GallringTest.php.
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function gallringContainer(): array
{
    $account = Account::factory()->create();
    $user = User::factory()->create();
    $container = Container::factory()->for($account, 'account')->create();

    return [$account, $user, $container];
}

/**
 * Ett item direkt i containern, med $user/$account som skapare.
 *
 * Ursprungligen i tests/Feature/Trash/GallringTest.php.
 */
function gallringItem(Container $container, Account $account, User $user, array $attribut = []): Item
{
    return Item::factory()->for($container, 'container')->create(array_merge([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ], $attribut));
}

/**
 * En bilaga direkt på itemet.
 *
 * Ursprungligen i tests/Feature/Trash/GallringTest.php.
 */
function gallringBilaga(Item $item, Account $account, User $user, array $attribut = []): Attachment
{
    return Attachment::factory()->for($item, 'item')->create(array_merge([
        'uploaded_by_user_id' => $user->id,
        'billed_account_id' => $account->id,
    ], $attribut));
}

/**
 * En kategori direkt i containern.
 *
 * Ursprungligen i tests/Feature/Trash/GallringTest.php.
 */
function gallringKategori(Container $container, array $attribut = []): Category
{
    return Category::factory()->for($container, 'container')->create($attribut);
}

/**
 * En tagg direkt i containern.
 *
 * Ursprungligen i tests/Feature/Trash/GallringTest.php.
 */
function gallringTagg(Container $container, array $attribut = []): Tag
{
    return Tag::factory()->for($container, 'container')->create($attribut);
}

/**
 * En stored_file med `reference_count` satt för hand. Fabrikerna räknar
 * inte — räknaren är domänkodens ansvar (StoreAttachment/PurgeAttachment),
 * så antalet bilagor måste stämmas av mot räknaren i testet.
 *
 * Ursprungligen i tests/Feature/Trash/GallringTest.php.
 */
function gallringStoredFil(int $referenceCount = 1): StoredFile
{
    return StoredFile::factory()->create(['reference_count' => $referenceCount]);
}

/**
 * Kör gallringen precis som schemaläggningen gör.
 *
 * Ursprungligen i tests/Feature/Trash/GallringTest.php.
 *
 * @return array{attachment: int, item: int, category: int, tag: int, container: int}
 */
function gallringKör(): array
{
    $purgeContent = new PurgeContent(new PurgeAttachment);

    return (new PurgesExpiredTrash($purgeContent, new PurgeContainer($purgeContent)))->handle();
}

// --- tests/Feature/Kvot ---------------------------------------------------

/**
 * Sänk/höj en gräns i en plans limits-JSON för det här testet. Gränsen kan
 * vara en siffra (`storage_bytes`) eller ett booleskt flaggvärde
 * (`loan_reminders`), se issue 76 § Beslut 10.
 *
 * Ursprungligen i tests/Feature/Kvot/UppladdningskvotTest.php.
 */
function sättPlangräns(string $kod, string $nyckel, int|bool $värde): void
{
    $plan = Plan::where('code', $kod)->firstOrFail();
    $limits = $plan->limits;
    $limits[$nyckel] = $värde;
    $plan->update(['limits' => $limits]);
}

// --- tests/Feature/Notis ---------------------------------------------------

/**
 * @param  array<string, mixed>  $payload
 *
 * Ursprungligen i tests/Feature/Notis/EpostkanalTest.php. Återanvänds även
 * av UndertryckningTest.php sedan den filens egen duplicerade
 * `undertryckningLeverans()` togs bort (samma kropp, se § Processnotering
 * i PR:en som flyttade den här filens hjälpare).
 */
function epostLeverans(Account $account, User $user, string $type, array $payload): NotificationDelivery
{
    $notification = Notification::factory()->create([
        'account_id' => $account->id,
        'user_id' => $user->id,
        'type' => $type,
        'payload' => $payload,
    ]);

    return NotificationDelivery::factory()->create(['notification_id' => $notification->id]);
}

/**
 * Ursprungligen i tests/Feature/Notis/EpostkanalTest.php. Återanvänds även
 * av UndertryckningTest.php, se epostLeverans() ovan.
 *
 * @return array<string, string>
 */
function uppgiftsPayload(): array
{
    return [
        'title' => 'Byt impeller',
        'item' => 'Drev',
        'container' => 'Vindil',
        'date' => '2026-09-20',
    ];
}

/**
 * Ursprungligen i tests/Feature/Notis/EpostkanalTest.php. MailFake bygger
 * inte mailet när det skickas — subject och markdown-vy hydreras först av
 * prepareMailableForDelivery() vid render() (envelope() och content() i
 * NotificationMail). Renderingen sker under mailets egen locale, som
 * Laravel återställer efteråt.
 */
function mejletsÄmne(NotificationMail $mail): string
{
    $mail->render();

    return $mail->subject;
}

// --- Källkodsprov mot .vue-filer -----------------------------------------

/**
 * Mallen i en `.vue`-fil under `resources/`, utan HTML-kommentarer — för ett
 * prov som läser källkoden i stället för att rendera den.
 *
 * Sökvägen är relativ `resources/`: `vueMall('js/components/ItemRow.vue')`.
 * Saknas filen eller mallen kastas `RuntimeException` med sökvägen, så att
 * provet faller högljutt i stället för att matcha mot en tom sträng.
 *
 * **Tre fällor har kostat varv, och hjälparna tar bara två av dem:**
 *
 * 1. **Kommentarer räknas som källkod.** En nål som bara står i en docblock
 *    eller en `<!-- -->` matchar en `file_get_contents` rakt av (#807, #444).
 *    Därför tar hjälparna bort kommentarer.
 * 2. **En nål kan stå i både skript och mall.** Prövar du mallen mot hela
 *    filen träffar du skriptblocket (#827, #807). Använd `vueMall` för det
 *    som ritas och `vueSkript` för det som körs, aldrig hela filen.
 * 3. **Pests `toContain($a, $b)` tar två nålar och inget felmeddelande**
 *    (#438, #862). Det går inte att lösa i en hjälpare: skriv en
 *    `toContain()` per nål.
 */
function vueMall(string $sokvag): string
{
    return vueMallUr(vueFilInnehall($sokvag), $sokvag);
}

/**
 * Innehållet i varje `<script>`-block i en `.vue`-fil, med eller utan `setup`,
 * i filens ordning, utan blockkommentarer och utan rader som börjar med `//`.
 * Fällorna står vid vueMall().
 */
function vueSkript(string $sokvag): string
{
    return vueSkriptUr(vueFilInnehall($sokvag), $sokvag);
}

/**
 * Ren kärna för vueMall(): texten mellan den första raden som börjar med
 * `<template` och den sista raden som är `</template>`, med varje
 * `<!-- … -->` borttagen, även över flera rader. En inre `<template #slot>`
 * är indragen och stör inte avgränsningen.
 *
 * @throws RuntimeException om mallen saknas
 */
function vueMallUr(string $innehall, string $sokvag = '(sträng)'): string
{
    $rader = preg_split('/\R/', $innehall) ?: [];

    $start = null;
    $slut = null;
    foreach ($rader as $i => $rad) {
        if ($start === null && str_starts_with($rad, '<template')) {
            $start = $i;
        }
        if ($rad === '</template>') {
            $slut = $i;
        }
    }

    if ($start === null || $slut === null || $slut <= $start) {
        throw new RuntimeException("Hittade ingen <template> i {$sokvag}.");
    }

    $mall = implode("\n", array_slice($rader, $start + 1, $slut - $start - 1));

    return preg_replace('/<!--.*?-->/s', '', $mall) ?? $mall;
}

/**
 * Ren kärna för vueSkript(). `//` mitt på en rad står kvar, eftersom det kan
 * vara en URL i en sträng.
 *
 * @throws RuntimeException om skriptblock saknas
 */
function vueSkriptUr(string $innehall, string $sokvag = '(sträng)'): string
{
    if (preg_match_all('/<script\b[^>]*>(.*?)<\/script>/s', $innehall, $traffar) === 0) {
        throw new RuntimeException("Hittade inget <script>-block i {$sokvag}.");
    }

    $skript = implode("\n", $traffar[1]);
    $skript = preg_replace('/\/\*.*?\*\//s', '', $skript) ?? $skript;

    return preg_replace('/^[ \t]*\/\/.*(\R|$)/m', '', $skript) ?? $skript;
}

/**
 * Läser en fil under `resources/` eller kastar `RuntimeException` med sökvägen.
 */
function vueFilInnehall(string $sokvag): string
{
    $fil = dirname(__DIR__, 2).'/resources/'.ltrim($sokvag, '/');
    $innehall = is_file($fil) ? file_get_contents($fil) : false;

    if ($innehall === false) {
        throw new RuntimeException("Hittade inte {$sokvag} ({$fil}).");
    }

    return $innehall;
}

// --- tests/Feature/Mcp ------------------------------------------------

/*
 * Issue 400 · OAuth-flödet mot MCP. Hjälparna bor här och inte i en enskild
 * testfil därför att fyra filer under tests/Feature/Mcp använder samma flöde:
 * klientregistreringen, PKCE, godkännandet och bortkopplingen. Pest lägger
 * alla testfiler i samma namnrymd, så prefixet `mcp` håller dem åtskilda från
 * grannfilernas hjälpare.
 */

/**
 * Passport signerar varje token med ett nyckelpar under storage/, och sviten
 * har inga nycklar incheckade. De skapas en gång per testkörning — inte per
 * test — eftersom `passport:keys` genererar ett 4096-bitars RSA-par.
 */
function mcpSakerstallNycklar(): void
{
    if (! is_file(storage_path('oauth-private.key')) || ! is_file(storage_path('oauth-public.key'))) {
        Artisan::call('passport:keys', ['--force' => true]);
    }
}

/**
 * Ett personkonto med en medlem och, om `$plan` inte är `free`, en aktiv
 * prenumeration på den planen. Free-planen kräver ingen fixture — planraderna
 * kommer ur migrationen.
 *
 * @return array{0: Account, 1: User}
 */
function mcpKonto(string $plan = 'free'): array
{
    $konto = Account::factory()->create(['type' => 'personal']);
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    if ($plan !== 'free') {
        Subscription::factory()
            ->for($konto)
            ->for(Plan::where('code', $plan)->firstOrFail())
            ->create();
    }

    return [$konto, $anvandare];
}

/**
 * En publik OAuth-klient, registrerad på samma väg som den dynamiska
 * registreringen använder — utan klienthemlighet, som klienterna är.
 *
 * @param  array<int, string>|null  $redirectUris
 */
function mcpKlient(string $namn = 'Claude', ?array $redirectUris = null): Client
{
    return app(ClientRepository::class)->createAuthorizationCodeGrantClient(
        name: $namn,
        redirectUris: $redirectUris ?? ['https://claude.ai/api/mcp/auth_callback'],
        confidential: false,
        enableDeviceFlow: false,
    );
}

/**
 * PKCE-utmaningen för en verifierare: BASE64URL(SHA256(verifier)).
 */
function mcpUtmaning(string $verifierare): string
{
    return rtrim(strtr(base64_encode(hash('sha256', $verifierare, true)), '+/', '-_'), '=');
}

/**
 * Klientens första registrerade redirect-URI. `redirect_uris` är en accessor
 * på Passports Client-modell och syns därför inte som en deklarerad property
 * för PHPStan; läs den genom `getAttribute()` på ett ställe i stället.
 */
function mcpRedirectUri(Client $klient): string
{
    $uris = array_values((array) $klient->getAttribute('redirect_uris'));

    return (string) ($uris[0] ?? '');
}

/**
 * Auktoriseringsförfrågan (GET /oauth/authorize) som en inloggad användare.
 * `plain` skickas igenom som det är, så att ett prov kan se att grinden
 * avvisar det.
 */
function mcpAuktorisera(
    User $anvandare,
    Client $klient,
    ?string $redirectUri = null,
    string $verifierare = 'test-verifierare-0000000000000000000000000000000000000000',
    string $metod = 'S256',
): TestResponse {
    withoutVite();
    mcpSakerstallNycklar();

    $redirectUri ??= mcpRedirectUri($klient);
    $utmaning = $metod === 'S256' ? mcpUtmaning($verifierare) : $verifierare;

    $query = http_build_query([
        'client_id' => $klient->getKey(),
        'redirect_uri' => $redirectUri,
        'response_type' => 'code',
        'scope' => 'mcp:use',
        'state' => 'tillstand',
        'code_challenge' => $utmaning,
        'code_challenge_method' => $metod,
    ]);

    return somAnvandare($anvandare)->get('/oauth/authorize?'.$query);
}

/**
 * Propparna på godkännandesidan, lästa ur Inertias rotvy.
 *
 * @return array<string, mixed>
 */
function mcpSidprops(TestResponse $svar): array
{
    /** @var array{props: array<string, mixed>} $sida */
    $sida = $svar->assertOk()->viewData('page');

    return $sida['props'];
}

/**
 * Godkännandet (POST /oauth/authorize). `$val` är `new` eller
 * `replace:<ulid>`, precis som formuläret skickar det.
 *
 * @param  array<string, mixed>  $props
 * @param  array<string, mixed>  $extra
 */
function mcpGodkann(
    User $anvandare,
    array $props,
    ?string $val = null,
    array $extra = [],
): TestResponse {
    $kropp = array_merge([
        'client_id' => $props['client']['id'],
        'auth_token' => $props['authToken'],
    ], $extra);

    if ($val !== null) {
        $kropp['agent_choice'] = $val;
    }

    return somAnvandare($anvandare)->post('/oauth/authorize', $kropp);
}

/**
 * Koden ur godkännandets redirect till klientens `redirect_uri`.
 */
function mcpKod(TestResponse $svar): string
{
    $location = (string) $svar->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($query['code'] ?? null)->toBeString('Godkännandet gav ingen kod.');

    return (string) $query['code'];
}

/**
 * Byter en auktoriseringskod mot tokens (POST /oauth/token).
 */
function mcpVaxlaKod(
    Client $klient,
    string $kod,
    string $redirectUri,
    string $verifierare = 'test-verifierare-0000000000000000000000000000000000000000',
): TestResponse {
    return post('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $klient->getKey(),
        'redirect_uri' => $redirectUri,
        'code' => $kod,
        'code_verifier' => $verifierare,
    ]);
}

/**
 * Förnyar en refresh-token (POST /oauth/token).
 */
function mcpFornya(Client $klient, string $refreshToken): TestResponse
{
    return post('/oauth/token', [
        'grant_type' => 'refresh_token',
        'client_id' => $klient->getKey(),
        'refresh_token' => $refreshToken,
    ]);
}

/**
 * Ett helt godkänt flöde: sidan, godkännandet och tokenbytet. Returnerar
 * agenten och tokens.
 *
 * @return array{agent: Agent, client: Client, props: array<string, mixed>, access_token: string, refresh_token: string, redirect_uri: string}
 */
function mcpAnslut(
    User $anvandare,
    ?Client $klient = null,
    ?string $val = null,
): array {
    $klient ??= mcpKlient();
    $redirectUri = mcpRedirectUri($klient);

    $props = mcpSidprops(mcpAuktorisera($anvandare, $klient, $redirectUri));
    $kod = mcpKod(mcpGodkann($anvandare, $props, $val));

    $tokens = mcpVaxlaKod($klient, $kod, $redirectUri);

    $tokens->assertOk();
    $data = $tokens->json();

    return [
        'agent' => Agent::query()
            ->where('user_id', $anvandare->getKey())
            ->where('oauth_client_id', $klient->getKey())
            ->sole(),
        'client' => $klient,
        'props' => $props,
        'access_token' => (string) $data['access_token'],
        'refresh_token' => (string) $data['refresh_token'],
        'redirect_uri' => $redirectUri,
    ];
}

/**
 * En provrutt bakom guarden `mcp`. Den registreras i testet och finns inte i
 * appen — issue 400 bygger anslutningen, inte endpointen (issue 402).
 */
function mcpProvrutt(): void
{
    Route::middleware('auth:mcp')->get('/__mcp-prova', fn () => response()->json([
        'anvandare' => auth('mcp')->id(),
    ]));
}

/**
 * Det signerade bortkopplingslänken så som mejlet bär den: räknad ur
 * payloadens `agent_ulid`. Samma anrop som
 * resources/views/mail/notification.blade.php gör.
 */
function mcpBortkopplingslank(string $agentUlid): string
{
    return URL::temporarySignedRoute(
        'mcp.agents.disconnect',
        now()->addDays(7),
        ['agent' => $agentUlid],
    );
}

/**
 * Mejlets HTML för en notis, renderad ur samma mailable som leveransloopen
 * använder.
 */
function mcpMejlhtml(Notification $notis): string
{
    $anvandare = User::query()->findOrFail($notis->user_id);

    return (new NotificationMail($anvandare, $notis->type, $notis->payload))->render();
}
