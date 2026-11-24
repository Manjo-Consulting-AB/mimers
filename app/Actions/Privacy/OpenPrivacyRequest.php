<?php

namespace App\Actions\Privacy;

use App\Models\Account;
use App\Models\PrivacyRequest;
use App\Models\PrivacyRequestEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Öppnar ett ärende om en begäran från en registrerad — se [[ADR-0095
 * Integritetsbegäranden]] § 2 och § 5 samt [[Konton och åtkomst]]
 * § privacy_request.
 *
 * **Konsolen är en av två anropare.** `privacy:open` är handgreppet för den
 * som har tillgång till servern, och `/contact` ska kunna anropa samma
 * action när formuläret byggs (ADR-0095 § 2). Actionen läser därför inget ur
 * konsolen: allt den behöver kommer som argument, och mottagningsdagen
 * förvalar till i dag.
 *
 * **Namn, e-postadress och beskrivning krävs.** Det är allt intaget har
 * (ADR-0095 § 2); kopplingen till konto och person görs först när
 * identiteten är prövad, och därför är `$accountUlid` och `$userUlid`
 * valfria. Kommandot letar aldrig upp en person ur e-postadressen.
 *
 * **Fristen räknas som en kalendermånad med `addMonthsNoOverflow()`**
 * (ADR-0095 § 5): mottaget 31 januari ger 28 eller 29 februari, aldrig
 * 3 mars. `deadline_months` bor i config/privacy.php.
 *
 * Ärendet och dess `created`-händelse skrivs i EN transaktion. En halv
 * registrering — ett ärende utan spår av att det öppnats — vore värre än
 * ingen. Referensen prövas mot tabellen innan raden skrivs; det unika
 * indexet är det sista skyddet.
 */
class OpenPrivacyRequest
{
    public function __construct(
        private readonly RecordPrivacyEvent $recordEvent,
    ) {}

    /**
     * @param  string  $type  En av typerna i config('privacy.types').
     * @param  string  $requesterName  Den som begär.
     * @param  string  $requesterEmail  Dit svaret går.
     * @param  string  $description  Vad personen begär, med egna ord.
     * @param  string  $actor  Handläggarens namn, sparas på händelsen.
     * @param  Carbon|null  $receivedAt  Mottagningsdagen; förval i dag.
     * @param  string|null  $onBehalfOf  Huvudmannen när ett ombud begär.
     * @param  string|null  $accountUlid  Kontots ULID, när identiteten prövats.
     * @param  string|null  $userUlid  Personens ULID, när identiteten prövats.
     *
     * @throws InvalidArgumentException när typen är okänd eller mottagningen
     *                                  ligger i framtiden.
     * @throws ModelNotFoundException när en angiven ULID inte finns;
     *                                ingenting har då skrivits.
     */
    public function handle(
        string $type,
        string $requesterName,
        string $requesterEmail,
        string $description,
        string $actor,
        ?Carbon $receivedAt = null,
        ?string $onBehalfOf = null,
        ?string $accountUlid = null,
        ?string $userUlid = null,
    ): PrivacyRequest {
        if (! in_array($type, (array) config('privacy.types'), true)) {
            throw new InvalidArgumentException("Okänd typ: {$type}.");
        }

        $receivedAt = ($receivedAt ?? today())->copy()->startOfDay();

        // Fristen räknas från mottagandet, och en dag som inte varit kan
        // inte ha varit mottagningsdag. Kontrollen ligger efter typen och
        // före varje skrivning, så ett fel lämnar tabellerna orörda.
        if ($receivedAt->isAfter(today())) {
            throw new InvalidArgumentException('Mottagningsdagen kan inte ligga i framtiden.');
        }

        // Uppslagen sker före transaktionen: en okänd ULID ska bli ett fel
        // innan något skrivs. firstOrFail() ger ModelNotFoundException, som
        // kommandot översätter till ett felmeddelande.
        $account = $accountUlid === null
            ? null
            : Account::query()->where('ulid', $accountUlid)->firstOrFail();

        $user = $userUlid === null
            ? null
            : User::query()->where('ulid', $userUlid)->firstOrFail();

        return DB::transaction(function () use (
            $type,
            $requesterName,
            $requesterEmail,
            $description,
            $actor,
            $receivedAt,
            $onBehalfOf,
            $account,
            $user,
        ): PrivacyRequest {
            $request = new PrivacyRequest;
            $request->reference = $this->newReference();
            $request->type = $type;
            $request->status = PrivacyRequest::STATUS_RECEIVED;
            $request->priority = PrivacyRequest::PRIORITY_NORMAL;
            $request->requester_name = $requesterName;
            $request->requester_email = $requesterEmail;
            $request->on_behalf_of = $onBehalfOf;
            $request->account_id = $account?->id;
            $request->user_id = $user?->id;
            $request->role = PrivacyRequest::ROLE_UNDETERMINED;
            $request->description = $description;
            $request->received_at = $receivedAt;
            $request->deadline_at = $receivedAt->copy()
                ->addMonthsNoOverflow((int) config('privacy.deadline_months'));
            $request->extended_months = 0;
            $request->save();

            $this->recordEvent->handle(
                request: $request,
                kind: PrivacyRequestEvent::KIND_CREATED,
                actor: $actor,
            );

            return $request;
        });
    }

    /**
     * En referens som inte redan finns i tabellen. Formen och alfabetet bor
     * på modellen; unikhetsfrågan hör hit, för det är här raden skrivs.
     */
    private function newReference(): string
    {
        do {
            $reference = PrivacyRequest::newReference();
        } while (PrivacyRequest::query()->where('reference', $reference)->exists());

        return $reference;
    }
}
