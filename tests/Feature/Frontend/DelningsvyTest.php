<?php

// rott-pa-basen: issue 77b — ordbyte i prosa (kommentar och testnamn), ingen kodändring; bas och head delar applikationskod.

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\from;
use function Pest\Laravel\get;
use function Pest\Laravel\patch;
use function Pest\Laravel\withoutVite;

/*
 * Issue 55a · Förvaltningen av det som redan är delat: deltagarlistan,
 * åtkomsterna och nivåerna. Se
 * App\Http\Controllers\ContainerSharingController,
 * App\Http\Controllers\ContainerAccessController,
 * App\Actions\Access\ListContainerAccesses/ListParticipants/RevokeContainerAccess/UpdateContainerAccess,
 * resources/js/pages/Containers/Sharing.vue och
 * resources/js/components/AccessLevelField.vue.
 *
 * Sedan issue 1250 är deltagarlistan och åtkomstlistan EN lista, *People with
 * access*: ägarkontot överst utan kontroller, sedan en rad per giltig åtkomst
 * med nivåväljaren som sparar direkt, gästens utgångsdatum och *Remove
 * access* bakom en bekräftelsedialog. Historiken står sist i ett `<details>`.
 * Servern är oförändrad — det är vyn och texterna som flyttade.
 *
 * Inbjudningarna — avsändarytan, mejlets landningssida och acceptflödet — är
 * 55b och prövas inte här.
 *
 * Den viktigaste gränsen i filen är den mellan sidans två publiker: en
 * `read`-guest ser listan, och `accesses`-propen är `null` för henne — inte en
 * tom lista, och inte en fylld som vyn låter bli att rendera. Ett test som bara
 * tittade på den renderade HTML:en hade missat precis det som gör skillnad
 * (Beslut 3). Utan `accesses` finns varken nivåväljare, utgångsdatum,
 * borttagning eller historik.
 *
 * Den andra är att `PATCH` och `DELETE` svarar som webben gör och inte som
 * `/api` gör (Beslut 9): en flash-kod och ett formulärfel, aldrig en
 * JSON-kropp.
 *
 * Att `/api/containers/{container}/accesses` och `/participants` svarar
 * exakt som förut prövas av tests/Feature/Container/**, som är grönt utan en
 * enda ändrad förväntan efter utbrytningen i Beslut 8. En ny formulering av
 * samma sak här hade bevisat noll.
 *
 * Hjälparna har prefixet `delnings` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Ett konto med en medlem och en container ägd av kontot.
 *
 * @param  array<string, mixed>  $kontoAttribut
 * @return array{0: Account, 1: User, 2: Container}
 */
function delningsKontext(array $kontoAttribut = []): array
{
    $konto = Account::factory()->create($kontoAttribut);
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => 'owner']);
    $container = Container::factory()->for($konto, 'account')->create();

    return [$konto, $anvandare, $container];
}

/**
 * En åtkomstrad, containerbred eller knuten till ett item. `beviljaAccess()`
 * i tests/Support/Testhjalpare.php tar ingen `item_id`, och itemomfånget är
 * hela poängen med halva den här sidan.
 *
 * `$createdAt` sätts uttryckligen där ett test prövar ordningen: listan
 * sorteras `created_at` fallande, och den har sekundupplösning.
 */
function delningsAccess(
    Container $container,
    User|Account $grantee,
    string $level,
    string $kind = 'member',
    ?Item $item = null,
    ?Carbon $expiresAt = null,
    ?Carbon $revokedAt = null,
    ?Carbon $createdAt = null,
): ContainerAccess {
    return ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item?->id,
        'grantee_type' => $grantee instanceof User ? 'user' : 'account',
        'grantee_id' => $grantee->id,
        'level' => $level,
        'kind' => $kind,
        'expires_at' => $expiresAt,
        'revoked_at' => $revokedAt,
        'granted_by_user_id' => User::factory()->create()->id,
        'created_at' => $createdAt ?? now(),
    ]);
}

/*
 * Beslut 1: tre rutter, alla bakom `auth`. En utloggad besökare skickas till
 * inloggningen och når aldrig en kontrollermetod.
 */
it('skickar en utloggad besökare till inloggningen från delningsrutterna', function () {
    withoutVite();

    [, , $container] = delningsKontext();
    $access = delningsAccess($container, User::factory()->create(), 'read');

    get("/containers/{$container->ulid}/sharing")->assertRedirect('/login');
    patch("/containers/{$container->ulid}/accesses/{$access->ulid}", ['level' => 'write'])->assertRedirect('/login');
    delete("/containers/{$container->ulid}/accesses/{$access->ulid}")->assertRedirect('/login');
});

/*
 * Klart när: sidan renderar för en medlem i ägarkontot, med den enda listan
 * och med kontrollerna. `participants` och `accesses` är kvar som propar —
 * servern är oförändrad — men vyn ritar dem som EN lista sedan issue 1250.
 */
it('renderar delningssidan med den enda listan för en medlem i ägarkontot', function () {
    withoutVite();

    [$konto, $anvandare, $container] = delningsKontext();
    $mottagare = User::factory()->create(['name' => 'Sambon']);
    delningsAccess($container, $mottagare, 'write');

    actingAs($anvandare)
        ->get("/containers/{$container->ulid}/sharing")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Containers/Sharing')
            ->where('container.ulid', $container->ulid)
            ->where('container.account', $konto->ulid)
            ->has('participants', 2)
            ->has('accesses', 1)
            ->where('can.manage', true)
            ->where('can.revoke', true)
        );
});

/*
 * Beslut 3, och det viktigaste testet i filen: en `read`-guest NÅR sidan och
 * ser deltagarna — men `accesses`-propen är `null`. Inte en tom lista (som
 * hade sagt "det finns inga åtkomster"), och inte en fylld lista som vyn
 * låter bli att rendera (en prop i HTML:en är utlämnad oavsett vad Vue gör).
 */
it('ger en read-guest deltagarlistan men skickar inte åtkomsterna alls', function () {
    withoutVite();

    [, , $container] = delningsKontext();
    $gast = User::factory()->create(['name' => 'Charterkunden']);
    delningsAccess($container, $gast, 'read', 'guest');

    actingAs($gast)
        ->get("/containers/{$container->ulid}/sharing")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Containers/Sharing')
            ->where('accesses', null)
            ->where('itemNames', [])
            // Namnuppslagen är förvaltningsdata om åtkomsterna och följer
            // dem: ingen av dem bär ett namn när `accesses` är `null`.
            ->where('granteeNames', [])
            ->where('grantedByNames', [])
            ->has('participants', 2)
            // Grinden för sidan är `view`, och den har hon. Det är
            // `viewAccesses` — medlemskap i ägarkontot — som saknas.
            ->where('can.manage', false)
            ->where('can.revoke', false)
        );
});

/*
 * Klart när: ägarkontot först med rollen `owner`, sedan en post per giltig
 * åtkomst. Ordningen är ListParticipants' (issue 9c § Beslut 7) och rörs inte
 * av utbrytningen.
 */
it('visar ägarkontot först och en post per giltig åtkomst', function () {
    withoutVite();

    [$konto, $anvandare, $container] = delningsKontext();
    $mottagare = User::factory()->create(['name' => 'Sambon']);
    delningsAccess($container, $mottagare, 'write');

    actingAs($anvandare)->get("/containers/{$container->ulid}/sharing")->assertInertia(fn (AssertableInertia $page) => $page
        ->where('participants.0.role', 'owner')
        ->where('participants.0.type', 'account')
        ->where('participants.0.ulid', $konto->ulid)
        ->where('participants.0.name', $konto->name)
        ->where('participants.1.role', 'member')
        ->where('participants.1.type', 'user')
        ->where('participants.1.name', 'Sambon')
    );
});

/*
 * Klart när: en mottagare med fyra itemåtkomster är EN post i deltagarlistan
 * (issue 72 § Beslut 9, som utbrytningen ärver oförändrat).
 */
it('räknar en mottagare med fyra itemåtkomster som en deltagare', function () {
    withoutVite();

    [, $anvandare, $container] = delningsKontext();
    $mottagare = User::factory()->create();

    foreach (range(1, 4) as $i) {
        delningsAccess(
            $container,
            $mottagare,
            'read',
            item: Item::factory()->create(['container_id' => $container->id]),
            createdAt: now()->subMinutes(10 - $i),
        );
    }

    actingAs($anvandare)->get("/containers/{$container->ulid}/sharing")->assertInertia(fn (AssertableInertia $page) => $page
        ->has('participants', 2)
        ->where('participants.1.ulid', $mottagare->ulid)

        // Raderna finns kvar i förvaltningsvyn — det är deltagarlistan som
        // grupperar, inte åtkomstlistan.
        ->has('accesses', 4)
    );
});

/*
 * Klart när: ingen e-postadress förekommer någonstans i deltagarlistans HTML.
 * Adressen tillhör en MOTTAGARE och inte den inloggade — den inloggades egen
 * adress kommer med `auth.user` och är hennes egen.
 */
it('läcker aldrig en mottagares e-postadress till deltagarlistan', function () {
    withoutVite();

    [, $anvandare, $container] = delningsKontext();
    $mottagare = User::factory()->create(['email' => 'hemlig.adress@example.test']);
    delningsAccess($container, $mottagare, 'write');

    $svar = actingAs($anvandare)->get("/containers/{$container->ulid}/sharing");

    $svar->assertOk();
    expect($svar->getContent())->not->toContain('hemlig.adress@example.test');

    $svar->assertInertia(fn (AssertableInertia $page) => $page
        ->has('participants', 2)
        ->missing('participants.1.email')
        // Varken nivå, utgångsdatum, omfång eller vem som beviljade hör hit.
        ->missing('participants.1.level')
        ->missing('participants.1.expires_at')
        ->missing('participants.1.item')
        ->missing('participants.1.granted_by')
    );
});

/*
 * Klart när: en återkallad och en utgången åtkomst syns inte bland
 * deltagarna, men står under historiken i förvaltningsvyn. Historiken är
 * resten av raderna — vyn delar på `revoked_at` och `expires_at`, för
 * resursen bär ingen `status` (Beslut 7).
 */
it('håller återkallade och utgångna åtkomster borta från deltagarna men kvar i åtkomstlistan', function () {
    withoutVite();

    [, $anvandare, $container] = delningsKontext();
    $giltig = User::factory()->create(['name' => 'Sambon']);
    $aterkallad = User::factory()->create(['name' => 'Förre delägaren']);
    $utgangen = User::factory()->create(['name' => 'Charterkunden']);

    delningsAccess($container, $giltig, 'write', createdAt: now()->subMinutes(3));
    delningsAccess($container, $utgangen, 'read', 'guest', expiresAt: now()->subMinute(), createdAt: now()->subMinutes(2));
    delningsAccess($container, $aterkallad, 'read', revokedAt: now()->subDay(), createdAt: now()->subMinute());

    actingAs($anvandare)->get("/containers/{$container->ulid}/sharing")->assertInertia(fn (AssertableInertia $page) => $page
        // Ägarkontot och sambon. Ingen av de två döda raderna.
        ->has('participants', 2)
        ->where('participants.1.name', 'Sambon')

        // Alla tre raderna finns i förvaltningsvyn, med sina tidsstämplar —
        // uppdelningen i giltiga och historiska gör vyn.
        ->has('accesses', 3)
        ->where('accesses.0.revoked_at', fn ($value) => $value !== null)
        ->where('accesses.1.expires_at', fn ($value) => $value !== null)
        ->where('accesses.2.revoked_at', null)
        ->where('accesses.2.expires_at', null)
    );
});

/*
 * Klart när: en containerbred rad är "Hela containern" utan `reach`, och en
 * itemrad bär itemets ULID och `reach` (Beslut 6). `reach` räknas av
 * ResolveItemScope och rörs inte av den här issuen.
 */
it('skiljer en containerbred rad från en itemrad med omfång och reach', function () {
    withoutVite();

    [, $anvandare, $container] = delningsKontext();

    $motorn = Item::factory()->create(['container_id' => $container->id, 'name' => 'Motorn']);
    $impellern = Item::factory()->create(['container_id' => $container->id, 'name' => 'Impellern']);

    // Impellern är motorns barn: granten på motorn når två items.
    DB::table('item_link')->insert([
        'from_item_id' => $motorn->id,
        'to_item_id' => $impellern->id,
        'relation' => 'parent',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    delningsAccess($container, User::factory()->create(), 'read', createdAt: now()->subMinutes(2));
    delningsAccess($container, User::factory()->create(), 'write', item: $motorn, createdAt: now()->subMinute());

    actingAs($anvandare)->get("/containers/{$container->ulid}/sharing")->assertInertia(fn (AssertableInertia $page) => $page
        ->has('accesses', 2)

        // Nyast först: itemraden.
        ->where('accesses.0.item', $motorn->ulid)
        ->where('accesses.0.reach', 2)
        ->where('accesses.1.item', null)
        // Containerbred rad: `reach` är `null` med flit, och vyn visar det
        // aldrig — "Hela containern" behöver inget tal.
        ->where('accesses.1.reach', null)

        ->where('itemNames', [$motorn->ulid => 'Motorn'])
    );

    // Texten: "The whole container" för den breda raden, och itemets namn plus
    // talet för itemraden. Formuleringen bor i lang/, och valet av nyckel bor i
    // accessPresentation.js — EN gång, för både den giltiga och den
    // historiska listan.
    $en = require lang_path('en/ui.php');
    expect($en['sharing']['scope']['container'])->toBe('The whole container');
    expect($en['sharing']['scope']['item'])->toContain(':item')->toContain(':reach');

    $beskrivning = File::get(resource_path('js/components/accessPresentation.js'));
    expect($beskrivning)->toContain("t('sharing.scope.container')");
    expect($beskrivning)->toContain("t('sharing.scope.item'");
    expect($beskrivning)->toContain('access.item === null');
});

/*
 * Klart när: en itemrad vars item är mjukraderat visar itemets namn, inte
 * "Hela containern". Uppslagningen sker med `withTrashed()` (Beslut 6) — utan
 * den hade ULID:n fallit bort och raden lästs som en containerbred grant.
 */
it('redovisar ett mjukraderat item med sitt namn', function () {
    withoutVite();

    [, $anvandare, $container] = delningsKontext();

    $item = Item::factory()->create(['container_id' => $container->id, 'name' => 'Den sålda motorn']);
    delningsAccess($container, User::factory()->create(), 'read', item: $item);

    $item->delete();

    actingAs($anvandare)->get("/containers/{$container->ulid}/sharing")->assertInertia(fn (AssertableInertia $page) => $page
        ->where('accesses.0.item', $item->ulid)
        ->where('accesses.0.reach', 1)
        ->where('itemNames', [$item->ulid => 'Den sålda motorn'])
    );
});

/*
 * Arkitektsvaret § 1: mottagaren och beviljaren visas med NAMN, ingen ULID.
 * En rad som säger `Mottagare 01JKX7Q3F8Z2N6M4B9T0R5V1WQ` är oläsbar för den
 * enda publik sektionen har.
 *
 * Namnet bor i ett eget uppslag och INTE i ContainerAccessResource, av samma
 * skäl som itemnamnet (Beslut 6): `/api` har inte bett om det, och resursen är
 * delad. Resursen bär alltså kvar sin ULID — den är nyckeln uppslaget slås upp
 * på, precis som `access.item` är nyckeln för itemets namn.
 */
it('skickar mottagarens och beviljarens namn i egna uppslag', function () {
    withoutVite();

    [, $anvandare, $container] = delningsKontext();
    $mottagare = User::factory()->create(['name' => 'Sambon']);
    $kontomottagare = Account::factory()->create(['name' => 'Varvet']);

    $personrad = delningsAccess($container, $mottagare, 'write');
    $kontorad = delningsAccess($container, $kontomottagare, 'read', createdAt: now()->subMinute());

    // Samma beviljare på båda raderna: uppslaget är ett uppslag, och två
    // nycklar hade gjort jämförelsen nedan en ordningsfråga.
    $beviljare = User::factory()->create(['name' => 'Ägarens syster']);

    foreach ([$personrad, $kontorad] as $rad) {
        $rad->granted_by_user_id = $beviljare->id;
        $rad->save();
    }

    actingAs($anvandare)->get("/containers/{$container->ulid}/sharing")->assertInertia(fn (AssertableInertia $page) => $page
        ->has('accesses', 2)

        // Båda mottagarslagen löses upp: en `User` och ett `Account`.
        ->where('granteeNames', [
            $mottagare->ulid => 'Sambon',
            $kontomottagare->ulid => 'Varvet',
        ])

        // Beviljaren är alltid en `User`, och uppslaget är sitt eget — en
        // `granteeNames` som i smyg också bar beviljarna vore en prop som
        // ljuger om sitt namn.
        ->where('grantedByNames', [$beviljare->ulid => 'Ägarens syster'])

        // ULID:en finns kvar i raden som uppslagsnyckel, och resursen är
        // oförändrad — inget namn har smygits in i den.
        ->where('accesses.0.grantee', $mottagare->ulid)
        ->missing('accesses.0.grantee_name')
        ->missing('accesses.0.granted_by_name')
    );
});

/*
 * Klart när: en giltig åtkomstrad visar mottagarens namn. Raden renderar
 * etiketten ur accessPresentation.js och aldrig ULID:en själv —
 * `access.grantee` är uppslagsnyckeln, inte texten.
 *
 * Sedan issue 1250 bär raden inte längre beviljaren: listan svarar på VEM som
 * har åtkomst och till VAD, och *vem som beviljade* stod som en tredje rad i
 * småstil utan att någon frågade efter den. Uppslaget `grantedByNames` finns
 * kvar i svaret — det är kontraktet mellan kontroller och vy — men raden
 * läser det inte.
 */
it('renderar namn i stället för ULID på en giltig rad', function () {
    $rad = File::get(resource_path('js/components/ContainerAccessRow.vue'));
    $beskrivning = File::get(resource_path('js/components/accessPresentation.js'));

    expect($rad)->toContain('granteeLabel(t, granteeNames, access)');
    expect($rad)->toContain('accessScopeLabel(t, itemNames, access)');
    expect($rad)->not->toContain('{{ access.grantee }}');
    expect($rad)->not->toContain('{{ access.granted_by }}');

    expect($beskrivning)->toContain('export function granteeLabel');
    expect($beskrivning)->toContain('export function grantedByLabel');
    expect($beskrivning)->toContain("t('sharing.accesses.grantee_unknown')");
    expect($beskrivning)->toContain("t('sharing.accesses.granted_by_unknown')");
});

/*
 * Klart när: en historikrad visar mottagarens namn. Historiken är hela skälet
 * till att uppslaget inte får återanvända deltagarlistan: den bär bara
 * GILTIGA rader, och det är historikraderna och beviljarna som saknar namn.
 */
it('visar mottagarens namn också på en historikrad', function () {
    withoutVite();

    [, $anvandare, $container] = delningsKontext();
    $aterkallad = User::factory()->create(['name' => 'Förre delägaren']);

    delningsAccess($container, $aterkallad, 'read', revokedAt: now()->subDay());

    actingAs($anvandare)->get("/containers/{$container->ulid}/sharing")->assertInertia(fn (AssertableInertia $page) => $page
        ->has('accesses', 1)
        ->where('accesses.0.revoked_at', fn ($value) => $value !== null)

        // Uppslaget täcker HELA listan, också den döda raden.
        ->where('granteeNames', [$aterkallad->ulid => 'Förre delägaren'])
    );

    // Historikgrenen anropar samma etikett som den giltiga raden.
    $vy = File::get(resource_path('js/pages/Containers/Sharing.vue'));
    expect($vy)->toContain('granteeLabel(t, granteeNames, access)');
});

/*
 * Klart när: en mottagare vars användare är borta redovisas med en översatt
 * mening, inte med sin ULID.
 *
 * `grantee_id` har ingen främmandenyckel — den pekar polymorft på `User`
 * eller `Account`, se App\Models\ContainerAccess — och varken `User` eller
 * `Account` använder `SoftDeletes`, så en rad kan faktiskt peka på någon som
 * är borta. Det är inte den fallback `ParticipantResource` förbjuder: den
 * handlar om en `NOT NULL`-kolumn som aldrig är tom.
 *
 * Raden är ÅTERKALLAD, och det är inte en detalj: deltagarlistan läser
 * `$grantee->ulid` på samma rad och hade kraschat på en giltig rad med en
 * dito mottagare, medan den hoppar över döda rader. Historiken är alltså den
 * yta där en försvunnen mottagare faktiskt når fram till vyn — och just
 * historiken är den lista som annars inte visar någon identitet alls.
 */
it('redovisar en mottagare som inte längre finns med en mening', function () {
    withoutVite();

    [, $anvandare, $container] = delningsKontext();

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'grantee_type' => 'user',
        'grantee_id' => 99999999,
        'level' => 'read',
        'kind' => 'member',
        'revoked_at' => now()->subDay(),
        'granted_by_user_id' => $anvandare->id,
    ]);

    actingAs($anvandare)->get("/containers/{$container->ulid}/sharing")->assertInertia(fn (AssertableInertia $page) => $page
        ->has('accesses', 1)
        // Ingen ULID att slå upp, alltså ingen nyckel — vyn får sin mening.
        ->where('accesses.0.grantee', null)
        ->where('accesses.0.revoked_at', fn ($value) => $value !== null)
        ->where('granteeNames', [])
        ->where('grantedByNames', [$anvandare->ulid => $anvandare->name])
    );

    $en = require lang_path('en/ui.php');

    expect($en['sharing']['accesses']['grantee_unknown'])->toBe('Removed recipient');
    expect($en['sharing']['accesses']['granted_by_unknown'])->not->toBe('');
});

/*
 * Sidan ärver frågekostnaden från de två utbrutna Actionerna (issue 9b
 * § Beslut 11, issue 72 § Beslut 5, issue 9c § Beslut 8): deltagarna kostar
 * tre frågor och åtkomsterna två för ULID:erna plus två för omfånget,
 * oavsett hur många rader det finns. Namnuppslaget är EN fråga till. Inget av
 * det får växa med antalet rader — det är hela skälet till att listorna
 * hydreras i kontrollern och inte i resurserna.
 *
 * Testet mäter LIKHET och inte ett absolut tal, samma teknik som grannfilerna:
 * antalet frågor en webbsida kostar i övrigt (delade props, layout, grindar)
 * är inte den här issuens sak att låsa.
 */
it('gör ett konstant antal frågor oavsett antal rader', function () {
    withoutVite();

    [, $anvandare, $container] = delningsKontext();
    $item = Item::factory()->create(['container_id' => $container->id]);
    delningsAccess($container, User::factory()->create(), 'read', item: $item);
    delningsAccess($container, User::factory()->create(), 'write');

    actingAs($anvandare);

    // En uppvärmningsrequest först: den inloggade användaren ligger kvar i
    // minnet mellan anropen i samma test, så den första mätningen hade
    // annars betalat för laddningar den andra får gratis.
    get("/containers/{$container->ulid}/sharing")->assertOk();

    DB::enableQueryLog();
    DB::flushQueryLog(); // rensa bort factoryns egna INSERT-frågor
    get("/containers/{$container->ulid}/sharing")->assertOk();
    $faRader = count(DB::getQueryLog());

    DB::flushQueryLog();

    foreach (range(1, 5) as $i) {
        delningsAccess(
            $container,
            User::factory()->create(),
            'read',
            item: Item::factory()->create(['container_id' => $container->id]),
            createdAt: now()->subMinutes($i),
        );
    }

    DB::flushQueryLog(); // även de nya radernas INSERT-frågor
    get("/containers/{$container->ulid}/sharing")->assertOk();
    $flerRader = count(DB::getQueryLog());

    expect($flerRader)->toBe($faRader);
});

/*
 * Klart när (issue 439): de fyra nivåerna står som fyra likvärdiga val i
 * ladderns ordning, utan *Advanced*. Fältet renderar laddern ur propen —
 * nivåerna kommer ur AccessLevel::LADDER, aldrig som en avskrift i
 * JavaScript — och `read` bär märkningen *Recommended*.
 */
it('visar de fyra nivåerna som likvärdiga val utan Advanced', function () {
    withoutVite();

    [, $anvandare, $container] = delningsKontext();

    actingAs($anvandare)->get("/containers/{$container->ulid}/sharing")->assertInertia(fn (AssertableInertia $page) => $page
        ->where('levels', ['read', 'create', 'write', 'delete'])
    );

    $falt = File::get(resource_path('js/components/AccessLevelField.vue'));

    // Ingen <details>, ingen *Avancerat* och ingen uppdelning i två grupper.
    expect($falt)->not->toContain('<details');
    expect($falt)->not->toContain("t('sharing.advanced')");
    expect($falt)->not->toContain('ADVANCED');
    expect($falt)->not->toContain('level in common');
    expect($falt)->not->toContain('level in advanced');

    // Alla fyra ritas i en enda lista, i ladderns ordning.
    expect($falt)->toContain('v-for="level in levels"')
        ->toContain('t(`sharing.level.${level}.label`)')
        ->toContain('t(`sharing.level.${level}.description`)');

    // *Recommended* på Read, som en UiBadge i etikettraden.
    expect($falt)->toContain('UiBadge')
        ->toContain("v-if=\"level === 'read'\"")
        ->toContain("t('sharing.level.recommended')");
});

/*
 * Klart när (issue 439): etiketterna är de som ritas överallt där en nivå
 * namnges, och varje beskrivning säger vad MOTTAGAREN får göra — inte vad
 * systemet gör. Nivån `write` heter *Edit*; *Change* finns inte kvar.
 */
it('namnger nivåerna som fyra handlingar med mottagarens ord', function () {
    $en = require lang_path('en/ui.php');
    $nivaer = $en['sharing']['level'];

    expect($nivaer['read']['label'])->toBe('Read')
        ->and($nivaer['read']['description'])->toBe('Can view everything that is shared. Cannot make changes.')
        ->and($nivaer['create']['label'])->toBe('Add')
        ->and($nivaer['create']['description'])->toBe('Can add new items, attachments, tasks and costs. Cannot change or remove existing information.')
        ->and($nivaer['write']['label'])->toBe('Edit')
        ->and($nivaer['write']['description'])->toBe('Can add new information and edit existing content. Cannot delete anything.')
        ->and($nivaer['delete']['label'])->toBe('Delete')
        ->and($nivaer['delete']['description'])->toBe('Can also delete existing information and restore deleted content.')
        ->and($nivaer['recommended'])->toBe('Recommended');

    // *Change* finns inte kvar som namn på en nivå, och *Advanced* är borta.
    expect([
        $nivaer['read']['label'],
        $nivaer['create']['label'],
        $nivaer['write']['label'],
        $nivaer['delete']['label'],
    ])->not->toContain('Change');

    expect($en['sharing'])->not->toHaveKey('advanced');
    expect($en['sharing']['accesses']['level'])->toBe('What can this person do?');

    // Meningen om vad ingen nivå får göra står EN gång på sidan (Beslut 4).
    expect($en['sharing']['accesses']['limits'])->toContain('transfer of ownership');
});

/*
 * Klart när (issue 439): agentfältet står hopfällt bakom *AI agent
 * permissions* och fälls ut när en agentnivå redan är satt, och *Remove
 * access* står sist på raden, skild från behörighetsvalen med en avdelare.
 * Väljarens värden och taket är oförändrade.
 */
it('hopfäller agentfältet och skiljer Remove access från nivåvalet', function () {
    $rad = File::get(resource_path('js/components/ContainerAccessRow.vue'));
    $en = require lang_path('en/ui.php');

    expect($rad)->toContain('<details')
        ->toContain("t('agents.access_field.summary')")
        ->toContain(':open="agentLevel !== null"')
        // Väljaren och taket är oförändrade.
        ->toContain('v-for="level in agentLevels"')
        ->toContain("t('agents.access_field.none')")
        ->toContain('props.levels.slice(0, props.levels.indexOf(props.access.level) + 1)');

    // Agentytan står EFTER nivåvalet.
    expect(strpos($rad, '<AccessLevelField'))->toBeLessThan(strpos($rad, '<details'));

    // *Remove access* står sist, inne i en avdelad yta med luft ovanför.
    $knapp = strpos($rad, "t('sharing.accesses.remove')");
    $avdelare = strpos($rad, 'border-t border-border pt-3');

    expect($avdelare)->not->toBeFalse();
    expect($avdelare)->toBeLessThan($knapp);
    expect($knapp)->toBeGreaterThan(strpos($rad, '</details>'));

    expect($en['agents']['access_field']['summary'])->toBe('AI agent permissions')
        ->and($en['agents']['access_field']['label'])->toBe('Allow AI agents')
        ->and($en['agents']['access_field']['none'])->toBe('No agent access')
        ->and($en['agents']['access_field']['hint'])->toBe('Controls what this person\'s AI agents can access in this container. Agent permissions can never exceed the person\'s own permissions.');
});

/*
 * Klart när: ett val sparas direkt (issue 1250 § 1). Nivåväljaren postar
 * `level` i sin EGEN PATCH, raden har ingen *Save*, och väljaren är avstängd
 * medan anropet pågår. Källkodsprov av samma skäl som grannproven: valet är
 * klientsidans, och det som går att avgöra på serversidan är formen på
 * anropet.
 */
it('sparar nivån direkt med router.patch och utan Save', function () {
    $rad = File::get(resource_path('js/components/ContainerAccessRow.vue'));

    // Kroppen bär `level` och ingenting annat: `sometimes` i den delade
    // FormRequesten lämnar då utgången orörd — beteendet prövas ovan.
    expect($rad)->toContain('router.patch(url, { level: value }');
    expect($rad)->toContain("t('sharing.accesses.updated')");
    expect($rad)->toContain(':disabled="pending"');

    // Ingen *Save*, inget formulär och ingen skicka-knapp: regeln från issue
    // 1245 är att ett val sparas direkt och text sparas med *Save*.
    expect($rad)->not->toContain('sharing.accesses.save');
    expect($rad)->not->toContain('useForm');
    expect($rad)->not->toContain('type="submit"');
});

/*
 * Klart när: ett val sparas direkt (issue 1250 § 1) — också gästens
 * utgångsdatum. Fältet skickar `expires_at` i sin EGEN PATCH när det ändras,
 * och kroppen bär bara datumet: `sometimes` i den delade FormRequesten
 * lämnar nivån orörd, precis som ett nivåval lämnar utgången orörd (det
 * beteendet prövas serverside ovanför).
 */
it('sparar utgångsdatumet direkt med router.patch', function () {
    $rad = File::get(resource_path('js/components/ContainerAccessRow.vue'));

    expect($rad)->toContain('router.patch(url, { expires_at: expiresAt.value }');
    expect($rad)->toContain('@change="saveExpiry"');
});

/*
 * Klart när: webbytan erbjuder ingen väg att tömma utgången — inte heller
 * genom att tömma fältet.
 *
 * Ett `<input type="date">` som töms (backspace eller Rensa) ger `''`, inte
 * `null`. Servern gör `''` till `null`, och `nullable` i den delade
 * UpdateContainerAccessRequest hade då rensat gästens utgång på en enda gest.
 * Ett beteendeprov räcker inte: servern TILLÅTER `null` på `/api`, så det är
 * klientens vakt som ska prövas — att den står FÖRE anropet.
 */
it('skickar aldrig ett tomt utgångsdatum', function () {
    $rad = File::get(resource_path('js/components/ContainerAccessRow.vue'));

    $start = strpos($rad, 'function saveExpiry(');

    expect($start)->not->toBeFalse('saveExpiry saknas i raden');

    $kropp = substr($rad, $start);
    $kropp = substr($kropp, 0, strpos($kropp, "\n}"));

    $vakt = strpos($kropp, 'if (! expiresAt.value)');
    $anrop = strpos($kropp, 'router.patch(');

    expect($vakt)->not->toBeFalse('saveExpiry fångar inte ett tomt värde');
    expect($anrop)->not->toBeFalse('saveExpiry anropar inte router.patch');
    expect($vakt)->toBeLessThan($anrop);

    // Fältet ställs tillbaka till serverns värde, inte till tomt.
    expect($kropp)->toContain('expiresAt.value = saved');
});

/*
 * Klart när: ett värde servern inte sparade visas inte som sparat.
 *
 * `onError` anropas bara för ett valideringssvar (422). Ett fryst ägarkonto
 * får 403 på PATCH, och ett nätfel ger inget svar alls — ingen av dem når
 * `onError`, så en återställning som bara stod där hade lämnat väljaren på
 * ett värde ägaren trodde gällde. Utfallet avgörs därför i `onFinish`, där
 * propen bär serverns värde.
 */
it('ställer tillbaka nivån och utgången när anropet inte gick igenom', function () {
    $rad = File::get(resource_path('js/components/ContainerAccessRow.vue'));

    foreach (['saveLevel', 'saveExpiry'] as $funktion) {
        $start = strpos($rad, "function {$funktion}(");

        expect($start)->not->toBeFalse("{$funktion} saknas i raden");

        $kropp = substr($rad, $start);
        $kropp = substr($kropp, 0, strpos($kropp, "\n}"));

        $finish = strpos($kropp, 'onFinish:');

        expect($finish)->not->toBeFalse("{$funktion} saknar onFinish");

        // Leta efter återställningen INIFRÅN onFinish: funktionen kan bära
        // tidigare vakter av samma form (saveExpiry fångar ett tomt datum).
        $aterstallning = strpos($kropp, 'if (! ', $finish);

        expect($aterstallning)->not->toBeFalse("{$funktion} ställer inte tillbaka något värde");
        expect($aterstallning)->toBeGreaterThan($finish);
    }

    // Återställningen läser serverns värde ur propen, och ett avbrutet anrop
    // gör samma sak.
    expect($rad)->toContain('level.value = props.access.level')
        ->and($rad)->toContain('expiresAt.value = hasExpiry ? dateInput(props.access.expires_at) : null')
        ->and($rad)->toContain('onCancel:');
});

/*
 * Klart när: borttagningen frågar först, och frågan är appens egen dialog
 * (issue 330) med danger-ton. Ingen Inertia-länk som postar en DELETE och
 * ingen webbläsardialog.
 */
it('frågar i UiConfirmDialog innan åtkomsten tas bort', function () {
    $rad = File::get(resource_path('js/components/ContainerAccessRow.vue'));

    expect($rad)->toContain('UiConfirmDialog');
    expect($rad)->toContain('tone="danger"');
    expect($rad)->toContain("t('sharing.accesses.remove')");
    expect($rad)->toContain("t('sharing.accesses.remove_heading')");
    expect($rad)->toContain("t('sharing.accesses.remove_body')");
    expect($rad)->toContain('router.delete(url');

    expect($rad)->not->toContain('<Link');

    $en = require lang_path('en/ui.php');

    expect($en['sharing']['accesses']['remove'])->toBe('Remove access');
    expect($en['sharing']['accesses']['remove_heading'])->not->toBe('');
    expect($en['sharing']['accesses']['remove_body'])->not->toBe('');
});

/*
 * Klart när: en borttagning som inte går igenom säger det.
 *
 * Ett fryst konto får 403 på DELETE och ett nätfel ger inget svar alls —
 * båda stänger dialogen utan att raden försvinner. Utan besked ser det ut som
 * att åtkomsten är borta. Raden bär därför en felmening, som de andra
 * ytorna som skriver `t('error.generic')` när anropet fallerar.
 */
it('ger besked när borttagningen inte går igenom', function () {
    $rad = File::get(resource_path('js/components/ContainerAccessRow.vue'));

    expect($rad)->toContain('removeError')
        ->and($rad)->toContain("t('error.generic')")
        ->and($rad)->toContain('role="alert"');
});

/*
 * Klart när: ägarkontot står överst i listan (issue 1250 § 1). Raden ritas ur
 * deltagarlistans första post — ägarkontot är alltid den (issue 9c § Beslut
 * 3) — och står före åtkomsterna i mallen, med rollen *Owner* och utan
 * kontroller.
 */
it('ställer ägarkontot överst i listan', function () {
    $vy = File::get(resource_path('js/pages/Containers/Sharing.vue'));

    expect($vy)->toContain('const owner = computed(() => props.participants[0]');
    expect($vy)->toContain("t('sharing.role.owner')");

    $agare = strpos($vy, "t('sharing.role.owner')");
    $atkomster = strpos($vy, 'v-for="access in validAccesses"');

    expect($agare)->not->toBeFalse();
    expect($atkomster)->not->toBeFalse();
    expect($agare)->toBeLessThan($atkomster);
});

/*
 * Klart när: historiken står sist i ett hopfällt avsnitt (issue 1250 § 3).
 * Provet läser ordningen i mallen: `<details>` står efter inbjudningarna, och
 * avsnittet ritas bara när `accesses` finns — en icke-ägare har ingen
 * historik att fälla ut.
 */
it('ställer historiken sist i ett hopfällt avsnitt', function () {
    $vy = File::get(resource_path('js/pages/Containers/Sharing.vue'));

    $detaljer = strpos($vy, '<details');
    $inbjudningar = strpos($vy, "t('sharing.invitations.heading')");

    expect($detaljer)->not->toBeFalse();
    expect($inbjudningar)->not->toBeFalse();
    expect($detaljer)->toBeGreaterThan($inbjudningar);

    expect($vy)->toContain('v-if="accesses && historicalAccesses.length > 0"');
    // Hopfällt från början: elementet bär ingen `open`.
    expect($vy)->not->toMatch('/<details[^>]*\sopen/');
    expect($vy)->toContain("t('sharing.history.heading')");
});

/*
 * Klart när: en icke-ägare ser listan utan kontroller (issue 1250 § 1).
 * Listan ritas ur `participants` när `accesses` är `null`, och
 * radkomponenten — som bär nivå, utgång och borttagning — ritas bara i
 * grenen där `accesses` finns.
 */
it('visar listan utan kontroller för en icke-ägare', function () {
    withoutVite();

    [, , $container] = delningsKontext();
    $innehavare = User::factory()->create();
    delningsAccess($container, $innehavare, 'write');

    actingAs($innehavare)->get("/containers/{$container->ulid}/sharing")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('accesses', null)
            ->where('invitations', null)
            ->has('participants', 2)
        );

    $vy = File::get(resource_path('js/pages/Containers/Sharing.vue'));

    // Samma lista, men ur deltagarlistan: namn och roll.
    expect($vy)->toContain('v-for="participant in participants"');
    expect($vy)->toContain('t(`sharing.role.${participant.role}`)');

    // Kontrollerna hänger på `accesses` — inte på en egen flagga som kan
    // glida isär från serverns svar.
    $gren = strpos($vy, 'v-if="accesses"');
    $kontroller = strpos($vy, '<ContainerAccessRow');

    expect($gren)->not->toBeFalse();
    expect($kontroller)->not->toBeFalse();
    expect($kontroller)->toBeGreaterThan($gren);
});

/*
 * Klart när: sidan använder designsystemets tokens (issue 1250 § 4).
 * Källkodsprov: de gamla palettnivåerna `slate-*`, `blue-700` och `red-700`
 * får inte finnas kvar i vyn, raden eller nivåfältet — färgerna kommer ur
 * rollerna i resources/css/app.css, samma regel som [[ADR-0042
 * Designsystemet]] § Beslut.
 */
it('använder designsystemets tokens på delningssidan', function () {
    $filer = [
        'js/pages/Containers/Sharing.vue',
        'js/components/ContainerAccessRow.vue',
        'js/components/AccessLevelField.vue',
    ];

    foreach ($filer as $fil) {
        $kod = File::get(resource_path($fil));

        expect($kod)->not->toMatch(
            '/\b(?:slate|blue|red)-\d{2,3}\b/',
            "{$fil} bär en palettfärg i stället för en roll",
        );
    }

    $vy = File::get(resource_path($filer[0]));

    foreach ([
        'border-border',
        'bg-surface',
        'text-ink',
        'text-ink-muted',
        'rounded-card',
        'text-title',
        'text-body',
        'text-meta',
    ] as $token) {
        expect($vy)->toContain($token);
    }
});

/*
 * Klart när: texterna finns under `sharing`, och de som inte längre läses är
 * borta (issue 1250 § 6). `participants.*`, `accesses.heading/description`,
 * `accesses.save` och `accesses.revoke` lästes bara av den gamla vyn; en
 * kvarglömd nyckel är en mening som kan börja gälla igen av misstag.
 */
it('har den enda listans texter och inga kvarglömda nycklar', function () {
    $en = require lang_path('en/ui.php');

    expect($en['sharing']['accesses']['people_heading'])->toBe('People with access');

    foreach (['updated', 'remove', 'remove_heading', 'remove_body'] as $nyckel) {
        expect($en['sharing']['accesses'][$nyckel])->not->toBe('');
    }

    expect($en['sharing']['history']['heading'])->toBe('Revoked and expired access');

    expect($en['sharing'])->not->toHaveKey('participants');
    expect($en['sharing']['accesses'])->not->toHaveKey('save');
    expect($en['sharing']['accesses'])->not->toHaveKey('heading');
    expect($en['sharing']['accesses'])->not->toHaveKey('description');
    expect($en['sharing']['accesses'])->not->toHaveKey('revoke');
});

/*
 * Klart när: PATCH sparar `level` för en medlem i ägarkontot, och nivån syns
 * ändrad efteråt.
 *
 * Issue 1250:s beteendeprov: en PATCH med BARA `level` lämnar `expires_at`
 * orört. Den delade UpdateContainerAccessRequest har `sometimes` på båda
 * fälten, så den som inte skickas rörs inte — radens två kontroller skickar
 * därför sina egna kroppar, och ett nivåval flyttar aldrig ett gästkontos
 * utgång. Regeln formulerades i issue 72 § Beslut 4; här prövas den på
 * webbens rutt.
 */
it('sparar nivån, lämnar utgången orörd och visar nivån ändrad efteråt', function () {
    withoutVite();

    [, $anvandare, $container] = delningsKontext();
    $access = delningsAccess(
        $container,
        User::factory()->create(),
        'read',
        'guest',
        expiresAt: now()->addWeek(),
    );

    $utgang = $access->expires_at->toIso8601String();

    actingAs($anvandare)
        ->patch("/containers/{$container->ulid}/accesses/{$access->ulid}", ['level' => 'write'])
        ->assertRedirect()
        ->assertSessionHas('status', 'access-updated');

    $access->refresh();

    expect($access->level)->toBe('write');
    expect($access->expires_at->toIso8601String())->toBe($utgang);

    actingAs($anvandare)->get("/containers/{$container->ulid}/sharing")->assertInertia(fn (AssertableInertia $page) => $page
        ->where('accesses.0.level', 'write')
        ->where('accesses.0.expires_at', $utgang)
    );
});

/*
 * Arkitektsvaret § 3: en gästrad kan få sin utgång flyttad framåt, och det
 * nya datumet syns efteråt. Alternativet — att återkalla och bjuda in på nytt
 * för att flytta ett datum en vecka — är precis det som gör ytan oanvändbar.
 *
 * `expires_at` skickas i SAMMA PATCH som `level`, och rutt och FormRequest
 * kunde det redan: den delade UpdateContainerAccessRequest har fältet, och
 * UpdateContainerAccess fyller båda.
 */
it('flyttar fram en gästrads utgång och visar det nya datumet efteråt', function () {
    withoutVite();

    [, $anvandare, $container] = delningsKontext();
    $access = delningsAccess(
        $container,
        User::factory()->create(),
        'read',
        'guest',
        expiresAt: now()->addWeek(),
    );

    $nytt = now()->addMonth()->startOfDay();

    actingAs($anvandare)
        ->patch("/containers/{$container->ulid}/accesses/{$access->ulid}", [
            'level' => 'read',
            'expires_at' => $nytt->toDateString(),
        ])
        ->assertRedirect()
        ->assertSessionHas('status', 'access-updated');

    expect($access->refresh()->expires_at->toDateString())->toBe($nytt->toDateString());

    actingAs($anvandare)->get("/containers/{$container->ulid}/sharing")->assertInertia(fn (AssertableInertia $page) => $page
        ->where('accesses.0.expires_at', fn ($value) => str_starts_with($value, $nytt->toDateString()))
    );
});

/*
 * Klart när: webbytan erbjuder ingen väg att tömma `expires_at`.
 *
 * Den delade UpdateContainerAccessRequest tillåter `null` och rörs inte — den
 * är `/api`:s också — men formuläret bär nyckeln BARA när raden redan har ett
 * datum, så den kan aldrig skickas som `null`. Det är vad som skiljer fältet
 * från en tömningsknapp: en `guest` utan utgång motsäger Beslut 5, och vägen
 * från gäst till permanent går genom `kind`, som är `prohibited` med flit.
 */
it('erbjuder ingen väg att tömma utgången', function () {
    withoutVite();

    [, $anvandare, $container] = delningsKontext();
    $medlem = delningsAccess($container, User::factory()->create(), 'write');

    // En medlemsrad har ingen utgång alls — och ingen yta som kan ge den en.
    actingAs($anvandare)->get("/containers/{$container->ulid}/sharing")->assertInertia(fn (AssertableInertia $page) => $page
        ->where('accesses.0.expires_at', null)
    );

    $rad = File::get(resource_path('js/components/ContainerAccessRow.vue'));

    // Fältet renderas bara på en rad som REDAN har ett datum.
    expect($rad)->toContain('const hasExpiry = props.access.expires_at !== null');
    expect($rad)->toContain('v-if="hasExpiry"');

    // Och formuläret bär nyckeln bara då. Ett `expires_at: null` i kroppen
    // hade varit en tömning, och `nullable` hade tillåtit den.
    expect($rad)->not->toContain('expires_at: null');
    expect($rad)->not->toContain('expires_at:null');

    // Meningen om varför står i vyn, i stället för att ägaren letar efter en
    // knapp som inte finns.
    $en = require lang_path('en/ui.php');

    expect($en['sharing']['accesses']['expires_fixed'])->not->toBe('');
    expect($rad)->toContain("t('sharing.accesses.expires_fixed')");

    expect($medlem->refresh()->expires_at)->toBeNull();
});

/*
 * Klart när: ett datum i det förflutna ger ett fältfel vid `expires_at`.
 *
 * `after:now` tolkar dagens datum som midnatt bakåt och avvisar det, så
 * fältets `min` är i morgon och inte i dag. Felet är ett vanligt valideringsfel
 * ur den delade FormRequesten — ingen `ApiErrorTranslator`, för det är ingen
 * domänfelkod.
 */
it('ger ett fältfel vid expires_at för ett datum i det förflutna', function () {
    withoutVite();

    [, $anvandare, $container] = delningsKontext();
    $access = delningsAccess(
        $container,
        User::factory()->create(),
        'read',
        'guest',
        expiresAt: now()->addWeek(),
    );

    $oforandrat = $access->expires_at->toDateString();

    actingAs($anvandare)
        ->patch("/containers/{$container->ulid}/accesses/{$access->ulid}", [
            'level' => 'read',
            'expires_at' => now()->subDay()->toDateString(),
        ])
        ->assertSessionHasErrors('expires_at');

    expect($access->refresh()->expires_at->toDateString())->toBe($oforandrat);

    $rad = File::get(resource_path('js/components/ContainerAccessRow.vue'));

    // Raden bär fältets eget fel, med `role="alert"` och ett id som fältet
    // pekar på — och `min` är i morgon, för `after:now` tolkar dagens datum
    // som midnatt bakåt.
    expect($rad)->toContain('expiryError');
    expect($rad)->toContain('role="alert"');
    expect($rad)->toContain(':min="tomorrow"');
});

/*
 * Beslut 5: `kind` går inte att ändra — den är `prohibited` i den delade
 * UpdateContainerAccessRequest, och formuläret skickar den aldrig. Kroppen
 * nekas av samma FormRequest som `/api` använder.
 */
it('nekar en PATCH som rör kind eller item', function () {
    withoutVite();

    [, $anvandare, $container] = delningsKontext();
    $access = delningsAccess($container, User::factory()->create(), 'read');
    $item = Item::factory()->create(['container_id' => $container->id]);

    actingAs($anvandare)
        ->patch("/containers/{$container->ulid}/accesses/{$access->ulid}", ['level' => 'write', 'kind' => 'guest'])
        ->assertSessionHasErrors('kind');

    actingAs($anvandare)
        ->patch("/containers/{$container->ulid}/accesses/{$access->ulid}", ['item' => $item->ulid])
        ->assertSessionHasErrors('item');

    expect($access->refresh()->level)->toBe('read');
});

/*
 * Beslut 9: en återkallad eller utgången rad nekas — och på webben blir det
 * ett formulärfel, inte en JSON-kropp. `ApiException` får aldrig nå
 * webbläsaren (samma regel som issue 54 § Beslut 4).
 */
it('ger ett läsbart formulärfel i stället för JSON på en död rad', function () {
    withoutVite();

    [, $anvandare, $container] = delningsKontext();
    $aterkallad = delningsAccess($container, User::factory()->create(), 'read', revokedAt: now()->subDay());
    $utgangen = delningsAccess($container, User::factory()->create(), 'read', 'guest', expiresAt: now()->subDay());

    // Meningen finns i katalogen, och är inte felkoden själv.
    $en = require lang_path('en/ui.php');
    expect($en['error']['container_access']['revoked'])->not->toBe('container_access.revoked');

    foreach ([$aterkallad, $utgangen] as $access) {
        $svar = from("/containers/{$container->ulid}/sharing")
            ->actingAs($anvandare)
            ->patch("/containers/{$container->ulid}/accesses/{$access->ulid}", ['level' => 'write']);

        $svar->assertRedirect("/containers/{$container->ulid}/sharing");
        $svar->assertSessionHasErrors('level');

        expect($svar->getContent())->not->toContain('error.code');
    }

    expect($aterkallad->refresh()->level)->toBe('read');
    expect($utgangen->refresh()->level)->toBe('read');
});

/*
 * Klart när: DELETE sätter `revoked_at`, skriver `access.revoked` i
 * audit_log, och en andra DELETE skriver varken eller.
 */
it('återkallar en åtkomst, loggar den, och loggar inte en andra gång', function () {
    withoutVite();

    [$konto, $anvandare, $container] = delningsKontext();
    $access = delningsAccess($container, User::factory()->create(), 'read');

    actingAs($anvandare)
        ->from("/containers/{$container->ulid}/sharing")
        ->delete("/containers/{$container->ulid}/accesses/{$access->ulid}")
        ->assertRedirect("/containers/{$container->ulid}/sharing")
        ->assertSessionHas('status', 'access-revoked');

    $access->refresh();

    expect($access->revoked_at)->not->toBeNull();

    $forsta = $access->revoked_at;

    $logg = AuditLog::query()
        ->where('action', AuditLog::ACTION_ACCESS_REVOKED)
        ->where('subject_id', $access->ulid)
        ->where('container_id', $container->id)
        ->where('account_id', $konto->id)
        ->get();

    expect($logg)->toHaveCount(1);
    expect($logg->first()->meta['level'])->toBe('read');
    expect($logg->first()->meta['item'])->toBeNull();

    actingAs($anvandare)
        ->delete("/containers/{$container->ulid}/accesses/{$access->ulid}")
        ->assertRedirect();

    expect($access->refresh()->revoked_at->timestamp)->toBe($forsta->timestamp);
    expect(AuditLog::query()->where('action', AuditLog::ACTION_ACCESS_REVOKED)->count())->toBe(1);
});

/*
 * Klart när: en `read_only`-ägare kan återkalla men får 403 på PATCH. Regel 4
 * undantar återkallandet uttryckligen — det minskar exponeringen i stället
 * för att öka den — och `manageAccess()` har därför en `isFrozen()`-kontroll
 * som `revokeAccess()` saknar.
 */
it('låter ett fryst ägarkonto återkalla men inte ändra nivå', function () {
    withoutVite();

    [, $anvandare, $container] = delningsKontext(['status' => 'read_only']);
    $access = delningsAccess($container, User::factory()->create(), 'read');

    actingAs($anvandare)
        ->patch("/containers/{$container->ulid}/accesses/{$access->ulid}", ['level' => 'write'])
        ->assertForbidden();

    expect($access->refresh()->level)->toBe('read');

    actingAs($anvandare)
        ->delete("/containers/{$container->ulid}/accesses/{$access->ulid}")
        ->assertRedirect();

    expect($access->refresh()->revoked_at)->not->toBeNull();
});

/*
 * Vyn skriver ut fryst tillstånd i stället för att låta användaren upptäcka
 * det som ett fel (Beslut 9). Kombinationen "får återkalla men inte ändra" är
 * exakt vad frysningen betyder.
 */
it('skickar fryst-flaggan till vyn och skriver ut den där', function () {
    withoutVite();

    [, $anvandare, $container] = delningsKontext(['status' => 'read_only']);

    actingAs($anvandare)->get("/containers/{$container->ulid}/sharing")->assertInertia(fn (AssertableInertia $page) => $page
        ->where('can.manage', false)
        ->where('can.revoke', true)
    );

    $vy = File::get(resource_path('js/pages/Containers/Sharing.vue'));

    expect($vy)->toContain("t('sharing.frozen')");
    expect($vy)->toContain('can.revoke');
});

/*
 * Klart när: en användare som inte är medlem i ägarkontot får 403 på både
 * PATCH och DELETE.
 */
it('nekar en icke-medlem både PATCH och DELETE', function () {
    withoutVite();

    [, , $container] = delningsKontext();
    $access = delningsAccess($container, User::factory()->create(), 'read');
    $frammande = User::factory()->create();

    actingAs($frammande)
        ->patch("/containers/{$container->ulid}/accesses/{$access->ulid}", ['level' => 'write'])
        ->assertForbidden();

    actingAs($frammande)
        ->delete("/containers/{$container->ulid}/accesses/{$access->ulid}")
        ->assertForbidden();

    // En delegerad `write`-innehavare hanterar inte åtkomster heller — regel
    // 3 spärrar det, och `write` är inte medlemskap.
    $innehavare = User::factory()->create();
    delningsAccess($container, $innehavare, 'write');

    actingAs($innehavare)
        ->get("/containers/{$container->ulid}/sharing")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('accesses', null));

    actingAs($innehavare)
        ->delete("/containers/{$container->ulid}/accesses/{$access->ulid}")
        ->assertForbidden();

    expect($access->refresh()->revoked_at)->toBeNull();
});

/*
 * Klart när: en åtkomst i en annan container går inte att nå via den här containerns
 * rutt (404). `scopeBindings()` på de två skrivningarna, av samma skäl som
 * routes/api.php gör det (issue 9b § Beslut 1).
 */
it('når inte en åtkomst i en annan container via den här containerns rutt', function () {
    withoutVite();

    [$konto, $anvandare, $container] = delningsKontext();
    $annan = Container::factory()->for($konto, 'account')->create();

    $access = delningsAccess($annan, User::factory()->create(), 'read');

    actingAs($anvandare)
        ->patch("/containers/{$container->ulid}/accesses/{$access->ulid}", ['level' => 'write'])
        ->assertNotFound();

    actingAs($anvandare)
        ->delete("/containers/{$container->ulid}/accesses/{$access->ulid}")
        ->assertNotFound();

    expect($access->refresh()->revoked_at)->toBeNull();
    expect($access->level)->toBe('read');
});

/*
 * Beslut 1: sektionsraden. Navigationen renderas ur containerSections, så en
 * ny sida är en ny rad där och ingen ändring i ContainerLayout.
 */
it('lägger delningssidan i containerns navigation', function () {
    $sektioner = File::get(resource_path('js/layouts/containerSections.js'));

    expect($sektioner)->toContain("key: 'sharing'");
    expect($sektioner)->toContain('/containers/${ulid}/sharing');
});

/*
 * Beslut 2: webben beviljar aldrig en åtkomst direkt. Ingen POST-rutt mot
 * `/accesses`, ingen mottagarväljare och inget adressuppslag — all ny delning
 * går genom en inbjudan.
 *
 * Sedan issue 55b finns inbjudningsformulärets POST här, och den är just
 * motsatsen till en direkt bevilning: den skapar en `pending`-rad som
 * mottagaren själv måste acceptera, och den har inget med `/accesses` att göra.
 * 55b:s egna tester ligger i tests/Feature/Frontend/InbjudningsvyTest.php.
 *
 * Sedan issue 56a finns också kategorins och taggens POST här. Ingen av dem rör
 * åtkomster — den här listan är hela uppräkningen av POST-rutter under en container,
 * och det är hela poängen: den ska behöva ändras när en ny yta lägger till en.
 *
 * Sedan issue 56b finns även uppsättningens POST här. Den skapar vanliga
 * kategorier genom App\Actions\Category\CreateCategory och rör inga åtkomster
 * — den är alltså ytterligare ett exempel på motsatsen till en direkt
 * bevilning, inte ett undantag från regeln.
 *
 * Sedan issue 57b finns itemets POST här. Den skapar en item-rad och rör inga
 * åtkomster; att få SKAPA i en container och att få BEVILJA åtkomst till den är två
 * olika pinnar ([[ADR-0028 Åtkomst på itemnivå]] § Beslut).
 *
 * Sedan issue 58 finns relationens POST här. Den skriver en `item_link`-rad
 * och rör inga åtkomster — men den är den ENDA posten i listan som har med
 * delning att göra, och därför värd en mening: en `parent`/`child`-kant bär
 * behörighet nedåt ([[ADR-0028 Åtkomst på itemnivå]] § Beslut regel 1), så
 * att knyta ihop två items kan utvidga vad någon annan når. Det är just
 * därför grinden är `update` i BÅDA ändarna (issue 71 § Beslut 4) och inte
 * en åtkomstskrivning: ingen ny grant skapas, ingen mottagare läggs till, och
 * ett delat item kan inte knytas ihop av någon som bara ser det.
 *
 * Sedan issue 60a finns bilagans POST här. Den skriver en `attachment`-rad och
 * rör inga åtkomster; grinden är `create` på ITEMET (issue 71 § Beslut 1), och
 * den som får lägga till en bilaga får varken se eller dela ut mer av containern.
 * Medlemsprövningen i kontrollern gäller betalkontot och skapar ingen åtkomst.
 *
 * Sedan issue 62a finns återställningens POST här. Den väcker innehåll till
 * liv igen och rör inga åtkomster: grinden är per rad — `ItemPolicy::delete()`
 * för ett item och för bilagans item, `ContainerPolicy::update()` för en
 * kategori eller tagg (issue 74 § Beslut 2) — så ingen ny grant skapas och
 * ingen mottagare läggs till. En `read`-deltagare ser papperskorgen men får
 * 403 här, och en `write`-deltagare når sina items men varken raderar eller
 * återställer (regel 3).
 *
 * Sedan issue 63a finns schemats POST här. Den skriver en `schedule`-rad — och
 * i samma transaktion dess första förekomst genom
 * App\Actions\Schedule\OpenNextOccurrence — men rör inga åtkomster: grinden är
 * `create` på ITEMET (issue 71b § Beslut 1, issue 63a § Beslut 7), så den som
 * får lägga till en uppgift på sitt item får varken se eller dela ut mer av
 * containern. En `read`-deltagare ser schemalistan och får 403 här; en
 * `create`-deltagare lägger till men ändrar och raderar inget befintligt.
 *
 * Sedan issue 63b finns förekomstens två POST här. De stänger en `open`-rad och
 * öppnar nästa genom App\Actions\Schedule\CloseOccurrence, men rör inga
 * åtkomster: grinden är `update` på ITEMET (issue 71 § Beslut 5), så den som
 * får bocka av sin egen uppgift får varken se eller dela ut mer av containern. En
 * `read`-deltagare ser historiken och den öppna förekomsten men får 403 här; en
 * `create`-deltagare nekas av samma grind, för att bocka av ändrar en rad som
 * redan finns.
 *
 * Sedan issue 63c finns beroendenas två POST här, en per nivå. De skriver en
 * `schedule_dependency`- respektive `occurrence_dependency`-rad genom
 * App\Actions\Schedule\DependSchedule/DependOccurrence, men rör inga åtkomster:
 * grinden är `update` på ITEMET i BÅDA ändar (issue 63c § Beslut 8,
 * [[ADR-0028 Åtkomst på itemnivå]] § Beslut — att ändra en kant kräver `write` i
 * båda ändar). En `read`-deltagare ser beroendena men får 403 här; en
 * `write`-deltagare som saknar åtkomst till motparten får också 403, utan att
 * motpartens namn syns i svaret.
 *
 * Sedan issue 65b finns containerns kalenderlänk här. Den skriver en
 * `calendar_feed`-rad — användarens EGEN prenumeration på containern — men rör
 * inga åtkomster: grinden är `view` på containern (issue 36a § Beslut 4, 65b
 * § Beslut 2), så den som redan får läsa containern får en länk som visar exakt
 * samma sak. Ingen ny läsare och ingen ny mottagare; raden pekar på den
 * inloggade användaren själv.
 *
 * Sedan issue 67a finns utlåningens POST här. Den skriver en `loan`-rad — vem
 * som har prylen och när den ska tillbaka — men rör inga åtkomster: grinden är
 * `create` på ITEMET (issue 67a § Beslut 6, issue 71b § Beslut 1), så den som
 * får låna ut sitt item får varken se eller dela ut mer av containern. En
 * `read`-deltagare ser utlåningen och får 403 här; en `create`-deltagare lägger
 * till en rad men ändrar och raderar inget befintligt. Ingen ny läsare och
 * ingen ny mottagare: `borrower_email` är en kontaktuppgift och systemet mejlar
 * aldrig låntagaren ([[ADR-0017 Missbruksvektorer]] § 7).
 *
 * Sedan issue 67b finns ägarbytets POST här. Den skriver en
 * `ownership_transfer`-rad — en avsikt, ännu ingen åtkomst — och rör inga
 * `container_access`-rader alls. Grinden är `transfer()` (ägarkontots
 * medlemmar, och aldrig ett fryst konto), alltså strängare än både `create` på
 * itemet och `manageAccess()`. Den kvarhållna åtkomsten skapas först vid
 * ACCEPT, av App\Actions\OwnershipTransfer\AcceptOwnershipTransfer, och det är
 * den AVGIVANDE sidans konto som behåller den — ingen tredje part får något,
 * och acceptrutten ligger på toppnivå (`/transfers`, utanför `containers/`).
 * Ingen ny läsare läggs alltså till här: en pågående överlåtelse ger mottagaren
 * ingenting förrän hon svarar.
 *
 * Sedan issue 67c finns exportens POST här. Den skriver en `export`-rad — en
 * beställning om att packa containern i en fil — och rör inga
 * `container_access`-rader alls: grinden är `view` på containern (issue 41a
 * § Beslut 3, issue 67c § Beslut 2), alltså exakt den grind som redan avgör
 * vem som får LÄSA containern. Ingen ny läsare och ingen ny mottagare läggs
 * till: den som kan se innehållet kan redan hämta det bilaga för bilaga, och
 * exporten är fri på alla plannivåer med flit ([[Planer och kvoter]]
 * § Gränserna i MVP). Raden den skriver är beställarens EGET ärende —
 * jobbet packar det hon själv når (issue 74 § Beslut 8) — och resultatet
 * delas med ingen.
 *
 * Sedan issue 105 finns favoritens POST här. Den skriver en `favorite`-rad —
 * ett bokmärke på ett item — och rör inga `container_access`-rader alls:
 * grinden är `view` på ITEMET ([[ADR-0042 Designsystemet]] § Konsekvenser),
 * alltså samma grind som avgör om hon får LÄSA itemet. Markeringen speglar
 * åtkomsten, den ger den inte ([[ADR-0028 Åtkomst på itemnivå]] § Beslut):
 * raden pekar på den inloggade användaren själv, ingen ny läsare och ingen ny
 * mottagare läggs till, och ett item utanför omfånget ger 403 — den som inte
 * får se itemet får heller inte märka det.
 */
it('har ingen rutt som beviljar en åtkomst i webben', function () {
    $rutter = collect(app('router')->getRoutes()->getRoutes());

    $poster = $rutter->filter(fn ($rutt) => $rutt->methods() === ['POST']
        && ($rutt->uri() === 'containers' || str_starts_with($rutt->uri(), 'containers/')));

    // Itemet, favoriten, utlåningen, relationen, bilagan, schemat,
    // förekomstens två avslut, containerns eget skapande, kalenderlänken,
    // inbjudan, ägarbytet, exporten, kategorin, uppsättningen, taggen och
    // papperskorgen. Ingen /accesses.
    // Ordningen är registreringsordningen i routes/web.php — itemrutterna
    // ligger ovanför `POST /containers`.
    expect($poster->pluck('uri')->values()->all())->toBe([
        'containers/{container}/items',
        // Issue 413: återställningen av en version. Den skriver en
        // `item_version`-rad och itemets fält och rör inga
        // `container_access`-rader alls — grinden är itemets `update`, samma
        // pinne som att ändra itemet. Ingen ny läsare och ingen ny mottagare
        // läggs till.
        'containers/{container}/items/{item}/versions/{version}/restore',
        'containers/{container}/items/{item}/favorite',
        'containers/{container}/items/{item}/loans',
        // Issue 168: kostnadsraden. Den skriver en `cost_entry`-rad och rör
        // inga `container_access`-rader alls — grinden är itemets `create`,
        // samma pinne som relationen och bilagan använder. Ingen ny läsare
        // och ingen ny mottagare läggs till.
        'containers/{container}/items/{item}/costs',
        'containers/{container}/items/{item}/links',
        // Issue 344: flytten av ett item. Den byter en `item_link`-kant och rör
        // inga `container_access`-rader.
        'containers/{container}/items/{item}/move',
        'containers/{container}/items/{item}/attachments',
        // Issue 242: flytten och kopian av en bilaga. Ingen av dem rör några
        // `container_access`-rader: flytten kräver `delete` på källans item
        // och `create` på målets, kopian `view` på källan och `create` på
        // målet ([[ADR-0053 Flytt och kopiering]] § 2) — pinningar som redan
        // finns på itemet. Ägaren flyttas mellan konton, men ägarskapet är
        // `attachment.billed_account_id` och inte en åtkomstrad, och grinden
        // är oförändrad. Ingen ny läsare och ingen ny mottagare läggs till.
        'containers/{container}/items/{item}/attachments/{attachment}/move',
        'containers/{container}/items/{item}/attachments/{attachment}/copy',
        // Issue 244: *Back to Inbox* för en bilaga. Den rör inga
        // `container_access`-rader alls — grinden är `delete` på källans item,
        // samma pinne som flytten intill — och målet är användarens EGEN
        // inbox, som ingen annan når (ADR-0054 § 2). Ägaren blir personkontot,
        // men ägarskapet är `attachment.billed_account_id` och inte en
        // åtkomstrad. Ingen ny läsare och ingen ny mottagare läggs till.
        'containers/{container}/items/{item}/attachments/{attachment}/inbox',
        'containers/{container}/items/{item}/schedules',
        // Issue 240: flytten av en uppgift. Den rör inga
        // `container_access`-rader alls — grinden är `delete` på källans item
        // och `create` på målets, samma pinningar som bilagans flytt (issue
        // 242). Uppgiften byter item men inte ägare; ingen ny läsare och ingen
        // ny mottagare läggs till.
        'containers/{container}/items/{item}/schedules/{schedule}/move',
        // Issue 244: *Back to Inbox* för en uppgift. Samma grind (`delete` på
        // källans item) och samma mål — användarens egen inbox. Ingen ny
        // läsare och ingen ny mottagare läggs till.
        'containers/{container}/items/{item}/schedules/{schedule}/inbox',
        'containers/{container}/items/{item}/schedules/{schedule}/occurrences/{occurrence}/complete',
        'containers/{container}/items/{item}/schedules/{schedule}/occurrences/{occurrence}/skip',
        // Issue 1120: återöppningen av en avbockad uppgift. Grinden är itemets
        // `update`, som för `complete`; ingen `container_access`-rad rörs.
        'containers/{container}/items/{item}/schedules/{schedule}/occurrences/{occurrence}/reopen',
        'containers/{container}/items/{item}/schedules/{schedule}/dependencies',
        'containers/{container}/items/{item}/schedules/{schedule}/occurrences/{occurrence}/dependencies',
        'containers',
        // Issue 422: mallarna. Att spara en mall skriver en `container_template`
        // -rad på kontot och kräver medlemskap där; att tillämpa en lägger
        // till kategorier och fält och kräver `ContainerPolicy::update`.
        // Ingen av dem rör någon `container_access`-rad, och ingen ny läsare
        // eller mottagare läggs till.
        'containers/{container}/templates',
        'containers/{container}/template',
        // Issue 159: containerns bild. Den sätter en bilaga på containern och
        // rör inga `container_access`-rader alls — grinden är
        // `ContainerPolicy::update`, samma pinne som att byta containerns
        // namn. Ingen ny läsare och ingen ny mottagare läggs till.
        'containers/{container}/cover',
        'containers/{container}/calendar',
        'containers/{container}/invitations',
        'containers/{container}/transfer',
        'containers/{container}/export',
        'containers/{container}/categories',
        'containers/{container}/categories/preset',
        'containers/{container}/tags',
        // Issue 417: fältens två POST. De skriver en `item_field`-rad och rör
        // inga åtkomster — grinden är `ContainerPolicy::update` för
        // containerns fält och `ItemPolicy::update` för itemets egna, samma
        // pinne som att definiera dem. Ingen ny läsare och ingen ny mottagare
        // läggs till.
        'containers/{container}/fields',
        'containers/{container}/items/{item}/fields',
        'containers/{container}/trash/restore',
    ]);

    foreach ([
        'containers.sharing',
        'containers.accesses.update',
        'containers.accesses.destroy',
        'containers.invitations.store',
        'containers.invitations.update',
        'containers.invitations.destroy',
    ] as $namn) {
        expect($rutter->first(fn ($rutt) => $rutt->getName() === $namn))->not->toBeNull();
    }
});
