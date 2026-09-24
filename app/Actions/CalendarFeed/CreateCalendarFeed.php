<?php

namespace App\Actions\CalendarFeed;

use App\Actions\Audit\RecordAuditEvent;
use App\Models\AuditLog;
use App\Models\CalendarFeed;
use App\Models\Container;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Utfärdar en ICS-prenumerationslänk åt en användare och skriver
 * `calendar_feed.created` i samma transaktion — se issue 36a § Beslut 2 och 5
 * och [[ADR-0043 Tre loggar]] § Händelseloggen.
 *
 * Bryts ut enligt [[ADR-0024 Tunna controllers och actions]] i issue 111:
 * App\Http\Controllers\CalendarFeedController::store() och
 * App\Http\Controllers\Api\CalendarFeedController::store() bar var sin
 * avskrift av samma skrivning, och loggraden gjorde dem till två sanningar om
 * vad ett kalenderflöde ÄR. Två avskrifter av samma loggrad är precis vad
 * milstolpen varnar för.
 *
 * **Klartexten lämnar klassen i returvärdet och lagras aldrig.** Bara hashen
 * sparas (issue 36a § Beslut 5), och `meta` bär varken klartexten eller hashen:
 * loggen får inte bli en andra väg till feeden (issue 111).
 *
 * **Ingen `Gate::authorize()`.** Behörigheten prövas av anroparen — samma linje
 * som App\Actions\Access\RevokeContainerAccess och
 * App\Actions\Container\CreateContainer, se issue 54 § Beslut 3. Båda ytorna
 * grindar med `view`: den som får läsa containern får prenumerera på dess
 * kalender (36a § Beslut 4).
 *
 * **URL:en byggs av anroparen.** Den är svarets form och skiljer sig åt mellan
 * ytorna — redirectens flash i webben, `url`-fältet i `/api` — medan sökvägen
 * `/kalender/{token}.ics` är kontraktet från 36a § Beslut 6 och ligger i
 * App\Http\Controllers\CalendarFeedDownloadController.
 */
class CreateCalendarFeed
{
    /**
     * Klartextens längd: 64 tecken ur Str::random()s 62-teckens alfabet, samma
     * som App\Actions\Invitation\CreateInvitation::TOKEN_LENGTH. Entropin i ett
     * token som hashas, inte ett format två ytor måste vara ense om.
     */
    private const TOKEN_LENGTH = 64;

    public function __construct(private readonly RecordAuditEvent $recordAuditEvent) {}

    /**
     * `container_id`, `user_id` och `token_hash` sätts explicit på
     * modellinstansen, aldrig via massilldelning — App\Models\CalendarFeed har
     * `#[Fillable([])]`.
     *
     * @param  User  $user  Den feeden tillhör. Blir `user_id` på raden och på
     *                      loggraden.
     * @return array{feed: CalendarFeed, token: string} Klartexten är
     *                                                  returvärdets enda
     *                                                  konsument — den sparas
     *                                                  aldrig och får aldrig
     *                                                  hamna i `meta`.
     */
    public function handle(Container $container, User $user): array
    {
        $rawToken = Str::random(self::TOKEN_LENGTH);

        $feed = DB::transaction(function () use ($container, $user, $rawToken): CalendarFeed {
            $feed = new CalendarFeed;
            $feed->container_id = $container->id;
            $feed->user_id = $user->id;
            $feed->token_hash = hash('sha256', $rawToken);
            $feed->save();

            // `meta` är tom: ett flöde har ingenting som är en värdelista, ett
            // tal eller ett datum, och tokenet får aldrig följa med (issue 111).
            $this->recordAuditEvent->handle(
                action: AuditLog::ACTION_CALENDAR_FEED_CREATED,
                account: $container->account,
                user: $user,
                container: $container,
                subjectType: 'calendar_feed',
                subjectUlid: $feed->ulid,
            );

            return $feed;
        });

        return ['feed' => $feed, 'token' => $rawToken];
    }
}
