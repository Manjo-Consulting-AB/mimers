<?php

namespace App\Actions\CalendarFeed;

use App\Actions\Audit\RecordAuditEvent;
use App\Models\AuditLog;
use App\Models\CalendarFeed;
use App\Models\Container;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Återkallar en ICS-prenumerationslänk: `revoked_at` sätts och
 * `calendar_feed.revoked` skrivs i EN transaktion — se issue 36a § Beslut 3
 * och 4 och [[ADR-0043 Tre loggar]] § Händelseloggen.
 *
 * Bryts ut enligt [[ADR-0024 Tunna controllers och actions]] i issue 111,
 * samma skäl som App\Actions\CalendarFeed\CreateCalendarFeed: webben och
 * `/api` bar var sin avskrift av samma skrivning och samma loggrad.
 *
 * **Ingen `Gate::authorize()`.** Behörigheten prövas av anroparen — båda
 * ytorna grindar med `view`, för att återkalla är att MINSKA exponeringen och
 * den som får skapa en feed får klippa den (36a § Beslut 4).
 *
 * **Ingen retur, och en andra återkallelse är inte ett fel.** Den rör varken
 * `revoked_at` eller loggen — den ursprungliga tidsstämpeln är historien
 * (issue 36a § Beslut 3, issue 9b § Beslut 9) — och båda ytorna svarar som ett
 * första anrop.
 */
class RevokeCalendarFeed
{
    public function __construct(private readonly RecordAuditEvent $recordAuditEvent) {}

    /**
     * Raden raderas aldrig och `revoked_at` skrivs bara om den är null:
     * App\Models\CalendarFeed använder inte SoftDeletes.
     *
     * Raden läses om och låses INNE i transaktionen (`lockForUpdate`):
     * route-modellbindningens instans lästes innan transaktionen öppnades, och
     * två samtidiga anrop mot samma feed skulle annars båda se
     * `revoked_at === null` på sin egen instans och skriva var sin loggrad för
     * samma återkallelse — samma teknik och samma skäl som
     * App\Actions\Access\RevokeContainerAccess.
     *
     * @param  User  $actor  Den som återkallar. Behörigheten är redan prövad av
     *                       anroparen; hen blir `user_id` på loggraden.
     * @param  CalendarFeed  $feed  Raden ur route-modellbindningen. Metoden
     *                              läser om den med lås och litar inte på
     *                              instansen.
     */
    public function handle(User $actor, Container $container, CalendarFeed $feed): void
    {
        DB::transaction(function () use ($actor, $container, $feed): void {
            $låstFeed = CalendarFeed::query()
                ->whereKey($feed->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($låstFeed->revoked_at !== null) {
                // En andra återkallelse är ingen handling: den rör varken
                // tidsstämpeln eller loggen (issue 36a § Beslut 3, issue 111).
                return;
            }

            $låstFeed->revoked_at = now();
            $låstFeed->save();

            // `meta` är tom, och tokenet — klartexten och hashen — får aldrig
            // följa med (issue 111).
            $this->recordAuditEvent->handle(
                action: AuditLog::ACTION_CALENDAR_FEED_REVOKED,
                account: $container->account,
                user: $actor,
                container: $container,
                subjectType: 'calendar_feed',
                subjectUlid: $låstFeed->ulid,
            );
        });
    }
}
