<?php

use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * M60 · issue 385 · Klockan, panelen och sidan `/notifications`, se
 * [[ADR-0077 Notislistan i appen]] § 2, 3 och 7,
 * resources/js/components/NotificationBell.vue,
 * resources/js/components/NotificationPanel.vue,
 * resources/js/components/NotificationRow.vue och
 * resources/js/pages/Notifications/Index.vue.
 *
 * **Det här är ett källkodsprov, som grannarna i den här katalogen.** Ytan
 * ritas i klienten — Inertia renderar layouten i webbläsaren, och en
 * svarskropp i sviten bär bara rotvyn — så det som går att pröva på
 * serversidan är formen på källkoden och katalogens nycklar. Att panelen
 * verkligen fälls ut, att fokus går tillbaka till klockan och att markeringen
 * syns först nästa gång listan öppnas kräver en webbläsare och står i
 * PR-kroppens handprov.
 *
 * Kommentarerna rensas bort före varje kontroll, som i GenomgangTest: filerna
 * är fulla av svensk prosa, och en regel som letar efter en tagg ska inte
 * kunna nöjas av en mening i ett docblock.
 *
 * Hjälparna har prefixet `notisvy` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * En fil ur resources/js med kommentarer borta.
 */
function notisvyKod(string $sokvag): string
{
    $kod = File::get(resource_path("js/{$sokvag}"));

    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/**
 * En oläst rad med färdig `in_app`-leverans, skriven med fabriken — samma
 * form som inAppRad() i tests/Feature/Notis/InAppKanalTest.php.
 *
 * @param  array<string, mixed>  $payload
 */
function notisvyRad(
    User $user,
    ?Carbon $skapad = null,
    string $typ = Notification::TYPE_TRANSFER_REQUESTED,
    array $payload = [],
): Notification {
    $notis = Notification::factory()->create([
        'user_id' => $user->id,
        'type' => $typ,
        'payload' => $payload,
        'created_at' => $skapad ?? now(),
    ]);

    NotificationDelivery::factory()->create([
        'notification_id' => $notis->id,
        'channel' => NotificationDelivery::CHANNEL_IN_APP,
        'status' => NotificationDelivery::STATUS_SENT,
        'attempts' => 0,
        'sent_at' => $skapad ?? now(),
        'read_at' => null,
    ]);

    return $notis;
}

/*
 * Klart när (punkt 1): klockan visar antalet olästa, öppnar panelen över
 * `md:` och leder till sidan under `md:`.
 *
 * Skalet ritar klockan som förut; formen bor i klockan själv. Över `md:` är
 * den en knapp med `aria-controls` och panelen som monteras först när den
 * öppnas; under `md:` är den en `<Link>` till `/notifications`. Siffran läses
 * ur den delade proppen `notifications.unread`, döljs vid noll och visar 9+
 * över nio.
 */
it('ritar klockan med notifications.unread, panel över md: och länk under md:', function () {
    $layout = notisvyKod('layouts/AppLayout.vue');
    $klocka = notisvyKod('components/NotificationBell.vue');

    expect($layout)->toContain('<NotificationBell v-if="user" />');

    // Siffran kommer ur den delade proppen — inte ur en egen räkning.
    expect($klocka)->toContain('page.props.notifications?.unread')
        // Över md: knappen som öppnar panelen, med relationen uttalad.
        ->and($klocka)->toContain(':aria-controls="panelId"')
        ->and($klocka)->toContain(':aria-expanded="isOpen"')
        ->and($klocka)->toContain(':aria-label="label"')
        // Under md: länken till sidan.
        ->and($klocka)->toContain('href="/notifications"')
        // Panelen monteras bara i sidhuvudets form och bara när den är öppen.
        ->and($klocka)->toContain('<NotificationPanel')
        ->and($klocka)->toContain('v-if="! isTab && isOpen"')
        // Träffytan och märket: 44 px, dolt vid noll, 9+ över nio.
        ->and($klocka)->toContain('min-h-11')
        ->and($klocka)->toContain("'9+'")
        ->and($klocka)->toContain('v-if="unread > 0"');

    // Panelen hämtar de tio senaste först när den öppnas.
    $panel = notisvyKod('components/NotificationPanel.vue');

    expect($panel)->toContain("fetch('/notifications/panel'")
        ->and($panel)->toContain('<NotificationRow')
        ->and($panel)->toContain("t('notifications.list.see_all')");
});

/*
 * Granskningen av #1315, fynd 1: klockan får inte bli `disabled` medan
 * läsningen postas. En inaktiverad knapp tappar fokus till `body`, och då når
 * Escape inte rotens lyssnare och fokus går inte tillbaka till klockan (§ 1).
 * Vänteläget uttalas i stället med `aria-disabled` (till skärmläsaren) och
 * `:pending` (formen GenomgangTest känner igen runt ett router-anrop), och
 * `toggle` ignorerar klicket så länge skrivningen är på väg — annars är
 * vänteläget bara en etikett.
 */
it('behåller fokus i klockan medan läsningen postas', function () {
    $klocka = notisvyKod('components/NotificationBell.vue');

    // Knappen fångas först: ett mönster som letar i hela filen hade också sett
    // panelens markup.
    preg_match('/<button\b.*?<\/button>/s', $klocka, $traff);
    $knapp = $traff[0] ?? '';

    expect($knapp)->not->toBe('', 'NotificationBell.vue ritar ingen <button>');
    expect($knapp)->not->toContain(':disabled')
        ->and($knapp)->toContain(':aria-disabled="marking"')
        // GenomgangTest kräver en väntelägesbindning runt router-anropet;
        // `:pending` är dess form, och `disabled` får det inte vara.
        ->and($knapp)->toContain(':pending="marking"');

    expect($klocka)->toContain('if (marking.value)');
});

/*
 * Klart när (punkt 2): panelen och sidan visar raderna med text per typ.
 * Rader med mål är länkar och rader utan mål är ren text.
 *
 * Raden är en `<li>` med EN länk: `v-if="notification.target"` avgör, och
 * `v-else`-grenen är en `<div>` utan hover. Meningen byggs ur typen, aldrig
 * ur en färdig sträng från servern.
 */
it('länkar en rad bara när den har ett mål', function () {
    $rad = notisvyKod('components/NotificationRow.vue');

    expect(substr_count($rad, '<Link'))->toBe(1)
        ->and($rad)->toContain('v-if="notification.target"')
        ->and($rad)->toContain(':href="notification.target"')
        ->and($rad)->toContain('v-else')
        // Oläst är `read_at === null`, och tiden skrivs av datumregeln.
        ->and($rad)->toContain('read_at === null')
        ->and($rad)->toContain('eventDate(')
        // Båda skälen för container.deleted väljs ur payloaden.
        ->and($rad)->toContain("'account_inactive'")
        ->and($rad)->toContain("'owner_deleted'");

    // Sidan ritar samma rad som panelen: en komponent, inte en avskrift.
    expect(notisvyKod('pages/Notifications/Index.vue'))->toContain('<NotificationRow')
        ->and(notisvyKod('components/NotificationPanel.vue'))->toContain('<NotificationRow');
});

/*
 * Granskningen av #1315, fynd 1: inbjudningarna hör kvar i klockan.
 *
 * En inbjudan är ingen notis — den skrivs aldrig till `notification` och
 * kommer därför inte med i `in_app` — men den väntar på ett svar och ska
 * synas på klockan ([[M20 Kontot]] § 131 och [[M22 Redo för testare]]).
 * Panelen och sidan ritar den delade proppen `pendingInvitations` med en rad
 * per inbjudan och länk till `/invitations`, märket räknar
 * `notifications.unread + pendingInvitations.length`, och inbjudningarna går
 * aldrig till `POST /notifications/read` — de försvinner först när de
 * besvarats eller gått ut.
 */
it('visar väntande inbjudningar och räknar dem i märket', function () {
    $klocka = notisvyKod('components/NotificationBell.vue');
    $panel = notisvyKod('components/NotificationPanel.vue');
    $sida = notisvyKod('pages/Notifications/Index.vue');

    // Märket: notiserna plus inbjudningarna, samma summa i namnet.
    expect($klocka)->toContain('page.props.pendingInvitations')
        ->and($klocka)->toContain('pendingInvitations.value.length')
        ->and($klocka)->toContain('page.props.notifications?.unread')
        ->and($klocka)->toContain(':pending-invitations="pendingInvitations"');

    // Panelen: raden bär katalogens mening och länken dit svaret ges, och
    // tomt läget kräver att båda listorna är tomma.
    expect($panel)->toContain('pendingInvitations')
        ->and($panel)->toContain("t('inbox.invitation.received'")
        ->and($panel)->toContain(':href="invitation.url"')
        ->and($panel)->toContain('props.pendingInvitations.length === 0');

    // Sidan ritar samma rader överst: under `md:` är klockan bara en länk
    // hit, så utan dem hade en inbjudan inte synts alls på mobilen.
    expect($sida)->toContain('page.props.pendingInvitations')
        ->and($sida)->toContain("t('inbox.invitation.received'")
        ->and($sida)->toContain(':href="invitation.url"')
        ->and($sida)->toContain('pendingInvitations.value.length === 0');

    // Läsningen rör bara notisraderna: panelen bygger `shown` ur fetch-svaret
    // och sidan postar sina `rows`, aldrig inbjudningarna.
    expect($panel)->toContain('.map((row) => row.ulid)')
        ->and($sida)->toContain('.filter((row) => row.read_at === null)');
});

/*
 * Klart när (punkt 3): sidan har *Load more* och *Mark all as read*, och
 * markeringen av de visade raderna postas när de visats.
 */
it('har Load more, Mark all as read och markeringen av visade rader på sidan', function () {
    $sida = notisvyKod('pages/Notifications/Index.vue');

    expect($sida)->toContain('defineOptions({ layout: AppLayout })')
        ->and($sida)->toContain("t('notifications.list.load_more')")
        ->and($sida)->toContain("t('notifications.list.mark_all_read')")
        ->and($sida)->toContain("t('notifications.list.empty')")
        ->and($sida)->toContain('@click="loadMore"')
        ->and($sida)->toContain('@click="markAll"')
        // Nästa sida hämtas med markören i en partiell omladdning.
        ->and($sida)->toContain("router.get('/notifications', { cursor: cursor.value }")
        ->and($sida)->toContain("only: ['notifications']")
        // Markeringen: visade rader postas, och alla rader har sin egen rutt.
        ->and($sida)->toContain("router.post('/notifications/read', { ulids }")
        ->and($sida)->toContain("router.post('/notifications/read-all'")
        // En h1 och en sidtitel, som varje sida under pages/.
        ->and($sida)->toContain('<h1')
        ->and($sida)->toContain('<Head :title=');
});

/*
 * Klart när (punkt 4): katalogen har nycklarna, båda skälen för
 * `container.deleted` inräknade.
 *
 * Nycklarna läses ur katalogen i stället för att räknas upp mot en avskrift av
 * källkoden, och båda skälen prövas för sig: en rad som glömde det ena skälet
 * hade visat en nyckel i stället för en mening.
 */
it('har notislistans strängar i ui.php, båda skälen för container.deleted inräknade', function () {
    /** @var array<string, mixed> $ui */
    $ui = require lang_path('en/ui.php');

    $lista = $ui['notifications']['list'] ?? [];

    foreach (['title', 'heading', 'bell', 'bell_unread', 'see_all', 'mark_all_read', 'load_more', 'empty', 'fallback'] as $nyckel) {
        expect($lista[$nyckel] ?? '')->not->toBe('', "ui.notifications.list.{$nyckel} saknas");
    }

    $typer = $lista['type'] ?? [];

    expect($typer['transfer']['requested'] ?? '')->not->toBe('')
        ->and($typer['quota']['warning'] ?? '')->not->toBe('')
        ->and($typer['container']['deleted']['owner_deleted'] ?? '')->not->toBe('')
        ->and($typer['container']['deleted']['account_inactive'] ?? '')->not->toBe('');

    // Skälen har samma innebörd som mejlet (lang/en/notiser.php §
    // container_deleted), och meningen bär containerns namn.
    $mejl = require lang_path('en/notiser.php');

    expect($mejl['container_deleted']['line_owner_deleted'] ?? '')->not->toBe('')
        ->and($mejl['container_deleted']['line_account_inactive'] ?? '')->not->toBe('')
        ->and($typer['container']['deleted']['owner_deleted'])->toContain(':container')
        ->and($typer['container']['deleted']['account_inactive'])->toContain(':container');
});

/*
 * Klockans siffra på sidan själv. `/notifications` bär `notifications` som
 * sidpropp och skuggar den delade sammanfattningen; 384 skickar därför med
 * `unread` i kartan, annars hade märket försvunnit just på notissidan.
 */
it('bär märket i sidans propp så att klockan visar rätt på /notifications', function () {
    withoutVite();

    Carbon::setTestNow(now());

    [, $anvandare] = kontoMedMedlem();

    notisvyRad($anvandare, now()->subMinute());
    notisvyRad($anvandare, now());

    actingAs($anvandare)->get('/notifications')->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('Notifications/Index')
            ->where('notifications.unread', 2)
    );
});
