<?php

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
 * Inbjudningarna — avsändarytan, mejlets landningssida och acceptflödet — är
 * 55b och prövas inte här.
 *
 * Den viktigaste gränsen i filen är den mellan sidans två sektioner: en
 * `read`-guest ser deltagarna, och `accesses`-propen är `null` för henne —
 * inte en tom lista, och inte en fylld som vyn låter bli att rendera. Ett test
 * som bara tittade på den renderade HTML:en hade missat precis det som gör
 * skillnad (Beslut 3).
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
 * Ett konto med en medlem och en pärm ägd av kontot.
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
 * Klart när: sidan renderar för en medlem i ägarkontot, med BÅDE Deltagare
 * och Åtkomster.
 */
it('renderar delningssidan med både deltagare och åtkomster för en medlem i ägarkontot', function () {
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
 * Klart när: en containerbred rad är "Hela pärmen" utan `reach`, och en
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
        // aldrig — "Hela pärmen" behöver inget tal.
        ->where('accesses.1.reach', null)

        ->where('itemNames', [$motorn->ulid => 'Motorn'])
    );

    // Texten: "Hela pärmen" för den breda raden, och itemets namn plus talet
    // för itemraden. Formuleringen bor i lang/, och valet av nyckel bor i
    // accessPresentation.js — EN gång, för både den giltiga och den
    // historiska listan.
    $sv = require lang_path('sv/ui.php');
    expect($sv['sharing']['scope']['container'])->toBe('Hela pärmen');
    expect($sv['sharing']['scope']['item'])->toContain(':item')->toContain(':reach');

    $beskrivning = File::get(resource_path('js/components/accessPresentation.js'));
    expect($beskrivning)->toContain("t('sharing.scope.container')");
    expect($beskrivning)->toContain("t('sharing.scope.item'");
    expect($beskrivning)->toContain('access.item === null');
});

/*
 * Klart när: en itemrad vars item är mjukraderat visar itemets namn, inte
 * "Hela pärmen". Uppslagningen sker med `withTrashed()` (Beslut 6) — utan
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
 * Klart när: en giltig åtkomstrad visar mottagarens och beviljarens namn.
 * Raden renderar etiketterna ur accessPresentation.js och aldrig ULID:en
 * själv — `access.grantee` är uppslagsnyckeln, inte texten.
 */
it('renderar namn i stället för ULID på en giltig rad', function () {
    $rad = File::get(resource_path('js/components/ContainerAccessRow.vue'));
    $beskrivning = File::get(resource_path('js/components/accessPresentation.js'));

    expect($rad)->toContain('granteeLabel(t, granteeNames, access)');
    expect($rad)->toContain('grantedByLabel(t, grantedByNames, access)');
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

    $sv = require lang_path('sv/ui.php');
    $en = require lang_path('en/ui.php');

    expect($sv['sharing']['accesses']['grantee_unknown'])->toBe('Borttagen mottagare');
    expect($en['sharing']['accesses']['grantee_unknown'])->not->toBe('');
    expect($sv['sharing']['accesses']['granted_by_unknown'])->not->toBe('');
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
 * Beslut 4: fyra nivåer, två synliga. `create` och `delete` ligger bakom en
 * <details>-yta — STÄNGD, inte dold, alltså renderad och nåbar med
 * tangentbordet, inte villkorad med v-if.
 */
it('lägger create och delete bakom Avancerat utan att gömma dem', function () {
    withoutVite();

    [, $anvandare, $container] = delningsKontext();

    actingAs($anvandare)->get("/containers/{$container->ulid}/sharing")->assertInertia(fn (AssertableInertia $page) => $page
        // Laddern kommer som prop ur AccessLevel::LADDER, aldrig som en
        // avskrift i JavaScript — samma teknik som Container::KINDS.
        ->where('levels', ['read', 'create', 'write', 'delete'])
    );

    $falt = File::get(resource_path('js/components/AccessLevelField.vue'));

    expect($falt)->toContain('<details');
    expect($falt)->toContain("t('sharing.advanced')");
    // Alla fyra nivåerna ritas ur `levels`, och bara de två avancerade står
    // innanför <details>. Ingen v-if någonstans i fältet.
    expect($falt)->toContain('v-for="level in common"');
    expect($falt)->toContain('v-for="level in advanced"');
    expect($falt)->not->toContain('v-if="advanced"');
    expect($falt)->toContain('t(`sharing.level.${level}.label`)');
    expect($falt)->toContain('t(`sharing.level.${level}.description`)');
});

it('visar nivåerna och deras beskrivningar på båda språken', function () {
    $sv = require lang_path('sv/ui.php');
    $en = require lang_path('en/ui.php');

    foreach (['read', 'create', 'write', 'delete'] as $niva) {
        foreach (['label', 'description'] as $falt) {
            expect($sv['sharing']['level'][$niva][$falt])->not->toBe('');
            expect($en['sharing']['level'][$niva][$falt])->not->toBe('');
        }
    }

    // Meningen om vad ingen nivå får göra står EN gång på sidan (Beslut 4).
    expect($sv['sharing']['accesses']['limits'])->toContain('ägarbyte');
});

/*
 * Klart när: PATCH sparar `level` för en medlem i ägarkontot, och nivån syns
 * ändrad efteråt.
 */
it('sparar nivån och visar den ändrad efteråt', function () {
    withoutVite();

    [, $anvandare, $container] = delningsKontext();
    $access = delningsAccess($container, User::factory()->create(), 'read');

    actingAs($anvandare)
        ->patch("/containers/{$container->ulid}/accesses/{$access->ulid}", ['level' => 'write'])
        ->assertRedirect()
        ->assertSessionHas('status', 'access-updated');

    expect($access->refresh()->level)->toBe('write');

    actingAs($anvandare)->get("/containers/{$container->ulid}/sharing")->assertInertia(fn (AssertableInertia $page) => $page
        ->where('accesses.0.level', 'write')
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
    $sv = require lang_path('sv/ui.php');
    $en = require lang_path('en/ui.php');

    expect($sv['sharing']['accesses']['expires_fixed'])->not->toBe('');
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

    expect($rad)->toContain('form.errors.expires_at');
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

    // Meningen finns på båda språken, och är inte felkoden själv.
    $sv = require lang_path('sv/ui.php');
    expect($sv['error']['container_access']['revoked'])->not->toBe('container_access.revoked');

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
 * Klart när: en åtkomst i en annan pärm går inte att nå via den här pärmens
 * rutt (404). `scopeBindings()` på de två skrivningarna, av samma skäl som
 * routes/api.php gör det (issue 9b § Beslut 1).
 */
it('når inte en åtkomst i en annan pärm via den här pärmens rutt', function () {
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
it('lägger delningssidan i pärmens navigation', function () {
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
 * åtkomster — den här listan är hela uppräkningen av POST-rutter under en pärm,
 * och det är hela poängen: den ska behöva ändras när en ny yta lägger till en.
 *
 * Sedan issue 56b finns även uppsättningens POST här. Den skapar vanliga
 * kategorier genom App\Actions\Category\CreateCategory och rör inga åtkomster
 * — den är alltså ytterligare ett exempel på motsatsen till en direkt
 * bevilning, inte ett undantag från regeln.
 *
 * Sedan issue 57b finns itemets POST här. Den skapar en item-rad och rör inga
 * åtkomster; att få SKAPA i en pärm och att få BEVILJA åtkomst till den är två
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
 * den som får lägga till en bilaga får varken se eller dela ut mer av pärmen.
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
 * pärmen. En `read`-deltagare ser schemalistan och får 403 här; en
 * `create`-deltagare lägger till men ändrar och raderar inget befintligt.
 *
 * Sedan issue 63b finns förekomstens två POST här. De stänger en `open`-rad och
 * öppnar nästa genom App\Actions\Schedule\CloseOccurrence, men rör inga
 * åtkomster: grinden är `update` på ITEMET (issue 71 § Beslut 5), så den som
 * får bocka av sin egen uppgift får varken se eller dela ut mer av pärmen. En
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
 */
it('har ingen rutt som beviljar en åtkomst i webben', function () {
    $rutter = collect(app('router')->getRoutes()->getRoutes());

    $poster = $rutter->filter(fn ($rutt) => $rutt->methods() === ['POST']
        && ($rutt->uri() === 'containers' || str_starts_with($rutt->uri(), 'containers/')));

    // Itemet, relationen, bilagan, schemat, förekomstens två avslut,
    // containerns eget skapande, inbjudan, kategorin, uppsättningen, taggen
    // och papperskorgen. Ingen /accesses. Ordningen är
    // registreringsordningen i routes/web.php — itemrutterna ligger ovanför
    // `POST /containers`.
    expect($poster->pluck('uri')->values()->all())->toBe([
        'containers/{container}/items',
        'containers/{container}/items/{item}/links',
        'containers/{container}/items/{item}/attachments',
        'containers/{container}/items/{item}/schedules',
        'containers/{container}/items/{item}/schedules/{schedule}/occurrences/{occurrence}/complete',
        'containers/{container}/items/{item}/schedules/{schedule}/occurrences/{occurrence}/skip',
        'containers/{container}/items/{item}/schedules/{schedule}/dependencies',
        'containers/{container}/items/{item}/schedules/{schedule}/occurrences/{occurrence}/dependencies',
        'containers',
        'containers/{container}/invitations',
        'containers/{container}/categories',
        'containers/{container}/categories/preset',
        'containers/{container}/tags',
        'containers/{container}/trash/restore',
    ]);

    foreach ([
        'containers.sharing',
        'containers.accesses.update',
        'containers.accesses.destroy',
        'containers.invitations.store',
        'containers.invitations.destroy',
    ] as $namn) {
        expect($rutter->first(fn ($rutt) => $rutt->getName() === $namn))->not->toBeNull();
    }
});
