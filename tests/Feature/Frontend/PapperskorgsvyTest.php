<?php

// rott-pa-basen: issue 77b — ordbyte i prosa (kommentar och testnamn), ingen kodändring; bas och head delar applikationskod.

use App\Actions\Access\ResolveItemScope;
use App\Actions\Trash\ListTrash;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\from;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\withoutVite;

/*
 * Issue 62a · Containerns papperskorg: det mjukraderade innehållet, den
 * återstående tiden och återställningen. Se
 * App\Http\Controllers\TrashController,
 * App\Actions\Trash\ListTrash, App\Actions\Trash\FindTrashedInContainer,
 * App\Actions\Trash\RestoreContent, resources/js/pages/Containers/Trash.vue,
 * resources/js/components/TrashRow.vue och
 * resources/js/components/trashPresentation.js.
 *
 * **Grinden per rad prövas på två ställen med flit** (Beslut 3): `/api`:s
 * `authorizeRestore()` låses av tests/Feature/Trash/PapperskorgTest.php och
 * webbens kopia av den här filen. Att `/api` svarar bit för bit som förut
 * prövas av PapperskorgTest, som är grönt utan en enda ändrad förväntan
 * efter utbrytningen — en ny formulering av samma sak här hade bevisat noll.
 *
 * **Två acceptanskriterier prövas inte här**, därför att de redan har en
 * ägare: `/api`:s fyra frågor och bitvis identiska svar (PapperskorgTest)
 * och "ingen svensk sträng i en .vue-fil" (SprakTest § "har inga
 * användarvända strängar kvar i Vue-komponenterna"). Den här filen prövar i stället
 * att papperskorgens EGNA nycklar finns, och att flaggan följer samma grind
 * som rutten.
 *
 * Hjälparna har prefixet `papperskorgsvy` — Pest lägger alla testfiler i
 * samma namnrymd när hela sviten körs.
 */

/**
 * Ett konto med en ägare och en container.
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function papperskorgsvyKontext(): array
{
    $konto = Account::factory()->create();
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => 'owner']);
    $container = Container::factory()->for($konto, 'account')->create();

    return [$konto, $anvandare, $container];
}

/**
 * Mjukraderar en rad genom att sätta `deleted_at` — samma sluttillstånd som
 * raderingsrutterna (SoftDeletes) men med kontrollerad tidpunkt.
 * `mjukraderaPapperskorg()` i tests/Feature/Trash/PapperskorgTest.php gör
 * exakt samma sak; den ligger i en annan testfil och får inte sitt eget
 * namn lånat hit.
 */
function papperskorgsvyRaderad(Item|Attachment|Category|Tag $modell, ?Carbon $deletedAt = null): void
{
    $modell->deleted_at = $deletedAt ?? now();
    $modell->save();
}

/**
 * En åtkomst som når ETT item. `beviljaAccess()` i
 * tests/Support/Testhjalpare.php tar ingen `item_id`, och itemomfånget är
 * halva poängen med papperskorgen (issue 74 § Beslut 1).
 */
function papperskorgsvyAccess(Container $container, User $mottagare, string $level, ?Item $item = null): ContainerAccess
{
    return ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item?->id,
        'grantee_type' => 'user',
        'grantee_id' => $mottagare->id,
        'level' => $level,
        'kind' => 'member',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);
}

/*
 * Beslut 1: två rutter, båda bakom `auth`. En utloggad besökare skickas till
 * inloggningen och når aldrig en kontrollermetod.
 */
it('skickar en utloggad besökare till inloggningen från papperskorgsrutterna', function () {
    withoutVite();

    [, , $container] = papperskorgsvyKontext();

    get("/containers/{$container->ulid}/trash")->assertRedirect('/login');
    post("/containers/{$container->ulid}/trash/restore", ['type' => 'item', 'ulid' => '01JKX7Q3F8Z2N6M4B9T0R5V1WQ'])
        ->assertRedirect('/login');
});

/*
 * Klart när: papperskorgen listar mjukraderade items, bilagor, kategorier och
 * taggar, senast raderat först. Alla fyra typerna i EN lista (issue 20a
 * § Beslut 1), som på `/api`.
 */
it('listar alla fyra typerna med det senast raderade först', function () {
    withoutVite();

    [$konto, $ägare, $container] = papperskorgsvyKontext();

    $item = papperskorgsItem($container, $konto, $ägare, ['name' => 'Motorn']);
    papperskorgsvyRaderad($item, now()->subDays(4));

    $kategori = Category::factory()->for($container, 'container')->create(['name' => 'Elsystem']);
    papperskorgsvyRaderad($kategori, now()->subDays(3));

    $värd = papperskorgsItem($container, $konto, $ägare, ['name' => 'Växellådan']);
    $bilaga = papperskorgsBilaga($värd, $konto, $ägare, ['filename' => 'faktura.pdf']);
    papperskorgsvyRaderad($bilaga, now()->subDays(2));

    $tagg = Tag::factory()->for($container, 'container')->create(['name' => 'Vinter']);
    papperskorgsvyRaderad($tagg, now()->subDay());

    actingAs($ägare)
        ->get("/containers/{$container->ulid}/trash")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Containers/Trash')
            ->where('container.ulid', $container->ulid)
            ->has('entries', 4)
            ->where('entries.0.ulid', $tagg->ulid)
            ->where('entries.1.ulid', $bilaga->ulid)
            ->where('entries.2.ulid', $kategori->ulid)
            ->where('entries.3.ulid', $item->ulid)
        );
});

/*
 * Klart när: varje rad visar vad den är, sitt sammanhang (bilagans item,
 * underkategorins förälder), när den raderades och hur många dagar som
 * återstår. Raderna kommer ur TrashEntryResource, samma sex nycklar som
 * `/api` — vyn hittar inte på någon egen form.
 *
 * **Tiden är frusen, och det är kravet och inte en stilfråga.** `expires_at`
 * är `deleted_at` + retentionen: raderna raderas med ett `now()` och förväntan
 * räknas ur ett ANDRA `now()` — efter ett helt HTTP-anrop. Faller en
 * sekundgräns däremellan skiljer de sig på sekunden, och provet är rött utan
 * att något är fel (samma frysning som ContainerpapperskorgTest § "listar
 * raderade containers senast raderad först" och FiloriginTest § "länken lever
 * i femton minuter").
 */
it('bär vad raden är, sitt sammanhang och båda tiderna', function () {
    withoutVite();

    Carbon::setTestNow('2026-09-02 12:00:00');

    try {
        [$konto, $ägare, $container] = papperskorgsvyKontext();

        $värd = papperskorgsItem($container, $konto, $ägare, ['name' => 'Växellådan']);
        $bilaga = papperskorgsBilaga($värd, $konto, $ägare, ['filename' => 'faktura.pdf']);
        papperskorgsvyRaderad($bilaga, now()->subDay());

        $förälder = Category::factory()->for($container, 'container')->create(['name' => 'Elsystem']);
        $barn = Category::factory()->for($container, 'container')->create(['name' => 'Startmotor', 'parent_id' => $förälder->id]);
        papperskorgsvyRaderad($barn, now()->subDays(2));

        $retention = (int) config('files.trash_retention_days');

        actingAs($ägare)
            ->get("/containers/{$container->ulid}/trash")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('entries', 2)
                // Bilagan: filnamnet som label, itemets namn som sammanhang.
                ->where('entries.0.type', 'attachment')
                ->where('entries.0.label', 'faktura.pdf')
                ->where('entries.0.context', 'Växellådan')
                ->where('entries.0.expires_at', now()->subDay()->addDays($retention)->toIso8601String())
                // Underkategorin: sitt eget namn, förälderns namn som sammanhang —
                // också när föräldern själv ligger i papperskorgen.
                ->where('entries.1.type', 'category')
                ->where('entries.1.label', 'Startmotor')
                ->where('entries.1.context', 'Elsystem')
                ->where('entries.1.deleted_at', now()->subDays(2)->toIso8601String())
            );
    } finally {
        Carbon::setTestNow();
    }
});

/*
 * Beslut 4 och [[ADR-0008 Soft delete och papperskorg]]: raden visar den
 * ÅTERSTÅENDE tiden, inte bara raderingsdatumet. Valet av nyckel bor i
 * resources/js/components/trashPresentation.js, för `t()` har ingen
 * pluralisering (issue 52 § Beslut 4) och en mall går inte att pröva.
 *
 * Klart när: sista dygnet skrivs som "försvinner idag", och en dag kvar
 * skrivs i singular.
 */
it('väljer mening på den återstående tiden, i singular, plural och sista dygnet', function () {
    $modul = File::get(resource_path('js/components/trashPresentation.js'));

    expect($modul)->toContain("t('trash.expires.today')");
    expect($modul)->toContain("t('trash.expires.day')");
    expect($modul)->toContain("t('trash.expires.days', { days })");
    // Trösklarna: mindre än ett dygn är "idag" och aldrig "0 dagar", exakt
    // ett dygn är singular.
    expect($modul)->toContain('days < 1');
    expect($modul)->toContain('days === 1');

    $sv = require lang_path('en/ui.php');
    $en = require lang_path('en/ui.php');

    foreach (['today', 'day', 'days'] as $nyckel) {
        expect($sv['trash']['expires'][$nyckel])->not->toBe('');
        expect($en['trash']['expires'][$nyckel])->not->toBe('');
    }

    // "0 dagar" finns inte som mening någonstans.
    expect($sv['trash']['expires']['today'])->not->toContain('0');
    expect($sv['trash']['expires']['days'])->toContain(':days');

    // Raden visar BÅDA tiderna: raderingsdagen och den återstående tiden.
    $rad = File::get(resource_path('js/components/TrashRow.vue'));

    expect($rad)->toContain("t('trash.deleted_at'");
    expect($rad)->toContain('remainingLabel(t, entry.expires_at)');
});

/*
 * Klart när: innehåll som passerat retentionen listas inte, även om
 * gallringsjobbet inte hunnit köra — och ett utgånget innehåll går inte att
 * återställa (404, issue 20a § Beslut 5).
 */
it('listar inte innehåll som passerat retentionen', function () {
    withoutVite();

    Carbon::setTestNow('2026-09-02 12:00:00');

    try {
        [$konto, $ägare, $container] = papperskorgsvyKontext();

        $retention = (int) config('files.trash_retention_days');

        $utgånget = papperskorgsItem($container, $konto, $ägare, ['name' => 'Utgånget']);
        papperskorgsvyRaderad($utgånget, now()->subDays($retention + 1));

        $kvar = papperskorgsItem($container, $konto, $ägare, ['name' => 'Kvar']);
        papperskorgsvyRaderad($kvar, now()->subDays($retention - 1));

        actingAs($ägare)
            ->get("/containers/{$container->ulid}/trash")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('entries', 1)
                ->where('entries.0.ulid', $kvar->ulid)
            );
    } finally {
        Carbon::setTestNow();
    }
});

it('svarar 404 för ett utgånget innehåll i stället för att återställa det', function () {
    withoutVite();

    Carbon::setTestNow('2026-09-02 12:00:00');

    try {
        [$konto, $ägare, $container] = papperskorgsvyKontext();

        $retention = (int) config('files.trash_retention_days');
        $utgånget = papperskorgsItem($container, $konto, $ägare, ['name' => 'Utgånget']);
        papperskorgsvyRaderad($utgånget, now()->subDays($retention + 1));

        actingAs($ägare)
            ->post("/containers/{$container->ulid}/trash/restore", [
                'type' => 'item',
                'ulid' => $utgånget->ulid,
            ])
            ->assertNotFound();

        expect($utgånget->refresh()->deleted_at)->not->toBeNull();
    } finally {
        Carbon::setTestNow();
    }
});

/*
 * Klart när: en omfångsbegränsad mottagare ser bara sina items och deras
 * bilagor — aldrig en kategori och aldrig en tagg (issue 74 § Beslut 1).
 *
 * Skälet står i ListTrash: en raderad tagg som heter "Skilsmässa" är en
 * upplysning om containern, inte om itemet hon når.
 */
it('visar en omfångsbegränsad mottagare bara sina items och deras bilagor', function () {
    withoutVite();

    [$konto, $ägare, $container] = papperskorgsvyKontext();

    $mitt = papperskorgsItem($container, $konto, $ägare, ['name' => 'Min motor']);
    $minBilaga = papperskorgsBilaga($mitt, $konto, $ägare, ['filename' => 'min-faktura.pdf']);
    papperskorgsvyRaderad($mitt, now()->subDay());
    papperskorgsvyRaderad($minBilaga);

    $annans = papperskorgsItem($container, $konto, $ägare, ['name' => 'Den andres motor']);
    $annansBilaga = papperskorgsBilaga($annans, $konto, $ägare, ['filename' => 'hemlig-faktura.pdf']);
    papperskorgsvyRaderad($annans, now()->subDay());
    papperskorgsvyRaderad($annansBilaga);

    $kategori = Category::factory()->for($container, 'container')->create(['name' => 'Skilsmässa']);
    papperskorgsvyRaderad($kategori, now()->subDay());

    $tagg = Tag::factory()->for($container, 'container')->create(['name' => 'Hemligt']);
    papperskorgsvyRaderad($tagg, now()->subDay());

    $mottagare = User::factory()->create();
    papperskorgsvyAccess($container, $mottagare, 'read', $mitt);

    $svar = actingAs($mottagare)->get("/containers/{$container->ulid}/trash");

    $svar->assertOk();
    $svar->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Containers/Trash')
        ->has('entries', 2)
        ->where('entries.0.ulid', $minBilaga->ulid)
        ->where('entries.1.ulid', $mitt->ulid)
    );

    $innehall = $svar->getContent();

    expect($innehall)->not->toContain('Den andres motor');
    expect($innehall)->not->toContain('hemlig-faktura.pdf');
    expect($innehall)->not->toContain('Skilsmässa');
    expect($innehall)->not->toContain('Hemligt');
});

/*
 * Klart när: en tom papperskorg ger samma mening för mottagaren som för
 * ägaren, utan tal och utan antydan om dolda rader (Beslut 5, issue 74
 * § Beslut 1, issue 73 § Beslut 6).
 *
 * Beviset är att de två svaren bär EXAKT samma props: hade den ena burit ett
 * tal om hur många rader som dolts, eller en annan mening, hade det synts
 * här. Att båda renderar samma komponent med samma nyckel prövas i
 * resources/js/pages/Containers/Trash.vue — EN v-if, EN mening.
 */
it('ger en tom papperskorg samma svar för mottagaren som för ägaren', function () {
    withoutVite();

    [$konto, $ägare, $container] = papperskorgsvyKontext();

    // Mottagaren når ett item — men inget av det som raderats.
    $hennes = papperskorgsItem($container, $konto, $ägare, ['name' => 'Hennes motor']);
    $mottagare = User::factory()->create();
    papperskorgsvyAccess($container, $mottagare, 'read', $hennes);

    $raderat = papperskorgsItem($container, $konto, $ägare, ['name' => 'Något raderat']);
    papperskorgsvyRaderad($raderat);
    $kategori = Category::factory()->for($container, 'container')->create(['name' => 'Skilsmässa']);
    papperskorgsvyRaderad($kategori);

    // Ägarens andra container, där ingenting alls raderats.
    $tomContainer = Container::factory()->for($konto, 'account')->create();

    $ägarsvar = actingAs($ägare)->get("/containers/{$tomContainer->ulid}/trash");
    $mottagarsvar = actingAs($mottagare)->get("/containers/{$container->ulid}/trash");

    foreach ([$ägarsvar, $mottagarsvar] as $svar) {
        $svar->assertOk();
        $svar->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Containers/Trash')
            ->where('entries', [])
            ->where('canRestore', [])
        );
    }

    expect($mottagarsvar->getContent())->not->toContain('Något raderat');
    expect($mottagarsvar->getContent())->not->toContain('Skilsmässa');

    // Samma props, i samma ordning: ingenting i svaret säger att något
    // filtrerats bort.
    expect(array_keys($mottagarsvar->viewData('page')['props']))
        ->toBe(array_keys($ägarsvar->viewData('page')['props']));

    $vy = File::get(resource_path('js/pages/Containers/Trash.vue'));

    expect(substr_count($vy, "t('trash.empty')"))->toBe(1);
    expect($vy)->toContain('entries.length > 0');
});

/*
 * Klart när: ett item återställs ur vyn och dyker upp i containerns itemlista
 * igen, med flashkoden `trash-restored` (Beslut 7).
 */
it('återställer ett item ur vyn och visar det i containerns itemlista igen', function () {
    withoutVite();

    [$konto, $ägare, $container] = papperskorgsvyKontext();

    $raderat = papperskorgsItem($container, $konto, $ägare, ['name' => 'Motorn']);
    papperskorgsvyRaderad($raderat);

    $url = "/containers/{$container->ulid}/trash";

    from($url)
        ->actingAs($ägare)
        ->post("{$url}/restore", ['type' => 'item', 'ulid' => $raderat->ulid])
        ->assertRedirect($url)
        ->assertSessionHas('status', 'trash-restored');

    expect($raderat->refresh()->deleted_at)->toBeNull();

    actingAs($ägare)
        ->get("/containers/{$container->ulid}/items")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('items', fn ($items) => collect($items)->pluck('ulid')->contains($raderat->ulid))
        );
});

/*
 * Klart när: en bilaga återställs och syns på sitt item igen. Bilagans
 * återställning går genom RestoreContent, som ökar kontots förbrukning i
 * samma transaktion (issue 26a) — den delen rörs inte här.
 */
it('återställer en bilaga och visar den på sitt item igen', function () {
    withoutVite();

    [$konto, $ägare, $container] = papperskorgsvyKontext();

    $item = papperskorgsItem($container, $konto, $ägare, ['name' => 'Motorn']);
    $bilaga = papperskorgsBilaga($item, $konto, $ägare, ['filename' => 'faktura.pdf']);
    papperskorgsvyRaderad($bilaga);

    $url = "/containers/{$container->ulid}/trash";

    from($url)
        ->actingAs($ägare)
        ->post("{$url}/restore", ['type' => 'attachment', 'ulid' => $bilaga->ulid])
        ->assertRedirect($url)
        ->assertSessionHas('status', 'trash-restored');

    expect($bilaga->refresh()->deleted_at)->toBeNull();

    actingAs($ägare)
        ->get("/containers/{$container->ulid}/items/{$item->ulid}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('attachments', fn ($attachments) => collect($attachments)->pluck('ulid')->contains($bilaga->ulid))
        );
});

/*
 * Klart när: en bilaga vars item ligger kvar i papperskorgen ger meningen om
 * föräldern som formulärfel, ingen JSON-kropp och ingen återställning
 * (Beslut 7, issue 20a § Beslut 8).
 *
 * App\Exceptions\Api\ApiException implementerar `Responsable` och svarar
 * `{"error":{"code":…}}` var den än kastas — också mitt i en Inertia-sida.
 * Fångsten i TrashController::restore() är det enda som hindrar den från att
 * nå webbläsaren, och App\Support\Frontend\ApiErrorTranslator är det enda som
 * gör koden till en mening.
 */
it('ger ett formulärfel i stället för JSON när föräldern ligger kvar i papperskorgen', function () {
    withoutVite();

    [$konto, $ägare, $container] = papperskorgsvyKontext();

    $item = papperskorgsItem($container, $konto, $ägare, ['name' => 'Motorn']);
    $bilaga = papperskorgsBilaga($item, $konto, $ägare, ['filename' => 'faktura.pdf']);
    papperskorgsvyRaderad($item);
    papperskorgsvyRaderad($bilaga);

    $url = "/containers/{$container->ulid}/trash";

    $svar = from($url)
        ->actingAs($ägare)
        ->post("{$url}/restore", ['type' => 'attachment', 'ulid' => $bilaga->ulid]);

    $svar->assertRedirect($url);
    $svar->assertSessionHasErrors('trash');

    // Meningen, inte koden — på något av de två språken.
    $sv = require lang_path('en/ui.php');
    $en = require lang_path('en/ui.php');

    expect([
        $sv['error']['trash']['parent_deleted'],
        $en['error']['trash']['parent_deleted'],
    ])->toContain(session('errors')->first('trash'));

    expect($svar->getContent())->not->toContain('error.code');

    // Ingen återställning: bilagan ligger kvar.
    expect($bilaga->refresh()->deleted_at)->not->toBeNull();
});

/*
 * Klart när: en `read`-deltagare ser listan men har ingen återställningsknapp
 * och får 403 om hon postar ändå (Beslut 3 och 6, issue 74 § Beslut 2).
 *
 * `read` varken raderar eller återställer ([[Konton och åtkomst]]
 * § Behörighetsregler regel 3).
 */
it('låter en read-deltagare se listan men inte återställa', function () {
    withoutVite();

    [$konto, $ägare, $container] = papperskorgsvyKontext();

    $raderat = papperskorgsItem($container, $konto, $ägare, ['name' => 'Motorn']);
    papperskorgsvyRaderad($raderat);

    $gäst = User::factory()->create();
    papperskorgsvyAccess($container, $gäst, 'read');

    $url = "/containers/{$container->ulid}/trash";

    actingAs($gäst)
        ->get($url)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('entries', 1)
            ->where('entries.0.ulid', $raderat->ulid)
            // Ingen knapp: flaggan är false, och vyn ritar den bara när den
            // är sann.
            ->where("canRestore.{$raderat->ulid}", false)
        );

    from($url)
        ->actingAs($gäst)
        ->post("{$url}/restore", ['type' => 'item', 'ulid' => $raderat->ulid])
        ->assertForbidden();

    expect($raderat->refresh()->deleted_at)->not->toBeNull();

    // Vyn ritar knappen ur flaggan och aldrig ur något annat.
    $rad = File::get(resource_path('js/components/TrashRow.vue'));

    expect($rad)->toContain('v-if="canRestore"');
});

/*
 * Klart när: en `write`-deltagare kan inte återställa. `write` ändrar det
 * som står där, men varken raderar eller återställer (regel 3), och
 * `ItemPolicy::delete()` kräver `delete` (issue 74 § Beslut 2).
 */
it('låter inte en write-deltagare återställa ett item', function () {
    withoutVite();

    [$konto, $ägare, $container] = papperskorgsvyKontext();

    $raderat = papperskorgsItem($container, $konto, $ägare, ['name' => 'Motorn']);
    papperskorgsvyRaderad($raderat);

    $deltagare = User::factory()->create();
    papperskorgsvyAccess($container, $deltagare, 'write');

    $url = "/containers/{$container->ulid}/trash";

    actingAs($deltagare)
        ->get($url)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where("canRestore.{$raderat->ulid}", false)
        );

    from($url)
        ->actingAs($deltagare)
        ->post("{$url}/restore", ['type' => 'item', 'ulid' => $raderat->ulid])
        ->assertForbidden();

    expect($raderat->refresh()->deleted_at)->not->toBeNull();
});

/*
 * Klart när: en omfångsbegränsad mottagare med `delete` på sitt item kan
 * återställa DET, och får 403 på en kategori.
 *
 * Det är hela poängen med issue 74 § Beslut 2: före det krävde grinden en
 * container-bred grant, och den som raderat sitt eget item kom inte åt att
 * ångra sig. Kategorin är fortfarande containervid och grindas mot
 * `ContainerPolicy::update()`.
 */
it('låter en omfångsbegränsad mottagare återställa sitt item men inte en kategori', function () {
    withoutVite();

    [$konto, $ägare, $container] = papperskorgsvyKontext();

    $mitt = papperskorgsItem($container, $konto, $ägare, ['name' => 'Min motor']);
    papperskorgsvyRaderad($mitt);

    $kategori = Category::factory()->for($container, 'container')->create(['name' => 'Elsystem']);
    papperskorgsvyRaderad($kategori);

    $mottagare = User::factory()->create();
    papperskorgsvyAccess($container, $mottagare, 'delete', $mitt);

    $url = "/containers/{$container->ulid}/trash";

    actingAs($mottagare)
        ->get($url)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('entries', 1)
            ->where('entries.0.ulid', $mitt->ulid)
            ->where("canRestore.{$mitt->ulid}", true)
        );

    from($url)
        ->actingAs($mottagare)
        ->post("{$url}/restore", ['type' => 'item', 'ulid' => $mitt->ulid])
        ->assertRedirect($url)
        ->assertSessionHas('status', 'trash-restored');

    expect($mitt->refresh()->deleted_at)->toBeNull();

    // Kategorin har hon ingen behörighet till — och ser den inte ens.
    from($url)
        ->actingAs($mottagare)
        ->post("{$url}/restore", ['type' => 'category', 'ulid' => $kategori->ulid])
        ->assertForbidden();

    expect($kategori->refresh()->deleted_at)->not->toBeNull();
});

/*
 * Klart när: en ULID ur en annan container är 422, ett utgånget innehåll 404.
 *
 * Den delade RestoreRequest ger 422 `validation.failed` på `/api`
 * (PapperskorgTest § "en ulid ur en annan container avvisas"). På webben är
 * samma svar ett fältfel på `ulid` och en omdirigering tillbaka — webben
 * behåller Laravels vanliga valideringsfel och har inget felkodshölje
 * ([[ADR-0020 Plattformsidentitet och frontendgräns]] § Konsekvenser).
 */
it('avvisar en ulid ur en annan container som ett valideringsfel', function () {
    withoutVite();

    [$konto, $ägare, $container] = papperskorgsvyKontext();

    $annatKonto = Account::factory()->create();
    $annanContainer = Container::factory()->for($annatKonto, 'account')->create();
    $deras = papperskorgsItem($annanContainer, $annatKonto, User::factory()->create(), ['name' => 'Deras']);
    papperskorgsvyRaderad($deras);

    $url = "/containers/{$container->ulid}/trash";

    from($url)
        ->actingAs($ägare)
        ->post("{$url}/restore", ['type' => 'item', 'ulid' => $deras->ulid])
        ->assertSessionHasErrors('ulid');

    expect($deras->refresh()->deleted_at)->not->toBeNull();
});

/*
 * Klart när: papperskorgen syns i containerns navigering (Beslut 1). Raden
 * ligger SIST — papperskorgen är dit man går när något gått fel, inte en yta
 * man arbetar i. Texten kommer ur `container.nav.trash`, aldrig ur en
 * sträng i JavaScript.
 */
it('lägger papperskorgen i containerns navigation, sist', function () {
    $sektioner = File::get(resource_path('js/layouts/containerSections.js'));

    expect($sektioner)->toContain("key: 'trash'");
    expect($sektioner)->toContain('/containers/${ulid}/trash');
    expect(strpos($sektioner, "key: 'trash'"))->toBeGreaterThan(strpos($sektioner, "key: 'settings'"));

    $en = require lang_path('en/ui.php');

    expect($en['container']['nav']['trash'])->not->toBe('');

    // Nycklarna papperskorgen äger. Att ingen av dem är tom prövas dessutom av
    // SprakTest § "har inga tomma strängar i ui.php".
    foreach (['title', 'heading', 'description', 'empty', 'deleted_at', 'restore'] as $nyckel) {
        expect($en['trash'][$nyckel])->not->toBe('');
    }

    foreach (['item', 'attachment', 'category', 'tag'] as $typ) {
        expect($en['trash']['type'][$typ])->not->toBe('');
    }
});

/*
 * Klart när: listan kostar fyra frågor oavsett antal rader, och
 * `can_restore`-flaggorna kostar noll extra.
 *
 * Det första prövas mot ListTrash själv, med omfånget redan upplöst:
 * ResolveItemScope är memoiserad per `{user}:{container}` och löses upp EN
 * gång per request — de tre-fyra frågorna den kostar hör till omfånget och
 * inte till listan.
 *
 * Det andra prövas mot sidan: flaggorna räknas ur rader som redan ligger i
 * minnet, och antalet frågor växer inte när antalet rader gör det. De kostar
 * två CONSTANTA frågor och aldrig en per rad — ett `exists()` för den
 * containervida grinden (`ContainerPolicy::update()` memoiserar inte) och en
 * hämtning av bilagornas items. Se § Frågor och antaganden i PR:en: "noll
 * extra" håller per rad, inte för sidan.
 */
it('gör fyra frågor för listan', function () {
    [$konto, $ägare, $container] = papperskorgsvyKontext();

    foreach (range(1, 3) as $i) {
        papperskorgsvyRaderad(papperskorgsItem($container, $konto, $ägare, ['name' => "Item $i"]));
    }

    papperskorgsvyRaderad(Category::factory()->for($container, 'container')->create());
    papperskorgsvyRaderad(Tag::factory()->for($container, 'container')->create());
    papperskorgsvyRaderad(papperskorgsBilaga(
        papperskorgsItem($container, $konto, $ägare, ['name' => 'Värd']),
        $konto,
        $ägare,
    ));

    // Omfånget först, så att memon sitter — exakt som i en request, där
    // ListTrash löser upp det innan de fyra typ-frågorna ställs.
    app(ResolveItemScope::class)->handle($ägare, $container);

    DB::enableQueryLog();
    $lista = app(ListTrash::class)->handle($ägare, $container);
    $antal = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($lista['entries'])->toHaveCount(6);
    expect($antal)->toBe(4);
});

it('gör ett konstant antal frågor på sidan, oavsett antal rader', function () {
    withoutVite();

    [$konto, $ägare, $container] = papperskorgsvyKontext();

    papperskorgsvyRaderad(papperskorgsItem($container, $konto, $ägare, ['name' => 'Motorn']));
    papperskorgsvyRaderad(Category::factory()->for($container, 'container')->create());
    papperskorgsvyRaderad(papperskorgsBilaga(
        papperskorgsItem($container, $konto, $ägare, ['name' => 'Värd']),
        $konto,
        $ägare,
    ));

    $url = "/containers/{$container->ulid}/trash";

    actingAs($ägare);

    // En uppvärmningsrequest först: den inloggade användaren ligger kvar i
    // minnet mellan anropen i samma test, så den första mätningen hade
    // annars betalat för laddningar den andra får gratis.
    get($url)->assertOk();

    DB::enableQueryLog();
    DB::flushQueryLog();
    get($url)->assertOk();
    $faRader = count(DB::getQueryLog());

    DB::flushQueryLog();

    foreach (range(1, 10) as $i) {
        papperskorgsvyRaderad(papperskorgsItem($container, $konto, $ägare, ['name' => "Extra $i"]));
    }

    // Fler containervida rader OCH fler bilagor: båda de konstanta frågorna
    // (containergrinden och bilagornas items) ska vara desamma.
    foreach (range(1, 3) as $i) {
        papperskorgsvyRaderad(Category::factory()->for($container, 'container')->create());
        papperskorgsvyRaderad(papperskorgsBilaga(
            papperskorgsItem($container, $konto, $ägare, ['name' => "Värd $i"]),
            $konto,
            $ägare,
        ));
    }

    DB::flushQueryLog(); // även de nya radernas INSERT-frågor
    get($url)->assertOk();
    $flerRader = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($flerRader)->toBe($faRader);
});
