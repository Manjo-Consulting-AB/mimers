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
 * oförändrade. Ursprungsfilen står i funktionens docblock.
 */

use App\Actions\Attachment\PurgeAttachment;
use App\Actions\Schedule\OpenNextOccurrence;
use App\Actions\Trash\PurgeContainer;
use App\Actions\Trash\PurgeContent;
use App\Console\PurgesExpiredTrash;
use App\Mail\NotificationMail;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Invitation;
use App\Models\Item;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\OccurrenceDependency;
use App\Models\Plan;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\StoredFile;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Carbon;
use PragmaRX\Google2FA\Google2FA;

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

    $occurrence = app(OpenNextOccurrence::class)->handle($schedule);

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
