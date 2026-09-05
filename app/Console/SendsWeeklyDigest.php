<?php

namespace App\Console;

use App\Mail\WeeklyDigestMail;
use App\Models\EmailSuppression;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Support\Notification\AddressSuppressedException;
use App\Support\Notification\LocaleResolver;
use App\Support\Notification\UnknownNotificationTypeException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Veckosammanfattningen — jobbet som samlar `digest`-markerade notiser till
 * ett mejl i veckan, issue 35. Se [[Notiser]] § notification_preference,
 * [[ADR-0010 Notisarkitektur]] § Konsekvenser punkt 3 och Beslut 4–6 i issuen.
 *
 * Sammanfattningen är standard för uppgiftspåminnelserna och den ENDA
 * anledningen till att `digest`-kolumnen finns. Beslutet om en notis ska
 * samlas fryses in när notisen skapas (35 § Beslut 1–2, CreateNotification);
 * det här jobbet läser bara kolumnen och räknar aldrig om ett beslut.
 *
 * Jobbet gör, per mottagare (Beslut 4):
 *   1. Hämtar `pending` e-postleveranser med `digest = true` för användaren,
 *      äldst först.
 *   2. Är listan tom: hoppa över användaren. Inget tomt mejl.
 *   3. Är adressen undertryckt: sätt alla raderna till `suppressed` utan att
 *      räkna upp `attempts`, skicka ingenting — samma regel som 34a § Beslut 5.
 *   4. Rendera och skicka ETT mejl på mottagarens språk.
 *   5. Sätt alla plockade raderna till `sent` med `sent_at = now()` och
 *      `attempts + 1`, i en transaktion.
 *
 * `available_at` och tysta timmar läses INTE här (Beslut 6): fönstret finns
 * för att ingen ska väckas klockan tre, och ett veckobrev som skickas en
 * bestämd morgon väcker ingen. Att i stället skjuta varje mottagares
 * sammanfattning till hens lokala morgon vore ett andra köschema för en enda
 * mejltyp.
 *
 * En okänd notistyp i en rad stoppar inte mejlet (Beslut 5): den raden blir
 * `failed` med felet i `last_error`, resten av sammanfattningen skickas. Ett
 * fel för en mottagare stoppar inte de andra — try/catch per användare — och
 * ett misslyckat utskick lämnar raderna `pending` med `attempts + 1` och
 * `last_error`: ingen omkörning inom veckan, en sammanfattning som kommer på
 * tisdag när den skulle kommit på måndag är fortfarande en sammanfattning.
 *
 * Schemaläggs i routes/console.php med `Schedule::call`, aldrig
 * `Schedule::command` och aldrig `->runInBackground()` — se AGENTS.md §
 * Driftmiljön saknar proc_open.
 */
final class SendsWeeklyDigest
{
    public function __construct(
        private readonly LocaleResolver $locales,
    ) {}

    public function handle(): void
    {
        $userIds = $this->userIdsWithPendingDigest();

        foreach ($userIds as $userId) {
            try {
                $this->sendTo((int) $userId);
            } catch (Throwable $e) {
                // Ett oväntat fel utanför de kända utfallsvägarna i sendTo()
                // — i urvalsläsningen eller bokföringen — får inte tysta de
                // andra mottagarna (Beslut 4). Rader som redan bokförts av
                // ett tidigare steg står sig; resten ligger kvar som `pending`
                // och plockas nästa vecka.
                Log::error('notification.digest_recipient_failed', [
                    'user_id' => $userId,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }

    private function sendTo(int $userId): void
    {
        $user = User::query()->find($userId);

        if ($user === null) {
            return;
        }

        $deliveries = $this->pendingDigestDeliveriesFor($user);

        if ($deliveries->isEmpty()) {
            return;
        }

        if (EmailSuppression::isSuppressed($user->email)) {
            $this->markSuppressed($deliveries, $user);

            return;
        }

        $locale = $this->locales->forUser($user);

        // Okända typer rensas ut innan mejlet byggs (Beslut 5): raden blir
        // `failed`, resten av sammanfattningen skickas. Kontrollen är samma
        // språkuppsättning som EmailChannel gör — `Lang::has` mot mottagarens
        // katalog, utan fallback till reservspråket.
        [$kanda, $okanda] = $deliveries->partition(
            fn (NotificationDelivery $delivery): bool => Lang::has(
                'notiser.'.str_replace('.', '_', $delivery->notification->type),
                $locale,
                false,
            ),
        );

        if ($okanda->isNotEmpty()) {
            $this->markFailed($okanda);
        }

        if ($kanda->isEmpty()) {
            return;
        }

        // `max_items` är ett tak på mejlets STORLEK, inte på vad som bokförs:
        // alla plockade rader markeras `sent`, även de som inte ryms i listan
        // (35 § "Att se upp med") — de ska inte dyka upp i nästa veckas mejl.
        // Sorteringen är äldst först, så en kapad lista tappar det nyaste.
        $maxItems = (int) config('notiser.digest.max_items', 50);
        $visade = $kanda->take($maxItems);
        $fler = max(0, $kanda->count() - $visade->count());

        $items = $visade
            ->map(fn (NotificationDelivery $delivery): array => [
                'type' => $delivery->notification->type,
                'payload' => $delivery->notification->payload,
            ])
            ->values()
            ->all();

        try {
            Mail::to($user)->locale($locale)->send(
                new WeeklyDigestMail($items, $fler)
            );
        } catch (Throwable $e) {
            // Utskicket misslyckades: raderna står kvar som `pending` med
            // `attempts + 1` och `last_error` och plockas nästa vecka. De
            // okända typerna har redan fått `failed` ovan och rörs inte här.
            $this->markSendFailed($kanda, $e);

            return;
        }

        $this->markSent($kanda);
    }

    /**
     * Användare med minst en väntande `digest`-e-postrad, i bestämd ordning.
     *
     * @return Collection<int, int>
     */
    private function userIdsWithPendingDigest(): Collection
    {
        return DB::table('notification_delivery')
            ->join('notification', 'notification.id', '=', 'notification_delivery.notification_id')
            ->where('notification_delivery.status', NotificationDelivery::STATUS_PENDING)
            ->where('notification_delivery.channel', NotificationDelivery::CHANNEL_EMAIL)
            ->where('notification_delivery.digest', true)
            ->whereNotNull('notification.user_id')
            ->distinct()
            ->orderBy('notification.user_id')
            ->pluck('notification.user_id');
    }

    /**
     * @return Collection<int, NotificationDelivery>
     */
    private function pendingDigestDeliveriesFor(User $user): Collection
    {
        return NotificationDelivery::query()
            ->where('status', NotificationDelivery::STATUS_PENDING)
            ->where('channel', NotificationDelivery::CHANNEL_EMAIL)
            ->where('digest', true)
            ->whereHas('notification', function ($query) use ($user): void {
                $query->where('user_id', $user->getKey());
            })
            ->with('notification')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, NotificationDelivery>  $deliveries
     */
    private function markSuppressed(Collection $deliveries, User $user): void
    {
        $message = new AddressSuppressedException($user->email)->getMessage();

        DB::transaction(function () use ($deliveries, $message): void {
            NotificationDelivery::query()
                ->whereIn('id', $deliveries->pluck('id'))
                ->update([
                    'status' => NotificationDelivery::STATUS_SUPPRESSED,
                    'last_error' => mb_substr($message, 0, 1000),
                ]);
        });
    }

    /**
     * @param  Collection<int, NotificationDelivery>  $deliveries
     */
    private function markFailed(Collection $deliveries): void
    {
        foreach ($deliveries as $delivery) {
            $message = UnknownNotificationTypeException::forType($delivery->notification->type)->getMessage();

            $delivery->status = NotificationDelivery::STATUS_FAILED;
            $delivery->attempts = $delivery->attempts + 1;
            $delivery->last_error = mb_substr($message, 0, 1000);
            $delivery->save();
        }
    }

    /**
     * @param  Collection<int, NotificationDelivery>  $deliveries
     */
    private function markSent(Collection $deliveries): void
    {
        DB::transaction(function () use ($deliveries): void {
            NotificationDelivery::query()
                ->whereIn('id', $deliveries->pluck('id'))
                ->update([
                    'status' => NotificationDelivery::STATUS_SENT,
                    'attempts' => DB::raw('attempts + 1'),
                    'sent_at' => now(),
                    'last_error' => null,
                ]);
        });
    }

    /**
     * @param  Collection<int, NotificationDelivery>  $deliveries
     */
    private function markSendFailed(Collection $deliveries, Throwable $e): void
    {
        $message = mb_substr($e->getMessage(), 0, 1000);

        DB::transaction(function () use ($deliveries, $message): void {
            NotificationDelivery::query()
                ->whereIn('id', $deliveries->pluck('id'))
                ->update([
                    'status' => NotificationDelivery::STATUS_PENDING,
                    'attempts' => DB::raw('attempts + 1'),
                    'last_error' => $message,
                ]);
        });
    }
}
