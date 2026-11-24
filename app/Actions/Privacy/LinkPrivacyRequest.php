<?php

namespace App\Actions\Privacy;

use App\Models\Account;
use App\Models\PrivacyRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Kopplar ett ärende till ett konto och en person — se [[ADR-0095
 * Integritetsbegäranden]] § 2 och [[Konton och åtkomst]] § privacy_request.
 *
 * Kopplingen görs först när identiteten är prövad (RecordPrivacyVerification),
 * och den skrivs som en händelse `linked` med de två ULID:erna. ULID:erna och
 * inte de interna id:na: händelsen ska gå att läsa utan tabellerna, och den
 * interna nyckeln är inget som lämnar systemet (AGENTS.md
 * § Databaskonventioner).
 *
 * **Bara det som anges kopplas.** `--account` utan `--user` sätter kontot och
 * lämnar personen orörd, och tvärtom. En okänd ULID är ett fel innan något
 * skrivs — ett ärende som pekar på ett konto som inte finns vore ett bevis
 * på en koppling som aldrig gjordes.
 *
 * `account_id` och `user_id` saknar främmande nycklar, så kopplingen
 * överlever att kontot eller personen raderas (migrationen och ADR-0095 § 3).
 */
class LinkPrivacyRequest
{
    /**
     * Slaget i privacy_request_event. Konstanten bor här och inte på
     * modellen, som är utanför den här issuen.
     */
    private const KIND = 'linked';

    public function __construct(
        private readonly RecordPrivacyEvent $recordEvent,
    ) {}

    /**
     * @param  string|null  $accountUlid  Kontots ULID; null lämnar kopplingen orörd.
     * @param  string|null  $userUlid  Personens ULID; null lämnar kopplingen orörd.
     * @param  string  $actor  Handläggarens namn ur `--by`.
     *
     * @throws InvalidArgumentException när varken konto eller person angetts;
     *                                  ingenting har då skrivits.
     * @throws ModelNotFoundException när en angiven ULID inte finns;
     *                                ingenting har då skrivits.
     */
    public function handle(
        PrivacyRequest $request,
        ?string $accountUlid,
        ?string $userUlid,
        string $actor,
    ): PrivacyRequest {
        $accountUlid = $this->text($accountUlid);
        $userUlid = $this->text($userUlid);

        if ($accountUlid === null && $userUlid === null) {
            throw new InvalidArgumentException('Ange --account eller --user.');
        }

        // Uppslagen sker före transaktionen, som i OpenPrivacyRequest: en
        // okänd ULID ska bli ett fel innan något skrivs. firstOrFail() ger
        // ModelNotFoundException, som kommandot översätter.
        $account = $accountUlid === null
            ? null
            : Account::query()->where('ulid', $accountUlid)->firstOrFail();

        $user = $userUlid === null
            ? null
            : User::query()->where('ulid', $userUlid)->firstOrFail();

        // Den samlade kopplingen efter bytet: angiven ULID, annars den som
        // redan stod på ärendet. Händelsen ska visa det som gäller, inte
        // bara det som råkade skickas in.
        $linkedAccount = $accountUlid ?? $request->account?->ulid;
        $linkedUser = $userUlid ?? $request->user?->ulid;

        return DB::transaction(function () use (
            $request,
            $account,
            $user,
            $actor,
            $linkedAccount,
            $linkedUser,
        ): PrivacyRequest {
            if ($account !== null) {
                $request->account_id = $account->id;
            }

            if ($user !== null) {
                $request->user_id = $user->id;
            }

            $request->save();

            $this->recordEvent->handle(
                request: $request,
                kind: self::KIND,
                actor: $actor,
                data: [
                    'account' => $linkedAccount,
                    'user' => $linkedUser,
                ],
            );

            return $request;
        });
    }

    /**
     * Ett ULID som text, eller null när det inte angetts. Ett tomt
     * `--account=` är ingen koppling.
     */
    private function text(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
