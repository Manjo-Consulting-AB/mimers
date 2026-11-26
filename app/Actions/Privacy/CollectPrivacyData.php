<?php

namespace App\Actions\Privacy;

use App\Models\PrivacyRequest;
use App\Models\PrivacyRequestAttachment;
use App\Models\PrivacyRequestEvent;
use InvalidArgumentException;
use JsonException;
use Throwable;

/**
 * Sammanställer de uppgifter i databasen som Mimers är
 * personuppgiftsansvarig för om personen ärendet är kopplat till — se
 * [[ADR-0095 Integritetsbegäranden]] § 12 och [[Registerförteckning]].
 *
 * **Ingen sammanställning görs innan identiteten är prövad** (ADR-0095
 * § 2): ärendet måste vara kopplat till en person, och det måste finnas en
 * händelse `verification` med `subject: identity` och `result: verified`.
 *
 * **Personen kan vara raderad.** Kopplingen `privacy_request.user_id` saknar
 * främmande nyckel och lever kvar, medan raden i `user` är borta. Då är
 * filens `user` null och de rader som finns kvar sammanställs som vanligt.
 *
 * **Varje källa är en egen nyckel med raderna som en lista.** Listan
 * `not_included` säger vad handläggaren får hämta för hand: användarens
 * innehåll (Mimers är biträde där, ADR-0095 § 9), Sentry, Mailgun, backuperna
 * och serverloggarna.
 *
 * **Den rättsliga spärren tas med, utom när den är tystnadsbelagd**
 * ([[ADR-0043 Tre loggar]] § Den rättsliga spärren). GDPR tillåter bara att
 * uppgifter hålls inne med stöd i lag (art. 23), och det stödet finns när en
 * myndighet ålagt oss tystnad (`legal_hold.confidential`), inte när vi själva
 * eller en anmälare enligt DSA vill att den som utreds inte ska få veta. För
 * varje spärr på personens konton tas `case_number`, `created_at` och
 * `lifted_at` med — aldrig `reason`, som är fritext om utredningen — och
 * kontots rader i säkerhetsloggen om den. En tystnadsbelagd spärr utelämnas
 * helt, och likaså de rader i säkerhetsloggen vars `meta.case_number` hör
 * till den på samma konto. Kommandot varnar handläggaren på skärmen och
 * säger om spärren är tystnadsbelagd.
 *
 * Filen skapas genom AttachPrivacyAttachment::handleContents(), utan en
 * temporär kopia: den finns bara på disken `privacy` (ADR-0095 § 12).
 */
class CollectPrivacyData
{
    /**
     * Händelsen som bevisar att identiteten är prövad.
     */
    private const VERIFICATION_KIND = 'verification';

    private const IDENTITY_SUBJECT = 'identity';

    private const VERIFIED_RESULT = 'verified';

    /**
     * Systemet och operationen i händelsen `action` som antecknar
     * sammanställningen (ADR-0095 § 7 och § 12).
     */
    private const SYSTEM = 'database';

    private const OPERATION = 'exported';

    public function __construct(
        private readonly AttachPrivacyAttachment $attach,
        private readonly RecordPrivacyAction $recordAction,
        private readonly CollectPersonData $person,
    ) {}

    /**
     * @param  string  $actor  Handläggarens namn ur `--by`.
     *
     * @throws InvalidArgumentException när ärendet saknar personkoppling,
     *                                  identiteten inte är verifierad eller
     *                                  sammanställningen inte kan kodas som
     *                                  JSON; ingen bilaga och ingen händelse
     *                                  finns då.
     * @throws PrivacyCollectionNotRecordedException när bilagan skrevs men
     *                                               händelsen `action` inte
     *                                               kunde antecknas; filen
     *                                               finns då kvar.
     */
    public function handle(PrivacyRequest $request, string $actor): PrivacyRequestAttachment
    {
        $userId = $request->user_id;

        if ($userId === null) {
            throw new InvalidArgumentException(
                'Ärendet är inte kopplat till en person. Koppla personen först med privacy:link.'
            );
        }

        if (! $this->isIdentityVerified($request)) {
            throw new InvalidArgumentException(
                'Identiteten är inte verifierad. Ingen sammanställning görs innan verifieringen är registrerad med privacy:verify.'
            );
        }

        $data = $this->person->collect((int) $userId);

        try {
            $json = json_encode(
                $data,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $e) {
            throw new InvalidArgumentException(
                'Sammanställningen kunde inte kodas som JSON.',
                0,
                $e,
            );
        }

        // Filen läggs på ärendet först. Händelsen `action` skrivs efteråt,
        // så att den aldrig påstår en export som inte finns: faller
        // attach-steget finns varken fil eller händelse. Faller i stället
        // händelsen finns bilagan kvar, och undantaget bär dess ulid så att
        // handläggaren kan följa upp ärendet för hand.
        $attachment = $this->attach->handleContents(
            request: $request,
            originalName: 'insamling-'.$request->reference.'.json',
            contents: $json."\n",
            actor: $actor,
        );

        /** @var list<string> $categories */
        $categories = array_values((array) config('privacy.collection_categories'));

        try {
            $this->recordAction->handle(
                request: $request,
                system: self::SYSTEM,
                operation: self::OPERATION,
                categories: $categories,
                retentionReason: null,
                feature: null,
                note: null,
                actor: $actor,
            );
        } catch (Throwable $e) {
            throw new PrivacyCollectionNotRecordedException($attachment, $e);
        }

        return $attachment;
    }

    /**
     * Personens konto-id:n; kommandot ställer spärrfrågan på dem. Frågan
     * bor i CollectPersonData, som också läser dem för säkerhetsloggen.
     *
     * @return list<int>
     */
    public function accountIds(?int $userId): array
    {
        return $this->person->accountIds($userId);
    }

    /**
     * Är identiteten prövad? En verifiering med `subject: identity` och
     * `result: verified` räcker; en `authority`-verifiering eller ett annat
     * resultat gör det inte (ADR-0095 § 2).
     */
    private function isIdentityVerified(PrivacyRequest $request): bool
    {
        return $request->events()
            ->where('kind', self::VERIFICATION_KIND)
            ->get()
            ->contains(function (PrivacyRequestEvent $event): bool {
                $data = $event->data;

                return ($data['subject'] ?? null) === self::IDENTITY_SUBJECT
                    && ($data['result'] ?? null) === self::VERIFIED_RESULT;
            });
    }
}
