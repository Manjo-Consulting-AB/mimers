<?php

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\CalendarFeed;
use App\Models\Category;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Invitation;
use App\Models\Item;
use App\Models\OwnershipTransfer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

/*
 * Issue 111 · Container- och åtkomsthändelserna. Varje skrivning på
 * containern själv, på det som hänger på den — kategorier, taggar och
 * kalenderflöden — och på åtkomsten, inbjudningarna och överlåtelsen loggas i
 * händelseloggen, genom App\Actions\Audit\RecordAuditEvent, i handlingens
 * transaktion. Se [[ADR-0043 Tre loggar]] § Händelseloggen och
 * App\Models\AuditLog för handlingarnas namn.
 *
 * Fyra saker prövas i nästan varje test och är lätta att tappa:
 *
 * 1. **Webben och `/api` skriver samma rad.** Nästan varje skrivning finns i
 *    båda ytorna, och instrumenteras de var för sig glider de isär. Sedan issue
 *    111 går båda genom samma Actions — CreateContainer, UpdateContainer,
 *    TrashContainer, RestoreTrashedContainer, CreateCategory, UpdateCategory,
 *    DeleteCategory, CreateTag, UpdateTag, DeleteTag, CreateCalendarFeed,
 *    RevokeCalendarFeed, GrantContainerAccess, UpdateContainerAccess,
 *    CreateInvitation, AcceptInvitation, RevokeInvitation, RejectInvitation,
 *    OfferOwnershipTransfer, RevokeOwnershipTransfer och
 *    RejectOwnershipTransfer.
 * 2. **Fritext följer aldrig med.** Containerns namn, beskrivning, kategorins
 *    namn, taggens namn och mottagarens e-postadress får bara finnas som
 *    fältnamn — `meta` säger VILKA fält som ändrades, inte vad som stod där.
 * 3. **Kalenderflödets token följer aldrig med**, varken klartexten eller
 *    hashen: loggen får inte bli en andra väg till feeden.
 * 4. **En ändring som inte ändrar något skriver ingen rad**, och en andra
 *    radering eller återkallelse är ingen handling alls.
 *
 * kontoMedMedlem(), Container::factory(), Category::factory() och
 * Item::factory() är de vanliga hjälparna; de fyra functionerna nedan är
 * lokala för den här filen.
 */

/**
 * Rader i händelseloggen för en container och en handling.
 *
 * @return Collection<int, stdClass>
 */
function containerhandelseRader(string $action, Container $container): Collection
{
    return DB::table('audit_log')
        ->where('container_id', $container->id)
        ->where('action', $action)
        ->get();
}

/**
 * Den enda raden för en container och en handling, som stdClass.
 */
function containerhandelseRad(string $action, Container $container): stdClass
{
    $rader = containerhandelseRader($action, $container);

    expect($rader)->toHaveCount(1);

    return $rader->first();
}

/**
 * `meta` på en rad, avkodad.
 *
 * @return array<string, mixed>
 */
function containerhandelseMeta(stdClass $rad): array
{
    return json_decode($rad->meta, true);
}

/**
 * Ett item i containern, med sammanhängande `created_by_*`.
 */
function containerhandelseItem(Container $container, User $skapare, string $namn = 'Motorn'): Item
{
    return Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => $skapare->id,
        'created_by_account_id' => $container->account_id,
    ]);
}

/**
 * Ger kontot en Pro-prenumeration — `ownership_transfer` är en planfunktion,
 * och kontrollerna nekar en överlåtelse utan den (issue 39a § Beslut 9).
 * Samma form som ägarbyteProKonto() i tests/Feature/Agarbyte/AgarbyteTest.php,
 * med ett eget namn så funktionerna inte kolliderar när hela sviten körs.
 */
function containerhandelsePro(Account $konto): void
{
    Subscription::factory()
        ->for($konto)
        ->for(Plan::where('code', 'pro')->firstOrFail())
        ->create();
}

/**
 * En inloggad användare med en given adress, plus ett Sanctum-headerpar.
 * Adressen behövs för inbjudningarnas adressmatchning.
 *
 * @return array{0: User, 1: array<string, string>} [$user, $headers]
 */
function containerhandelseMottagare(string $email, bool $verifierad = true): array
{
    $factory = User::factory();

    if (! $verifierad) {
        $factory = $factory->unverified();
    }

    $user = $factory->create(['email' => $email]);

    $token = $user->createToken('api');

    return [$user, ['Authorization' => "Bearer {$token->plainTextToken}"]];
}

/**
 * En `pending` inbjudan direkt i databasen, tillsammans med KLARTEXTTOKENET —
 * raden lagrar bara hashen (issue 10a § Beslut 5), och en riktig mottagare får
 * klartexten enbart i mejlet.
 *
 * @param  array<string, mixed>  $attribut
 * @return array{0: Invitation, 1: string} [$invitation, $rawToken]
 */
function containerhandelseInbjudan(Container $container, string $email, array $attribut = []): array
{
    $rawToken = Str::random(64);

    $invitation = Invitation::factory()->create(array_merge([
        'container_id' => $container->id,
        'email' => $email,
        'level' => 'write',
        'token_hash' => hash('sha256', $rawToken),
        'status' => 'pending',
        'expires_at' => now()->addDays(Invitation::TTL_DAYS),
        'invited_by_user_id' => User::factory()->create()->id,
    ], $attribut));

    return [$invitation, $rawToken];
}

it('varje skrivning på en container skriver exakt en rad via webben och via API:t', function () {
    // Två konton: en container räknas mot ägarkontots tak, och de två ytorna
    // prövas därför åt varsitt håll — annars hade den andra skapelsen fallit
    // på kvoten och inte på något issuen handlar om. API:t går först:
    // `actingAs()` sätter sessionens användare för resten av testet, och
    // Sanctums RequestGuard cachar den autentiserade användaren efter första
    // uppslaget (se tests/Feature/Notis/PreferensYtaTest.php).
    [$konto, $ägare, $headers] = kontoMedMedlem();
    [$apiKonto, $apiÄgare, $apiHeaders] = kontoMedMedlem();

    /*
     * API:t: skapa, ändra, radera, återställ — fyra handlingar, fyra rader.
     */
    $skapad = postJson('/api/containers', ['account' => $apiKonto->ulid, 'name' => 'Rigg'], $apiHeaders);
    $skapad->assertCreated();

    $api = Container::query()->where('ulid', $skapad->json('data.ulid'))->firstOrFail();

    patchJson("/api/containers/{$api->ulid}", ['name' => 'Rigg II'], $apiHeaders)->assertOk();
    deleteJson("/api/containers/{$api->ulid}", [], $apiHeaders)->assertNoContent();
    postJson('/api/trash/containers/restore', ['ulid' => $api->ulid], $apiHeaders)->assertOk();

    /*
     * Webben: samma fyra skrivningar på en andra container.
     */
    actingAs($ägare)->post('/containers', [
        'account' => $konto->ulid,
        'name' => 'Havsörnen',
        'kind' => 'boat',
    ])->assertRedirect();

    $webb = Container::query()->where('name', 'Havsörnen')->firstOrFail();

    actingAs($ägare)->patch("/containers/{$webb->ulid}", ['name' => 'Havsörnen II'])->assertRedirect();
    actingAs($ägare)->delete("/containers/{$webb->ulid}")->assertRedirect();
    actingAs($ägare)->post('/trash/containers/restore', ['ulid' => $webb->ulid])->assertRedirect();

    foreach ([[$webb, $konto, $ägare], [$api, $apiKonto, $apiÄgare]] as [$container, $förväntatKonto, $förväntadAnvändare]) {
        expect(containerhandelseRader(AuditLog::ACTION_CONTAINER_CREATED, $container))->toHaveCount(1);
        expect(containerhandelseRader(AuditLog::ACTION_CONTAINER_UPDATED, $container))->toHaveCount(1);
        expect(containerhandelseRader(AuditLog::ACTION_CONTAINER_DELETED, $container))->toHaveCount(1);
        expect(containerhandelseRader(AuditLog::ACTION_CONTAINER_RESTORED, $container))->toHaveCount(1);

        // Varje rad pekar ut containern den gäller, sitt konto och sin
        // användare. En containerhändelse hör aldrig till ett item.
        foreach (containerhandelseRader(AuditLog::ACTION_CONTAINER_CREATED, $container)
            ->merge(containerhandelseRader(AuditLog::ACTION_CONTAINER_UPDATED, $container))
            ->merge(containerhandelseRader(AuditLog::ACTION_CONTAINER_DELETED, $container))
            ->merge(containerhandelseRader(AuditLog::ACTION_CONTAINER_RESTORED, $container)) as $rad) {
            expect($rad->account_id)->toBe($förväntatKonto->id);
            expect($rad->user_id)->toBe($förväntadAnvändare->id);
            expect($rad->subject_type)->toBe('container');
            expect($rad->subject_id)->not->toBeNull();
            expect($rad->item_id)->toBeNull();
        }
    }

    // Åtta skrivningar, åtta rader.
    expect(DB::table('audit_log')->count())->toBe(8);

    // Ändringen bär fältets NAMN och inte dess innehåll: `changed` är `name`,
    // och det nya namnet finns inte i raden.
    $ändrad = containerhandelseRad(AuditLog::ACTION_CONTAINER_UPDATED, $webb);
    expect(containerhandelseMeta($ändrad))->toBe(['changed' => ['name']]);
    expect($ändrad->meta)->not->toContain('Havsörnen II');

    // En PATCH med samma namn ändrar ingenting och skriver ingen rad.
    actingAs($ägare)->patch("/containers/{$webb->ulid}", ['name' => 'Havsörnen II'])->assertRedirect();

    expect(containerhandelseRader(AuditLog::ACTION_CONTAINER_UPDATED, $webb))->toHaveCount(1);
    expect(DB::table('audit_log')->count())->toBe(8);
});

it('kategorier, taggar och kalenderflöden skriver en rad per skrivning', function () {
    [$konto, $ägare, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();

    /*
     * Kategorier — skapa, ändra och radera på båda ytorna.
     */
    actingAs($ägare)->post("/containers/{$container->ulid}/categories", ['name' => 'Verktyg'])->assertRedirect();
    $webbKategori = Category::query()->where('name', 'Verktyg')->firstOrFail();

    actingAs($ägare)->patch("/containers/{$container->ulid}/categories/{$webbKategori->ulid}", [
        'name' => 'Verktyg II',
        'parent' => null,
    ])->assertRedirect();

    actingAs($ägare)->delete("/containers/{$container->ulid}/categories/{$webbKategori->ulid}")->assertRedirect();

    $apiKategori = postJson("/api/containers/{$container->ulid}/categories", ['name' => 'Rigg'], $headers)
        ->assertCreated()
        ->json('data.ulid');

    patchJson("/api/containers/{$container->ulid}/categories/{$apiKategori}", ['name' => 'Rigg II'], $headers)->assertOk();
    deleteJson("/api/containers/{$container->ulid}/categories/{$apiKategori}", [], $headers)->assertNoContent();

    /*
     * Taggar — samma tre skrivningar på båda ytorna.
     */
    actingAs($ägare)->post("/containers/{$container->ulid}/tags", ['name' => 'Röd', 'color' => '#ff0000'])->assertRedirect();
    $webbTagg = Tag::query()->where('name', 'Röd')->firstOrFail();

    actingAs($ägare)->patch("/containers/{$container->ulid}/tags/{$webbTagg->ulid}", ['name' => 'Blå'])->assertRedirect();
    actingAs($ägare)->delete("/containers/{$container->ulid}/tags/{$webbTagg->ulid}")->assertRedirect();

    $apiTagg = postJson("/api/containers/{$container->ulid}/tags", ['name' => 'Grön'], $headers)
        ->assertCreated()
        ->json('data.ulid');

    patchJson("/api/containers/{$container->ulid}/tags/{$apiTagg}", ['color' => '#00ff00'], $headers)->assertOk();
    deleteJson("/api/containers/{$container->ulid}/tags/{$apiTagg}", [], $headers)->assertNoContent();

    /*
     * Kalenderflöden — utfärda och återkalla. Bara skapandet ger en token, och
     * den får aldrig hamna i loggen.
     */
    actingAs($ägare)->post("/containers/{$container->ulid}/calendar")->assertRedirect();

    $webbFeedRad = CalendarFeed::query()->where('container_id', $container->id)->firstOrFail();

    actingAs($ägare)->delete("/containers/{$container->ulid}/calendar/{$webbFeedRad->ulid}")->assertRedirect();

    $apiSvar = postJson("/api/containers/{$container->ulid}/calendar-feeds", [], $headers)->assertCreated();
    $apiToken = Str::between($apiSvar->json('url'), '/kalender/', '.ics');

    deleteJson("/api/containers/{$container->ulid}/calendar-feeds/{$apiSvar->json('data.ulid')}", [], $headers)
        ->assertNoContent();

    /*
     * Sex kategoriskrivningar, sex taggskrivningar, fyra flödesskrivningar.
     */
    expect(containerhandelseRader(AuditLog::ACTION_CATEGORY_CREATED, $container))->toHaveCount(2);
    expect(containerhandelseRader(AuditLog::ACTION_CATEGORY_UPDATED, $container))->toHaveCount(2);
    expect(containerhandelseRader(AuditLog::ACTION_CATEGORY_DELETED, $container))->toHaveCount(2);

    expect(containerhandelseRader(AuditLog::ACTION_TAG_CREATED, $container))->toHaveCount(2);
    expect(containerhandelseRader(AuditLog::ACTION_TAG_UPDATED, $container))->toHaveCount(2);
    expect(containerhandelseRader(AuditLog::ACTION_TAG_DELETED, $container))->toHaveCount(2);

    expect(containerhandelseRader(AuditLog::ACTION_CALENDAR_FEED_CREATED, $container))->toHaveCount(2);
    expect(containerhandelseRader(AuditLog::ACTION_CALENDAR_FEED_REVOKED, $container))->toHaveCount(2);

    expect(DB::table('audit_log')->where('container_id', $container->id)->count())->toBe(16);

    /*
     * Ändringarna bär fältens namn och inte deras innehåll: kategorins och
     * taggens namn står bara som `changed`, medan taggens färg — en värdelista
     * — bär gamla och nya värdet.
     */
    $kategoriÄndrad = containerhandelseRader(AuditLog::ACTION_CATEGORY_UPDATED, $container)->first();
    expect(containerhandelseMeta($kategoriÄndrad))->toBe(['changed' => ['name']]);
    expect($kategoriÄndrad->meta)->not->toContain('Rigg II');

    // Taggen ändrades i två steg — namnet via webben, färgen via API:t — och
    // varje rad bär sitt eget `changed`. Färgen är en värdelista och följer
    // med som gammalt och nytt värde; namnet gör det inte.
    $taggÄndrade = containerhandelseRader(AuditLog::ACTION_TAG_UPDATED, $container)
        ->map(fn (stdClass $rad): array => containerhandelseMeta($rad)['changed'])
        ->all();

    expect($taggÄndrade)->toEqualCanonicalizing([['name'], ['color']]);

    $färgÄndrad = containerhandelseRader(AuditLog::ACTION_TAG_UPDATED, $container)
        ->first(fn (stdClass $rad): bool => containerhandelseMeta($rad)['changed'] === ['color']);

    expect(containerhandelseMeta($färgÄndrad)['values'])->toBe(['color' => ['from' => null, 'to' => '#00ff00']]);
    expect($färgÄndrad->meta)->not->toContain('Röd');

    /*
     * Kalenderflödets token — klartexten ur svaret och hashen ur raden — finns
     * inte i någon av loggens rader. `meta` är tom, för ett flöde har
     * ingenting som är en värdelista, ett tal eller ett datum.
     */
    $apiFeed = CalendarFeed::query()
        ->where('container_id', $container->id)
        ->whereNotNull('revoked_at')
        ->where('ulid', '!=', $webbFeedRad->ulid)
        ->firstOrFail();

    $flödesRader = containerhandelseRader(AuditLog::ACTION_CALENDAR_FEED_CREATED, $container)
        ->merge(containerhandelseRader(AuditLog::ACTION_CALENDAR_FEED_REVOKED, $container));

    expect($flödesRader)->toHaveCount(4);

    foreach ($flödesRader as $flödesRad) {
        expect(containerhandelseMeta($flödesRad))->toBe([]);
        expect($flödesRad->meta)->not->toContain($apiToken);
        expect($flödesRad->meta)->not->toContain($apiFeed->token_hash);
        expect($flödesRad->meta)->not->toContain($webbFeedRad->token_hash);
    }

    // Item-kopplingen: ingen av raderna hör till ett item — kategorier, taggar
    // och flöden hänger på containern.
    expect(DB::table('audit_log')->whereNotNull('item_id')->count())->toBe(0);
});

it('en tillämpad kategorimall skriver en rad och en avfärdad ingen', function () {
    [$konto, $ägare] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();

    $svar = actingAs($ägare)->post("/containers/{$container->ulid}/categories/preset", [
        'categories' => [
            ['name' => 'Verktyg', 'children' => ['Skruvmejsel', 'Hammare']],
            ['name' => 'Rigg'],
        ],
    ]);

    $svar->assertRedirect();

    // Fyra kategorier skapades — och hela tillämpningen är EN rad.
    expect(Category::query()->where('container_id', $container->id)->count())->toBe(4);

    $rad = containerhandelseRad(AuditLog::ACTION_CATEGORY_TEMPLATE_APPLIED, $container);
    expect($rad->user_id)->toBe($ägare->id);
    expect($rad->subject_type)->toBe('container');
    expect($rad->subject_id)->toBe($container->ulid);
    expect(containerhandelseMeta($rad))->toBe(['categories' => 4]);

    // Ingen av kategorierna fick en egen rad — de är följden av handlingen,
    // inte handlingar (samma regel som förekomsten CloseOccurrence öppnar).
    expect(containerhandelseRader(AuditLog::ACTION_CATEGORY_CREATED, $container))->toHaveCount(0);
    expect(DB::table('audit_log')->where('container_id', $container->id)->count())->toBe(1);

    // "Nej tack" är ingen skrivning alls: ingen rad, varken för mallen eller
    // för avfärdandet.
    actingAs($ägare)->delete("/containers/{$container->ulid}/categories/preset")->assertRedirect();

    expect(DB::table('audit_log')->where('container_id', $container->id)->count())->toBe(1);
});

it('en inbjudan som skickas, återkallas, accepteras eller avböjs skriver en rad', function () {
    Notification::fake();

    [$konto, $ägare, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();

    /*
     * Skickas — via webben. Delningstaket är ett i förvalt plan, så den här
     * inbjudan måste komma före accepten nedan: efter den är mottagaren en
     * delad användare och en ny inbjudan hade fallit på kvoten i stället för
     * på något issuen handlar om.
     */
    actingAs($ägare)->post("/containers/{$container->ulid}/invitations", [
        'email' => 'mottagare@exempel.se',
        'level' => 'write',
    ])->assertRedirect();

    $skickad = Invitation::query()->where('email', 'mottagare@exempel.se')->firstOrFail();

    $skickadRad = containerhandelseRad(AuditLog::ACTION_INVITATION_CREATED, $container);
    expect($skickadRad->subject_type)->toBe('invitation');
    expect($skickadRad->subject_id)->toBe($skickad->ulid);
    expect($skickadRad->user_id)->toBe($ägare->id);
    expect($skickadRad->item_id)->toBeNull();
    expect(containerhandelseMeta($skickadRad))->toBe(['item' => null, 'level' => 'write']);

    // Adressen följer aldrig med (issue 40 § Beslut 10), och inte tokenet.
    expect($skickadRad->meta)->not->toContain('mottagare@exempel.se');
    expect($skickadRad->meta)->not->toContain('@');

    /*
     * Återkallas — via API:t, alltså samma Action och samma rad. Ägarens eget
     * token, och samma användare som `actingAs()` satte, så guardcachen
     * (nedan) spelar ingen roll här.
     */
    deleteJson("/api/containers/{$container->ulid}/invitations/{$skickad->ulid}", [], $headers)->assertNoContent();

    $återkalladRad = containerhandelseRad(AuditLog::ACTION_INVITATION_REVOKED, $container);
    expect($återkalladRad->subject_id)->toBe($skickad->ulid);
    expect($återkalladRad->user_id)->toBe($ägare->id);
    expect($återkalladRad->meta)->not->toContain('@');

    /*
     * Accepteras — en andra inbjudan, med mottagarens eget token.
     * `user_id` är MOTTAGAREN — accepten är hennes handling.
     *
     * `forgetGuards()`: Sanctums RequestGuard cachar den autentiserade
     * användaren efter första uppslaget, och utan den hade accepten nedan
     * svarat som ägaren — och adressmatchningen fallit.
     */
    auth()->forgetGuards();

    [$mottagare, $mottagarHeaders] = containerhandelseMottagare('accepterande@exempel.se');
    [$accepterad, $accepteradToken] = containerhandelseInbjudan($container, 'accepterande@exempel.se');

    postJson('/api/invitations/accept', ['token' => $accepteradToken], $mottagarHeaders)->assertOk();

    $accepteradRad = containerhandelseRad(AuditLog::ACTION_INVITATION_ACCEPTED, $container);
    expect($accepteradRad->subject_id)->toBe($accepterad->ulid);
    expect($accepteradRad->user_id)->toBe($mottagare->id);
    expect($accepteradRad->container_id)->toBe($container->id);
    expect(containerhandelseMeta($accepteradRad))->toBe(['item' => null, 'level' => 'write']);
    expect($accepteradRad->meta)->not->toContain('accepterande@exempel.se');

    /*
     * Avböjs — en tredje inbjudan, via webben.
     */
    [$avböjare] = containerhandelseMottagare('avbojande@exempel.se');
    [$avböjd, $avböjdToken] = containerhandelseInbjudan($container, 'avbojande@exempel.se');

    actingAs($avböjare)->post('/invitations/reject', ['token' => $avböjdToken])->assertRedirect();

    $avböjdRad = containerhandelseRad(AuditLog::ACTION_INVITATION_REJECTED, $container);
    expect($avböjdRad->subject_id)->toBe($avböjd->ulid);
    expect($avböjdRad->user_id)->toBe($avböjare->id);
    expect($avböjdRad->meta)->not->toContain('avbojande@exempel.se');

    // Fyra svar på fyra inbjudningar, fyra rader.
    expect(DB::table('audit_log')->where('container_id', $container->id)->count())->toBe(4);

    // En inbjudan som löper ut av sig själv är ingen handling: ingen rad
    // skrivs när tiden går.
    [$utgången] = containerhandelseInbjudan($container, 'utgangen@exempel.se', [
        'expires_at' => now()->subDay(),
    ]);

    expect(DB::table('audit_log')->where('container_id', $container->id)->count())->toBe(4);
    expect(DB::table('audit_log')->where('subject_id', $utgången->ulid)->count())->toBe(0);
});

it('en ändrad eller direkt beviljad åtkomst skriver en rad med nivå och typ men ingen e-post', function () {
    [$konto, $ägare, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();
    $mottagare = User::factory()->create();

    /*
     * Beviljas direkt — bara `/api` har den vägen; webben bjuder in.
     */
    postJson("/api/containers/{$container->ulid}/accesses", [
        'grantee_type' => 'user',
        'grantee' => $mottagare->ulid,
        'level' => 'write',
        'kind' => 'member',
    ], $headers)->assertCreated();

    $access = ContainerAccess::query()->where('container_id', $container->id)->firstOrFail();

    $beviljad = containerhandelseRad(AuditLog::ACTION_ACCESS_GRANTED, $container);
    expect($beviljad->subject_type)->toBe('container_access');
    expect($beviljad->subject_id)->toBe($access->ulid);
    expect($beviljad->user_id)->toBe($ägare->id);
    expect($beviljad->item_id)->toBeNull();

    // Samma `meta`-form som en återkallelse bär (issue 40 § Beslut 10):
    // mottagarens typ, ULID, nivå och sort — och aldrig en adress.
    $meta = containerhandelseMeta($beviljad);
    expect($meta)->toHaveKeys(['grantee_type', 'grantee', 'level', 'kind']);
    expect($meta['grantee_type'])->toBe('user');
    expect($meta['grantee'])->toBe($mottagare->ulid);
    expect($meta['level'])->toBe('write');
    expect($meta['kind'])->toBe('member');
    expect($beviljad->meta)->not->toContain('@');

    /*
     * Ändras — via webben, alltså samma Action och samma rad. Nivån är en
     * värdelista och bär gamla och nya värdet.
     */
    actingAs($ägare)->patch("/containers/{$container->ulid}/accesses/{$access->ulid}", ['level' => 'read'])
        ->assertRedirect();

    $ändrad = containerhandelseRad(AuditLog::ACTION_ACCESS_UPDATED, $container);
    expect($ändrad->subject_id)->toBe($access->ulid);
    expect($ändrad->user_id)->toBe($ägare->id);
    expect($ändrad->meta)->not->toContain('@');

    // Kontraktet för issue 116: `level` i basmetan är det NYA värdet, och
    // `values` bär paret gammalt→nytt. Raden läses alltså utan att slå upp
    // paret, och paret finns där historiken behöver det.
    $ändradMeta = containerhandelseMeta($ändrad);
    expect($ändradMeta['grantee_type'])->toBe('user');
    expect($ändradMeta['grantee'])->toBe($mottagare->ulid);
    expect($ändradMeta['kind'])->toBe('member');
    expect($ändradMeta['level'])->toBe('read');
    expect($ändradMeta['changed'])->toBe(['level']);
    expect($ändradMeta['values'])->toBe(['level' => ['from' => 'write', 'to' => 'read']]);
    expect($ändradMeta['level'])->toBe($ändradMeta['values']['level']['to']);

    // En PATCH med samma nivå ändrar ingenting och skriver ingen rad.
    actingAs($ägare)->patch("/containers/{$container->ulid}/accesses/{$access->ulid}", ['level' => 'read'])
        ->assertRedirect();

    expect(containerhandelseRader(AuditLog::ACTION_ACCESS_UPDATED, $container))->toHaveCount(1);
});

it('en inbjudan till ett item sätter item_id', function () {
    Notification::fake();

    [$konto, $ägare, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();
    $item = containerhandelseItem($container, $ägare);

    postJson("/api/containers/{$container->ulid}/invitations", [
        'email' => 'item@exempel.se',
        'level' => 'read',
        'item' => $item->ulid,
    ], $headers)->assertCreated();

    $rad = containerhandelseRad(AuditLog::ACTION_INVITATION_CREATED, $container);
    expect($rad->item_id)->toBe($item->id);
    expect($rad->container_id)->toBe($container->id);
    expect(containerhandelseMeta($rad)['item'])->toBe($item->ulid);
    expect($rad->meta)->not->toContain('item@exempel.se');

    /*
     * Varje svar på samma inbjudan bär samma item — återkallelsen, liksom
     * avböjandet. `item_id` sätts oavsett subjekt.
     */
    [$inbjudan] = containerhandelseInbjudan($container, 'item@exempel.se', ['item_id' => $item->id]);

    deleteJson("/api/containers/{$container->ulid}/invitations/{$inbjudan->ulid}", [], $headers)->assertNoContent();

    $återkallad = containerhandelseRad(AuditLog::ACTION_INVITATION_REVOKED, $container);
    expect($återkallad->item_id)->toBe($item->id);
    expect($återkallad->subject_id)->toBe($inbjudan->ulid);

    // ... och en iteminbjudan som avböjs.
    [$avböjare] = containerhandelseMottagare('itemavbojare@exempel.se');
    [, $avböjdToken] = containerhandelseInbjudan($container, 'itemavbojare@exempel.se', [
        'item_id' => $item->id,
    ]);

    actingAs($avböjare)->post('/invitations/reject', ['token' => $avböjdToken])->assertRedirect();

    $avböjd = containerhandelseRad(AuditLog::ACTION_INVITATION_REJECTED, $container);
    expect($avböjd->item_id)->toBe($item->id);
});

it('en överlåtelse som erbjuds, återkallas eller avböjs skriver en rad', function () {
    Notification::fake();

    [$säljarkonto, $säljare, $headers] = kontoMedMedlem();
    containerhandelsePro($säljarkonto);
    $container = Container::factory()->for($säljarkonto, 'account')->create();

    /*
     * Avböjs — först, med mottagarens eget token. `actingAs()` längre ned
     * sätter sessionens användare för resten av testet, och mottagaren av en
     * överlåtelse är en ANNAN användare än säljaren: kastas ordningen om
     * svarar anropet som säljaren, och mottagarens inkorg är tom.
     *
     * `user_id` är MOTTAGAREN — avböjandet är hans handling.
     */
    [$köparkonto, $köpare, $köparHeaders] = kontoMedMedlem();

    postJson("/api/containers/{$container->ulid}/transfers", [
        'to_account' => $köparkonto->ulid,
    ], $headers)->assertCreated();

    $motKonto = OwnershipTransfer::query()
        ->where('container_id', $container->id)
        ->where('status', 'pending')
        ->firstOrFail();

    // `forgetGuards()`: Sanctums RequestGuard cachar den autentiserade
    // användaren efter första uppslaget, och utan den hade mottagarens begäran
    // nedan fortfarande varit säljarens.
    auth()->forgetGuards();

    postJson("/api/transfers/{$motKonto->ulid}/reject", [], $köparHeaders)->assertNoContent();

    // Erbjudandet mot ett KONTO bär kontots ULID och typen `account` — samma
    // form som adressvägen bär, utan att någonsin bära en adress.
    $motKontoErbjuden = containerhandelseRad(AuditLog::ACTION_OWNERSHIP_TRANSFER_OFFERED, $container);
    expect($motKontoErbjuden->subject_id)->toBe($motKonto->ulid);
    expect(containerhandelseMeta($motKontoErbjuden))->toBe([
        'recipient_type' => 'account',
        'recipient' => $köparkonto->ulid,
        'retain_access_level' => null,
        'excluded_item_count' => 0,
    ]);

    $avböjd = containerhandelseRad(AuditLog::ACTION_OWNERSHIP_TRANSFER_REJECTED, $container);
    expect($avböjd->subject_type)->toBe('ownership_transfer');
    expect($avböjd->subject_id)->toBe($motKonto->ulid);
    expect($avböjd->user_id)->toBe($köpare->id);
    expect($avböjd->item_id)->toBeNull();
    expect(containerhandelseMeta($avböjd))->toBe([]);

    /*
     * Erbjuds — via webben, mot en adress. Adressen får aldrig hamna i loggen:
     * `meta` bär mottagarens TYP och kontots ULID, och typen säger vilken väg
     * det gick.
     */
    actingAs($säljare)->post("/containers/{$container->ulid}/transfer", [
        'to_email' => 'Kopare@Exempel.se',
        'retain_access_level' => 'read',
    ])->assertRedirect();

    $motAdress = OwnershipTransfer::query()
        ->where('container_id', $container->id)
        ->where('status', 'pending')
        ->firstOrFail();

    $erbjuden = containerhandelseRader(AuditLog::ACTION_OWNERSHIP_TRANSFER_OFFERED, $container)
        ->first(fn (stdClass $rad): bool => $rad->subject_id === $motAdress->ulid);

    expect($erbjuden->user_id)->toBe($säljare->id);
    expect(containerhandelseMeta($erbjuden))->toBe([
        'recipient_type' => 'email',
        'recipient' => null,
        'retain_access_level' => 'read',
        'excluded_item_count' => 0,
    ]);
    expect($erbjuden->meta)->not->toContain('@');
    expect($erbjuden->meta)->not->toContain('exempel.se');

    /*
     * Återkallas — via webben.
     */
    actingAs($säljare)->delete("/containers/{$container->ulid}/transfer/{$motAdress->ulid}")->assertRedirect();

    $återkallad = containerhandelseRad(AuditLog::ACTION_OWNERSHIP_TRANSFER_REVOKED, $container);
    expect($återkallad->subject_id)->toBe($motAdress->ulid);
    expect($återkallad->user_id)->toBe($säljare->id);
    expect(containerhandelseMeta($återkallad))->toBe([]);

    /*
     * Fyra handlingar, fyra rader: två erbjudanden (ett mot ett konto, ett mot
     * en adress, båda via `/api` respektive webben) och ett svar på var och en.
     * `container.transferred` — accepten, issue 40 — rörs inte av den här
     * issuen.
     */
    expect(containerhandelseRader(AuditLog::ACTION_OWNERSHIP_TRANSFER_OFFERED, $container))->toHaveCount(2);
    expect(DB::table('audit_log')->where('container_id', $container->id)->count())->toBe(4);
    expect(containerhandelseRader(AuditLog::ACTION_CONTAINER_TRANSFERRED, $container))->toHaveCount(0);
});
