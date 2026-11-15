<?php

namespace App\Http\Controllers\Api;

use App\Actions\Notification\CountUnreadInAppNotifications;
use App\Actions\Notification\ListInAppNotifications;
use App\Actions\Notification\MarkInAppNotificationsRead;
use App\Http\Controllers\Controller;
use App\Http\Requests\Notification\ListNotificationsRequest;
use App\Http\Resources\InAppNotificationResource;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Notislistan i appen i `/api` — läsningen och de två markeringarna, se
 * [[ADR-0077 Notislistan i appen]] § 2 och 8 samt M60 · issue 384.
 *
 * **Samma actions som webben** (§ 8). App\Http\Controllers\
 * NotificationController anropar exakt samma tre actions; den här kontrollern
 * äger bara svarsformen och 404:an.
 *
 * **Toppnivå och ingen `{user}`** — samma val som `/api/me/notification-
 * preferences` (issue 31b § Beslut 1): listan är personens egen, anroparen
 * kommer ur token, och en rutt utan främmande identifierare går inte att
 * peka fel. Den enda identifieraren är notisens ULID på `{ulid}/read`, och
 * den auktoriseras mot `notification.user_id`.
 *
 * **404 för en ULID som inte är personens egen** (§ 2). Uppslaget prövar
 * både ULID:en och ägaren innan actionen får den; en annans rad och en
 * okänd rad svarar samma sak, så svaret avslöjar inte att raden finns.
 * Markeringen själv är idempotent — en redan läst rad ger fortfarande 204.
 */
final class NotificationController extends Controller
{
    public function __construct(
        private readonly ListInAppNotifications $listNotifications,
        private readonly MarkInAppNotificationsRead $markNotificationsRead,
        private readonly CountUnreadInAppNotifications $countUnread,
    ) {}

    /**
     * GET /api/notifications — 200 med `data`, `next_cursor` och `unread`.
     *
     * `data` är sidan, nyast först, högst 50 rader (förval 20); `unread` är
     * antalet olästa i samma 90-dagarsfönster, så märket och listan alltid är
     * samma mängd. `next_cursor` är `null` på sista sidan.
     */
    public function index(ListNotificationsRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $list = $this->listNotifications->handle(
            $user,
            $request->cursor(),
            $request->limit(),
        );

        return response()->json([
            'data' => InAppNotificationResource::collection($list['data'])->resolve($request),
            'next_cursor' => $list['next_cursor'],
            'unread' => $this->countUnread->handle($user),
        ]);
    }

    /**
     * POST /api/notifications/{ulid}/read — 204.
     *
     * En ULID som inte finns, eller som tillhör någon annan, ger 404
     * `resource.not_found` genom den vanliga NotFoundHttpException-mappningen
     * (bootstrap/app.php). Ingen body: markeringen är ett tillstånd, inte en
     * resurs.
     */
    public function read(Request $request, string $ulid): Response
    {
        /** @var User $user */
        $user = $request->user();

        $ownsNotification = Notification::query()
            ->where('ulid', $ulid)
            ->where('user_id', $user->getKey())
            ->exists();

        abort_unless($ownsNotification, 404);

        $this->markNotificationsRead->handle($user, [$ulid]);

        return response()->noContent();
    }

    /**
     * POST /api/notifications/read-all — 204.
     *
     * `null` betyder alla personens rader i actionen, och bara de olästa
     * skrivs — anropet är idempotent. Ingen body.
     */
    public function readAll(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $this->markNotificationsRead->handle($user, null);

        return response()->noContent();
    }
}
