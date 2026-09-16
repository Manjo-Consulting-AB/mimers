<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateNotificationPreferencesRequest;
use App\Http\Requests\UpdateQuietHoursRequest;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Support\Notification\NotificationPreferences;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Notisinställningarna — den inloggade användarens egna kanalval,
 * veckosammanfattning och tysta timmar, se issue 65a § Beslut 1–6.
 *
 * **En sida, tre rutter.** Preferenserna och de tysta timmarna är två olika
 * skrivningar mot två olika `/api`-rutter (`PUT /api/me/notification-preferences`
 * och `PATCH /api/me/quiet-hours`) och behåller den uppdelningen här: ett
 * gemensamt formulär hade behövt slå ihop två requests och två felmängder. De
 * ligger på samma sida eftersom de besvarar samma fråga — "när och hur vill
 * jag bli störd?".
 *
 * **Objektet är anroparen själv.** Ingen ruttparameter och ingen policy, exakt
 * som App\Http\Controllers\Api\NotificationPreferenceController § Beslut 3:
 * en NotificationPreferencePolicy hade svarat på "får jag ändra min egen
 * rad?", vars svar alltid är ja.
 *
 * **Ingenting av `/api` byggs om.** `UpdateNotificationPreferencesRequest`
 * och `UpdateQuietHoursRequest` delas rakt av, och skrivningen är de raderna
 * kontrollern gör — samma väg som `/api` går, utan en ny Action (Beslut 1).
 *
 * **Förvalen formuleras inte om.** Listan över typer kommer ur
 * `NotificationPreferences::types()` och förvalen ur samma klass, precis som
 * `Api\NotificationPreferenceController` gör (31a § Beslut 2 och 3).
 */
class NotificationSettingsController extends Controller
{
    /**
     * GET /settings/notifications — varje typ ur
     * `NotificationPreferences::types()`, i konstanternas ordning, med det
     * EFFEKTIVA värdet och `is_default` (Beslut 3), samt de tysta timmarna och
     * användarens tidszon.
     *
     * Listan har samma form som `GET /api/me/notification-preferences` svarar
     * med — `type`, `channel`, `enabled`, `digest`, `is_default` — och byggs
     * här i stället för att delas: `/api`-kontrollerns uppbyggnad är en privat
     * metod på en kontroller som svarar `JsonResponse`, och den här sidan
     * behöver dessutom tidszonen i samma svar. Formen hålls identisk med flit,
     * så att de två ytorna inte kan säga olika saker om samma rad.
     *
     * Kanalen `webhook` listas inte: preferenser styr inte webhooks (31a
     * § Beslut 4). Den kommer ändå med som fält per typ, så att vyn kan skicka
     * tillbaka den i PUT-kroppen utan att själv namnge en kanal.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Settings/Notifications', [
            'preferences' => $this->preferences($user),

            // `H:i` eller `null`, samma form som requesten vill ha (Beslut 4):
            // kolumnen är MySQL TIME och bär `22:00:00` i databasen.
            'quietHoursStart' => self::timeToApi($user->quiet_hours_start),
            'quietHoursEnd' => self::timeToApi($user->quiet_hours_end),

            // Användarens EGEN tidszon, `null` inkluderat. Den är nullbar
            // sedan issue 3 och betyder "följ kontots" — se Beslut 4 om varför
            // vyn inte hittar på ett värde i det fallet.
            'timezone' => $user->timezone,
        ]);
    }

    /**
     * PUT /settings/notifications — en UPSERT av en delmängd i en transaktion
     * på (user_id, type, channel), exakt som
     * `Api\NotificationPreferenceController::update()` (31b § Beslut 4): typer
     * som inte nämns rörs inte, och samma kropp två gånger ger samma rader och
     * samma svar. Vyn skickar bara de typer användaren ändrat.
     */
    public function update(UpdateNotificationPreferencesRequest $request): RedirectResponse
    {
        $user = $request->user();

        DB::transaction(function () use ($user, $request): void {
            foreach ($request->validated('preferences') as $preference) {
                NotificationPreference::updateOrCreate(
                    [
                        'user_id' => $user->getKey(),
                        'type' => $preference['type'],
                        'channel' => $preference['channel'],
                    ],
                    [
                        'enabled' => $preference['enabled'],
                        'digest' => $preference['digest'],
                    ],
                );
            }
        });

        return back()->with('status', 'notification-preferences-updated');
    }

    /**
     * PATCH /settings/notifications/quiet-hours — fönstret, `H:i` eller
     * `null`, båda tillsammans (Beslut 4).
     *
     * **Tidszonen skrivs inte härifrån**, fastän `UpdateQuietHoursRequest`
     * tillåter fältet: två ställen att ändra samma sak är ett ställe för
     * mycket, och profilen äger det sedan issue 53c. `Arr::only()` är vad som
     * gör den regeln till kod i stället för till en kommentar — en kropp med
     * `timezone` passerar valideringen och lämnar kolumnen orörd.
     *
     * Fälten är `nullable`: båda tomma betyder inga tysta timmar. Ett fönster
     * som passerar midnatt (22:00–07:00) är det normala fallet och sparas som
     * det står — `App\Support\Notification\QuietHours` äger tolkningen.
     */
    public function updateQuietHours(UpdateQuietHoursRequest $request): RedirectResponse
    {
        $request->user()->update(
            Arr::only($request->validated(), ['quiet_hours_start', 'quiet_hours_end']),
        );

        return back()->with('status', 'quiet-hours-updated');
    }

    /**
     * Den effektiva listan för en användare: raden om en finns, annars
     * förvalet i kod (31a § Beslut 2), i `types()`-ordning.
     *
     * Ett konstant antal frågor: en uppslagning av användarens rader, och inga
     * fler — förvalen hämtas med `$user = null`, vilket aldrig frågar
     * databasen.
     *
     * @return list<array{type: string, channel: string, enabled: bool, digest: bool, is_default: bool}>
     */
    private function preferences(User $user): array
    {
        $defaults = app(NotificationPreferences::class);

        $rows = NotificationPreference::query()
            ->where('user_id', $user->getKey())
            ->where('channel', NotificationDelivery::CHANNEL_EMAIL)
            ->get()
            ->keyBy('type');

        $items = [];

        foreach ($defaults->types() as $type) {
            $row = $rows->get($type);

            $items[] = [
                'type' => $type,
                'channel' => NotificationDelivery::CHANNEL_EMAIL,
                'enabled' => $row === null
                    ? $defaults->isEnabled(null, $type, NotificationDelivery::CHANNEL_EMAIL)
                    : $row->enabled,
                'digest' => $row === null
                    ? $defaults->digest(null, $type, NotificationDelivery::CHANNEL_EMAIL)
                    : $row->digest,
                'is_default' => $row === null,
            ];
        }

        return $items;
    }

    /**
     * Ett klockslag från TIME-kolumnen (`'22:00:00'`, i sqlite ibland
     * `'22:00'`) till `H:i` — samma normalisering som
     * `Api\QuietHoursController::timeToApi()`, så att samma värde går att
     * skicka tillbaka utan att skrivas om.
     */
    private static function timeToApi(?string $time): ?string
    {
        return $time === null ? null : substr($time, 0, 5);
    }
}
