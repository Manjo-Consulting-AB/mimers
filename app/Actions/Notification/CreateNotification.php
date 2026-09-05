<?php

namespace App\Actions\Notification;

use App\Models\Account;
use App\Models\Container;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Skapar en notis — den ENDA vägen in i `notification`, se issue 30
 * (M5 Notiser). En notis skapas som en rad och levereras sedan av kön,
 * aldrig synkront i ett request ([[ADR-0010 Notisarkitektur]] § Beslut); den
 * här actionen skriver bara utboksraden och sin e-postleverans. Ingenting
 * skickas, ingen kanal finns, inget schemalagt jobb tillkommer — leveransen
 * är 34a, kanalerna 32a och 37b.
 *
 * Idempotent mot minutcronen (Beslut 7): en generator som frågar "vilka
 * förekomster förfaller idag" svarar likadant sextio gånger i timmen
 * ([[Notiser]] § notification). En nyckel i `dedupe_key` betyder "samma
 * logiska händelse" — finns raden redan returneras den befintliga, utan ny
 * notis, utan nya leveransrader, utan undantag. `dedupe_key = null`
 * dedupliceras aldrig: flera rader är rätt för en engångshändelse
 * (`invitation.received`), fel för allt en cron skapar.
 *
 * Allt sker i EN transaktion (Beslut 7): notisraden och leveransraderna
 * skapas tillsammans eller inte alls. Två samtidiga cronkörningar kan båda se
 * att raden saknas, så uniknyckelbrottet på `dedupe_key` fångas, raden läses
 * om och returneras — en `firstOrCreate` som inte fångar kastet vore samma
 * sak som ingen dedupe alls den dag två körningar överlappar.
 *
 * I den här issuen skapas en `pending`-e-postleverans per notis och någon
 * frågas inte (preferenserna är 31a). Unik `(notification_id, channel)` är
 * den ANDRA spärren mot dubbletter och gäller även när `dedupe_key` är null:
 * samma notis kan aldrig få två leveransrader på samma kanal.
 */
class CreateNotification
{
    public function handle(
        string $type,
        Account $account,
        ?User $user = null,
        ?Container $container = null,
        ?Model $subject = null,
        array $payload = [],
        ?string $dedupeKey = null,
    ): Notification {
        return DB::transaction(function () use ($type, $account, $user, $container, $subject, $payload, $dedupeKey): Notification {
            if ($dedupeKey !== null) {
                $existing = $this->findByDedupeKey($dedupeKey);

                if ($existing !== null) {
                    return $existing;
                }
            }

            try {
                $notification = new Notification;
                $notification->type = $type;
                $notification->account_id = $account->getKey();
                $notification->user_id = $user?->getKey();
                $notification->container_id = $container?->getKey();
                $notification->subject_type = $subject?->getMorphClass();
                $notification->subject_id = $subject?->getKey();
                $notification->payload = $payload;
                $notification->dedupe_key = $dedupeKey;
                // Tysta timmar räknas inte här — available_at sätts till now().
                // Tidszonsräkningen är 31a, som byter ut det enda uttrycket
                // (issue 30 § Beslut 6).
                $notification->available_at = now();
                $notification->save();

                $delivery = new NotificationDelivery;
                $delivery->notification_id = $notification->getKey();
                $delivery->channel = NotificationDelivery::CHANNEL_EMAIL;
                $delivery->status = NotificationDelivery::STATUS_PENDING;
                $delivery->attempts = 0;
                $delivery->save();

                return $notification;
            } catch (UniqueConstraintViolationException $e) {
                if ($dedupeKey === null) {
                    throw $e;
                }

                // Två cronkörningar hann båda se att raden saknades och den
                // här tappade racet på uniknyckeln. Omläsningen MÅSTE vara
                // låsande: den första läsningen ovan etablerade transaktionens
                // snapshot, och under MySQL:s REPEATABLE READ ser en vanlig
                // SELECT fortsatt raden som saknad trots att konkurrenten
                // hann committa. En låsande läsning läser senaste committade
                // data, inte snapshotten (issue 30 § Beslut 7).
                $existing = $this->findByDedupeKey($dedupeKey, locking: true);

                if ($existing === null) {
                    throw $e;
                }

                return $existing;
            }
        });
    }

    private function findByDedupeKey(string $dedupeKey, bool $locking = false): ?Notification
    {
        return Notification::query()
            ->where('dedupe_key', $dedupeKey)
            ->when($locking, fn ($query) => $query->lockForUpdate())
            ->first();
    }
}
