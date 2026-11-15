<?php

namespace App\Http\Controllers;

use App\Actions\Notification\CountUnreadInAppNotifications;
use App\Actions\Notification\ListInAppNotifications;
use App\Actions\Notification\MarkInAppNotificationsRead;
use App\Http\Requests\Notification\ListNotificationsRequest;
use App\Http\Requests\Notification\MarkNotificationsReadRequest;
use App\Http\Resources\InAppNotificationResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Notislistan i appen på webben — sidan `/notifications` och panelens
 * hämtning, se [[ADR-0077 Notislistan i appen]] § 2 och 3 samt M60 ·
 * issue 384.
 *
 * **Samma actions som API:et** (§ 8). Sidan, panelen och
 * App\Http\Controllers\Api\NotificationController läser alla genom
 * App\Actions\Notification\ListInAppNotifications och markerar genom
 * MarkInAppNotificationsRead; kontrollern äger bara svar och props.
 *
 * **Sidan bär bara första sidan.** Fler sidor hämtas med en partiell
 * omladdning av proppen `notifications` och `?cursor=` — därför returnerar
 * `index()` alltid samma props och låter Inertia filtrera dem. Proppen är en
 * karta med `data`, `next_cursor` och `unread`, samma form som API:ets svar
 * (§ 2). `unread` ligger med fastän § 3 bara nämner sidan och markören:
 * den delade proppen `notifications` är en karta med `unread`
 * (App\Http\Middleware\HandleInertiaRequests), och en sidpropp med samma
 * nyckel skuggar den. Utan `unread` i kartan hade märket försvunnit just på
 * notissidan.
 *
 * **Panelen är JSON och inte en Inertia-propp.** Den hämtas först när klockan
 * öppnas (385), och att bära de tio senaste på varje sidladdning hade varit
 * den kostnad 383:s delade `notifications` med flit undviker. Tio rader och
 * `unread`, ingenting annat.
 *
 * **Markeringen rör bara personens egna rader.** Actionen filtrerar på
 * `notification.user_id`, så en ULID som tillhör någon annan är en tyst
 * no-op här — ingen rad att auktorisera mot, och inget svar som avslöjar att
 * den finns. API:ets `{ulid}/read` svarar 404 i samma fall eftersom den
 * rutten pekar ut en enda rad (§ 2).
 */
final class NotificationController extends Controller
{
    public function __construct(
        private readonly ListInAppNotifications $listNotifications,
        private readonly MarkInAppNotificationsRead $markNotificationsRead,
        private readonly CountUnreadInAppNotifications $countUnread,
    ) {}

    /**
     * GET /notifications — sidan med första sidan av listan, nyast först.
     *
     * Markören pekar på sista raden i svaret; `null` betyder att det inte
     * finns fler. En trasig markör ger första sidan i stället för ett fel,
     * eftersom den kommer ur ett adressfält någon klistrat i.
     */
    public function index(ListNotificationsRequest $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $list = $this->listNotifications->handle(
            $user,
            $request->cursor(),
            $request->limit(),
        );

        return Inertia::render('Notifications/Index', [
            'notifications' => [
                'data' => InAppNotificationResource::collection($list['data'])->resolve($request),
                'next_cursor' => $list['next_cursor'],
                'unread' => $this->countUnread->handle($user),
            ],
        ]);
    }

    /**
     * GET /notifications/panel — de tio senaste och antalet olästa som JSON.
     *
     * Ingen markören: panelen visar bara det senaste, och historiken finns på
     * sidan. Anropet skapar ingen läsning — det är 385 som markerar de
     * visade raderna.
     */
    public function panel(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $list = $this->listNotifications->handle($user, null, 10);

        return response()->json([
            'data' => InAppNotificationResource::collection($list['data'])->resolve($request),
            'unread' => $this->countUnread->handle($user),
        ]);
    }

    /**
     * POST /notifications/read — markerar de visade notiserna som lästa.
     *
     * **Utan `ulids` behålls klockans gamla skrivning.** Före ytan i 385
     * postar resources/js/components/NotificationBell.vue samma rutt utan
     * kropp för att nollställa `unreadNotificationCount`; den skrivningen
     * sätter användarens `notifications_read_at` precis som
     * App\Http\Controllers\NotificationInboxController gjorde. När 385
     * ersätter klockan försvinner grenen. Så länge den finns behöver den
     * gamla klockan inte veta att rutten bytt kontroller.
     */
    public function read(MarkNotificationsReadRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $request->has('ulids')) {
            $user->notifications_read_at = now();
            $user->save();

            return back();
        }

        /** @var list<string> $ulids */
        $ulids = array_values(array_unique((array) $request->input('ulids', [])));

        $this->markNotificationsRead->handle($user, $ulids);

        return back();
    }

    /**
     * POST /notifications/read-all — markerar alla personens olästa
     * `in_app`-rader som lästa.
     *
     * `null` och inte en lista med alla ULID:n: actionens `null` betyder
     * "alla personens rader" och uppdaterar bara de olästa, så anropet är
     * idempotent och kräver ingen läsning först.
     */
    public function readAll(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->markNotificationsRead->handle($user, null);

        return back();
    }
}
