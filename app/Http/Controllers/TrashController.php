<?php

namespace App\Http\Controllers;

use App\Actions\Trash\FindTrashedInContainer;
use App\Actions\Trash\ListTrash;
use App\Actions\Trash\RestoreContent;
use App\Exceptions\Api\ApiException;
use App\Http\Requests\Trash\RestoreRequest;
use App\Http\Resources\ContainerResource;
use App\Http\Resources\TrashEntryResource;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Container;
use App\Models\Item;
use App\Models\Tag;
use App\Models\User;
use App\Support\Frontend\ApiErrorTranslator;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Webbens papperskorg — det mjukraderade innehållet i en levande pärm,
 * den återstående tiden och återställningen, se issue 62a § Beslut 1–9.
 *
 * **Ingenting av `/api` görs om.** Listan och uppslaget av en enskild rad
 * är App\Actions\Trash\ListTrash respektive
 * App\Actions\Trash\FindTrashedInContainer — exakt de två Actions som
 * App\Http\Controllers\Api\TrashController anropar sedan issue 62a, och
 * återställningen är App\Actions\Trash\RestoreContent, orörd (§ Beslut 2).
 * `RestoreRequest` och `TrashEntryResource` delas rakt av.
 *
 * **Papperskorgen för raderade PÄRMAR är 62b.** Här finns ingen rad för en
 * mjukraderad container (den når aldrig fram: SoftDeletes' globala scope
 * löser inte upp `{container}`), ingen raderingsknapp och ingen tömning.
 * Gallringen är schemalagd och ska inte gå att framkalla ur en vy
 * ([[ADR-0008 Soft delete och papperskorg]] § Retentionstiden i MVP), så
 * det finns ingen "radera permanent" här heller.
 *
 * **Grinden per rad upprepas från `Api\TrashController::authorizeRestore()`
 * med flit** (§ Beslut 3): `item` och bilagans item mot
 * `ItemPolicy::delete()`, `category` och `tag` mot
 * `ContainerPolicy::update()`. Den flyttar inte in i en Action, för då hade
 * behörighetsbeslutet lämnat kontrollern ([[ADR-0024 Tunna controllers och
 * actions]]). Bilagans förälder slås upp med `withTrashed()` av exakt det
 * skäl som står i API-kontrollern: en bilaga vars item ligger i
 * papperskorgen ska nå `RestoreContent` och få `trash.parent_deleted`, inte
 * 403.
 *
 * **Flaggan `can_restore` är presentation** (§ Beslut 6). Den ritas ur
 * samma grind som `restore()` prövar, men rutten auktoriserar ändå — en
 * `read`-deltagare ser listan utan knappar och får 403 om hon postar.
 *
 * **Ingen text och inget tal avslöjar omfånget** (§ Beslut 5, issue 74
 * § Beslut 1 och issue 73 § Beslut 6). En tom papperskorg ger samma mening
 * för mottagaren som för ägaren, och vyn räknar aldrig rader.
 *
 * Rutterna ligger bakom `auth` (routes/web.php) — en utloggad besökare
 * skickas till /login av middlewaren och når aldrig de här metoderna.
 */
class TrashController extends Controller
{
    /**
     * GET /containers/{container}/trash.
     *
     * Grinden är `view()` — varje deltagare ser papperskorgen, också en
     * `read`-guest, precis som på `/api` (issue 74 § Beslut 3). Att se den
     * och att få återställa ur den är två skilda pinnar.
     *
     * `entries` är `TrashEntryResource`-rader, samma sex nycklar som `/api`
     * svarar med, så vyn formulerar ingen egen form av en papperskorgspost.
     * `canRestore` är uppslaget `ulid → bool` BREDVID raderna (§ Beslut 6):
     * resursen är delad med `/api` och får inget nytt fält, samma regel som
     * kategorinamnen i issue 57a § Beslut 1.
     *
     * Ägarkontot laddas uttryckligen: `ContainerResource::make()` läser
     * `$container->account`, och `ItemPolicy` läser samma relation genom
     * varje rads grindobjekt — utan den hade den kostat ett uppslag.
     */
    public function index(Request $request, Container $container, ListTrash $listTrash): Response
    {
        Gate::authorize('view', $container);

        $user = $request->user();

        $container->loadMissing('account');

        $list = $listTrash->handle($user, $container);

        return Inertia::render('Containers/Trash', [
            'container' => ContainerResource::make($container)->resolve($request),
            'entries' => TrashEntryResource::collection($list['entries'])->resolve($request),
            'canRestore' => (object) $this->canRestore($user, $container, $list),
        ]);
    }

    /**
     * POST /containers/{container}/trash/restore — 302 tillbaka till
     * papperskorgen.
     *
     * Kroppen är `/api`:s: `type` och `ulid`, inte en URL per typ, eftersom
     * fyra typer delar en lista (issue 20a § Beslut 1). `RestoreRequest` är
     * delad och svarar på samma sätt: en ULID ur en annan pärm eller en
     * levande rad är ett valideringsfel, och på webben blir det ett fältfel
     * på `ulid` i stället för en 422-kropp ([[ADR-0020 Plattformsidentitet
     * och frontendgräns]] § Konsekvenser).
     *
     * **Ett utgånget innehåll är 404.** Uppslaget tillämpar retentionen, och
     * hittar det inget finns raden inte — varken i listan eller som en
     * återställning (§ Beslut 5). Valideringen släpper med flit igenom
     * utgångna rader, se RestoreRequest.
     *
     * **Ett domänfel blir ett formulärfel, aldrig en JSON-kropp** (§ Beslut
     * 7). `RestoreContent` kastar `ApiException` för `trash.parent_deleted`
     * — en bilaga vars item ligger kvar i papperskorgen, eller en
     * underkategori vars förälder gör det — och App\Support\Frontend\
     * ApiErrorTranslator gör koden till en mening på användarens språk.
     * Nyckeln är `trash` och inte ett fältnamn: felet handlar inte om vad
     * användaren skrev, och vyn renderar `errors.trash` som en ruta ovanför
     * listan — samma mönster som issue 55b valde för en obesvarad inbjudan.
     */
    public function restore(
        RestoreRequest $request,
        Container $container,
        FindTrashedInContainer $findTrashed,
        RestoreContent $restoreContent,
        ApiErrorTranslator $translator,
    ): RedirectResponse {
        $type = $request->validated('type');
        $model = $findTrashed->handle($container, $type, $request->validated('ulid'));

        abort_if($model === null, 404);

        $this->authorizeRestore($container, $model);

        try {
            $restoreContent->handle($type, $model);
        } catch (ApiException $e) {
            throw ValidationException::withMessages(['trash' => $translator->message($e)]);
        }

        return back()->with('status', 'trash-restored');
    }

    /**
     * Grinden för EN återställning, vald på radens typ — en medveten kopia
     * av `Api\TrashController::authorizeRestore()`, se klassens docblock och
     * § Beslut 3.
     *
     * `item` grindas mot sitt eget item, `attachment` mot itemet den hänger
     * på, och de två containervida typerna mot containern: en tagg eller
     * kategori är pärmens organisation och inte någons item, så en
     * omfångsbegränsad mottagare får 403 här.
     */
    private function authorizeRestore(Container $container, Item|Attachment|Category|Tag $model): void
    {
        if ($model instanceof Item) {
            Gate::authorize('delete', $model);

            return;
        }

        if ($model instanceof Attachment) {
            $item = Item::withTrashed()->find($model->item_id);

            abort_if($item === null, 404);

            Gate::authorize('delete', $item);

            return;
        }

        Gate::authorize('update', $container);
    }

    /**
     * `ulid → bool` för återställningsknappen, ur SAMMA grind som
     * `restore()` prövar (§ Beslut 6).
     *
     * Kostnaden är konstant, aldrig en fråga per rad:
     *
     * - `item`-rader grindas mot itemet som listan redan laddat.
     * - `attachment` grindas mot sitt ITEM (issue 74 § Beslut 2), och det
     *   itemet kan leva — då finns det inte bland papperskorgens rader.
     *   Bilagornas items hämtas därför i EN fråga för hela listan.
     * - `category` och `tag` grindas mot containern, och den frågan ställs EN
     *   gång: `ContainerPolicy::update()` kostar ett `exists()` per anrop
     *   (`isMemberOfOwnerAccount()`), och svaret är detsamma för varje
     *   containervid rad.
     *
     * Itemets container sätts i minnet i stället för att slås upp:
     * `ItemPolicy::allows()` läser `$item->container->account`, och en lat
     * hämtning per rad är precis den N+1 mätningen ska fånga. Själva omfånget
     * kostar ingenting extra — App\Actions\Access\ResolveItemScope är
     * registrerad `scoped` och memoiserad per `{user}:{container}`, och
     * ListTrash har redan löst upp det.
     *
     * @param  array{entries: list<array{type: string, ulid: string, label: string, context: string|null, deleted_at: Carbon|null, expires_at: Carbon|null}>, subjects: array<string, Item|Attachment|Category|Tag>}  $list
     * @return array<string, bool>
     */
    private function canRestore(?User $user, Container $container, array $list): array
    {
        $parents = $this->attachmentItems($list);

        $containerWide = null;

        $flags = [];

        foreach ($list['entries'] as $entry) {
            $subject = $list['subjects'][$entry['ulid']];

            $flags[$entry['ulid']] = match (true) {
                $subject instanceof Item => Gate::forUser($user)->allows('delete', $this->inContainer($subject, $container)),
                $subject instanceof Attachment => ($parent = $parents[$subject->item_id] ?? null) !== null
                    && Gate::forUser($user)->allows('delete', $this->inContainer($parent, $container)),
                // Kategori och tagg — containervida, som i restore(). EN fråga
                // för hela listan, se docblocken.
                default => $containerWide ??= Gate::forUser($user)->allows('update', $container),
            };
        }

        return $flags;
    }

    /**
     * Bilagornas items, i EN fråga för hela listan och nycklade på sitt
     * löpnummer.
     *
     * `withTrashed()`: en bilaga vars item ligger i papperskorgen ska ge
     * samma svar här som rutten ger — den når `RestoreContent` och får
     * `trash.parent_deleted`, den blir inte 403. Exakt samma uppslag och
     * samma skäl som i `authorizeRestore()` och i
     * App\Actions\Trash\RestoreContent.
     *
     * @param  array{entries: list<array{type: string, ulid: string, label: string, context: string|null, deleted_at: Carbon|null, expires_at: Carbon|null}>, subjects: array<string, Item|Attachment|Category|Tag>}  $list
     * @return array<int, Item>
     */
    private function attachmentItems(array $list): array
    {
        $itemIds = [];

        foreach ($list['subjects'] as $subject) {
            if ($subject instanceof Attachment) {
                $itemIds[] = $subject->item_id;
            }
        }

        if ($itemIds === []) {
            return [];
        }

        return Item::withTrashed()
            ->whereIn('id', array_unique($itemIds))
            ->get()
            ->keyBy('id')
            ->all();
    }

    /**
     * Itemet med sin container satt i minnet. Alla rader i listan hör till
     * samma container — den är redan laddad, och `ItemPolicy` läser
     * `$item->container->account` för varje grind.
     */
    private function inContainer(Item $item, Container $container): Item
    {
        $item->setRelation('container', $container);

        return $item;
    }
}
