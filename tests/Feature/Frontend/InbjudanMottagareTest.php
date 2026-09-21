<?php

// rott-pa-basen: issue 77b — ordbyte i prosa (kommentar och testnamn), ingen kodändring; bas och head delar applikationskod.

use App\Http\Controllers\InvitationResponseController;
use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Invitation;
use App\Models\Item;
use App\Models\User;
use App\Support\Frontend\ActiveContainer;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\withoutVite;
use function Pest\Laravel\withSession;

/*
 * Issue 55b · Mejlets landningssida och de fem lägena, se
 * App\Http\Controllers\InvitationResponseController,
 * resources/js/pages/Invitations/Show.vue och
 * App\Actions\Invitation\AcceptInvitation (som anropas oförändrad).
 *
 * Avsändarytan — formuläret, listan och tillbakadragandet — är samma issue men
 * en annan fil: tests/Feature/Frontend/InbjudningsvyTest.php.
 *
 * Filen prövar två saker som är lätta att tappa: att tillstånden verkligen går
 * att skilja åt (gäst, overifierad, fel adress, och det neutrala beskedet), och
 * att de två sista INTE går att skilja åt — ett "finns inte" mot ett "är redan
 * accepterad" är en orakelyta mot giltiga token (§ Beslut 3).
 *
 * Hjälparna har prefixet `mottagar` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Skapar en `pending` inbjudan och returnerar den med KLARTEXTTOKENET — raden
 * lagrar bara hashen (issue 10a § Beslut 5), och en riktig mottagare får
 * klartexten enbart i mejlet.
 *
 * @param  array<string, mixed>  $overrides
 * @return array{0: Invitation, 1: string} [$invitation, $rawToken]
 */
function mottagarInbjudan(Container $container, string $email, array $overrides = []): array
{
    $rawToken = Str::random(64);

    $invitation = Invitation::factory()->create(array_merge([
        'container_id' => $container->id,
        'email' => $email,
        'level' => 'read',
        'token_hash' => hash('sha256', $rawToken),
        'status' => 'pending',
        'expires_at' => now()->addDays(Invitation::TTL_DAYS),
        'invited_by_user_id' => User::factory()->create(['name' => 'Inbjudaren'])->id,
    ], $overrides));

    return [$invitation, $rawToken];
}

/**
 * Containern i ett konto, utan inloggad mottagare.
 */
function mottagarParm(): Container
{
    return Container::factory()->for(Account::factory()->create(), 'account')->create();
}

/**
 * Sessionsnyckeln, hämtad ur den enda klass som stavar den.
 *
 * @return array<string, string>
 */
function mottagarSession(string $rawToken): array
{
    return [InvitationResponseController::SESSION_KEY => $rawToken];
}

/*
 * Beslut 2: tokenet lämnar URL:en direkt. Rutten renderar INGENTING — den
 * lägger tokenet i sessionen och omdirigerar.
 */
it('renderar ingenting på token-URL:en och lägger tokenet i sessionen', function () {
    withoutVite();

    [, $rawToken] = mottagarInbjudan(mottagarParm(), 'ny@exempel.se');

    $svar = get("/invitations/{$rawToken}");

    $svar->assertRedirect('/invitations');

    // Ingen renderad sida: svaret är en omdirigering, och den kropp Symfony
    // hänger på den (en meta-refresh-sköld) bär varken tokenet eller något
    // annat ur inbjudan.
    expect($svar->getContent())->not->toContain($rawToken)
        ->and(session(InvitationResponseController::SESSION_KEY))->toBe($rawToken);
});

/*
 * `GET /invitations/accept` matchar `{token}`-rutten. Efter en lyckad
 * inloggning skickar `redirect()->intended()` en gäst tillbaka dit, och den
 * vägen får inte skriva över inbjudan sessionen redan bär — då hade mottagaren
 * mötts av "går inte att använda" i stället för av sin inbjudan.
 */
it('skriver inte över en väntande inbjudan när inloggningen skickar tillbaka till accept-URL:en', function () {
    withoutVite();

    [, $rawToken] = mottagarInbjudan(mottagarParm(), 'ny@exempel.se');

    withSession(mottagarSession($rawToken))
        ->get('/invitations/accept')
        ->assertRedirect('/invitations');

    expect(session(InvitationResponseController::SESSION_KEY))->toBe($rawToken);
});

/*
 * Klart när: en gäst på `/invitations` ser containerns namn och vägarna till
 * inloggning och registrering, och ingen e-postadress.
 *
 * Adressen inbjudan gäller visas aldrig — den vet mottagaren redan, och en
 * bärare som inte är mottagaren ska inte få veta den. Containerns namn och
 * inbjudarens namn är däremot ingen ny uppgift: båda står i mejlet.
 */
it('visar containerns namn och vägarna in för en gäst, men ingen adress', function () {
    withoutVite();

    $container = mottagarParm();
    $container->update(['name' => 'Vindö 40']);
    [, $rawToken] = mottagarInbjudan($container, 'hemlig.adress@example.test', ['level' => 'write']);

    // Två steg, precis som mejlets länk gör: tokenet in i sessionen, sedan
    // landningssidan.
    get("/invitations/{$rawToken}")->assertRedirect('/invitations');

    $svar = get('/invitations');

    $svar->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Invitations/Show')
        ->where('state', 'guest')
        ->where('invitation.container', 'Vindö 40')
        ->where('invitation.inviter', 'Inbjudaren')
        ->where('invitation.level', 'write')
        // Ingen adress i propen alls, och inget token: gästen har inget
        // formulär att lägga det i.
        ->missing('invitation.email')
        ->where('token', null)
    );

    // Inertia renderar komponenten i klienten, så själva länkmarkupen finns
    // inte i svaret — den finns i vyn, och den kontrolleras där.
    expect($svar->getContent())->not->toContain('hemlig.adress@example.test')
        ->and($svar->getContent())->not->toContain($rawToken);

    $vy = File::get(resource_path('js/pages/Invitations/Show.vue'));

    expect($vy)->toContain('href="/login"')
        ->and($vy)->toContain('href="/register"')
        ->and($vy)->toContain("t('invitation.guest')");
});

/*
 * En gäst får ingen förhandsvisning av en inbjudan som inte längre går att
 * använda: kontrollen av utgång sker FÖRE adressjämförelsen, så gästen faller
 * ut i det neutrala beskedet och inte i gästläget.
 */
it('visar ingen förhandsvisning för en gäst när inbjudan gått ut', function () {
    withoutVite();

    $container = mottagarParm();
    $container->update(['name' => 'Vindö 40']);
    [, $rawToken] = mottagarInbjudan($container, 'ny@exempel.se', ['expires_at' => now()->subDay()]);

    $svar = withSession(mottagarSession($rawToken))->get('/invitations');

    $svar->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('state', 'unavailable')
        ->where('invitation', null)
        ->where('token', null)
    );

    expect($svar->getContent())->not->toContain('Vindö 40');

    // Sessionen glöms: det finns ingenting kvar att svara på.
    expect(session(InvitationResponseController::SESSION_KEY))->toBeNull();
});

/*
 * Klart när: en utgången inbjudan, en redan accepterad och ett okänt token ger
 * samma neutrala besked — ingen annan formulering och inget annat tillstånd.
 * Skillnaden hade varit en orakelyta mot giltiga token (§ Beslut 3).
 */
it('ger utgången, besvarad och okänt token samma neutrala besked', function () {
    withoutVite();

    $container = mottagarParm();

    [, $utgangen] = mottagarInbjudan($container, 'utgangen@exempel.se', ['expires_at' => now()->subDay()]);
    [, $besvarad] = mottagarInbjudan($container, 'besvarad@exempel.se', ['status' => 'accepted']);
    [, $tillbakadragen] = mottagarInbjudan($container, 'tillbaka@exempel.se', ['status' => 'revoked']);
    $okant = Str::random(64);

    $tillstand = [];

    foreach ([$utgangen, $besvarad, $tillbakadragen, $okant] as $token) {
        $svar = withSession(mottagarSession($token))->get('/invitations');

        $svar->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Invitations/Show')
            ->where('state', 'unavailable')
            ->where('invitation', null)
            ->where('token', null)
        );

        $tillstand[] = $svar->viewData('page')['props'];

        expect(session(InvitationResponseController::SESSION_KEY))->toBeNull();
    }

    // Samma props varje gång — samma besked, ingen skillnad att läsa ut.
    expect(array_unique(array_map(fn (array $props) => json_encode($props['state']), $tillstand)))->toHaveCount(1);
});

/*
 * Klart när: en inloggad, overifierad mottagare ser verifieringsuppmaningen.
 * EXAKT EN gång: AppLayouts banner bär den för varje overifierad användare
 * utom på /email/verify, och /invitations är inget undantag. Renderade sidan
 * sin egen kopia möttes mottagaren av två likadana knappar — därför finns
 * komponenten i layouten och inte i vyn.
 */
it('visar verifieringsuppmaningen exakt en gång för en overifierad mottagare', function () {
    withoutVite();

    $container = mottagarParm();
    [, $rawToken] = mottagarInbjudan($container, 'ny@exempel.se');

    $overifierad = User::factory()->unverified()->create(['email' => 'ny@exempel.se']);

    actingAs($overifierad)
        ->withSession(mottagarSession($rawToken))
        ->get('/invitations')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('state', 'unverified')
            ->where('invitation.container', $container->name)
            // Verifieringspåminnelsen är en knapp utan formulärdata, så
            // tokenet har ingenstans att ta vägen här.
            ->where('token', null)
        );

    // Inertia renderar komponenten i klienten, så dubbletten syns inte i
    // svarskroppen — den syns i källan. Bannern äger uppmaningen; sidan får
    // inte importera eller rendera en andra kopia.
    $sida = File::get(resource_path('js/pages/Invitations/Show.vue'));

    expect($sida)->not->toContain('VerifyEmailNotice')
        ->and(File::get(resource_path('js/layouts/AppLayout.vue')))->toContain('<VerifyEmailNotice />');
});

/*
 * Klart när: `POST /invitations/accept` ger inget `container_access` för en
 * overifierad mottagare — och svaret är ett formulärfel, inte en JSON-kropp
 * mitt i en webbsida.
 */
it('ger ingen åtkomst när en overifierad mottagare försöker acceptera', function () {
    withoutVite();

    $container = mottagarParm();
    [$inbjudan, $rawToken] = mottagarInbjudan($container, 'ny@exempel.se');

    $overifierad = User::factory()->unverified()->create(['email' => 'ny@exempel.se']);

    $svar = actingAs($overifierad)
        ->withSession(mottagarSession($rawToken))
        ->post('/invitations/accept', ['token' => $rawToken]);

    $svar->assertSessionHasErrors('invitation');

    expect($svar->getContent())->not->toContain('error.code')
        ->and(ContainerAccess::query()->count())->toBe(0)
        ->and($inbjudan->refresh()->status)->toBe('pending');
});

/*
 * Klart när: en inloggad användare med FEL adress ser ett besked som inte
 * innehåller den inbjudna adressen, och accepten skapar ingenting.
 */
it('ger fel adress ett besked utan den inbjudna adressen, och ingen åtkomst', function () {
    withoutVite();

    $container = mottagarParm();
    [$inbjudan, $rawToken] = mottagarInbjudan($container, 'hemlig.adress@example.test');

    $felAdress = User::factory()->create(['email' => 'nagon.annan@exempel.se']);

    $svar = actingAs($felAdress)
        ->withSession(mottagarSession($rawToken))
        ->get('/invitations');

    $svar->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('state', 'mismatch')
        // Beskedet säger inte VILKEN adress inbjudan gäller — bara att det är
        // en annan än den inloggades.
        ->where('invitation', null)
        ->where('token', null)
    );

    expect($svar->getContent())->not->toContain('hemlig.adress@example.test');

    // Och inbjudan ligger kvar: den är inte ogiltig, den tillhör någon annan.
    expect($inbjudan->refresh()->status)->toBe('pending')
        ->and(session(InvitationResponseController::SESSION_KEY))->toBe($rawToken);

    actingAs($felAdress)
        ->post('/invitations/accept', ['token' => $rawToken])
        ->assertSessionHasErrors('invitation');

    expect(ContainerAccess::query()->count())->toBe(0);
});

/*
 * Klart när: en verifierad mottagare som accepterar får en
 * `container_access`-rad med inbjudans `level` och `item_id`, inbjudan blir
 * `accepted`, containern blir aktiv och hon landar på `/containers`.
 */
it('accepterar inbjudan och landar i den nya containern', function () {
    withoutVite();

    $container = mottagarParm();
    $item = Item::factory()->create(['container_id' => $container->id, 'name' => 'Motorn']);

    [$inbjudan, $rawToken] = mottagarInbjudan($container, 'ny@exempel.se', [
        'level' => 'write',
        'item_id' => $item->id,
    ]);

    $mottagare = User::factory()->create(['email' => 'ny@exempel.se']);

    // Först: sidan mottagaren möter. Formulären finns bara här, och det är
    // därför tokenet skickas med som prop just i det här tillståndet.
    actingAs($mottagare)
        ->withSession(mottagarSession($rawToken))
        ->get('/invitations')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('state', 'ready')
            ->where('token', $rawToken)
            ->where('invitation.level', 'write')
        );

    actingAs($mottagare)
        ->withSession(mottagarSession($rawToken))
        ->post('/invitations/accept', ['token' => $rawToken])
        ->assertRedirect('/containers')
        ->assertSessionHas('status', 'invitation-accepted');

    $access = ContainerAccess::query()->sole();

    expect($access->container_id)->toBe($container->id)
        ->and($access->item_id)->toBe($item->id)
        ->and($access->grantee_id)->toBe($mottagare->id)
        ->and($access->level)->toBe('write')
        ->and($access->kind)->toBe('member')
        ->and($access->granted_by_user_id)->toBe($inbjudan->invited_by_user_id);

    expect($inbjudan->refresh()->status)->toBe('accepted');

    // Den som just fått en container ska landa i den (Beslut 4).
    expect(session(ActiveContainer::SESSION_KEY))->toBe($container->ulid);

    // Och tokenet glöms — inbjudan är besvarad.
    expect(session(InvitationResponseController::SESSION_KEY))->toBeNull();
});

/*
 * Klart när: en andra accept med samma token ger inget andra `container_access`
 * och inget fel som ser ut som ett systemfel.
 */
it('ger inget andra container_access vid en andra accept', function () {
    withoutVite();

    $container = mottagarParm();
    [, $rawToken] = mottagarInbjudan($container, 'ny@exempel.se');

    $mottagare = User::factory()->create(['email' => 'ny@exempel.se']);

    actingAs($mottagare)
        ->post('/invitations/accept', ['token' => $rawToken])
        ->assertRedirect('/containers');

    $svar = actingAs($mottagare)
        ->withSession(mottagarSession($rawToken))
        ->post('/invitations/accept', ['token' => $rawToken]);

    // Ett formulärfel på `invitation`, inte en rå felkod och inte en 500:a.
    $svar->assertSessionHasErrors('invitation');

    expect($svar->getContent())->not->toContain('error.code')
        ->and(ContainerAccess::query()->count())->toBe(1);
});

/*
 * Klart när: `POST /invitations/reject` fungerar för en OVERIFIERAD inloggad
 * mottagare och sätter `status = 'rejected'` — att tacka nej ger ingen
 * behörighet, och att tvinga fram en verifiering för att bli av med ett mejl
 * vore fel väg (issue 10b § Beslut 7).
 */
it('avvisar en inbjudan utan krav på verifierad adress', function () {
    withoutVite();

    $container = mottagarParm();
    [$inbjudan, $rawToken] = mottagarInbjudan($container, 'ny@exempel.se');

    $overifierad = User::factory()->unverified()->create(['email' => 'ny@exempel.se']);

    actingAs($overifierad)
        ->withSession(mottagarSession($rawToken))
        ->post('/invitations/reject', ['token' => $rawToken])
        ->assertRedirect('/')
        ->assertSessionHas('status', 'invitation-rejected');

    expect($inbjudan->refresh()->status)->toBe('rejected')
        ->and(ContainerAccess::query()->count())->toBe(0)
        ->and(session(InvitationResponseController::SESSION_KEY))->toBeNull();
});

/*
 * Klart när: en gäst som postar till accept eller reject skickas till
 * inloggningen och kommer tillbaka till inbjudan efteråt.
 *
 * `auth`-middlewaren svarar först, så ingen kontrollermetod nås — men tokenet
 * ligger kvar i sessionen (inloggningen regenererar sessionen utan att tömma
 * den), och efter inloggningen är det samma inbjudan som väntar.
 */
it('skickar en gäst till inloggningen och behåller inbjudan i sessionen', function () {
    withoutVite();

    $container = mottagarParm();
    [, $rawToken] = mottagarInbjudan($container, 'ny@exempel.se');

    foreach (['accept', 'reject'] as $vag) {
        withSession(mottagarSession($rawToken))
            ->post("/invitations/{$vag}", ['token' => $rawToken])
            ->assertRedirect('/login');

        expect(session(InvitationResponseController::SESSION_KEY))->toBe($rawToken);
    }

    // Efter inloggningen: samma inbjudan, nu med formulären.
    $mottagare = User::factory()->create(['email' => 'ny@exempel.se']);

    actingAs($mottagare)
        ->withSession(mottagarSession($rawToken))
        ->get('/invitations')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('state', 'ready')
            ->where('token', $rawToken)
        );
});

/*
 * Sidans text bor i lang/ och aldrig i vyn, och varje ny nyckel finns på båda
 * språken — samma krav som 55a ställde på delningssidan.
 */
it('har mottagarsidans texter', function () {
    $sv = require lang_path('en/ui.php');
    $en = require lang_path('en/ui.php');

    foreach (['title', 'heading', 'intro', 'level', 'guest', 'mismatch', 'unavailable', 'accept', 'reject', 'home'] as $nyckel) {
        expect($sv['invitation'][$nyckel])->not->toBe('')
            ->and($en['invitation'][$nyckel])->not->toBe('');
    }

    // Felkoderna mottagarsidan kan mötas av har var sin mening — ingen av dem
    // är koden själv.
    expect($sv['error']['invitation']['not_pending'])->not->toBe('invitation.not_pending')
        ->and($sv['error']['invitation']['email_not_verified'])->not->toBe('invitation.email_not_verified')
        ->and($sv['error']['invitation']['email_mismatch'])->not->toBe('invitation.email_mismatch');
});
