<?php

namespace App\Http\Controllers;

use App\Actions\Attachment\ListAccessibleAttachments;
use App\Actions\Attachment\ListContainerAttachments;
use App\Http\Resources\AttachmentResource;
use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Den globala dokumentlistan — `GET /documents`, se M28 (testarnas fynd
 * 2026-10-07) och App\Actions\Attachment\ListAccessibleAttachments.
 *
 * **Sidan är containerns dokumentflik över hela kontot.** Fliken visar en
 * containers filer (issue 178); här ser användaren samma sak i varje container
 * hon når OCH i sin egen inbox, på ett ställe. Urvalet, omfånget per container
 * och sorteringen är actionens — kontrollern ritar bara propparnas form
 * ([[ADR-0024 Tunna controllers och actions]]).
 *
 * **Filtren står i querysträngen** (Beslut 2): `container[]` (ULID:er plus
 * ordet `inbox`), `kind[]` och `sort`. `kind` och `sort` har containerns fliks
 * värden och läses ur ListContainerAttachments — samma tre typer och samma
 * fyra sorteringar, EN formulering (`kindFilters()` och `SORTS`). Filtret
 * LÄSES en gång och används två: som fråga till actionen och som
 * `filter`-propp till vyn. Två läsningar av samma sträng glider isär, och då
 * visar fältet ett filter listan inte tillämpade.
 *
 * **Ett värde som inte går att läsa utelämnas i stället för att avvisas**,
 * samma linje som ContainerDocumentController: en ULID som inte är en ULID, en
 * typ utanför de tre och en okänd sortering är alla "inget filter" — en
 * handredigerad adress ska ge listan och inte ett formulärfel på ett fält
 * användaren inte har ([[ADR-0038 Gränsen för Pro i kostnaderna]] § Beslut om
 * formulärfel).
 *
 * **Grinden är `auth` och ingenting mer.** Rutten har ingen container och
 * inget item att pröva en grind mot, och listan är byggd ur det användaren
 * når: en främling har ingen lista att få, och en tom lista är rätt svar för
 * en användare utan containrar.
 *
 * **Raden är resursen plus två grannar** (Beslut 3): `item` och `container`
 * som `{ulid, name}` BREDVID AttachmentResource — resursen är `/api`:s
 * kontrakt och har inte bett om fälten, samma mönster som `item` i
 * ContainerDocumentController. **En inboxfil bär `in_inbox: true` och varken
 * item eller container**: inboxen och dess item är dolda ([[ADR-0054 Inboxen]]
 * § 1), och varken deras ULID eller namn får lämna servern.
 */
class DocumentController extends Controller
{
    /**
     * GET /documents — 200.
     */
    public function __invoke(Request $request, ListAccessibleAttachments $listAttachments): Response
    {
        $user = $request->user();

        $filter = $this->filter($request) + ['sort' => $this->sort($request)];

        // Raderna formas ur sidans MODELLER: `through()` ersätter dem med
        // arrayer, och `item.container` är eager-laddad av actionen så att
        // uppslaget kostar noll frågor per rad (Beslut 5).
        $rows = $listAttachments->handle($user, $filter)->through(
            fn (Attachment $attachment): array => $this->row($request, $attachment),
        );

        return Inertia::render('Documents/Index', [
            // Rader i en sida om femtio, i den valda ordningen. Paginatorn går
            // rakt igenom: sidnumret kommer ur `?page=` och räknas av
            // ramverket, och vyn ritar bara de länkar den får — med filtren
            // kvar, så en `?page=2` inte tyst byter fråga mitt i en listning.
            'attachments' => $rows,
            // Filtret så som servern tillämpade det, med ALLA nycklar:
            // `container` och `kind` som LISTOR — `[]` när gruppen inte
            // filtrerar — och `sort` med förvalet ifyllt. Vyn ritar sina fält
            // ur proppen och läser aldrig adressen själv, samma form och samma
            // skäl som `filter` i ContainerDocumentController.
            'filter' => $filter,
            // Containerväljaren med antalen (Beslut 4): varje container
            // användaren når, och inboxen som `inbox` först när den har filer.
            // Antalen följer inte det valda filtret — en väljare där alla
            // andra alternativ stod på noll hade sagt att de är tomma.
            'containers' => $listAttachments->containers($user),
            // Typantalen (Beslut 4), samma regel: antalet bilder användaren
            // når, oavsett vad som är valt.
            'kinds' => $listAttachments->kinds($user),
        ]);
    }

    /**
     * Raden ur resursen, med sitt item och sin container (Beslut 3).
     *
     * **Inboxens item och container lämnar aldrig servern.** Är containern en
     * inbox är båda `null` och `in_inbox` sann i stället: vyn ritar platsen
     * *Inbox* ur sin egen ordlista, precis som `/inbox` gör, och en ULID den
     * inte får se hade varit en länk till en sida som inte finns (ADR-0054
     * § 1).
     *
     * @return array<string, mixed>
     */
    private function row(Request $request, Attachment $attachment): array
    {
        $item = $attachment->item;
        $container = $item->container;
        $inInbox = $container->isInbox();

        return [
            ...AttachmentResource::make($attachment)->resolve($request),
            'item' => $inInbox ? null : [
                'ulid' => (string) $item->ulid,
                'name' => (string) $item->name,
            ],
            'container' => $inInbox ? null : [
                'ulid' => (string) $container->ulid,
                'name' => (string) $container->name,
            ],
            'in_inbox' => $inInbox,
        ];
    }

    /**
     * Filtret ur querysträngen, normaliserat (Beslut 2).
     *
     * `container` tar ULID:er och ordet `inbox`; allt annat faller bort, och
     * ingenting slås upp. Att slå upp ULID:erna är actionens sak, och en ULID
     * som pekar utanför det användaren når ska ge noll rader och inte ett
     * avvisat filter (ListAccessibleAttachments § docblock).
     *
     * `kind` normaliseras av actionen och inte av en egen lista här: samma tre
     * värden, en formulering (ListContainerAttachments::kindFilters()).
     *
     * @return array{container: list<string>, kind: list<string>}
     */
    private function filter(Request $request): array
    {
        return [
            'container' => $this->containerFilter($request->query('container')),
            'kind' => ListContainerAttachments::kindFilters($request->query('kind')),
        ];
    }

    /**
     * Sorteringen ur querysträngen, med förvalet `newest` (Beslut 2). Listan
     * av giltiga värden är containerns fliks (ListContainerAttachments::SORTS).
     */
    private function sort(Request $request): string
    {
        $sort = $request->query('sort');

        return is_string($sort) && in_array($sort, ListContainerAttachments::SORTS, true) ? $sort : 'newest';
    }

    /**
     * Containervärdena ur en query-parameter, som en lista (Beslut 2).
     *
     * Både `container=<ulid>` och `container[]=<ulid>&container[]=inbox` tas
     * emot: en sträng blir en lista med ett element, och listan DEDUPLICERAS
     * så att samma värde två gånger är ett värde. FORMEN prövas och ingenting
     * slås upp — `Str::isUlid()` är samma kontroll som `HasUlid` bygger på,
     * och en sträng som varken är en ULID eller ordet `inbox` kan inte matcha
     * en container.
     *
     * @return list<string>
     */
    private function containerFilter(mixed $value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $giltiga = array_filter(
            $value,
            static fn (mixed $val): bool => is_string($val)
                && ($val === ListAccessibleAttachments::INBOX || Str::isUlid($val)),
        );

        return array_values(array_unique($giltiga));
    }
}
