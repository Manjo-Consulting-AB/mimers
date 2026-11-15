<?php

namespace App\Actions\Notification;

use App\Models\Loan;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use App\Support\Notification\NotificationTarget;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Personens `in_app`-rader, nyast först — listan i appen
 * ([[ADR-0077 Notislistan i appen]] § 2). Issue 383 bygger läsningen; 384
 * lägger rutterna och resursen ovanpå den, och webben och API:et delar den
 * här actionen (§ 8).
 *
 * **Fönstret är 90 dagar** (§ 5): rader vars notis skapades tidigare finns
 * inte längre, lästa eller inte. Gallringen
 * (App\Console\PrunesInAppNotifications) tar bort dem ur tabellen, men
 * fönstret står också i frågan — en rad som ännu inte hunnit gallras ska inte
 * dyka upp i listan.
 *
 * **Frågan går via `notification`.** Personen och ordningen bor där, och
 * indexet `(user_id, created_at)` bär båda. `whereHas` på `deliveries`
 * filtrerar på kanalen; den unika `(notification_id, channel)` gör att det
 * finns exakt en rad att läsa `read_at` ur. Leveransraden hämtas i samma
 * svep med `with`, så en sida kostar ett konstant antal frågor — inte en per
 * rad.
 *
 * **Adressen per typ** kommer ur App\Support\Notification\NotificationTarget,
 * samma uppslag som HandleInertiaRequests använder (§ 3). Ingen rutt stavas
 * här.
 *
 * **Markören** är en ogenomskinlig sträng med `created_at` och notisens ULID,
 * samma form som App\Actions\Trash\ListAllTrash använder. En trasig markör
 * ger första sidan i stället för ett fel: den kommer ur ett adressfält någon
 * klistrat i.
 */
final class ListInAppNotifications
{
    /** 384 § 2: högst 50 per sida, förval 20 sätts av anroparen. */
    private const MAX_LIMIT = 50;

    public function __construct(
        private readonly NotificationTarget $target,
    ) {}

    /**
     * @return array{
     *     data: list<array{
     *         ulid: string,
     *         type: string,
     *         payload: array<string, mixed>,
     *         created_at: string,
     *         read_at: string|null,
     *         target: string|null,
     *     }>,
     *     next_cursor: string|null,
     * }
     */
    public function handle(User $user, ?string $cursor, int $limit): array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $decodedCursor = $this->decodeCursor($cursor);

        $query = Notification::query()
            ->where('user_id', $user->getKey())
            ->where('created_at', '>=', now()->subDays(NotificationDelivery::IN_APP_RETENTION_DAYS))
            ->whereHas('deliveries', function (Builder $query): void {
                $query->where('channel', NotificationDelivery::CHANNEL_IN_APP);
            })
            ->with([
                'container',
                // Samma inläsning som HandleInertiaRequests gör för klockans
                // lista: adressen till ett item byggs ur subjectet, och
                // morphWith ger hela sidan i ett konstant antal frågor.
                'subject' => function (Relation $relation): void {
                    if ($relation instanceof MorphTo) {
                        $relation->morphWith([
                            ScheduleOccurrence::class => ['schedule.item'],
                            Loan::class => ['item'],
                        ]);
                    }
                },
                'deliveries' => function ($query): void {
                    $query->where('channel', NotificationDelivery::CHANNEL_IN_APP);
                },
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('ulid')
            ->limit($limit + 1);

        if ($decodedCursor !== null) {
            $this->beforeCursor($query, $decodedCursor);
        }

        $rows = $query->get();
        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit)->values();
        $last = $page->last();

        return [
            'data' => $page->map(function (Notification $notification): array {
                $delivery = $notification->deliveries->first();

                return [
                    'ulid' => $notification->ulid,
                    'type' => $notification->type,
                    'payload' => $notification->payload,
                    'created_at' => $notification->created_at->toIso8601String(),
                    'read_at' => $delivery?->read_at?->toIso8601String(),
                    'target' => $this->target->for($notification),
                ];
            })->all(),
            'next_cursor' => $hasMore && $last !== null
                ? $this->encodeCursor($last->created_at, $last->ulid)
                : null,
        ];
    }

    /**
     * Markörens villkor: strikt före raden i sorteringen
     * (`created_at` fallande, ULID fallande).
     *
     * @param  array{created_at: string, ulid: string}  $cursor
     */
    private function beforeCursor(Builder $query, array $cursor): void
    {
        $query->where(function (Builder $query) use ($cursor): void {
            $query->where('created_at', '<', $cursor['created_at'])
                ->orWhere(function (Builder $query) use ($cursor): void {
                    $query->where('created_at', '=', $cursor['created_at'])
                        ->where('ulid', '<', $cursor['ulid']);
                });
        });
    }

    private function encodeCursor(CarbonInterface $createdAt, string $ulid): string
    {
        return rtrim(strtr(base64_encode($createdAt->toDateTimeString().'|'.$ulid), '+/', '-_'), '=');
    }

    /**
     * Markören ur querysträngen. En trasig markör ger null — första sidan —
     * i stället för ett fel.
     *
     * @return array{created_at: string, ulid: string}|null
     */
    private function decodeCursor(?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);

        if ($decoded === false) {
            return null;
        }

        $parts = explode('|', $decoded);

        if (count($parts) !== 2) {
            return null;
        }

        [$createdAt, $ulid] = $parts;

        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $createdAt) !== 1
            || preg_match('/^[0-9A-Za-z]{26}$/', $ulid) !== 1) {
            return null;
        }

        return ['created_at' => $createdAt, 'ulid' => $ulid];
    }
}
