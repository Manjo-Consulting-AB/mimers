<?php

namespace App\Http\Controllers;

use App\Actions\Attachment\RecordAttachmentOpen;
use App\Actions\Security\RecordSecurityEvent;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\SecurityLog;
use App\Support\Files\AttachmentDelivery;
use App\Support\Files\FileOrigin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /files/{attachment} på appdomänen — nedladdning av en bilagas byten,
 * se issue 19a och [[ADR-0019 Filleverans]]. En rutt utanför /api: den
 * klickas i en webbläsare och lämnar inga JSON-fel (Beslut 1).
 *
 * **Rutten är appdomänens ingång till leveransen, oavsett var bytena kommer
 * ifrån** (issue 61a § Beslut 1). Är `config('files.url')` satt — och
 * värdnamnet där skiljer sig från appens — svarar den 302 till en kortlivad
 * signerad URL på filoriginet och levererar ingenting själv; i annat fall
 * levererar den bytena precis som förut (Beslut 2). Att appdomänens URL
 * består som ingång är hela poängen: varje befintlig klient,
 * deploy/verifiera-filleverans.sh, en kommande mobilapp och 60a:s
 * nedladdningslänk fortsätter fungera oförändrade — en webbläsare och
 * `curl -L` följer omdirigeringen, och en `<img src="/files/{ulid}?variant=thumb">`
 * likaså.
 *
 * **Sessionen är skälet till att det måste se ut så.** Sessionskakan gäller
 * appens värdnamn, inte filoriginets; en rutt på `files.mimers.app` som
 * frågade `auth:sanctum` hade nekat varje inloggad användare. Signaturen är
 * därför inte ett extra lager ovanpå inloggningen — den ÄR autentiseringen på
 * det originet, precis som tokenet är det för ICS-feeden (36a § Beslut 1) och
 * den signerade länken för avanmälan (32b § Beslut 1).
 *
 * **Behörighetsprövningen sker här, och bara här** (§ Beslut 4). Den som har
 * länken får hämta filen under länkens livstid, utan session — samma
 * egenskap som en presignerad S3-URL har. Behörigheten prövades när länken
 * präglades; på originet finns ingen användare att pröva den mot.
 *
 * INGEN behörighetslogik utöver det bor här: efter att ägaren visat sig inte
 * vara mjukraderad anropas bara Gate::authorize('view', ...) — sedan issue 71
 * mot BILAGANS ITEM, App\Policies\ItemPolicy::view() (Beslut 5). Läsning
 * räcker för att ladda ner; det är aldrig `update` och aldrig containern. Det
 * här är filleverans, och en containergrind där itemets skulle stått är exakt
 * det fel [[ADR-0028 Åtkomst på itemnivå]] § Konsekvenser räknar upp: en
 * omfångsbegränsad mottagare hade kunnat hämta en bilaga på ett item hon inte
 * ser.
 *
 * **Sedan issue 158 har en bilaga två slag** ([[ADR-0047 Containerns bild]]).
 * Ägaren kommer ur App\Models\Attachment::accessSubject() — itemet för en
 * itembilaga, containern för en containerbilaga — och grinden ställs mot
 * exakt det som kom tillbaka. Containern för en itembilaga grindas alltså
 * fortfarande INTE: dess `view` hade släppt igenom varje medlem i ägarkontot
 * och därmed också en itemgrant-innehavare förbi itemets omfång. Valet av
 * ägare är det enda som avgör, och det valet bor i modellen och inte här.
 *
 * **Sedan issue 177 skriver rutten också en öppning** i `attachment_open`,
 * för "senast öppnade filer" i dokumentfliken ([[ADR-0051 Senast öppnade
 * filer]]). Den ligger efter grinden och före båda leveransgrenarna, och
 * vad som räknas som en öppning står i `recordOpen()` nedan. Rutten är den
 * ENDA som skriver: `files.deliver` levererar bytena men vet inte vem som
 * frågar.
 *
 * `{attachment}` binds på bilagans ULID via #[RouteKey('ulid')] — en
 * mjukraderad bilaga syns inte av bindningen och ger 404 (Beslut 7).
 *
 * Sökvägen till bytena är aldrig indata (Beslut 8): rutten tar en ULID, slår
 * upp stored_file-raden och levererar den sökväg som står i databasen.
 */
class AttachmentDownloadController extends Controller
{
    public function __invoke(
        Request $request,
        Attachment $attachment,
        RecordAttachmentOpen $recordAttachmentOpen,
        RecordSecurityEvent $recordSecurityEvent,
    ): Response {
        $attachment->load(['storedFile', 'item.container', 'container']);

        // Vilket item eller vilken container bilagan hör till avgörs på ETT
        // ställe, i modellen ([[ADR-0047 Containerns bild]] § Beslut, sista
        // stycket) — inte här. Sedan issue 158 finns två slag av bilaga, och
        // den här raden var den enda som frågade "vilket item?".
        //
        // SoftDeletes' globala scope gäller genom relationerna: båda är
        // belongsTo mot mjukraderingsmodeller, så ett raderat item eller en
        // raderad container ger null här och 404 redan i === null-grenen.
        // trashed()-anropet är bälte-och-hängslen om någon senare lägger
        // withTrashed() i bindningen — det är inte det som skyddar i dag
        // (Beslut 7).
        $subject = $attachment->accessSubject();

        if ($subject === null || $subject->trashed()) {
            abort(404);
        }

        // Containern i säkerhetsloggen nedan. För en itembilaga är det
        // itemets container, för en containerbilaga containern själv.
        $container = $attachment->owningContainer();

        if ($container === null || $container->trashed()) {
            abort(404);
        }

        // Grinden blir `view` på containern när `container_id` är satt och
        // `view` på itemet när `item_id` är satt (ADR-0047 § Beslut). För en
        // itembilaga är det alltså alltjämt itemgrinden, och
        // [[ADR-0028 Åtkomst på itemnivå]] gäller oförändrat: en
        // omfångsbegränsad mottagare når sitt item och inget annat. För en
        // containerbilaga är det containergrinden, som en itemgrant passerar
        // — bilden är containerns ansikte, och den som ser containerns namn
        // ser också dess bild.
        Gate::authorize('view', $subject);

        $variant = $request->query('variant');
        $filorigin = FileOrigin::host();

        // Öppningen skrivs efter grinden och FÖRE båda grenarna nedan: raden
        // hör till den här rutten och inte till leveransen, och en 302 till
        // filoriginet är samma öppning som en direkt leverans (issue 177 ·
        // [[ADR-0051 Senast öppnade filer]] § Beslut).
        $this->recordOpen($request, $attachment, $variant, $recordAttachmentOpen);

        if ($filorigin !== null) {
            $svar = redirect()->to(self::signedDeliveryUrl($attachment, $variant));
        } else {
            // Ingen egen origin: leveransen ligger kvar på appdomänen och allt är
            // attachment, utan undantag ([[ADR-0019 Filleverans]] § Uppföljning
            // 2026-08-31, andra punkten).
            $svar = AttachmentDelivery::make($attachment, $variant, inline: false);
        }

        // Loggrader skrivs först när svaret är byggt, aldrig före (issue 113):
        // båda grenarna slår upp varianten medan de byggs, och en okänd eller
        // saknad variant ger 404 redan där (AttachmentDelivery::storagePath) —
        // liksom en fil som inte finns på disken. Ett 404 är ingen nedladdning,
        // och en rad som påstod en händelse som inte hände vore sämre än ingen
        // rad. Samma regel som ExportDownloadController och LiftLegalHold följer.
        $this->recordForeignDownload($request, $attachment, $container, $recordSecurityEvent);

        return $svar;
    }

    /**
     * Öppningen, i "senast öppnade filer" (issue 177 · [[ADR-0051 Senast
     * öppnade filer]] § Beslut 1).
     *
     * **Vad som är en öppning avgörs här, och bara här.** Två undantag:
     *
     * - **Containerns egen bild räknas aldrig** ([[ADR-0047 Containerns
     *   bild]]). En bilaga med `item_id = NULL` hör till containern och inte
     *   till något item, och en containerbild är containerns ansikte — den
     *   ritas i skalet och inte av någon som öppnat en fil.
     * - **Miniatyren räknas inte.** `?variant=thumb` ritas i en lista utan
     *   att någon öppnat något, och en rad för den hade fyllt taket med
     *   filer ingen tittat på. Det som räknas är nedladdningen och
     *   PDF-förhandsvisningen (`variant` saknas) och bildvisarens `medium` —
     *   se `attachmentPresentation.js`, där `thumb` är listbilden och
     *   `medium` är bilden som öppnas.
     *
     * **Anropet ligger efter `Gate::authorize()`**, som för besöksraden i
     * ItemController::show(): en nekad förfrågan kastar innan raden skrivs,
     * så en bilaga utanför omfånget lämnar varken en rad eller ett spår.
     * `files.deliver` anropar aldrig den här metoden — den rutten bär en
     * signerad URL och vet inte vem som frågar.
     *
     * **Varianten valideras före skrivningen.** Ett okänt värde eller en
     * variant som saknas ger 404, och en 404 är ingen öppning. Valideringen
     * är `AttachmentDelivery::storagePath()` och inte en egen kontroll av
     * derivatraderna: regeln om vilka varianter som finns bor där, och två
     * formuleringar av den hade glidit isär. Grenarna nedan slår upp samma
     * variant en gång till när de bygger sitt svar — det är priset för att
     * kunna skriva före dem, och för `variant` utan värde kostar det ingen
     * fråga alls.
     *
     * **Skrivningen ligger före båda grenarna nedan**, så att 302:an till
     * filoriginet och den direkta leveransen skriver samma rad. Alla 404:ar
     * som kan komma före den ligger ovanför: bilagan finns inte, containern
     * finns inte, eller varianten finns inte.
     *
     * Säkerhetsloggen (recordForeignDownload) ligger i stället EFTER att
     * svaret byggts, och skillnaden är vad de två beskriver. Loggen är en
     * missbrukssignal och skrivs inte för en fil som visar sig saknas på
     * disken; öppningen är vad användaren gjorde — hon bad om filen — och
     * den sista 404:an (bytena borta, issue 167) lämnar därför en
     * öppningsrad men ingen loggrad.
     */
    private function recordOpen(
        Request $request,
        Attachment $attachment,
        mixed $variant,
        RecordAttachmentOpen $recordAttachmentOpen,
    ): void {
        if ($attachment->item_id === null) {
            return;
        }

        if ($variant !== null && $variant !== 'medium') {
            return;
        }

        AttachmentDelivery::storagePath($attachment->storedFile, $variant);

        $recordAttachmentOpen->handle($request->user(), $attachment);
    }

    /**
     * Nedladdningen ur någon ANNANS container, i säkerhetsloggen (issue 113).
     *
     * **Den enda läsning som loggas** ([[ADR-0043 Tre loggar]]
     * § Säkerhetsloggen). Att logga varje visning vore en logg över allt alla
     * tittar på; en nedladdning ur en container man inte äger är däremot när
     * innehållet lämnar sin ägare, och det är där både dataintrång och
     * spridning av olagligt material syns.
     *
     * **"Äger" är medlemskap i ägarkontot**, samma mått som händelseloggens
     * regel 1 använder — en container har exakt en ägare och ägaren är ett
     * konto (AGENTS.md § Sådant som är lätt att göra fel). Den som laddar ner
     * ur sitt eget kontos container skriver alltså ingen rad, hur hon än nådde
     * den: som ägare, som medlem eller som inbjuden gäst.
     *
     * **Raden skrivs när länken präglas**, det vill säga här, och inte på
     * fildomänen: FileDeliveryController har ingen session och ingen
     * användare, och en leverans som loggas där kunde bara säga att NÅGON
     * hämtade filen (issue 113 § Omfångsrutan). Filnamnet följer aldrig med —
     * det är användarens text (ADR § Händelseloggen), och det bor i itemet.
     *
     * **Anropet ligger efter att svaret byggts, inte före.** En nedladdning
     * som slutar i 404 — okänd eller saknad variant, eller en fil som inte
     * finns på disken — är ingen nedladdning, och ska inte lämna en rad som
     * påstår motsatsen.
     */
    private function recordForeignDownload(
        Request $request,
        Attachment $attachment,
        Container $container,
        RecordSecurityEvent $recordSecurityEvent,
    ): void {
        $user = $request->user();

        // `accounts` är den lista App\Actions\Access\ResolveItemScope läste
        // några rader ovanför, i grinden: samma användarinstans, samma
        // relation, redan i minnet. Kontrollen kostar därför ingen fråga på
        // den här vägen — och är listan mot förmodan inte laddad laddas den
        // här, en gång, aldrig en per bilaga.
        $äger = $user !== null
            && $user->accounts->contains('id', $container->account_id);

        if ($äger) {
            return;
        }

        $recordSecurityEvent->handle(
            action: SecurityLog::ACTION_ATTACHMENT_DOWNLOADED,
            account: $container->account,
            user: $user,
            ip: $request->ip(),
            userAgent: $request->userAgent(),
            meta: [
                'container' => $container->ulid,
                'attachment' => $attachment->ulid,
            ],
        );
    }

    /**
     * Den kortlivade signerade URL:en till filoriginet.
     *
     * Signaturen säger vilken fil länken gäller — bilagans ULID och eventuell
     * variant — och aldrig vem som bad om den (Beslut 4). Den präglas över
     * hela URL:en inklusive querysträngen, så byter någon ut ULID:n eller
     * varianten i den färdiga länken slutar signaturen stämma och
     * `signed`-middlewaren nekar.
     *
     * Livstiden är `config('files.signed_url_ttl_minutes')`, 15 minuter som
     * standard: långt nog för att en stor PDF ska hinna laddas och en
     * bläddring i ett bildgalleri ska hinna ske, kort nog för att en länk som
     * hamnar i en logg eller ett `Referer`-huvud ska vara död när någon
     * hittar den.
     */
    private static function signedDeliveryUrl(Attachment $attachment, mixed $variant): string
    {
        // Varianten valideras före präglingen: ett okänt värde eller en
        // variant som saknas ger 404 redan här. Annars hade en signerad länk
        // präglats och först på originet visat sig peka på ingenting — och
        // den som fick länken hade fått en 404 i stället för ett svar på
        // appdomänen, där felet hör hemma.
        AttachmentDelivery::storagePath($attachment->storedFile, $variant);

        $parameters = ['attachment' => $attachment->ulid];

        if (is_string($variant)) {
            $parameters['variant'] = $variant;
        }

        return URL::temporarySignedRoute(
            'files.deliver',
            now()->addMinutes((int) config('files.signed_url_ttl_minutes')),
            $parameters,
        );
    }
}
