<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withoutVite;

/*
 * Issue 67a · Utlåningssektionen på itemets detaljvy — den öppna utlåningen,
 * historiken, utlåningen, återlämningen och raderingen. Se
 * App\Http\Controllers\LoanController, App\Http\Controllers\ItemController::
 * show() och resources/js/components/ItemLoanSection.vue.
 *
 * Filen bevisar de sex gränserna issuen är byggd kring:
 *
 * 1. **Listan** — den öppna utlåningen överst och historiken under, delade på
 *    servern ur detaljvyns props och till ett konstant antal frågor oavsett
 *    antal lån (Beslut 1 och 2).
 * 2. **Skrivningarna** — utlåningen, återlämningen och raderingen går genom
 *    `StoreLoanRequest` och `UpdateLoanRequest` rakt av, med `after_or_equal:
 *    lent_at` orörd (Beslut 3).
 * 3. **Försenad är härledd** på serverns datum och läses ur en flagga — vyn
 *    har ingen egen klocka (Beslut 5).
 * 4. **Adressen är en kontaktuppgift** — ingen `mailto:`, ingen
 *    påminnelseknapp, och ytan säger varför (Beslut 4).
 * 5. **Grindarna** är itemets, en pinne per handling: `view`, `create`,
 *    `update`, `delete` (Beslut 6).
 * 6. **Raderingen är inte en återlämning** och bekräftelsen säger det
 *    (Beslut 7).
 *
 * Att ingen svensk sträng står kvar i en Vue-komponent och att varje ny nyckel
 * finns på båda språken prövas av tests/Feature/Frontend/SprakTest.php, som
 * läser varenda fil under resources/js och jämför ui.php nyckel för nyckel.
 * Att `/api`:s fyra utlåningsrutter svarar exakt som förut prövas i sista
 * testet här.
 *
 * Hjälparna har prefixet `utlaningsvy` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Ett konto med en medlem, och en pärm med ett item under kontot. Båda på
 * svenska, så meningarna nedan kan jämföras mot `Lang::get(…, 'sv')`.
 *
 * @return array{0: Account, 1: User, 2: Container, 3: Item}
 */
function utlaningsvyKontext(): array
{
    $konto = Account::factory()->create(['locale' => 'sv_SE']);
    $anvandare = User::factory()->create(['locale' => 'sv_SE']);
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    $container = Container::factory()->for($konto, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'name' => 'Motorn',
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $konto->id,
    ]);

    return [$konto, $anvandare, $container, $item];
}

/**
 * En mottagare UTANFÖR ägarkontot, med en itemgrant på angiven nivå.
 *
 * @return array{0: User, 1: Account} mottagaren och hennes EGET konto
 */
function utlaningsvyMottagare(Container $container, Item $item, string $niva): array
{
    $mottagare = User::factory()->create(['locale' => 'sv_SE']);

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item->id,
        'grantee_type' => 'user',
        'grantee_id' => $mottagare->id,
        'level' => $niva,
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    $egetKonto = Account::factory()->create();
    $egetKonto->users()->attach($mottagare, ['role' => 'owner']);

    return [$mottagare, $egetKonto];
}

/**
 * En utlåningsrad på itemet. Varje fält kan överstyras; grunden är en ÖPPEN
 * utlåning, eftersom det är den vanligaste raden i testerna.
 *
 * @param  array<string, mixed>  $attribut
 */
function utlaningsvyLan(Item $item, array $attribut = []): Loan
{
    return Loan::factory()->for($item, 'item')->create(array_merge([
        'borrower_name' => 'Grannen',
        'borrower_email' => null,
        'lent_at' => '2026-09-01',
        'due_at' => null,
        'returned_at' => null,
        'note' => null,
    ], $attribut));
}

function utlaningsvyUrl(Container $container, Item $item): string
{
    return "/containers/{$container->ulid}/items/{$item->ulid}";
}

/**
 * Källkoden för sektionen, med kommentarerna borttagna — samma städning som
 * SprakTest gör. Utan den hade en mening i ett docblock kunnat bevisa eller
 * motbevisa ett test, och det är koden som gäller.
 */
function utlaningsvyKomponent(): string
{
    $kod = File::get(resource_path('js/components/ItemLoanSection.vue'));
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

// --- listan: öppen överst, historik under -------------------------------

it('visar den öppna utlåningen överst och tidigare utlåningar som historik', function () {
    withoutVite();

    [, $anvandare, $container, $item] = utlaningsvyKontext();

    // Den öppna lånades ut FÖRST, alltså sist i serverns sortering (lent_at
    // fallande) — och ändå överst på sidan. Det är hela skillnaden mot att
    // rendera listan i den ordning `/api` ger den.
    $oppen = utlaningsvyLan($item, ['borrower_name' => 'Grannen', 'lent_at' => '2026-08-01']);
    $aldre = utlaningsvyLan($item, ['borrower_name' => 'Systern', 'lent_at' => '2026-09-01', 'returned_at' => '2026-09-05']);
    $nyare = utlaningsvyLan($item, ['borrower_name' => 'Kollegan', 'lent_at' => '2026-09-10', 'returned_at' => '2026-09-12']);

    actingAs($anvandare)->get(utlaningsvyUrl($container, $item))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('Containers/Items/Show')
            ->where('openLoan.ulid', $oppen->ulid)
            ->where('openLoan.borrower_name', 'Grannen')
            ->where('openLoan.is_open', true)
            ->has('loanHistory', 2)
            ->where('loanHistory.0.ulid', $nyare->ulid)
            ->where('loanHistory.1.ulid', $aldre->ulid)
            ->where('loanHistory.0.is_open', false)
    );
});

it('visar varken öppen utlåning eller historik på ett item som aldrig lånats ut', function () {
    withoutVite();

    [, $anvandare, $container, $item] = utlaningsvyKontext();

    actingAs($anvandare)->get(utlaningsvyUrl($container, $item))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('openLoan', null)
            ->where('openLoanOverdue', false)
            ->has('loanHistory', 0)
    );
});

it('kostar ett konstant antal frågor oavsett antal utlåningar', function () {
    withoutVite();

    [, $anvandare, $container, $item] = utlaningsvyKontext();

    $url = utlaningsvyUrl($container, $item);

    // Jämförelsepunkten är EN rad och inte noll: en tom relation hoppar
    // Eloquent över sin fråga helt, så noll rader kostar mindre av ett skäl
    // som inte har med per-rad-arbete att göra (samma resonemang som
    // BilagevyTest).
    utlaningsvyLan($item, ['returned_at' => '2026-09-02']);

    // Värm sessionen så att den första frågan för `last_active_at` inte räknas
    // med.
    actingAs($anvandare)->get($url)->assertOk();

    $antal = 0;

    DB::listen(function ($query) use (&$antal) {
        if (! str_contains($query->sql, 'last_active_at')) {
            $antal++;
        }
    });

    $antal = 0;
    actingAs($anvandare)->get($url)->assertOk();
    $medEn = $antal;

    foreach (range(2, 10) as $i) {
        utlaningsvyLan($item, ['returned_at' => '2026-09-02']);
    }

    $antal = 0;
    actingAs($anvandare)->get($url)->assertOk();

    // Lånen hämtas i EN fråga och LoanResource läser bara kolumner på raden
    // själv — inga relationer att ladda i förväg (Beslut 1).
    expect($antal)->toBe($medEn);
});

// --- utlåningen: samma regler som /api ----------------------------------

it('lånar ut ett item med namn, adress, datum och anteckning', function () {
    withoutVite();

    [, $anvandare, $container, $item] = utlaningsvyKontext();

    $svar = actingAs($anvandare)->post(utlaningsvyUrl($container, $item).'/loans', [
        'borrower_name' => 'Grannen',
        'borrower_email' => 'granne@example.com',
        'lent_at' => '2026-09-01',
        'due_at' => '2026-09-15',
        'note' => 'Med tillbehörslådan.',
    ]);

    $svar->assertRedirect(utlaningsvyUrl($container, $item));
    $svar->assertSessionHas('status', 'loan-created');

    $loan = Loan::query()->firstOrFail();

    expect($loan->item_id)->toBe($item->id);
    expect($loan->borrower_name)->toBe('Grannen');
    expect($loan->borrower_email)->toBe('granne@example.com');
    expect($loan->lent_at->toDateString())->toBe('2026-09-01');
    expect($loan->due_at->toDateString())->toBe('2026-09-15');
    expect($loan->returned_at)->toBeNull();

    actingAs($anvandare)->get(utlaningsvyUrl($container, $item))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('openLoan.ulid', $loan->ulid)
            ->has('loanHistory', 0)
    );
});

it('lägger en utlåning som redan är återlämnad i historiken', function () {
    withoutVite();

    [, $anvandare, $container, $item] = utlaningsvyKontext();

    actingAs($anvandare)->post(utlaningsvyUrl($container, $item).'/loans', [
        'borrower_name' => 'Grannen',
        'lent_at' => '2026-09-01',
        'returned_at' => '2026-09-10',
    ])->assertRedirect();

    $loan = Loan::query()->firstOrFail();

    expect($loan->returned_at->toDateString())->toBe('2026-09-10');

    actingAs($anvandare)->get(utlaningsvyUrl($container, $item))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('openLoan', null)
            ->has('loanHistory', 1)
            ->where('loanHistory.0.ulid', $loan->ulid)
    );
});

it('ger ett fältfel när due_at ligger före lent_at', function () {
    withoutVite();

    [, $anvandare, $container, $item] = utlaningsvyKontext();

    $svar = actingAs($anvandare)->post(utlaningsvyUrl($container, $item).'/loans', [
        'borrower_name' => 'Grannen',
        'lent_at' => '2026-09-10',
        'due_at' => '2026-09-01',
    ]);

    // `after_or_equal:lent_at` ur StoreLoanRequest, oförändrad (Beslut 3).
    $svar->assertSessionHasErrors('due_at');
    expect(Loan::query()->count())->toBe(0);
});

it('gör en andra öppen utlåning till ett fältfel i stället för en JSON-kropp', function () {
    withoutVite();

    [, $anvandare, $container, $item] = utlaningsvyKontext();

    utlaningsvyLan($item, ['lent_at' => '2026-09-01']);

    $svar = actingAs($anvandare)->post(utlaningsvyUrl($container, $item).'/loans', [
        'borrower_name' => 'Grannen',
        'lent_at' => '2026-09-02',
    ]);

    // Spärren "högst en öppen utlåning" kastar ApiException, som svarar
    // `{"error":{…}}` var den än kastas — mitt i en webbsida hade användaren
    // fått rå JSON. Kontrollern översätter den till ett fältfel (Beslut 6).
    $svar->assertSessionHasErrors('borrower_name');
    expect($svar->getContent())->not->toContain('"error"');

    $mening = session('errors')->get('borrower_name')[0];

    expect($mening)->toBe(Lang::get('ui.error.loan.already_open', [], 'sv'));
    expect(Loan::query()->count())->toBe(1);
});

// --- försenad: härledd på serverns datum --------------------------------

it('visar en öppen utlåning med passerat due_at som försenad', function () {
    withoutVite();

    [, $anvandare, $container, $item] = utlaningsvyKontext();

    // Dagens datum är serverns, och raden byggs ur samma klocka: en gårdag är
    // försenad, i dag och i morgon är det inte.
    $forfallen = utlaningsvyLan($item, [
        'lent_at' => '2026-01-01',
        'due_at' => Carbon::today()->subDay()->toDateString(),
    ]);

    $url = utlaningsvyUrl($container, $item);

    actingAs($anvandare)->get($url)->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('openLoanOverdue', true)
    );

    $forfallen->update(['due_at' => Carbon::today()]);

    actingAs($anvandare)->get($url)->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('openLoanOverdue', false)
    );

    $forfallen->update(['due_at' => Carbon::today()->addDay()->toDateString()]);

    actingAs($anvandare)->get($url)->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('openLoanOverdue', false)
    );

    // En öppen utlåning utan `due_at` är inte försenad: ingen har sagt när den
    // skulle tillbaka.
    $forfallen->update(['due_at' => null]);

    actingAs($anvandare)->get($url)->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('openLoanOverdue', false)
    );
});

it('räknar aldrig försenat på klientens klocka', function () {
    withoutVite();

    [, $anvandare, $container, $item] = utlaningsvyKontext();

    utlaningsvyLan($item, ['lent_at' => '2026-01-01', 'due_at' => Carbon::today()->subDay()->toDateString()]);

    // Flaggan kommer ur svaret (Beslut 5).
    actingAs($anvandare)->get(utlaningsvyUrl($container, $item))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('openLoanOverdue', true)
    );

    // Och vyn har ingen egen klocka: den läser flaggan och bygger aldrig ett
    // datum för att jämföra. Samma regel som `overdue` i 63b § Beslut 3.
    $vy = utlaningsvyKomponent();

    expect($vy)->toContain('openLoanOverdue');
    expect($vy)->not->toContain('Date.now');
    expect($vy)->not->toContain('new Date');
});

// --- återlämningen -------------------------------------------------------

it('sätter returned_at till dagens datum och flyttar raden till historiken', function () {
    withoutVite();

    [, $anvandare, $container, $item] = utlaningsvyKontext();

    $loan = utlaningsvyLan($item, ['lent_at' => '2026-01-01']);

    $url = utlaningsvyUrl($container, $item);

    // Knappen skickar serverns eget datum, ur `today`-propen — inte
    // webbläsarens klocka.
    actingAs($anvandare)->get($url)->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('today', Carbon::today()->toDateString())
    );

    $svar = actingAs($anvandare)->patch($url."/loans/{$loan->ulid}", [
        'returned_at' => Carbon::today()->toDateString(),
    ]);

    $svar->assertRedirect($url);
    $svar->assertSessionHas('status', 'loan-updated');

    expect($loan->fresh()->returned_at->toDateString())->toBe(Carbon::today()->toDateString());

    actingAs($anvandare)->get($url)->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('openLoan', null)
            ->has('loanHistory', 1)
            ->where('loanHistory.0.ulid', $loan->ulid)
    );
});

it('tar ett eget återlämningsdatum och validerar det mot lent_at', function () {
    withoutVite();

    [, $anvandare, $container, $item] = utlaningsvyKontext();

    $loan = utlaningsvyLan($item, ['lent_at' => '2026-09-10']);

    $url = utlaningsvyUrl($container, $item)."/loans/{$loan->ulid}";

    // `after_or_equal:lent_at` gäller också den här vägen (Beslut 3): ett
    // datum före utlåningen är ett fältfel och ingenting skrivs.
    $fel = actingAs($anvandare)->patch($url, ['returned_at' => '2026-09-01']);

    $fel->assertSessionHasErrors('returned_at');
    expect($loan->fresh()->returned_at)->toBeNull();

    actingAs($anvandare)->patch($url, ['returned_at' => '2026-09-12'])->assertRedirect();

    // Bara `returned_at` skrivs tillbaka — resten av raden står orörd.
    $loan->refresh();

    expect($loan->returned_at->toDateString())->toBe('2026-09-12');
    expect($loan->borrower_name)->toBe('Grannen');
    expect($loan->lent_at->toDateString())->toBe('2026-09-10');
});

// --- adressen: en kontaktuppgift, aldrig en mottagare --------------------

it('visar låntagarens adress som text utan mailto och utan påminnelseknapp', function () {
    withoutVite();

    [, $anvandare, $container, $item] = utlaningsvyKontext();

    utlaningsvyLan($item, ['borrower_email' => 'granne@example.com']);

    actingAs($anvandare)->get(utlaningsvyUrl($container, $item))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('openLoan.borrower_email', 'granne@example.com')
    );

    // Adressen ritas som text, och kopieringen går till urklipp.
    $vy = utlaningsvyKomponent();

    expect($vy)->toContain('{{ openLoan.borrower_email }}');
    expect($vy)->toContain('navigator.clipboard');

    // Ingen utskicksväg ut ur ytan: ingen `mailto:`-länk som förifyller ett
    // meddelande från produkten, och ingen knapp som påminner låntagaren
    // (Beslut 4, [[ADR-0017 Missbruksvektorer]] § 7).
    expect($vy)->not->toContain('mailto:');
    expect(strtolower($vy))->not->toContain('remind');
});

it('förklarar att systemet aldrig mejlar låntagaren', function () {
    withoutVite();

    [, $anvandare, $container, $item] = utlaningsvyKontext();

    utlaningsvyLan($item, ['borrower_email' => 'granne@example.com']);

    // Meningen står på båda språken och säger regeln: adressen används aldrig
    // för utskick, och påminnelsen går till den som lånat ut.
    $svensk = (string) Lang::get('ui.item.loan.email_note', [], 'sv');

    expect($svensk)->toContain('aldrig för utskick');
    expect($svensk)->toContain('mejlar inte låntagaren');
    expect($svensk)->toContain('påminnelsen går till dig');

    $engelsk = (string) Lang::get('ui.item.loan.email_note', [], 'en');

    expect($engelsk)->toContain('never used for mailings');
    expect($engelsk)->toContain('does not email the borrower');

    // Och formulärets adressfält bär raden, så ett e-postfält utan förklaring
    // aldrig ritas (Beslut 4).
    $vy = utlaningsvyKomponent();

    expect($vy)->toContain("t('item.loan.email_note')");

    // Ingen notis och ingen leverans rörs av ytan: utlåningspåminnelsen är en
    // notisgenerator i M5 och en plangräns, och proparna bär den inte.
    actingAs($anvandare)->get(utlaningsvyUrl($container, $item))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->missing('notifications')
            ->missing('reminders')
    );
});

// --- raderingen ----------------------------------------------------------

it('tar bort en utlåningsrad och skiljer bekräftelsen från återlämning', function () {
    withoutVite();

    [, $anvandare, $container, $item] = utlaningsvyKontext();

    $loan = utlaningsvyLan($item);

    $url = utlaningsvyUrl($container, $item);

    $svar = actingAs($anvandare)->delete($url."/loans/{$loan->ulid}");

    $svar->assertRedirect($url);
    $svar->assertSessionHas('status', 'loan-deleted');

    // Mjuk radering, och raden hamnar INTE i papperskorgen — den listar fyra
    // typer och behåller fyra (issue 76 § Beslut 3). Meningen lovar därför
    // ingen återställning.
    expect($loan->fresh()->deleted_at)->not->toBeNull();
    expect(Loan::query()->count())->toBe(0);
    expect(Loan::query()->onlyTrashed()->count())->toBe(1);

    actingAs($anvandare)->get($url)->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('openLoan', null)
            ->has('loanHistory', 0)
    );

    // Och en redan raderad rad går inte att radera en gång till: bindningen ser
    // bara levande rader.
    actingAs($anvandare)->delete($url."/loans/{$loan->ulid}")->assertNotFound();

    // Bekräftelsen säger BÅDA sakerna (Beslut 7): raden försvinner, och det är
    // inte en återlämning — prylen är fortfarande utlånad. Återlämningen har
    // ingen bekräftelse alls; den är en knapp.
    $bekraftelse = (string) Lang::get('ui.item.loan.destroy_confirm', [], 'sv');

    expect($bekraftelse)->toContain('inte en återlämning');
    expect($bekraftelse)->toContain('fortfarande utlånad');

    expect(utlaningsvyKomponent())->toContain("window.confirm(t('item.loan.destroy_confirm'))");
});

it('ger 404 för ett lån på ett annat item', function () {
    withoutVite();

    [, $anvandare, $container, $item] = utlaningsvyKontext();

    $annat = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $container->account_id,
    ]);

    $frammande = utlaningsvyLan($annat);

    // `{loan}` binds genom App\Models\Item::loans() via scopeBindings(), så en
    // ULID från ett annat item löser aldrig upp (Beslut 1).
    actingAs($anvandare)
        ->delete(utlaningsvyUrl($container, $item)."/loans/{$frammande->ulid}")
        ->assertNotFound();

    actingAs($anvandare)
        ->patch(utlaningsvyUrl($container, $item)."/loans/{$frammande->ulid}", ['returned_at' => '2026-09-05'])
        ->assertNotFound();

    expect($frammande->fresh()->deleted_at)->toBeNull();
    expect($frammande->fresh()->returned_at)->toBeNull();
});

// --- grindarna: itemets pinnar, en per handling -------------------------

it('visar utlåningen för en read-mottagare men ingen skrivyta, och nekar posten', function () {
    withoutVite();

    [, , $container, $item] = utlaningsvyKontext();

    utlaningsvyLan($item, ['borrower_email' => 'granne@example.com']);

    [$lasare] = utlaningsvyMottagare($container, $item, 'read');

    $svar = actingAs($lasare)->post(utlaningsvyUrl($container, $item).'/loans', [
        'borrower_name' => 'Någon',
        'lent_at' => '2026-09-01',
    ]);

    $svar->assertForbidden();
    expect(Loan::query()->count())->toBe(1);

    // Läsning räcker för att SE sektionen (grinden är `view` på itemet) men
    // inte för att skriva: `create`, `update` och `delete` är egna pinnar
    // (Beslut 6).
    actingAs($lasare)->get(utlaningsvyUrl($container, $item))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('openLoan')
            ->has('loanHistory', 0)
            ->where('can.create', false)
            ->where('can.update', false)
            ->where('can.delete', false)
    );

    // Grinden är presentation i vyn och auktorisering i kontrollern: en
    // användare som inte får skriva ser ingen skrivyta, och en yta som inte
    // ritas prövas inte heller i webbläsaren.
    $vy = utlaningsvyKomponent();

    expect($vy)->toContain('v-if="can.create && !openLoan"');
    expect($vy)->toContain('v-if="can.update"');
    expect($vy)->toContain('v-if="can.delete"');
});

it('låter en create-mottagare låna ut men inte radera raden', function () {
    withoutVite();

    [, , $container, $item] = utlaningsvyKontext();

    [$skapare] = utlaningsvyMottagare($container, $item, 'create');

    actingAs($skapare)->post(utlaningsvyUrl($container, $item).'/loans', [
        'borrower_name' => 'Grannen',
        'lent_at' => '2026-09-01',
    ])->assertRedirect();

    $loan = Loan::query()->firstOrFail();

    // `create` lägger till, men rör inte det som redan står där: `update` är en
    // pinne högre, och `delete` en till.
    actingAs($skapare)
        ->patch(utlaningsvyUrl($container, $item)."/loans/{$loan->ulid}", ['returned_at' => '2026-09-02'])
        ->assertForbidden();

    actingAs($skapare)
        ->delete(utlaningsvyUrl($container, $item)."/loans/{$loan->ulid}")
        ->assertForbidden();

    expect($loan->fresh()->returned_at)->toBeNull();
    expect($loan->fresh()->deleted_at)->toBeNull();
});

it('låter en write-mottagare registrera återlämning men inte radera raden', function () {
    withoutVite();

    [, , $container, $item] = utlaningsvyKontext();

    $loan = utlaningsvyLan($item, ['lent_at' => '2026-09-01']);

    [$skrivare] = utlaningsvyMottagare($container, $item, 'write');

    actingAs($skrivare)
        ->patch(utlaningsvyUrl($container, $item)."/loans/{$loan->ulid}", ['returned_at' => '2026-09-05'])
        ->assertRedirect();

    expect($loan->fresh()->returned_at->toDateString())->toBe('2026-09-05');

    // `write` ändrar ett lån men tar inte bort det (Beslut 6).
    actingAs($skrivare)
        ->delete(utlaningsvyUrl($container, $item)."/loans/{$loan->ulid}")
        ->assertForbidden();

    expect($loan->fresh()->deleted_at)->toBeNull();

    // Och flaggan som ritar knapparna är samma grind.
    actingAs($skrivare)->get(utlaningsvyUrl($container, $item))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('can.update', true)
            ->where('can.delete', false)
    );
});

// --- /api är oförändrat -------------------------------------------------

it('lämnar /api:s fyra utlåningsrutter oförändrade', function () {
    [$konto, $user, $headers] = kontoMedMedlem();

    $container = Container::factory()->for($konto, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create();

    $url = "/api/containers/{$container->ulid}/items/{$item->ulid}/loans";

    $skapat = postJson($url, [
        'borrower_name' => 'Grannen',
        'lent_at' => '2026-09-01',
        'due_at' => '2026-09-15',
    ], $headers)->assertCreated();

    // Samma kropp som förut: resursens fält och ingenting mer.
    expect(array_keys($skapat->json('data')))->toBe([
        'ulid', 'borrower_name', 'borrower_email', 'lent_at', 'due_at', 'returned_at',
        'note', 'is_open', 'created_at', 'updated_at',
    ]);

    getJson($url, $headers)->assertOk()->assertJsonCount(1, 'data');

    // Domänfelet svarar fortfarande med felkoden i höljet på /api — den
    // översatta meningen hör till webben och till App\Support\Frontend\
    // ApiErrorTranslator, aldrig till /api ([[ADR-0013 Språk och i18n]]).
    postJson($url, ['borrower_name' => 'Någon', 'lent_at' => '2026-09-02'], $headers)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'loan.already_open');

    deleteJson($url.'/'.$skapat->json('data.ulid'), [], $headers)->assertNoContent();

    expect(Loan::query()->onlyTrashed()->count())->toBe(1);
});
