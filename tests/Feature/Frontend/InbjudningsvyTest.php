<?php

// rott-pa-basen: issue 77b — ordbyte i prosa (kommentar och testnamn), ingen kodändring; bas och head delar applikationskod.

use App\Models\Account;
use App\Models\Container;
use App\Models\Invitation;
use App\Models\Item;
use App\Models\User;
use App\Notifications\InvitationNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\from;
use function Pest\Laravel\post;
use function Pest\Laravel\withoutVite;

/*
 * Issue 55b · Inbjudningarnas avsändaryta i webben: formuläret, listan och
 * tillbakadragandet. Se
 * App\Http\Controllers\ContainerInvitationController,
 * App\Actions\Invitation\CreateInvitation och RevokeInvitation,
 * resources/js/pages/Containers/Sharing.vue (tredje sektionen) och
 * resources/js/components/InvitationForm.vue.
 *
 * Mejlets landningssida och acceptflödet — mottagarsidan — är samma issue men
 * en annan fil: tests/Feature/Frontend/InbjudanMottagareTest.php.
 *
 * Att `/api/containers/{container}/invitations` svarar exakt som förut prövas
 * av tests/Feature/Container/**, som är grönt utan en enda ändrad förväntan
 * efter utbrytningen i § Beslut 7. En ny formulering av samma sak här hade
 * bevisat noll.
 *
 * Hjälparna har prefixet `inbjudnings` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Ett konto med en medlem och en container ägd av kontot. Medlemmen får `sv_SE`, så
 * de översatta meningarna i felpåsen går att jämföra mot `lang/sv/ui.php` —
 * appens standardspråk är `en`, och en användare utan locale får den.
 *
 * @param  array<string, mixed>  $kontoAttribut
 * @return array{0: Account, 1: User, 2: Container}
 */
function inbjudningsKontext(array $kontoAttribut = []): array
{
    $konto = Account::factory()->create($kontoAttribut);
    $anvandare = User::factory()->create(['locale' => 'sv_SE']);
    $konto->users()->attach($anvandare, ['role' => 'owner']);
    $container = Container::factory()->for($konto, 'account')->create();

    return [$konto, $anvandare, $container];
}

/**
 * Delningssidans adress, som `back()` landar på.
 */
function inbjudningsSida(Container $container): string
{
    return "/containers/{$container->ulid}/sharing";
}

/*
 * Beslut 1: båda skrivningarna ligger bakom `auth`. En utloggad besökare
 * skickas till inloggningen och når aldrig en kontrollermetod.
 */
it('skickar en utloggad besökare till inloggningen från inbjudningsrutterna', function () {
    withoutVite();

    [, , $container] = inbjudningsKontext();
    $inbjudan = bjudInRad($container, 'ny@exempel.se');

    post("/containers/{$container->ulid}/invitations", ['email' => 'ny@exempel.se', 'level' => 'read'])
        ->assertRedirect('/login');

    delete("/containers/{$container->ulid}/invitations/{$inbjudan->ulid}")
        ->assertRedirect('/login');
});

/*
 * Klart när: POST skapar en `pending`-rad och skickar ett mejl med länken
 * `{app.url}/invitations/{token}` — kontraktet issue 10b skrev ut i
 * App\Http\Controllers\Api\ContainerInvitationController::store() och som
 * fram till nu pekade på en 404.
 */
it('skapar en pending-rad och mejlar länken till adressen', function () {
    Notification::fake();
    withoutVite();

    [, $anvandare, $container] = inbjudningsKontext();

    from(inbjudningsSida($container))
        ->actingAs($anvandare)
        ->post("/containers/{$container->ulid}/invitations", ['email' => 'Ny@Exempel.se', 'level' => 'write'])
        ->assertRedirect(inbjudningsSida($container))
        ->assertSessionHas('status', 'invitation-sent');

    $inbjudan = Invitation::query()->sole();

    // Adressen normaliseras INNAN den lagras — annars slinker `Ny@Exempel.se`
    // förbi bredvid `ny@exempel.se` och mottagarsidans adressjämförelse hittar
    // två rader (issue 10a § Beslut 6).
    expect($inbjudan->email)->toBe('ny@exempel.se')
        ->and($inbjudan->status)->toBe('pending')
        ->and($inbjudan->level)->toBe('write')
        ->and($inbjudan->container_id)->toBe($container->id)
        ->and($inbjudan->item_id)->toBeNull()
        ->and($inbjudan->invited_by_user_id)->toBe($anvandare->id);

    expect($inbjudan->expires_at->diffInSeconds(now()->addDays(Invitation::TTL_DAYS), absolute: true))
        ->toBeLessThan(60);

    Notification::assertSentOnDemand(
        InvitationNotification::class,
        function (InvitationNotification $notification, array $channels, AnonymousNotifiable $notifiable) use ($inbjudan) {
            $prefix = rtrim((string) config('app.url'), '/').'/invitations/';

            expect($notifiable->routes['mail'])->toBe('ny@exempel.se')
                ->and($notification->url)->toStartWith($prefix);

            // Länken bär klartexten. Att dess hash är radens `token_hash`
            // bevisar både att mejlet är användbart och att databasen bara har
            // hashen (issue 10a § Beslut 5).
            expect(hash('sha256', Str::after($notification->url, $prefix)))->toBe($inbjudan->token_hash);

            return true;
        }
    );
});

/*
 * Klart när: klartexttokenet finns varken i databasen, i svaret, i en prop
 * eller i en logg.
 */
it('läcker aldrig klartexttokenet till sidan eller till svaret', function () {
    Notification::fake();
    withoutVite();

    [, $anvandare, $container] = inbjudningsKontext();

    $svar = actingAs($anvandare)->post("/containers/{$container->ulid}/invitations", [
        'email' => 'ny@exempel.se',
        'level' => 'read',
    ]);

    $svar->assertRedirect();

    // Klartexten fångas ur mejlet — det är den enda plats den någonsin lämnar
    // processen.
    $raw = '';

    Notification::assertSentOnDemand(
        InvitationNotification::class,
        function (InvitationNotification $notification) use (&$raw): bool {
            $raw = Str::after($notification->url, '/invitations/');

            return true;
        }
    );

    expect($raw)->toHaveLength(64);

    $inbjudan = Invitation::query()->sole();

    // Raden bär hashen och aldrig klartexten, och svaret bär ingen av dem:
    // det är en omdirigering utan kropp.
    expect($inbjudan->token_hash)->toBe(hash('sha256', $raw))
        ->and($inbjudan->token_hash)->not->toBe($raw)
        ->and($svar->getContent())->not->toContain($raw)
        ->and($svar->getContent())->not->toContain($inbjudan->token_hash);

    // Och ingen prop bär den: listan visar adressen och nivån, inte länken.
    $sida = actingAs($anvandare)->get(inbjudningsSida($container));

    $sida->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('invitations', 1)
            ->where('invitations.0.email', 'ny@exempel.se')
            ->missing('invitations.0.token')
            ->missing('invitations.0.token_hash')
    );

    expect($sida->getContent())->not->toContain($raw);
});

/*
 * Klart när: en andra inbjudan till samma adress och container ger ett läsbart fel
 * på fältet `email`, ingen ny rad och inget mejl.
 */
it('ger ett läsbart fel på fältet email för en andra inbjudan till samma adress', function () {
    Notification::fake();
    withoutVite();

    [, $anvandare, $container] = inbjudningsKontext();
    $befintlig = bjudInRad($container, 'ny@exempel.se');

    $sv = require lang_path('sv/ui.php');

    $svar = actingAs($anvandare)->post("/containers/{$container->ulid}/invitations", [
        'email' => 'NY@Exempel.se',
        'level' => 'read',
    ]);

    // Duplikatspärren frågar på den NORMALISERADE adressen, och felet hamnar på
    // fältet användaren skrev (Beslut 6).
    $svar->assertSessionHasErrors(['email' => $sv['error']['invitation']['already_pending']]);

    // Ingen ny rad, inget mejl — och ingenting av det som ett passerat tak hade
    // kunnat avslöja om var gränsen ligger.
    expect(Invitation::query()->count())->toBe(1)
        ->and(Invitation::query()->sole()->ulid)->toBe($befintlig->ulid);

    Notification::assertNothingSent();
});

/*
 * Klart när: ett passerat delningstak ger ett läsbart meddelande under
 * `errors.quota`. Meddelandet är meningen ur lang/, aldrig felkoden.
 */
it('lägger ett passerat delningstak under errors.quota', function () {
    Notification::fake();
    withoutVite();

    [, $anvandare, $container] = inbjudningsKontext();
    bjudInRad($container, 'befintlig@exempel.se');

    sättPlangräns('free', 'shared_users_per_container', 1);

    $sv = require lang_path('sv/ui.php');

    $svar = actingAs($anvandare)->post("/containers/{$container->ulid}/invitations", [
        'email' => 'ny@exempel.se',
        'level' => 'read',
    ]);

    $svar->assertSessionHasErrors('quota');
    expect(session('errors')->first('quota'))->toStartWith('Delningen har nått kontots tak')
        ->and($sv['error']['quota']['shared_users_exceeded'])->not->toBe('quota.shared_users_exceeded');
});

/*
 * Klart när: ett passerat inbjudningstak ger sitt eget läsbara meddelande
 * under `errors.quota` (issue 48).
 */
it('lägger ett passerat inbjudningstak under errors.quota', function () {
    Notification::fake();
    withoutVite();

    [, $anvandare, $container] = inbjudningsKontext();
    bjudInRad($container, 'befintlig@exempel.se');

    sättPlangräns('free', 'shared_users_per_container', 50);
    sättPlangräns('free', 'pending_invitations', 1);

    $svar = actingAs($anvandare)->post("/containers/{$container->ulid}/invitations", [
        'email' => 'ny@exempel.se',
        'level' => 'read',
    ]);

    $svar->assertSessionHasErrors('quota');
    expect(session('errors')->first('quota'))->toStartWith('Kontot har nått sitt tak för utestående inbjudningar');

    expect(Invitation::query()->count())->toBe(1);
});

/*
 * Klart när: kontotaket prövas EFTER delningstaket. Ett nekande svarar den
 * gräns användaren kan göra något åt och avslöjar inte var utskickstaket
 * ligger (Beslut 6, issue 48 § Beslut 8).
 */
it('prövar kontotaket efter delningstaket', function () {
    Notification::fake();
    withoutVite();

    [, $anvandare, $container] = inbjudningsKontext();
    bjudInRad($container, 'befintlig@exempel.se');

    sättPlangräns('free', 'shared_users_per_container', 1);
    sättPlangräns('free', 'pending_invitations', 1);

    $svar = actingAs($anvandare)->post("/containers/{$container->ulid}/invitations", [
        'email' => 'ny@exempel.se',
        'level' => 'read',
    ]);

    $svar->assertSessionHasErrors('quota');
    expect(session('errors')->first('quota'))->toStartWith('Delningen har nått kontots tak');
});

/*
 * Klart när: en member som inte får hantera åtkomster, och varje icke-medlem,
 * får 403 på både POST och DELETE.
 *
 * Att en `write`-innehavare får 403 är regel 3: att bjuda in ÄR att hantera
 * åtkomster, och `write` är inte medlemskap i ägarkontot (issue 10a § Beslut
 * 10). Grinden är `manageAccess()` på båda rutterna — samma som `/api`.
 */
it('nekar en icke-medlem både POST och DELETE', function () {
    withoutVite();

    [, , $container] = inbjudningsKontext();
    $inbjudan = bjudInRad($container, 'ny@exempel.se');

    $innehavare = User::factory()->create(['locale' => 'sv_SE']);
    beviljaAccess($container, $innehavare, 'write', 'member');

    $frammande = User::factory()->create(['locale' => 'sv_SE']);

    foreach ([$innehavare, $frammande] as $nekad) {
        actingAs($nekad)
            ->post("/containers/{$container->ulid}/invitations", ['email' => 'ny2@exempel.se', 'level' => 'read'])
            ->assertForbidden();

        actingAs($nekad)
            ->delete("/containers/{$container->ulid}/invitations/{$inbjudan->ulid}")
            ->assertForbidden();
    }

    expect($inbjudan->refresh()->status)->toBe('pending')
        ->and(Invitation::query()->count())->toBe(1);
});

/*
 * Klart när: ett `read_only`-ägarkonto får 403 på POST. Återkallandet av en
 * BEFINTLIG åtkomst är undantaget i regel 4; en inbjudan är ingen befintlig
 * relation.
 */
it('nekar ett read_only-ägarkonto att bjuda in och att dra tillbaka', function () {
    withoutVite();

    [, $anvandare, $container] = inbjudningsKontext(['status' => 'read_only']);
    $inbjudan = bjudInRad($container, 'ny@exempel.se');

    actingAs($anvandare)
        ->post("/containers/{$container->ulid}/invitations", ['email' => 'ny2@exempel.se', 'level' => 'read'])
        ->assertForbidden();

    // 10a § Beslut 10: `manageAccess()` auktoriserar tillbakadragandet också —
    // en pending inbjudan har aldrig blivit en relation.
    actingAs($anvandare)
        ->delete("/containers/{$container->ulid}/invitations/{$inbjudan->ulid}")
        ->assertForbidden();

    // Men listan ser hon: `viewAccesses()` saknar `read_only`-kontrollen,
    // precis som för åtkomsterna i 55a.
    actingAs($anvandare)->get(inbjudningsSida($container))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('invitations', 1)
            ->where('can.manage', false)
    );
});

/*
 * Klart när: DELETE sätter `status = 'revoked'` på en `pending`-rad, också på
 * en utgången sådan, och ger 422 för en redan besvarad.
 */
it('drar tillbaka en pending och en utgången inbjudan, men inte en besvarad', function () {
    withoutVite();

    [, $anvandare, $container] = inbjudningsKontext();

    $vantande = bjudInRad($container, 'vantande@exempel.se');
    $utgangen = bjudInRad($container, 'utgangen@exempel.se', expiresAt: now()->subDay());
    $besvarad = bjudInRad($container, 'besvarad@exempel.se', status: 'accepted');

    $sv = require lang_path('sv/ui.php');

    foreach ([$vantande, $utgangen] as $rad) {
        from(inbjudningsSida($container))
            ->actingAs($anvandare)
            ->delete("/containers/{$container->ulid}/invitations/{$rad->ulid}")
            ->assertRedirect(inbjudningsSida($container))
            ->assertSessionHas('status', 'invitation-revoked');

        // Raden raderas aldrig — `revoked` är avsändarens ånger (issue 10a
        // § Beslut 13).
        expect($rad->refresh()->status)->toBe('revoked');
    }

    // En accepterad inbjudan går inte att ångra härifrån, och svaret är ett
    // formulärfel och inte en rå felkod (Beslut 8).
    $svar = actingAs($anvandare)->delete("/containers/{$container->ulid}/invitations/{$besvarad->ulid}");

    $svar->assertSessionHasErrors(['invitation' => $sv['error']['invitation']['not_pending']]);
    expect($svar->getContent())->not->toContain('error.code');
    expect($besvarad->refresh()->status)->toBe('accepted');
});

/*
 * Klart när: en inbjudan i en annan container går inte att dra tillbaka via den här
 * containerns rutt (404). `scopeBindings()` på skrivningen, av samma skäl som
 * routes/api.php gör det (issue 9b § Beslut 1).
 */
it('når inte en inbjudan i en annan container via den här containerns rutt', function () {
    withoutVite();

    [$konto, $anvandare, $container] = inbjudningsKontext();
    $annan = Container::factory()->for($konto, 'account')->create();
    $inbjudan = bjudInRad($annan, 'ny@exempel.se');

    actingAs($anvandare)
        ->delete("/containers/{$container->ulid}/invitations/{$inbjudan->ulid}")
        ->assertNotFound();

    expect($inbjudan->refresh()->status)->toBe('pending');
});

/*
 * Klart när: `invitations`-propen är `null` för en deltagare som inte är
 * medlem i ägarkontot, och ingen adress förekommer i den renderade HTML:en.
 *
 * En obesvarad inbjudan röjer en e-postadress, och listan är därför lika
 * känslig som förvaltningsvyn (Beslut 5). En prop i HTML:en är utlämnad
 * oavsett vad Vue gör med den — därför `null` och inte en tom lista.
 */
it('ger en icke-medlem ingen inbjudningslista och ingen adress i HTML:en', function () {
    withoutVite();

    [, , $container] = inbjudningsKontext();
    bjudInRad($container, 'hemlig.adress@example.test');

    $innehavare = User::factory()->create(['locale' => 'sv_SE']);
    beviljaAccess($container, $innehavare, 'write', 'member');

    $svar = actingAs($innehavare)->get(inbjudningsSida($container));

    $svar->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('invitations', null)
        // Uppslagen följer listan: ingen av dem bär något när den är `null`.
        ->where('invitedByNames', [])
        ->where('items', [])
    );

    expect($svar->getContent())->not->toContain('hemlig.adress@example.test');
});

/*
 * Klart när: listan visar adress, nivå, omfång, status, utgångsdatum och vem
 * som bjöd in — och statusen kommer ur InvitationResource, aldrig ur kolumnen.
 */
it('visar inbjudningslistan med adress, nivå, omfång, status, utgång och inbjudare', function () {
    withoutVite();

    [, $anvandare, $container] = inbjudningsKontext();

    $item = Item::factory()->create(['container_id' => $container->id, 'name' => 'Motorn']);

    // `created_at` sätts uttryckligen: listan sorteras `created_at` fallande,
    // och kolumnen har sekundupplösning.
    $bred = Invitation::factory()->create([
        'container_id' => $container->id,
        'email' => 'bred@exempel.se',
        'level' => 'read',
        'invited_by_user_id' => $anvandare->id,
        'created_at' => now()->subMinutes(3),
    ]);
    $utgangen = Invitation::factory()->create([
        'container_id' => $container->id,
        'email' => 'utgangen@exempel.se',
        'level' => 'read',
        'expires_at' => now()->subDay(),
        'invited_by_user_id' => $anvandare->id,
        'created_at' => now()->subMinutes(2),
    ]);
    $itemrad = Invitation::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item->id,
        'email' => 'item@exempel.se',
        'level' => 'write',
        'invited_by_user_id' => $anvandare->id,
        'created_at' => now()->subMinute(),
    ]);

    actingAs($anvandare)->get(inbjudningsSida($container))->assertInertia(fn (AssertableInertia $page) => $page
        ->has('invitations', 3)

        // Nyast först, samma ordning som /api:s index() (issue 10a § Beslut
        // 14).
        ->where('invitations.0.ulid', $itemrad->ulid)
        ->where('invitations.0.email', 'item@exempel.se')
        ->where('invitations.0.item', $item->ulid)
        ->where('invitations.0.level', 'write')
        ->where('invitations.0.status', 'pending')

        // En `pending`-rad som passerat sitt `expires_at` redovisas som
        // `expired` UTAN att kolumnen ändras — härledningen bor i resursen och
        // görs inte om här (issue 10a § Beslut 7).
        ->where('invitations.1.ulid', $utgangen->ulid)
        ->where('invitations.1.status', 'expired')

        ->where('invitations.2.ulid', $bred->ulid)
        ->where('invitations.2.item', null)
        ->where('invitations.2.status', 'pending')
        ->where('invitations.2.expires_at', fn ($value) => $value !== null)

        ->where('itemNames', [$item->ulid => 'Motorn'])
        // Alla tre raderna bjöds in av samma användare, så uppslaget har
        // exakt en nyckel.
        ->where('invitedByNames', [$anvandare->ulid => $anvandare->name])
    );

    // Statusen flippas aldrig av tidens gång.
    expect($utgangen->refresh()->status)->toBe('pending');
});

/*
 * Klart när: den enda ytan i M10 där en itemavgränsad delning kan skapas.
 * [[ADR-0028 Åtkomst på itemnivå]] § Beslut säger att `invitation` speglar
 * omfånget, och formuläret bär därför en lista över containerns levande items.
 */
it('skapar en itemavgränsad inbjudan från formuläret', function () {
    Notification::fake();
    withoutVite();

    [, $anvandare, $container] = inbjudningsKontext();
    $item = Item::factory()->create(['container_id' => $container->id, 'name' => 'Motorn']);

    actingAs($anvandare)->get(inbjudningsSida($container))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('items', [['ulid' => $item->ulid, 'name' => 'Motorn']])
    );

    actingAs($anvandare)
        ->post("/containers/{$container->ulid}/invitations", [
            'email' => 'ny@exempel.se',
            'level' => 'read',
            'item' => $item->ulid,
        ])
        ->assertRedirect();

    expect(Invitation::query()->sole()->item_id)->toBe($item->id);

    // En ULID ur en annan container är ett valideringsfel (422), aldrig en tyst
    // container-bred inbjudan — StoreInvitationRequest delas med /api och
    // regeln formuleras inte om här.
    $annan = Container::factory()->for($container->account, 'account')->create();
    $frammande = Item::factory()->create(['container_id' => $annan->id]);

    actingAs($anvandare)
        ->post("/containers/{$container->ulid}/invitations", [
            'email' => 'ny2@exempel.se',
            'level' => 'read',
            'item' => $frammande->ulid,
        ])
        ->assertSessionHasErrors('item');

    expect(Invitation::query()->count())->toBe(1);
});

/*
 * Klart när: en mjukraderad item-rad i listan redovisas med sitt namn, inte som
 * "Hela containern". Uppslaget sker med `withTrashed()` — utan det hade ULID:n
 * fallit bort och raden lästs som en containerbred inbjudan.
 */
it('redovisar en inbjudan till ett mjukraderat item med sitt namn', function () {
    withoutVite();

    [, $anvandare, $container] = inbjudningsKontext();
    $item = Item::factory()->create(['container_id' => $container->id, 'name' => 'Den sålda motorn']);

    Invitation::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item->id,
        'email' => 'item@exempel.se',
        'invited_by_user_id' => $anvandare->id,
    ]);

    $item->delete();

    // Det mjukraderade itemet är inte längre ett giltigt omfång i formuläret.
    actingAs($anvandare)->get(inbjudningsSida($container))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('items', [])
        ->where('invitations.0.item', $item->ulid)
        ->where('itemNames', [$item->ulid => 'Den sålda motorn'])
    );
});

/*
 * Beslut 5: formuläret är sidan från 55a:s tredje sektion, med samma grind som
 * Åtkomster, och det ritas bara för den som får skriva. Nivåfältet är 55a:s
 * AccessLevelField, oförändrat — fyra nivåer, två synliga.
 */
it('har inbjudningsformuläret som tredje sektion på delningssidan', function () {
    $sida = File::get(resource_path('js/pages/Containers/Sharing.vue'));
    $formular = File::get(resource_path('js/components/InvitationForm.vue'));

    expect($sida)->toContain("t('sharing.invitations.heading')");
    expect($sida)->toContain('v-if="invitations"');
    expect($sida)->toContain('InvitationForm');
    expect($sida)->toContain('v-if="can.manage"');
    expect($sida)->toContain('t(`sharing.invitations.status.${invitation.status}`)');

    // Nivåfältet är 55a:s komponent, oförändrad — ingen avskrift i formuläret.
    expect($formular)->toContain('AccessLevelField');
    expect($formular)->toContain('form.errors.quota');
    expect($formular)->toContain("t('sharing.invitations.item_container')");

    // `item` skickas som `null` när hela containern valts: en tom sträng fastnar i
    // `Rule::exists` i den delade FormRequesten.
    expect($formular)->toContain("data.item === '' ? null : data.item");
});

/*
 * Klart när: varje ny nyckel finns på `sv` och `en`. Formuleringen bor i
 * lang/ och aldrig i en .vue-fil — den grinden ägs av SprakTest, som läser
 * hela resources/js.
 */
it('har inbjudningstexterna på båda språken', function () {
    $sv = require lang_path('sv/ui.php');
    $en = require lang_path('en/ui.php');

    foreach (['heading', 'description', 'email', 'item', 'item_container', 'submit', 'revoke', 'expires', 'invited_by', 'empty'] as $nyckel) {
        expect($sv['sharing']['invitations'][$nyckel])->not->toBe('')
            ->and($en['sharing']['invitations'][$nyckel])->not->toBe('');
    }

    foreach (['pending', 'expired', 'accepted', 'rejected', 'revoked'] as $status) {
        expect($sv['sharing']['invitations']['status'][$status])->not->toBe('')
            ->and($en['sharing']['invitations']['status'][$status])->not->toBe('');
    }

    foreach (['shared_users_exceeded', 'pending_invitations_exceeded'] as $nyckel) {
        expect($sv['error']['quota'][$nyckel])->not->toBe('')
            ->and($en['error']['quota'][$nyckel])->not->toBe('');
    }

    foreach (['already_pending', 'not_pending', 'expired', 'email_mismatch', 'email_not_verified'] as $nyckel) {
        expect($sv['error']['invitation'][$nyckel])->not->toBe('')
            ->and($en['error']['invitation'][$nyckel])->not->toBe('');
    }
});
