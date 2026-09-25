<?php

use App\Http\Controllers\InvitationResponseController;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Invitation;
use App\Models\User;
use App\Support\Frontend\ActiveContainer;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\withoutVite;

/*
 * Issue 131 · Väntande inbjudningar syns för inloggade, se [[M20 Kontot]]
 * § 131, App\Http\Controllers\InvitationResponseController,
 * App\Support\Invitation\PendingInvitation,
 * App\Http\Middleware\HandleInertiaRequests,
 * resources/js/pages/Invitations/Show.vue och
 * resources/js/components/NotificationBell.vue.
 *
 * **Filen prövar den andra vägen in och att den ger exakt samma sak som den
 * första.** En inbjudan nådde förut bara mottagaren som mejl; här hittar hon
 * sina väntande inbjudningar på sin VERIFIERADE adress, och svarar på dem med
 * inbjudans ULID i stället för med tokenet. `AcceptInvitation` och
 * `RejectInvitation` anropas oförändrade, och det bevisas bäst genom att
 * jämföra raderna de två vägarna ger — inte genom att läsa koden.
 *
 * **Tokenvägen prövas oförändrad i tests/Feature/Frontend/InbjudanMottagareTest.**
 * Här prövas bara att den INTE påverkas av den nya grenen: ligger ett token i
 * sessionen renderas `ready` och ingen lista.
 *
 * **Verifieringen är hela identitetsbeviset** när ingen token finns
 * ([[ADR-0003 Åtkomstmodell]], [[ADR-0011 Autentisering]]): en overifierad
 * adress är inte bevisat hennes, och därför ser hon ingen lista — varken på
 * `/invitations` eller i klockan.
 *
 * **Ett gissat ULID avslöjar ingenting.** En rad som inte är användarens, en
 * utgången, en besvarad och en återkallad ger alla 404, precis som ett okänt
 * ulid — samma resonemang som tokenvägens `unavailable`.
 *
 * Hjälparna har prefixet `vantande` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * En container i ett konto, utan inloggad mottagare.
 */
function vantandeParm(): Container
{
    return Container::factory()->for(Account::factory()->create(), 'account')->create();
}

/**
 * En `pending` inbjudan till adressen, med en inbjudare testet väljer.
 *
 * Returnerar KLARTEXTTOKENET också — raden lagrar bara hashen (issue 10a
 * § Beslut 5), och jämförelseprovet nedan behöver båda vägarna in.
 *
 * @param  array<string, mixed>  $overrides
 * @return array{0: Invitation, 1: string} [$invitation, $rawToken]
 */
function vantandeInbjudan(Container $container, User $inbjudare, string $email, array $overrides = []): array
{
    $rawToken = Str::random(64);

    $invitation = Invitation::factory()->create(array_merge([
        'container_id' => $container->id,
        'email' => $email,
        'level' => 'read',
        'token_hash' => hash('sha256', $rawToken),
        'status' => 'pending',
        'expires_at' => now()->addDays(Invitation::TTL_DAYS),
        'invited_by_user_id' => $inbjudare->id,
    ], $overrides));

    return [$invitation, $rawToken];
}

/**
 * Klockans två listor, hämtade som klienten hämtar dem: en partiell omladdning
 * av de optionala propparna.
 *
 * Versionsheadern är den samma middleware skulle svara med — ett anrop med fel
 * version är 409, och det är inte det här provet handlar om.
 *
 * @return array<string, mixed>
 */
function vantandeKlockan(User $anvandare): array
{
    $svar = actingAs($anvandare)->get('/dashboard', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(Request::create('/')),
        'X-Inertia-Partial-Component' => 'Dashboard',
        'X-Inertia-Partial-Data' => 'notifications,pendingInvitations',
    ]);

    $svar->assertOk();

    return $svar->json('props');
}

/**
 * En mottagare med en inbjudan till sin egen adress, och containern den gäller.
 *
 * @return array{0: User, 1: Invitation, 2: Container, 3: User} [$mottagare, $inbjudan, $container, $inbjudare]
 */
function vantandeMottagare(string $adress = 'ny@exempel.se'): array
{
    $inbjudare = User::factory()->create(['name' => 'Inbjudaren']);
    $container = vantandeParm();
    $container->update(['name' => 'Vindö 40']);

    [$inbjudan] = vantandeInbjudan($container, $inbjudare, $adress);

    return [User::factory()->create(['email' => $adress]), $inbjudan, $container, $inbjudare];
}

/*
 * Klart när: en inloggad, verifierad användare ser sina väntande inbjudningar
 * på `/invitations` utan token.
 *
 * Listan bär containerns namn, inbjudarens namn och nivån — och ALDRIG
 * adressen, som inte finns i proppen alls (samma regel som
 * InvitationResponseController::preview()).
 */
it('visar en verifierad användares väntande inbjudningar utan token', function () {
    withoutVite();

    [$mottagare, $inbjudan, $container] = vantandeMottagare('hemlig.adress@example.test');

    $svar = actingAs($mottagare)->get('/invitations');

    $svar->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Invitations/Show')
        ->where('state', 'pending')
        ->has('invitations', 1)
        ->where('invitations.0.ulid', $inbjudan->ulid)
        ->where('invitations.0.container', $container->name)
        ->where('invitations.0.inviter', 'Inbjudaren')
        ->where('invitations.0.level', 'read')
        // Varken token eller förhandsvisning: inget av dem hör till listan.
        ->where('token', null)
        ->where('invitation', null)
        // Listan bär ingen adress — den finns i `auth.user.email` därför att
        // det är hennes egen, och den läggs inte till en andra gång här.
        ->missing('invitations.0.email')
    );
});

/*
 * Klart när: en utgången, återkallad eller besvarad inbjudan syns inte.
 *
 * Utgången härleds ur `expires_at` och aldrig ur `status` (issue 10a
 * § Beslut 7), så en `pending`-rad som passerat sitt datum faller bort utan
 * att någon kolumn ändras — och det är samma villkor som
 * Invitation::scopeOutstanding() formulerar för tokenvägen.
 */
it('visar inte en utgången, återkallad eller besvarad inbjudan', function () {
    withoutVite();

    $inbjudare = User::factory()->create();
    $mottagare = User::factory()->create(['email' => 'ny@exempel.se']);

    [$väntande] = vantandeInbjudan(vantandeParm(), $inbjudare, 'ny@exempel.se');

    vantandeInbjudan(vantandeParm(), $inbjudare, 'ny@exempel.se', ['expires_at' => now()->subDay()]);
    vantandeInbjudan(vantandeParm(), $inbjudare, 'ny@exempel.se', ['status' => 'revoked']);
    vantandeInbjudan(vantandeParm(), $inbjudare, 'ny@exempel.se', ['status' => 'accepted']);
    vantandeInbjudan(vantandeParm(), $inbjudare, 'ny@exempel.se', ['status' => 'rejected']);

    actingAs($mottagare)->get('/invitations')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('state', 'pending')
        ->has('invitations', 1)
        ->where('invitations.0.ulid', $väntande->ulid)
    );
});

/*
 * En container som hamnat i papperskorgen sedan inbjudan skickades syns
 * inte. `whereHas('container')` i PendingInvitation::forUser() bär Containers
 * globala SoftDeletes-scope — utan den hade raden ritats med `container` som
 * `null` och fällt vyn, och en inbjudan till något som inte längre finns är
 * ingenting att svara på. Samma villkor som ägarbytets inkorg ställer.
 */
it('visar inte en inbjudan till en container i papperskorgen', function () {
    withoutVite();

    $inbjudare = User::factory()->create();
    $mottagare = User::factory()->create(['email' => 'ny@exempel.se']);

    [$väntande] = vantandeInbjudan(vantandeParm(), $inbjudare, 'ny@exempel.se');

    $skräpad = vantandeParm();
    vantandeInbjudan($skräpad, $inbjudare, 'ny@exempel.se');
    $skräpad->delete();

    actingAs($mottagare)->get('/invitations')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('invitations', 1)
            ->where('invitations.0.ulid', $väntande->ulid)
    );
});

/*
 * Klart när: en overifierad användare ser ingen lista.
 *
 * Verifieringen är vad som ersätter tokenet — utan den finns inget bevis för
 * att adressen är hennes ([[ADR-0003 Åtkomstmodell]]). Tillståndet blir
 * `unverified`, och AppLayouts banner säger varför; proppen `invitations` är
 * `null` och inte en tom lista, för ingen fråga ställdes.
 */
it('visar ingen lista för en overifierad användare', function () {
    withoutVite();

    // Ingen mottagare med den adressen skapas här: den overifierade ÄR
    // mottagaren, och `user.email` är unik.
    [$inbjudan] = vantandeInbjudan(vantandeParm(), User::factory()->create(), 'ny@exempel.se');
    $overifierad = User::factory()->unverified()->create(['email' => 'ny@exempel.se']);

    $svar = actingAs($overifierad)->get('/invitations');

    $svar->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('state', 'unverified')
        ->where('invitations', null)
    );

    // Och raden ligger kvar: den är inte ogiltig, den är bara inte visad.
    expect($svar->getContent())->not->toContain($inbjudan->ulid)
        ->and($inbjudan->refresh()->status)->toBe('pending');
});

/*
 * Rutten ligger utanför `auth`-gruppen: en utloggad besökare på
 * `/invitations` möts av det neutrala beskedet och inte av inloggningssidan —
 * utan token finns varken adress eller inbjudan att visa.
 */
it('ger en gäst utan token det neutrala beskedet', function () {
    withoutVite();

    [$mottagare, $inbjudan] = vantandeMottagare();

    $svar = get('/invitations');

    $svar->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('state', 'unavailable')
        ->where('invitations', null)
    );

    expect($svar->getContent())->not->toContain($inbjudan->ulid)
        ->and($mottagare->email)->toBe('ny@exempel.se');
});

/*
 * Klart när: tokenvägen fungerar oförändrad.
 *
 * Ligger ett token i sessionen gäller 55b § Beslut 3 som förut — `ready` med
 * formulären — och listan ritas inte alls, även när användaren har andra
 * väntande inbjudningar. Det är den uttalade avvägningen i § 131: tokenet
 * vinner när det finns.
 */
it('renderar ready och ingen lista när ett token ligger i sessionen', function () {
    withoutVite();

    [$mottagare, $inbjudan, $container] = vantandeMottagare();

    // En andra, väntande inbjudan som listan hade visat — om den fick.
    vantandeInbjudan(vantandeParm(), User::factory()->create(), 'ny@exempel.se');

    [$medToken, $rawToken] = vantandeInbjudan($container, User::factory()->create(), 'ny@exempel.se');

    actingAs($mottagare)
        ->withSession([InvitationResponseController::SESSION_KEY => $rawToken])
        ->get('/invitations')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('state', 'ready')
            ->where('invitation.container', $container->name)
            ->where('token', $rawToken)
            ->where('invitations', null)
        );

    // `$inbjudan` och `$medToken` är två rader; bara den ena bär tokenet.
    expect($inbjudan->ulid)->not->toBe($medToken->ulid);
});

/*
 * Klart när: accept via `ulid` ger samma `container_access` som accept via
 * token.
 *
 * Jämförelsen är rad för rad och inte en avskrift av förväntade värden: det
 * är hela beviset för att `AcceptInvitation` anropas oförändrad. Samma
 * inbjudare i båda fallen, så `granted_by_user_id` är jämförbart — den ska
 * peka på den som DELEGERADE och inte på den som klickade.
 */
it('ger samma container_access via ulid som via token', function () {
    withoutVite();

    $inbjudare = User::factory()->create();
    $mottagare = User::factory()->create(['email' => 'ny@exempel.se']);

    // Väg ett: tokenet ur mejlet.
    $tokenParm = vantandeParm();
    [, $rawToken] = vantandeInbjudan($tokenParm, $inbjudare, 'ny@exempel.se', ['level' => 'write']);

    actingAs($mottagare)
        ->post('/invitations/accept', ['token' => $rawToken])
        ->assertRedirect('/containers');

    // Väg två: ULID:n ur listan.
    $ulidParm = vantandeParm();
    [$ulidInbjudan] = vantandeInbjudan($ulidParm, $inbjudare, 'ny@exempel.se', ['level' => 'write']);

    actingAs($mottagare)
        ->post("/invitations/{$ulidInbjudan->ulid}/accept")
        ->assertRedirect('/containers')
        ->assertSessionHas('status', 'invitation-accepted');

    $vardena = fn (ContainerAccess $access): array => Arr::except(
        $access->getAttributes(),
        ['id', 'ulid', 'container_id', 'created_at', 'updated_at'],
    );

    expect($vardena(ContainerAccess::query()->where('container_id', $ulidParm->id)->sole()))
        ->toBe($vardena(ContainerAccess::query()->where('container_id', $tokenParm->id)->sole()))
        ->and($ulidInbjudan->refresh()->status)->toBe('accepted')
        // Den som just fått en container ska landa i den (55b § Beslut 4).
        ->and(session(ActiveContainer::SESSION_KEY))->toBe($ulidParm->ulid);
});

/*
 * Klart när: avvisande via `ulid` ger samma status som via token — och inget
 * `container_access`, för att tacka nej ger ingen behörighet.
 */
it('ger samma status vid avvisande via ulid som via token', function () {
    withoutVite();

    $inbjudare = User::factory()->create();
    $mottagare = User::factory()->create(['email' => 'ny@exempel.se']);

    [, $rawToken] = vantandeInbjudan(vantandeParm(), $inbjudare, 'ny@exempel.se');

    actingAs($mottagare)->post('/invitations/reject', ['token' => $rawToken]);

    [$viaUlid] = vantandeInbjudan(vantandeParm(), $inbjudare, 'ny@exempel.se');

    actingAs($mottagare)
        ->post("/invitations/{$viaUlid->ulid}/reject")
        ->assertRedirect('/invitations')
        ->assertSessionHas('status', 'invitation-rejected');

    expect($viaUlid->refresh()->status)->toBe('rejected')
        ->and(ContainerAccess::query()->count())->toBe(0);
});

/*
 * Klart när: en annan användares inbjudan ger 404 vid accept och avvisande,
 * lika som ett okänt ulid.
 *
 * Ett gissat ulid ska inte avslöja att inbjudan finns — 403 hade bekräftat
 * den. Samma resonemang som tokenvägens `unavailable`, och samma svar som
 * ägarbytets mottagarrutter ger (issue 67b § Beslut 8).
 */
it('ger 404 för en annan användares inbjudan, lika som för ett okänt ulid', function () {
    withoutVite();

    $annan = User::factory()->create(['email' => 'nagon.annan@exempel.se']);
    [$agare, $inbjudan] = vantandeMottagare();

    actingAs($annan)->post("/invitations/{$inbjudan->ulid}/accept")->assertNotFound();
    actingAs($annan)->post("/invitations/{$inbjudan->ulid}/reject")->assertNotFound();

    actingAs($agare)->post('/invitations/'.Str::ulid().'/accept')->assertNotFound();

    // Ingenting hände med raden, och ingen åtkomst skapades.
    expect($inbjudan->refresh()->status)->toBe('pending')
        ->and(ContainerAccess::query()->count())->toBe(0);
});

/*
 * Klart när: det gäller OCKSÅ en utgången, besvarad eller återkallad inbjudan.
 *
 * Till skillnad från ägarbytets accept — som svarar `not_pending` och
 * `expired` — svarar ulid-vägen 404 på allt den inte får svara på. Det är
 * avvägningen i § 131: raden är osynlig för den som inte kan svara på den,
 * och "redan besvarad" hade berättat att den funnits.
 */
it('ger 404 för en utgången, besvarad eller återkallad inbjudan', function () {
    withoutVite();

    $inbjudare = User::factory()->create();
    $mottagare = User::factory()->create(['email' => 'ny@exempel.se']);

    $utgangen = vantandeInbjudan(vantandeParm(), $inbjudare, 'ny@exempel.se', ['expires_at' => now()->subDay()])[0];
    $besvarad = vantandeInbjudan(vantandeParm(), $inbjudare, 'ny@exempel.se', ['status' => 'accepted'])[0];
    $tillbakadragen = vantandeInbjudan(vantandeParm(), $inbjudare, 'ny@exempel.se', ['status' => 'revoked'])[0];

    foreach ([$utgangen, $besvarad, $tillbakadragen] as $rad) {
        actingAs($mottagare)->post("/invitations/{$rad->ulid}/accept")->assertNotFound();
        actingAs($mottagare)->post("/invitations/{$rad->ulid}/reject")->assertNotFound();
    }

    expect($utgangen->refresh()->status)->toBe('pending')
        ->and($besvarad->refresh()->status)->toBe('accepted')
        ->and($tillbakadragen->refresh()->status)->toBe('revoked')
        ->and(ContainerAccess::query()->count())->toBe(0);
});

/*
 * Klart när: adressjämförelsen är skiftlägesokänslig på samma sätt som
 * tokenvägens.
 *
 * Inbjudningens adress normaliseras vid lagring (10a,
 * App\Actions\Invitation\CreateInvitation), så den lagrade raden står i
 * gemener och det som varierar är användarens `User::email`. Provet lowercasar
 * därför användarens adress i två former mot samma lagrade gemen-adress.
 * `forUser()` avgör vad som är SYNLIGT, `mb_strtolower()` i
 * PendingInvitation::assert() vad som är TILLÅTET — och båda prövas här, för
 * glider de isär syns inbjudan men går inte att svara på.
 *
 * `forUser()` jämför `email = mb_strtolower($user->email)`: på MariaDB är
 * kolumnen `utf8mb4_unicode_ci` och `=` skiftlägesokänsligt i sig, vilket är
 * det som håller `(email, status)` användbart. sqlite, som CI kör, jämför `=`
 * skiftlägeskänsligt, så där bär det lowercasade värdet hela likheten — samma
 * drivrutinskillnad som ItemSokTest dokumenterar.
 */
it('jämför användarens adress skiftlägesokänsligt', function () {
    withoutVite();

    $inbjudare = User::factory()->create();

    $forstaInbjudan = vantandeInbjudan(vantandeParm(), $inbjudare, 'ny@exempel.se')[0];
    $andraInbjudan = vantandeInbjudan(vantandeParm(), $inbjudare, 'annan@exempel.se')[0];

    $versalMottagare = User::factory()->create(['email' => 'NY@EXEMPEL.SE']);
    $blandadMottagare = User::factory()->create(['email' => 'Annan@Exempel.SE']);

    actingAs($versalMottagare)->get('/invitations')->assertInertia(fn (AssertableInertia $page) => $page
        ->has('invitations', 1)
        ->where('invitations.0.ulid', $forstaInbjudan->ulid)
    );

    actingAs($versalMottagare)->post("/invitations/{$forstaInbjudan->ulid}/accept")->assertRedirect('/containers');

    actingAs($blandadMottagare)->get('/invitations')->assertInertia(fn (AssertableInertia $page) => $page
        ->has('invitations', 1)
        ->where('invitations.0.ulid', $andraInbjudan->ulid)
    );

    actingAs($blandadMottagare)->post("/invitations/{$andraInbjudan->ulid}/reject")->assertRedirect('/invitations');

    expect($forstaInbjudan->refresh()->status)->toBe('accepted')
        ->and($andraInbjudan->refresh()->status)->toBe('rejected');
});

/*
 * Klart när: klockan visar en rad per väntande inbjudan och länkar till
 * `/invitations`.
 *
 * Raden läses ur `invitation` och inte ur `notification`: ingen notisrad
 * skrivs för en inbjudan, och `Notification::TYPE_INVITATION_RECEIVED` har
 * fortfarande ingen skrivare. Adressen byggs på servern (issue 51 § Beslut 7).
 */
it('visar en rad per väntande inbjudan i klockan, med länk till /invitations', function () {
    $inbjudare = User::factory()->create(['name' => 'Inbjudaren']);
    $container = vantandeParm();
    $container->update(['name' => 'Vindö 40']);

    $mottagare = User::factory()->create(['email' => 'ny@exempel.se']);

    vantandeInbjudan($container, $inbjudare, 'ny@exempel.se');
    vantandeInbjudan(vantandeParm(), $inbjudare, 'ny@exempel.se');
    // De tre som inte väntar ska inte ge någon rad.
    vantandeInbjudan(vantandeParm(), $inbjudare, 'ny@exempel.se', ['expires_at' => now()->subDay()]);
    vantandeInbjudan(vantandeParm(), $inbjudare, 'ny@exempel.se', ['status' => 'accepted']);
    vantandeInbjudan(vantandeParm(), $inbjudare, 'nagon.annan@exempel.se');

    $props = vantandeKlockan($mottagare);

    expect($props['pendingInvitations'])->toHaveCount(2)
        ->and($props['pendingInvitations'][0])->toMatchArray([
            'container' => $container->name,
            'inviter' => 'Inbjudaren',
            'url' => '/invitations',
        ])
        // Ingen notisrad finns, så listan är tom medan inbjudningarna syns.
        ->and($props['notifications'])->toBe([]);
});

/*
 * Siffran räknar dem. Frågan går på indexet `(email, status)`, och en
 * inbjudan är något användaren behöver svara på.
 */
it('räknar inbjudningarna i klockans siffra', function () {
    withoutVite();

    $inbjudare = User::factory()->create();
    $mottagare = User::factory()->create(['email' => 'ny@exempel.se']);

    vantandeInbjudan(vantandeParm(), $inbjudare, 'ny@exempel.se');
    vantandeInbjudan(vantandeParm(), $inbjudare, 'ny@exempel.se');

    // Gästen först: actingAs() sätter guardens användare för resten av testet.
    get('/')->assertInertia(fn (AssertableInertia $page) => $page->where('unreadNotificationCount', 0));

    actingAs($mottagare)->get('/dashboard')->assertInertia(
        fn (AssertableInertia $page) => $page->where('unreadNotificationCount', 2)
    );

    // En utgången räknas inte.
    vantandeInbjudan(vantandeParm(), $inbjudare, 'ny@exempel.se', ['expires_at' => now()->subDay()]);

    actingAs($mottagare)->get('/dashboard')->assertInertia(
        fn (AssertableInertia $page) => $page->where('unreadNotificationCount', 2)
    );
});

/*
 * En overifierad användare ser ingen lista i klockan heller — samma regel som
 * på `/invitations`, och av samma skäl: adressen är inte bevisat hennes.
 */
it('räknar inte en inbjudan för en overifierad adress', function () {
    withoutVite();

    $inbjudare = User::factory()->create();
    $overifierad = User::factory()->unverified()->create(['email' => 'ny@exempel.se']);

    vantandeInbjudan(vantandeParm(), $inbjudare, 'ny@exempel.se');

    actingAs($overifierad)->get('/dashboard')->assertInertia(
        fn (AssertableInertia $page) => $page->where('unreadNotificationCount', 0)
    );

    expect(vantandeKlockan($overifierad)['pendingInvitations'])->toBe([]);
});

/*
 * Klart när: strängarna ligger i `lang/en/ui.php`.
 *
 * Listans tre egna nycklar och klockans radmening. Meningsbyggnaden prövas i
 * SprakTest (`inbox.invitation.received`), som läser nycklarna ur källkoden i
 * stället för ur en avskrift — här prövas att de finns och att ingen av dem är
 * tom.
 */
it('har listans och klockradens strängar i ui.php', function () {
    $ui = require lang_path('en/ui.php');

    foreach (['title', 'heading', 'empty'] as $nyckel) {
        expect($ui['invitation']['pending'][$nyckel] ?? '')->not->toBe('');
    }

    expect($ui['inbox']['invitation']['received'] ?? '')->not->toBe('');
});

/*
 * Att öppna klockan nollställer notiserna men INTE inbjudningarna. En
 * inbjudan är obesvarad till dess att den besvarats, och att gömma en
 * väntande inbjudan bakom ett klick på en klocka vore fel svar.
 */
it('nollställer inte inbjudningarna när klockan öppnas', function () {
    withoutVite();

    $inbjudare = User::factory()->create();
    $mottagare = User::factory()->create(['email' => 'ny@exempel.se']);

    vantandeInbjudan(vantandeParm(), $inbjudare, 'ny@exempel.se');

    actingAs($mottagare)->post('/notifications/read')->assertRedirect();

    actingAs($mottagare)->get('/dashboard')->assertInertia(
        fn (AssertableInertia $page) => $page->where('unreadNotificationCount', 1)
    );
});
