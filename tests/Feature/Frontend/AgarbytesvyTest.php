<?php

// rott-pa-basen: issue 77b — ordbyte i prosa (kommentar och testnamn), ingen kodändring; bas och head delar applikationskod.

use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\OwnershipTransfer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use App\Notifications\OwnershipTransferNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withoutVite;

/*
 * Issue 67b · Ägarbytets två ytor: avsändarens sida i containern och mottagarens
 * inkorg. Se App\Http\Controllers\OwnershipTransferController,
 * resources/js/pages/Containers/Transfers.vue,
 * resources/js/pages/Transfers/Index.vue,
 * resources/js/components/TransferForm.vue och IncomingTransferCard.vue.
 *
 * Filen bevisar de sex gränserna issuen är byggd kring:
 *
 * 1. **Två nivåer** (Beslut 1): avsändarens sida under containern, mottagarens på
 *    toppnivå — och mottagarens lista innehåller bara rader som är hennes.
 * 2. **Fyra val och inget femte** (Beslut 2): mottagare (konto ELLER adress),
 *    undantagna items, kvarhållen åtkomst — och bara de nivåer requesten
 *    tillåter.
 * 3. **Planen är en mening** (Beslut 3): ytan ritas för ett gratiskonto, med
 *    meningen, och POST ger fältfel i stället för en JSON-kropp.
 * 4. **Ånger utan radering** (Beslut 4): `revoked` sätter status, raden står
 *    kvar, och en utgången rad redovisas som utgången.
 * 5. **Identitet, aldrig en länk** (Beslut 5): ingen `{token}`-rutt finns, och
 *    en overifierad adress ger ingen träff.
 * 6. **Konsekvenserna före knappen** (Beslut 6 och 7): container, avsändare, antal
 *    items, kvarhållen åtkomst och de tolv månaderna Pro, och ett kvotfel som
 *    en mening med gräns och värde — utan att något flyttas.
 *
 * Att ingen svensk sträng står kvar i en Vue-komponent och att varje ny nyckel
 * finns prövas av tests/Feature/Frontend/SprakTest.php, som
 * läser varenda fil under resources/js och jämför ui.php nyckel för nyckel. De
 * nya nycklarna prövas dessutom i sista testet här.
 *
 * Hjälparna har prefixet `agarbytesvy` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Lägger en aktiv pro-prenumeration på kontot — den enda vägen ett konto får
 * ägarbyte-funktionen i testerna (planraderna kommer ur migrationen, issue 25
 * § Beslut 2). Samma form som ägarbyteProKonto() i tests/Feature/Agarbyte/**,
 * och ett eget namn av samma skäl: filerna ska kunna läsas separat.
 */
function agarbytesvyProKonto(Account $account): void
{
    $pro = Plan::where('code', 'pro')->firstOrFail();
    Subscription::factory()->for($account)->for($pro)->create();
}

/**
 * Ett ägarkonto med Pro, en medlem och en container — avsändarens utgångsläge.
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function agarbytesvySaljare(): array
{
    [$konto, $anvandare] = kontoMedMedlem();
    agarbytesvyProKonto($konto);

    $container = Container::factory()->for($konto, 'account')->create([
        'name' => 'Vindil',
        'kind' => 'boat',
    ]);

    return [$konto, $anvandare, $container];
}

/**
 * Ägarkontot UTAN Pro — gratisplanen, som inte har `ownership_transfer`.
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function agarbytesvyGratiskonto(): array
{
    [$konto, $anvandare] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();

    return [$konto, $anvandare, $container];
}

function agarbytesvyUrl(Container $container): string
{
    return "/containers/{$container->ulid}/transfer";
}

/**
 * Ett item i containern, med skaparen satt — fabrikens egna default-skapare hade
 * annars skapat ovidkommande konton per item.
 */
function agarbytesvyItem(Container $container, Account $konto, User $anvandare, string $namn): Item
{
    return Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $konto->id,
    ]);
}

/**
 * En rad som redan gått ut. `created_at` sätts i förväg och kolumnen står kvar
 * på `pending` — utgången HÄRLEDS ur tiden (39a § Beslut 10), och raden ska
 * alltså inte skilja sig från en färsk annat än på datumet.
 */
function agarbytesvyUtgangenRad(Container $container, array $attribut = []): OwnershipTransfer
{
    return skapaÄgarbyteRad($container, array_merge([
        'created_at' => Carbon::now()->subDays(OwnershipTransfer::TTL_DAYS + 1),
    ], $attribut));
}

/**
 * Mottagaren: ett eget konto hon är medlem i, med en verifierad adress.
 *
 * @return array{0: User, 1: Account} [$mottagare, $mottagarkonto]
 */
function agarbytesvyMottagare(string $email = 'kopare@exempel.se'): array
{
    $mottagare = User::factory()->create(['email' => $email]);
    $konto = Account::factory()->create(['name' => 'Köparen']);
    $konto->users()->attach($mottagare, ['role' => 'owner']);

    return [$mottagare, $konto];
}

/**
 * Källkoden för en komponent, med kommentarerna borttagna — samma städning som
 * SprakTest gör. Utan den hade en mening i ett docblock kunnat bevisa ett test,
 * och det är koden som gäller.
 */
function agarbytesvyKomponent(string $relativSokvag): string
{
    $kod = File::get(resource_path('js/'.$relativSokvag));
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

// --- avsändarens sida: listan ------------------------------------------

it('listar pågående och avslutade överlåtelser med status', function () {
    withoutVite();

    [, $anvandare, $container] = agarbytesvySaljare();
    [$mottagare, $mottagarkonto] = agarbytesvyMottagare();

    // Datumen sätts i förväg så ordningen är given: nyast först, som `/api`:s
    // index (39a § Beslut 10).
    $vantande = skapaÄgarbyteRad($container, ['to_account_id' => $mottagarkonto->id, 'to_email' => null]);
    $accepterad = skapaÄgarbyteRad($container, [
        'to_account_id' => $mottagarkonto->id,
        'to_email' => null,
        'status' => 'accepted',
        'accepted_at' => Carbon::now()->subDays(1),
        'created_at' => Carbon::now()->subDays(2),
    ]);
    $avvisad = skapaÄgarbyteRad($container, [
        'to_account_id' => $mottagarkonto->id,
        'to_email' => null,
        'status' => 'rejected',
        'created_at' => Carbon::now()->subDays(3),
    ]);
    $utgangen = agarbytesvyUtgangenRad($container, ['to_account_id' => $mottagarkonto->id, 'to_email' => null]);

    actingAs($anvandare)->get(agarbytesvyUrl($container))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('Containers/Transfers')
            ->has('transfers', 4)
            ->where('transfers.0.ulid', $vantande->ulid)
            ->where('transfers.0.status', 'pending')
            ->where('transfers.1.ulid', $accepterad->ulid)
            ->where('transfers.2.ulid', $avvisad->ulid)
            // Utgången HÄRLEDS: kolumnen står kvar på `pending`, och det är
            // resursen som redovisar raden som utgången.
            ->where('transfers.3.ulid', $utgangen->ulid)
            ->where('transfers.3.status', 'expired')
            // Namnet kommer från kontrollern, bredvid resursen: ULID:en är
            // oläsbar för den här listans enda publik.
            ->where('transfers.0.recipient_name', 'Köparen')
    );
});

it('visar mottagarens adress på en rad som nåddes på e-post', function () {
    withoutVite();

    [, $anvandare, $container] = agarbytesvySaljare();

    skapaÄgarbyteRad($container, ['to_account_id' => null, 'to_email' => 'kopare@exempel.se']);

    actingAs($anvandare)->get(agarbytesvyUrl($container))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('transfers.0.to_email', 'kopare@exempel.se')
            ->where('transfers.0.to_account', null)
    );
});

// --- initieringen: fyra val --------------------------------------------

it('kan initieras mot ett konto-ULID eller en e-postadress', function () {
    withoutVite();
    Notification::fake();

    [, $anvandare, $container] = agarbytesvySaljare();
    [, $mottagarkonto] = agarbytesvyMottagare();

    actingAs($anvandare)
        ->post(agarbytesvyUrl($container), ['to_account' => $mottagarkonto->ulid])
        ->assertRedirect(agarbytesvyUrl($container))
        ->assertSessionHas('status', 'transfer-created');

    $motKonto = OwnershipTransfer::query()->firstOrFail();
    expect($motKonto->to_account_id)->toBe($mottagarkonto->id);
    expect($motKonto->to_email)->toBeNull();
    expect($motKonto->from_account_id)->toBe($container->account_id);
    expect($motKonto->status)->toBe('pending');

    // En adress normaliseras till gemener innan den lagras, som
    // inbjudningarna (39a § Beslut 6). En EGEN container: den första raden väntar
    // fortfarande, och dubblettspärren hade nekat en andra på samma container.
    $annan = Container::factory()->for($container->account, 'account')->create();

    actingAs($anvandare)
        ->post(agarbytesvyUrl($annan), ['to_email' => 'Kopare@Exempel.SE'])
        ->assertRedirect();

    $motAdress = OwnershipTransfer::query()->where('container_id', $annan->id)->firstOrFail();
    expect($motAdress->to_email)->toBe('kopare@exempel.se');
    expect($motAdress->to_account_id)->toBeNull();
});

it('ger fältfel när båda mottagarvägarna fylls i samtidigt', function () {
    withoutVite();

    [, $anvandare, $container] = agarbytesvySaljare();
    [, $mottagarkonto] = agarbytesvyMottagare();

    actingAs($anvandare)
        ->post(agarbytesvyUrl($container), [
            'to_account' => $mottagarkonto->ulid,
            'to_email' => 'kopare@exempel.se',
        ])
        ->assertSessionHasErrors(['to_account', 'to_email']);

    // Och ingen av dem: samma svar, för `required_without` gäller åt båda håll.
    actingAs($anvandare)
        ->post(agarbytesvyUrl($container), [])
        ->assertSessionHasErrors(['to_account', 'to_email']);

    expect(OwnershipTransfer::query()->count())->toBe(0);
});

it('undantar items och sparar dem som ULID:er på raden', function () {
    withoutVite();
    Notification::fake();

    [$konto, $anvandare, $container] = agarbytesvySaljare();
    [, $mottagarkonto] = agarbytesvyMottagare();

    agarbytesvyItem($container, $konto, $anvandare, 'Motorn');
    $forsakringsbrev = agarbytesvyItem($container, $konto, $anvandare, 'Försäkringsbrevet');

    actingAs($anvandare)
        ->post(agarbytesvyUrl($container), [
            'to_account' => $mottagarkonto->ulid,
            'excluded_items' => [$forsakringsbrev->ulid],
        ])
        ->assertRedirect();

    expect(OwnershipTransfer::query()->firstOrFail()->excluded_item_ids)
        ->toBe([$forsakringsbrev->ulid]);
});

it('säger att allt annat än undantagen följer med', function () {
    $formular = agarbytesvyKomponent('components/TransferForm.vue');

    // Meningen och räkningen står i formuläret, inte bara i lang/: en avsändare
    // som kryssar fel ska se vad som faktiskt händer medan hon kryssar.
    expect($formular)->toContain('transfer.excluded.help');
    expect($formular)->toContain('transfer.excluded.following');

    // Och undantagen är undantag: kryssrutorna skickar de MARKERADE, aldrig
    // ett urval av vad som ingår.
    expect($formular)->toContain('excluded_items');
});

it('erbjuder read och write som kvarhållen åtkomst, inget annat', function () {
    withoutVite();
    Notification::fake();

    [, $anvandare, $container] = agarbytesvySaljare();
    [, $mottagarkonto] = agarbytesvyMottagare();

    actingAs($anvandare)->get(agarbytesvyUrl($container))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('retainLevels', ['read', 'write'])
    );

    // Laddern har fyra steg sedan [[ADR-0028 Åtkomst på itemnivå]], men den
    // här requesten tillåter två — och vyn uppfinner ingen nivå den avvisar.
    actingAs($anvandare)
        ->post(agarbytesvyUrl($container), [
            'to_account' => $mottagarkonto->ulid,
            'retain_access_level' => 'delete',
        ])
        ->assertSessionHasErrors('retain_access_level');

    expect(OwnershipTransfer::query()->count())->toBe(0);

    actingAs($anvandare)
        ->post(agarbytesvyUrl($container), [
            'to_account' => $mottagarkonto->ulid,
            'retain_access_level' => 'write',
        ])
        ->assertRedirect();

    expect(OwnershipTransfer::query()->firstOrFail()->retain_access_level)->toBe('write');
});

it('avvisar ett andra ägarbyte medan det första väntar', function () {
    withoutVite();

    [, $anvandare, $container] = agarbytesvySaljare();
    [, $mottagarkonto] = agarbytesvyMottagare();
    [, $annatKonto] = agarbytesvyMottagare('annan@exempel.se');

    skapaÄgarbyteRad($container, ['to_account_id' => $mottagarkonto->id, 'to_email' => null]);

    actingAs($anvandare)
        ->post(agarbytesvyUrl($container), ['to_account' => $annatKonto->ulid])
        ->assertSessionHasErrors('transfer');

    expect(OwnershipTransfer::query()->count())->toBe(1);
});

// --- planen -------------------------------------------------------------

it('ritar ytan för ett gratiskonto med en mening om planen och ger fältfel vid POST', function () {
    withoutVite();

    [, $anvandare, $container] = agarbytesvyGratiskonto();
    [, $mottagarkonto] = agarbytesvyMottagare();

    $mening = (string) trans('ui.error.plan.feature_unavailable', ['feature' => 'Ownership transfer'], 'en');

    actingAs($anvandare)->get(agarbytesvyUrl($container))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('Containers/Transfers')
            ->where('planNotice', $mening)
    );

    actingAs($anvandare)
        ->post(agarbytesvyUrl($container), ['to_account' => $mottagarkonto->ulid])
        ->assertRedirect()
        ->assertSessionHasErrors('plan');

    // Samma mening på sidan och i fältfelet, för den formuleras en gång.
    expect(session('errors')->get('plan')[0])->toBe($mening);
    expect(OwnershipTransfer::query()->count())->toBe(0);
});

it('länkar till plansidan bredvid planmeningen', function () {
    // Länken står bredvid rutan och inte i översättningssträngen — markup i en
    // sträng som också levereras av /api:ets felhölje blir escapad text eller
    // `v-html` någonstans.
    $sida = agarbytesvyKomponent('pages/Containers/Transfers.vue');

    expect($sida)->toContain('transfer.plan_link');
    expect($sida)->toContain('/settings/plan?account=');

    expect(Lang::has('ui.error.plan.feature_name.ownership_transfer', 'en'))->toBeTrue();
});

// --- behörigheten -------------------------------------------------------

it('ger 403 för en användare som inte får överlåta containern', function () {
    withoutVite();

    [, , $container] = agarbytesvySaljare();

    $utomstaende = User::factory()->create();
    $konto = Account::factory()->create();
    $konto->users()->attach($utomstaende, ['role' => 'owner']);

    // En delegerad åtkomst på `write` — högsta nivån under ägaren — når
    // varken sidan eller skrivningen: ingen nivå får initiera ett ägarbyte
    // ([[Konton och åtkomst]] § Behörighetsregler regel 3).
    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'grantee_type' => 'user',
        'grantee_id' => $utomstaende->id,
        'level' => 'write',
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    actingAs($utomstaende)->get(agarbytesvyUrl($container))->assertForbidden();
    actingAs($utomstaende)->post(agarbytesvyUrl($container), ['to_email' => 'nagon@exempel.se'])->assertForbidden();
});

it('låter ett fryst ägarkonto se listan men inte skicka', function () {
    withoutVite();

    [$konto, $anvandare, $container] = agarbytesvySaljare();
    $konto->update(['status' => 'read_only']);

    // `viewTransfers()` saknar regel 4-kontrollen med flit: att se sina
    // utestående ägarbyten är att läsa, och ett konto som är på väg att
    // nedgraderas måste kunna se sin egen historik. `transfer()` har den.
    actingAs($anvandare)->get(agarbytesvyUrl($container))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('can.transfer', false)
    );

    actingAs($anvandare)
        ->post(agarbytesvyUrl($container), ['to_email' => 'nagon@exempel.se'])
        ->assertForbidden();
});

// --- ångern -------------------------------------------------------------

it('drar tillbaka en pågående överlåtelse och låter raden stå kvar', function () {
    withoutVite();

    [, $anvandare, $container] = agarbytesvySaljare();
    [, $mottagarkonto] = agarbytesvyMottagare();

    $rad = skapaÄgarbyteRad($container, ['to_account_id' => $mottagarkonto->id, 'to_email' => null]);

    actingAs($anvandare)
        ->delete(agarbytesvyUrl($container)."/{$rad->ulid}")
        ->assertRedirect(agarbytesvyUrl($container))
        ->assertSessionHas('status', 'transfer-revoked');

    expect($rad->fresh()->status)->toBe('revoked');
    expect(OwnershipTransfer::query()->count())->toBe(1);

    // En andra tillbakadragning rör ingenting och svarar med meningen på
    // formulärnyckeln `transfer` — raden är redan besvarad.
    actingAs($anvandare)
        ->delete(agarbytesvyUrl($container)."/{$rad->ulid}")
        ->assertSessionHasErrors('transfer');

    expect($rad->fresh()->status)->toBe('revoked');
});

it('visar en utgången överlåtelse som utgången och låter den dras tillbaka', function () {
    withoutVite();

    [, $anvandare, $container] = agarbytesvySaljare();
    [, $mottagarkonto] = agarbytesvyMottagare();

    $rad = agarbytesvyUtgangenRad($container, ['to_account_id' => $mottagarkonto->id, 'to_email' => null]);

    actingAs($anvandare)->get(agarbytesvyUrl($container))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('transfers', 1)
            ->where('transfers.0.status', 'expired')
    );

    // Kolumnen står kvar på `pending`, så raden går fortfarande att städa bort
    // ur listan — det är precis vad avsändaren vill kunna göra.
    actingAs($anvandare)->delete(agarbytesvyUrl($container)."/{$rad->ulid}")->assertRedirect();

    expect($rad->fresh()->status)->toBe('revoked');
});

// --- mottagarens inkorg -------------------------------------------------

it('listar inkommande överlåtelser mot kontot och mot den verifierade adressen', function () {
    withoutVite();

    [, , $container] = agarbytesvySaljare();
    [$mottagare, $mottagarkonto] = agarbytesvyMottagare('kopare@exempel.se');
    [, $annatKonto] = agarbytesvyMottagare('nagon-annan@exempel.se');

    $motKontot = skapaÄgarbyteRad($container, ['to_account_id' => $mottagarkonto->id, 'to_email' => null]);

    // Den andra raden skapas "senare" så ordningen är given och inte beror på
    // om båda hamnade inom samma sekund.
    Carbon::setTestNow(Carbon::now()->addMinute());
    $motAdressen = skapaÄgarbyteRad($container, ['to_account_id' => null, 'to_email' => 'kopare@exempel.se']);
    Carbon::setTestNow();

    // Två rader som INTE är hennes: ett annat konto, och en annan adress.
    skapaÄgarbyteRad($container, ['to_account_id' => $annatKonto->id, 'to_email' => null]);
    skapaÄgarbyteRad($container, ['to_account_id' => null, 'to_email' => 'nagon-annan@exempel.se']);

    actingAs($mottagare)->get('/transfers')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('Transfers/Index')
            ->has('transfers', 2)
            ->where('transfers.0.ulid', $motAdressen->ulid)
            ->where('transfers.1.ulid', $motKontot->ulid)
    );
});

it('ger ingen träff för en overifierad adress', function () {
    withoutVite();

    [, , $container] = agarbytesvySaljare();

    $overifierad = User::factory()->unverified()->create(['email' => 'overifierad@exempel.se']);

    skapaÄgarbyteRad($container, ['to_account_id' => null, 'to_email' => 'overifierad@exempel.se']);

    actingAs($overifierad)->get('/transfers')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->has('transfers', 0)
    );
});

it('lämnar en utgången överlåtelse utanför inkorgen men kvar i avsändarens lista', function () {
    withoutVite();

    [, $saljare, $container] = agarbytesvySaljare();
    [$mottagare, $mottagarkonto] = agarbytesvyMottagare();

    agarbytesvyUtgangenRad($container, ['to_account_id' => $mottagarkonto->id, 'to_email' => null]);

    actingAs($mottagare)->get('/transfers')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->has('transfers', 0)
    );

    actingAs($saljare)->get(agarbytesvyUrl($container))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->has('transfers', 1)
    );
});

// --- ingen token, ingen länk -------------------------------------------

it('har inga tokenrutter och skickar inget token i mejlet', function () {
    $rutter = [
        'containers.transfer' => 'containers/{container}/transfer',
        'containers.transfer.store' => 'containers/{container}/transfer',
        'containers.transfer.destroy' => 'containers/{container}/transfer/{transfer}',
        'transfers.index' => 'transfers',
        'transfers.accept' => 'transfers/{transfer}/accept',
        'transfers.reject' => 'transfers/{transfer}/reject',
    ];

    foreach ($rutter as $namn => $uri) {
        $rutt = Route::getRoutes()->getByName($namn);

        expect($rutt)->not->toBeNull("Rutten {$namn} saknas");
        expect($rutt->uri())->toBe($uri);
    }

    // Ingen rutt i hela tabellen tar emot ett token, och ingen bär ett segment
    // som heter `transfers` utan att vara en av de sex ovan.
    foreach (Route::getRoutes()->getRoutes() as $rutt) {
        if (str_contains($rutt->uri(), 'transfer')) {
            expect($rutt->uri())->not->toContain('{token}');
        }
    }

    // Mejlet till en adress utan konto pekar på den statiska sökvägen — ingen
    // frågeparameter, ingen hemlighet, ingenting att klicka sönder.
    Notification::fake();

    [, $anvandare, $container] = agarbytesvySaljare();

    actingAs($anvandare)
        ->post(agarbytesvyUrl($container), ['to_email' => 'okand@exempel.se'])
        ->assertRedirect();

    Notification::assertSentOnDemand(
        OwnershipTransferNotification::class,
        function (OwnershipTransferNotification $notification, array $kanaler, AnonymousNotifiable $notifierad) {
            expect($notifierad->routes['mail'])->toBe('okand@exempel.se');
            expect($notification->url)->toBe(rtrim((string) config('app.url'), '/').'/transfers');

            return true;
        }
    );
});

// --- accepten -----------------------------------------------------------

it('visar konsekvenserna innan knappen', function () {
    withoutVite();

    [, , $container] = agarbytesvySaljare();
    [$mottagare, $mottagarkonto] = agarbytesvyMottagare();

    // Fem items i containern, två av dem undantagna — alltså tre som följer med.
    for ($i = 1; $i <= 3; $i++) {
        Item::factory()->for($container, 'container')->create(['name' => "Följer med {$i}"]);
    }

    $undantagna = Item::factory()->for($container, 'container')
        ->count(2)
        ->create(['name' => 'Stannar']);

    skapaÄgarbyteRad($container, [
        'to_account_id' => $mottagarkonto->id,
        'to_email' => null,
        'excluded_item_ids' => $undantagna->pluck('ulid')->all(),
        'retain_access_level' => 'write',
    ]);

    actingAs($mottagare)->get('/transfers')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('transfers.0.container.name', 'Vindil')
            ->where('transfers.0.from_account_name', $container->account->name)
            ->where('transfers.0.item_count', 5)
            ->where('transfers.0.excluded_count', 2)
            ->where('transfers.0.retain_access_level', 'write')
    );

    // Och de tolv månaderna Pro står i kortet, före knapparna — tillsammans
    // med meningen om att beslutet är slutgiltigt.
    $kort = agarbytesvyKomponent('components/IncomingTransferCard.vue');

    expect($kort)->toContain('transfer.card.pro');
    expect($kort)->toContain('transfer.card.final');
});

it('räknar bara undantag som fortfarande finns i containern', function () {
    withoutVite();

    [, , $container] = agarbytesvySaljare();
    [$mottagare, $mottagarkonto] = agarbytesvyMottagare();

    $kvar = Item::factory()->for($container, 'container')->create(['name' => 'Motorn']);
    $raderad = Item::factory()->for($container, 'container')->create(['name' => 'Såld']);

    skapaÄgarbyteRad($container, [
        'to_account_id' => $mottagarkonto->id,
        'to_email' => null,
        'excluded_item_ids' => [$kvar->ulid, $raderad->ulid],
    ]);

    // Posten raderas EFTER att överlåtelsen initierades. Den lyfts inte ut vid
    // accepten och följer varken med eller undantas — alltså räknas den inte.
    $raderad->delete();

    actingAs($mottagare)->get('/transfers')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('transfers.0.item_count', 1)
            ->where('transfers.0.excluded_count', 1)
    );
});

it('flyttar containern vid accept och låter mottagaren se den i containerlistan', function () {
    withoutVite();

    [, , $container] = agarbytesvySaljare();
    [$mottagare, $mottagarkonto] = agarbytesvyMottagare();

    $rad = skapaÄgarbyteRad($container, ['to_account_id' => $mottagarkonto->id, 'to_email' => null]);

    actingAs($mottagare)
        ->post("/transfers/{$rad->ulid}/accept")
        ->assertRedirect('/containers')
        ->assertSessionHas('status', 'transfer-accepted');

    expect($container->fresh()->account_id)->toBe($mottagarkonto->id);
    expect($rad->fresh()->status)->toBe('accepted');

    actingAs($mottagare)->get('/containers')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('containers.0.name', 'Vindil')
    );
});

it('accepterar en överlåtelse ställd till en adress när kroppen pekar ut kontot', function () {
    withoutVite();

    [, , $container] = agarbytesvySaljare();
    [$mottagare, $mottagarkonto] = agarbytesvyMottagare('kopare@exempel.se');

    $rad = skapaÄgarbyteRad($container, ['to_account_id' => null, 'to_email' => 'kopare@exempel.se']);

    // Utan `to_account` nekar den delade requesten: den inloggade kan vara
    // medlem i flera konton, och systemet får inte gissa vilket som köpte.
    actingAs($mottagare)
        ->post("/transfers/{$rad->ulid}/accept")
        ->assertSessionHasErrors('to_account');

    expect($container->fresh()->account_id)->not->toBe($mottagarkonto->id);

    actingAs($mottagare)
        ->post("/transfers/{$rad->ulid}/accept", ['to_account' => $mottagarkonto->ulid])
        ->assertRedirect('/containers');

    expect($container->fresh()->account_id)->toBe($mottagarkonto->id);
});

it('nekar en accept som spränger mottagarens plan och flyttar ingenting', function () {
    withoutVite();

    [, , $container] = agarbytesvySaljare();
    [$mottagare, $mottagarkonto] = agarbytesvyMottagare();

    // Gratisplanen rymmer EN container, och mottagaren har redan en: kvotkontrollen
    // i AcceptOwnershipTransfer slår i mot hennes NUVARANDE plan, före
    // Pro-bonusen.
    Container::factory()->for($mottagarkonto, 'account')->create();
    UsageCounter::factory()->create([
        'account_id' => $mottagarkonto->id,
        'container_count' => 1,
        'storage_bytes' => 0,
    ]);

    $rad = skapaÄgarbyteRad($container, ['to_account_id' => $mottagarkonto->id, 'to_email' => null]);

    actingAs($mottagare)
        ->post("/transfers/{$rad->ulid}/accept")
        ->assertRedirect()
        ->assertSessionHasErrors('transfer');

    // Meningen bär gränsen och värdet, som varje annat kvotfel på webben.
    $mening = session('errors')->get('transfer')[0];

    expect($mening)->toBe(trans('ui.error.quota.containers_exceeded', ['limit' => 1, 'used' => 1], 'en'));
    expect($mening)->toContain('1');

    // Och ingenting flyttades: hela transaktionen rullades tillbaka.
    expect($container->fresh()->account_id)->not->toBe($mottagarkonto->id);
    expect($rad->fresh()->status)->toBe('pending');
});

it('avvisar en överlåtelse och låter raden stå kvar utan väg tillbaka', function () {
    withoutVite();

    [, $saljare, $container] = agarbytesvySaljare();
    [$mottagare, $mottagarkonto] = agarbytesvyMottagare();

    $rad = skapaÄgarbyteRad($container, ['to_account_id' => $mottagarkonto->id, 'to_email' => null]);

    actingAs($mottagare)
        ->post("/transfers/{$rad->ulid}/reject")
        ->assertRedirect('/transfers')
        ->assertSessionHas('status', 'transfer-rejected');

    expect($rad->fresh()->status)->toBe('rejected');
    expect(OwnershipTransfer::query()->count())->toBe(1);

    // Raden är besvarad och försvinner ur inkorgen — ett andra försök är 404,
    // inte ett nytt avslag.
    actingAs($mottagare)->get('/transfers')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->has('transfers', 0)
    );

    actingAs($mottagare)->post("/transfers/{$rad->ulid}/reject")->assertNotFound();

    // Avsändaren ser den i historiken med sin status — beslutet går inte att
    // ångra, och en ny överlåtelse måste skickas.
    actingAs($saljare)->get(agarbytesvyUrl($container))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('transfers', 1)
            ->where('transfers.0.status', 'rejected')
    );
});

it('ger 404 för en överlåtelse som tillhör någon annan, aldrig 403', function () {
    withoutVite();

    [, , $container] = agarbytesvySaljare();
    [, $mottagarkonto] = agarbytesvyMottagare();
    [$utomstaende] = agarbytesvyMottagare('utomstaende@exempel.se');

    $rad = skapaÄgarbyteRad($container, ['to_account_id' => $mottagarkonto->id, 'to_email' => null]);

    // En rad som inte pekar på användaren ska vara OSYNLIG: ett 403 vore att
    // bekräfta att den finns (Beslut 8).
    actingAs($utomstaende)->post("/transfers/{$rad->ulid}/reject")->assertNotFound();
    actingAs($utomstaende)->post("/transfers/{$rad->ulid}/accept")->assertNotFound();

    expect($rad->fresh()->status)->toBe('pending');
    expect($rad->fresh()->to_account_id)->toBe($mottagarkonto->id);

    // Samma svar för en ULID som inte finns alls.
    actingAs($utomstaende)->post('/transfers/01JZZZZZZZZZZZZZZZZZZZZZZZ/reject')->assertNotFound();
});

// --- /api är oförändrat -------------------------------------------------

it('lämnar /api:s avsändarrutter oförändrade', function () {
    [$saljarkonto, , $saljarHeaders] = kontoMedMedlem();
    agarbytesvyProKonto($saljarkonto);

    $container = Container::factory()->for($saljarkonto, 'account')->create();
    [$mottagarkonto] = kontoMedMedlem();

    $url = "/api/containers/{$container->ulid}/transfers";

    $skapat = postJson($url, ['to_account' => $mottagarkonto->ulid], $saljarHeaders)->assertCreated();

    // Samma kropp som förut: resursens fält och ingenting mer.
    expect(array_keys($skapat->json('data')))->toBe([
        'ulid', 'status', 'to_email', 'to_account', 'container', 'excluded_items',
        'retain_access_level', 'accepted_at', 'expires_at', 'created_at',
    ]);

    getJson($url, $saljarHeaders)->assertOk()->assertJsonCount(1, 'data');

    // Dubblettspärren svarar fortfarande med felkoden i höljet, aldrig med en
    // översatt mening ([[ADR-0013 Språk och i18n]]).
    postJson($url, ['to_account' => $mottagarkonto->ulid], $saljarHeaders)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'transfer.already_pending');

    // En EGEN container för raden som ska dras tillbaka: den första väntar
    // fortfarande, och dubblettspärren gäller per container.
    $ny = Container::factory()->for($saljarkonto, 'account')->create();
    $nyUrl = "/api/containers/{$ny->ulid}/transfers";

    $andra = postJson($nyUrl, ['to_email' => 'ny@exempel.se'], $saljarHeaders)->assertCreated()->json('data.ulid');

    deleteJson($nyUrl.'/'.$andra, [], $saljarHeaders)->assertNoContent();

    expect(OwnershipTransfer::query()->where('ulid', $andra)->firstOrFail()->status)->toBe('revoked');
});

it('lämnar /api:s mottagarrutter oförändrade', function () {
    [, , $container] = agarbytesvySaljare();
    [$mottagare, $mottagarkonto] = agarbytesvyMottagare();

    $token = $mottagare->createToken('api');
    $headers = ['Authorization' => "Bearer {$token->plainTextToken}"];

    $rad = skapaÄgarbyteRad($container, ['to_account_id' => $mottagarkonto->id, 'to_email' => null]);

    getJson('/api/transfers', $headers)->assertOk()->assertJsonCount(1, 'data');

    postJson("/api/transfers/{$rad->ulid}/reject", [], $headers)->assertNoContent();

    getJson('/api/transfers', $headers)->assertOk()->assertJsonCount(0, 'data');

    postJson("/api/transfers/{$rad->ulid}/accept", [], $headers)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'transfer.not_pending');
});

// --- språknycklarna -----------------------------------------------------

it('har ägarbytets nycklar och läser dem ur lang/', function () {
    $nycklar = [
        'transfer.heading',
        'transfer.intro',
        'transfer.plan_link',
        'transfer.form.notice',
        'transfer.excluded.help',
        'transfer.excluded.following',
        'transfer.retain.none',
        'transfer.status.expired',
        'transfer.row.revoke',
        'transfer.inbox.empty',
        'transfer.card.pro',
        'transfer.card.quota',
        'transfer.card.final',
        'transfer.accept',
        'transfer.reject',
        'container.nav.transfer',
        'flash.transfer-created',
        'flash.transfer-revoked',
        'flash.transfer-accepted',
        'flash.transfer-rejected',
        'error.transfer.already_pending',
        'error.transfer.not_pending',
        'error.transfer.expired',
        'error.transfer.account_frozen',
        'error.plan.feature_name.ownership_transfer',
    ];

    foreach ($nycklar as $nyckel) {
        expect(Lang::has("ui.{$nyckel}", 'en'))->toBeTrue("ui.{$nyckel} saknas");
        expect(trim((string) trans("ui.{$nyckel}", [], 'en')))->not->toBe('');
    }

    // Felkoden `transfer.expired` slås upp som `error.transfer.expired` av
    // App\Support\Frontend\ApiErrorTranslator — grenen kan inte heta något
    // annat, hur gärna meningen än hör till ägarbytet. Meningen är en mening
    // och inte nyckeln själv.
    expect(trans('ui.error.transfer.expired', [], 'en'))
        ->toBe('The transfer has expired. The sender must send a new one.');
});
