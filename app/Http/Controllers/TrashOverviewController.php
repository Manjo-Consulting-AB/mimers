<?php

namespace App\Http\Controllers;

use App\Actions\Trash\ListAllTrash;
use App\Actions\Trash\RestoreContent;
use App\Actions\Trash\RestoreTrashedContainer;
use App\Exceptions\Api\ApiException;
use App\Http\Requests\Trash\RestoreAnyRequest;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\Item;
use App\Models\User;
use App\Support\Frontend\ApiErrorTranslator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Den samlade papperskorgen — `/trash` — se
 * [[ADR-0065 Den samlade papperskorgen]] § 2–9 och
 * [[M39 Den samlade papperskorgen]] § 319.
 *
 * **Listan är App\Actions\Trash\ListAllTrash och ingenting annat.** Urvalet
 * över alla containrar, inboxen, omfånget och vem som raderade bor i
 * actionen (issue 318). Kontrollern läser `q`, `type` och `cursor` ur
 * querysträngen, normaliserar `type` och skickar vidare. Ett okänt `type`
 * räknas som alla tre och ger inget fel — actionen gör samma normalisering,
 * och kontrollern speglar den i `filter` så att vyn visar det läge servern
 * faktiskt tillämpade.
 *
 * **`canRestore` räknas per rad ur SAMMA grind som återställningen prövar**
 * (§ 8), precis som i `ContainerTrashController` och `TrashController`.
 * Rader och grindobjekt kommer ur actionens `subjects`, så flaggan kostar
 * ingen ny fråga: itemets container och containerns ägarkonto ligger redan i
 * minnet, och bilagans item byggdes i actionen.
 *
 * **`entries` bär `kind` för bilagor.** Actionen lämnar `icon` tom för
 * bilagor — de har ingen itemikon — men raden ska rita bilagans typikon
 * (§ 4). Actionen väljer inte `kind`, så kontrollern hämtar den för sidans
 * bilagor i EN fråga, bredvid `deleted_by`-uppslaget.
 *
 * **Återställningen delegerar till dagens två vägar** (§ 8):
 *
 * - Items och bilagor: uppslaget och `RestoreContent` som i
 *   `TrashController::restore()`, och samma grind genom att
 *   `TrashController::authorizeRestore()` anropas — den flyttas inte.
 * - Containrar: uppslaget, grinden `ContainerPolicy::delete()` och
 *   `RestoreTrashedContainer` som i `ContainerTrashController::restore()`.
 *
 * **Containern är radens egen och måste vara levande.** ListAllTrash listar
 * aldrig innehåll i en raderad container (ADR-0065 § 3); en klient som
 * postar en sådan ULID förbi vyn får 404, samma svar som en rad som inte
 * finns. Villkoret gäller både item och bilaga.
 *
 * **Svaret leder tillbaka till `/trash` med filtret kvar** (§ 8): `q` och
 * `type` ur querysträngen följer med, så att en återställning från en
 * filtrerad lista inte tappar filtret. Flashkoden är den befintliga för
 * slaget — `trash-restored` för innehåll och `container-restored` för en
 * container — så `FlashMessage` formulerar samma mening som förut.
 *
 * Rutterna ligger bakom `auth` (routes/web.php) — en utloggad besökare
 * skickas till /login och når aldrig de här metoderna.
 */
class TrashOverviewController extends Controller
{
    /** Slagen den samlade papperskorgen listar — ADR-0065 § 2. */
    private const TYPES = ['item', 'attachment', 'container'];

    /**
     * `TrashController` anropas för grinden, inte för sin rutt: metoden
     * `authorizeRestore()` är den delade grinden för item och bilaga
     * ([[ADR-0024 Tunna controllers och actions]] — flytta den inte).
     */
    public function __construct(private readonly TrashController $trashController) {}

    /**
     * GET /trash.
     *
     * `entries`, `nextCursor`, `filter` och `canRestore` är hela sidans
     * underlag. `entries` är actionens tio nycklar plus `kind` för bilagor.
     */
    public function index(Request $request, ListAllTrash $listAllTrash): Response
    {
        $type = $request->query('type');
        $type = in_array($type, self::TYPES, true) ? $type : null;

        $q = $request->query('q');
        $q = is_string($q) ? trim($q) : null;
        $q = $q === '' ? null : $q;

        $cursor = $request->query('cursor');
        $cursor = is_string($cursor) && $cursor !== '' ? $cursor : null;

        $list = $listAllTrash->handle($request->user(), $type, $q, $cursor);

        /*
         * `ListAllTrash` väljer inte `attachment.kind` — raden behöver den
         * bara för bilagans typikon (§ 4). Ett uppslag för hela sidan, precis
         * som `deleted_by`: antalet frågor växer inte med antalet rader.
         * `withTrashed()` därför att raden ligger i papperskorgen.
         */
        $attachmentUlids = [];

        foreach ($list['entries'] as $entry) {
            if ($entry['type'] === 'attachment') {
                $attachmentUlids[] = $entry['ulid'];
            }
        }

        $kinds = $attachmentUlids === []
            ? []
            : Attachment::query()->withTrashed()
                ->whereIn('ulid', $attachmentUlids)
                ->pluck('kind', 'ulid')
                ->all();

        $entries = [];

        foreach ($list['entries'] as $entry) {
            if ($entry['type'] === 'attachment') {
                $entry['kind'] = $kinds[$entry['ulid']] ?? null;
            }

            // Tidsstämplarna i samma form som TrashEntryResource: webbens
            // papperskorgsvyer har alltid lovat `toIso8601String()`, och
            // klienten formulerar datumet ur den.
            $entry['deleted_at'] = $entry['deleted_at']?->toIso8601String();
            $entry['expires_at'] = $entry['expires_at']?->toIso8601String();

            $entries[] = $entry;
        }

        return Inertia::render('Trash/Index', [
            'entries' => $entries,
            'nextCursor' => $list['next_cursor'],
            'filter' => ['q' => $q, 'type' => $type],
            'canRestore' => (object) $this->canRestore($request->user(), $list['subjects']),
        ]);
    }

    /**
     * POST /trash/restore — 302 tillbaka till `/trash` med samma filter och
     * en flashkod.
     *
     * `type` väljer den av de två befintliga vägarna. Ingen ny
     * behörighetsregel: båda prövar samma grind som förut.
     */
    public function restore(
        RestoreAnyRequest $request,
        RestoreContent $restoreContent,
        RestoreTrashedContainer $restoreTrashedContainer,
        ApiErrorTranslator $translator,
    ): RedirectResponse {
        $type = $request->validated('type');
        $ulid = $request->validated('ulid');
        $user = $request->user();

        $status = $type === 'container'
            ? $this->restoreContainer($ulid, $user, $restoreTrashedContainer)
            : $this->restoreContent($type, $ulid, $user, $restoreContent, $translator);

        return $this->backToTrash($request)->with('status', $status);
    }

    /**
     * Item eller bilaga, med samma uppslag, samma Action och samma grind som
     * `TrashController::restore()`.
     *
     * Retentionen tillämpas i uppslaget precis som `FindTrashedInContainer`
     * gör: en utgången rad finns inte och ger 404. Grinden prövas EFTER
     * uppslaget, som i den befintliga rutten.
     */
    private function restoreContent(
        string $type,
        string $ulid,
        User $user,
        RestoreContent $restoreContent,
        ApiErrorTranslator $translator,
    ): string {
        $cutoff = now()->subDays((int) config('files.trash_retention_days'));

        if ($type === 'item') {
            /** @var Item|null $model */
            $model = Item::query()->onlyTrashed()
                ->where('ulid', $ulid)
                ->where('deleted_at', '>=', $cutoff)
                ->first();

            abort_if($model === null, 404);

            $container = Container::query()->find($model->container_id);

            abort_if($container === null, 404);

            $model->setRelation('container', $container);
        } else {
            /** @var Attachment|null $model */
            $model = Attachment::query()->onlyTrashed()
                ->where('ulid', $ulid)
                ->where('deleted_at', '>=', $cutoff)
                ->first();

            abort_if($model === null, 404);

            $item = Item::query()->withTrashed()->find($model->item_id);

            abort_if($item === null, 404);

            $container = Container::query()->find($item->container_id);

            abort_if($container === null, 404);

            $item->setRelation('container', $container);
            $model->setRelation('item', $item);
        }

        $this->trashController->authorizeRestore($container, $model);

        try {
            $restoreContent->handle($type, $model, $user);
        } catch (ApiException $e) {
            throw ValidationException::withMessages(['trash' => $translator->message($e)]);
        }

        return 'trash-restored';
    }

    /**
     * Container, med samma uppslag, samma Action och samma grind som
     * `ContainerTrashController::restore()`.
     *
     * Ordningen uppslag → grind → utgång är bindande, samma som där: en
     * icke-medlem får 403 också för en utgången container, och en medlem får
     * 404.
     */
    private function restoreContainer(
        string $ulid,
        User $user,
        RestoreTrashedContainer $restoreTrashedContainer,
    ): string {
        $container = Container::query()->onlyTrashed()
            ->where('ulid', $ulid)
            ->first();

        abort_if($container === null, 404);

        Gate::authorize('delete', $container);

        $cutoff = now()->subDays((int) config('files.trash_retention_days'));

        abort_if($container->deleted_at->lt($cutoff), 404);

        $restoreTrashedContainer->handle($container, $user);

        return 'container-restored';
    }

    /**
     * Tillbaka till `/trash` med filtret kvar.
     *
     * Bara `q` och `type` följer med. `cursor` gör det inte: den pekar på
     * nästa sida i den lista användaren just lämnade, inte på den sida
     * återställningen ska landa på.
     */
    private function backToTrash(Request $request): RedirectResponse
    {
        $query = [];

        $q = $request->query('q');

        if (is_string($q) && trim($q) !== '') {
            $query['q'] = trim($q);
        }

        $type = $request->query('type');

        if (is_string($type) && $type !== '') {
            $query['type'] = $type;
        }

        $url = '/trash'.($query === [] ? '' : '?'.http_build_query($query));

        return redirect($url);
    }

    /**
     * `ulid → bool` för återställningsknappen, ur SAMMA grind som
     * återställningen prövar.
     *
     * Actionen har redan satt itemets container och containerns ägarkonto i
     * minnet, och bilagans item byggdes ur den joinade raden — så flaggan
     * kostar ingen ny fråga.
     *
     * @param  array<string, Item|Attachment|Container>  $subjects
     * @return array<string, bool>
     */
    private function canRestore(?User $user, array $subjects): array
    {
        $flags = [];

        foreach ($subjects as $ulid => $subject) {
            $flags[$ulid] = match (true) {
                $subject instanceof Item => $user !== null
                    && Gate::forUser($user)->allows('delete', $subject),
                $subject instanceof Attachment => $user !== null
                    && $subject->item !== null
                    && Gate::forUser($user)->allows('delete', $subject->item),
                // Container — den sista typen i facket.
                default => $user !== null
                    && Gate::forUser($user)->allows('delete', $subject),
            };
        }

        return $flags;
    }
}
