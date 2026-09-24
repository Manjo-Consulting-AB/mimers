<?php

namespace App\Actions\OwnershipTransfer;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Notification\CreateNotification;
use App\Exceptions\Api\ApiException;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\Notification as NotificationModel;
use App\Models\OwnershipTransfer;
use App\Models\User;
use App\Notifications\OwnershipTransferNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/**
 * Initierar en överlåtelse: dubblettspärren, raden, mottagarvägens notifiering
 * och `ownership_transfer.offered` — se [[Konton och åtkomst]]
 * § ownership_transfer, Beslut 12, och [[ADR-0043 Tre loggar]]
 * § Händelseloggen.
 *
 * Bryts ut enligt [[ADR-0024 Tunna controllers och actions]] i issue 111:
 * `App\Http\Controllers\OwnershipTransferController::createTransfer()` och
 * `App\Http\Controllers\Api\OwnershipTransferController::store()` bar var sin
 * avskrift av samma sex kolumner, samma dubblettspärr och samma notifiering.
 * Den dubbleringen var medveten och dokumenterad medan `app/Actions/**` låg
 * utanför rutan — nu är raden i `audit_log` det som gör den till en andra
 * sanning om vad en överlåtelse ÄR.
 *
 * **Ingen `Gate::authorize()` och ingen kvotkontroll.** Behörigheten prövas av
 * anroparen, och `Entitlements::assertFeature($account,
 * 'ownership_transfer')` stannar i båda kontrollerna: den svarar OLIKA på de
 * två ytorna (en flash-notis i webben, 403 `plan.feature_unavailable` i
 * `/api`), och svaret är kontrollerns. Ordningen är grind före kvot före
 * dubblettspärr, som förut (issue 27 § Beslut 3).
 *
 * **Dubblettspärren frågar efter en `pending`-rad som inte gått ut.** En
 * utgången, avvisad eller tillbakadragen rad blockerar inget — att begära igen
 * efter ett nej ska gå — och utgången läses ur `created_at` + TTL och inte ur
 * `status`, för kolumnen flippas aldrig (39a § Beslut 10).
 *
 * **`to_email` följer aldrig med i `meta`.** En adress är det enda `meta`
 * aldrig får bära (issue 40 § Beslut 10): `meta.to_account` är mottagarkontots
 * ULID, eller `null` när vägen gick via en adress. `meta.recipient_type` säger
 * vilket, så historiken kan skilja fallen åt utan att någonsin bära adressen.
 */
class OfferOwnershipTransfer
{
    public function __construct(private readonly RecordAuditEvent $recordAuditEvent) {}

    /**
     * @param  User  $actor  Den som erbjuder containern. Behörigheten är redan
     *                       prövad av anroparen; hen blir `user_id` på
     *                       loggraden och `initiated_by_user_id` på raden.
     * @param  string|null  $toAccountUlid  Mottagarkontots ULID, eller `null`
     *                                      när mottagaren bara är en adress.
     *                                      Requesten har redan bevisat att
     *                                      exakt en av de två vägarna är satt.
     * @param  string|null  $toEmail  Adressen den kom in i kroppen, orensad —
     *                                den normaliseras här, så den lagrade raden
     *                                och notifieringen garanterat jämför samma
     *                                sträng.
     * @param  list<string>  $excludedItemUlids  ULID:erna för de items
     *                                           säljaren behåller — kolumnen
     *                                           bär ULID:er och inte
     *                                           löpnummer, se
     *                                           App\Models\OwnershipTransfer.
     * @param  string|null  $retainAccessLevel  Nivån säljaren behåller, eller
     *                                          `null` för ingen alls.
     *
     * @throws ApiException 422 `transfer.already_pending`.
     */
    public function handle(
        Container $container,
        User $actor,
        ?string $toAccountUlid,
        ?string $toEmail,
        array $excludedItemUlids,
        ?string $retainAccessLevel,
    ): OwnershipTransfer {
        $existing = $container->transfers()
            ->where('status', 'pending')
            ->where('created_at', '>', now()->subDays(OwnershipTransfer::TTL_DAYS))
            ->first();

        if ($existing instanceof OwnershipTransfer) {
            throw ApiException::make('transfer.already_pending', ['transfer' => $existing->ulid], 422);
        }

        $toAccount = $toAccountUlid === null
            ? null
            : Account::query()->where('ulid', $toAccountUlid)->firstOrFail();

        $transfer = DB::transaction(function () use ($container, $actor, $toAccount, $toEmail, $excludedItemUlids, $retainAccessLevel): OwnershipTransfer {
            $transfer = new OwnershipTransfer;
            $transfer->container_id = $container->id;
            $transfer->from_account_id = $container->account_id;
            $transfer->status = 'pending';
            $transfer->excluded_item_ids = $excludedItemUlids;
            $transfer->retain_access_level = $retainAccessLevel;
            $transfer->initiated_by_user_id = $actor->id;

            if ($toAccount instanceof Account) {
                $transfer->to_account_id = $toAccount->id;
                $transfer->to_email = null;
            } else {
                $transfer->to_account_id = null;
                $transfer->to_email = mb_strtolower((string) $toEmail);
            }

            $transfer->save();

            // `meta` bär mottagarens typ och ULID, nivån säljaren behåller och
            // antalet undantagna items — **aldrig adressen** (issue 40
            // § Beslut 10). Antalet och inte listan: items har egna rader i
            // historiken, och undantagslistan är en hel lista av ULID:er som
            // inte säger en läsare något.
            $this->recordAuditEvent->handle(
                action: AuditLog::ACTION_OWNERSHIP_TRANSFER_OFFERED,
                account: $container->account,
                user: $actor,
                container: $container,
                subjectType: 'ownership_transfer',
                subjectUlid: $transfer->ulid,
                meta: [
                    'recipient_type' => $toAccount instanceof Account ? 'account' : 'email',
                    'to_account' => $toAccount?->ulid,
                    'retain_access_level' => $retainAccessLevel,
                    'excluded_item_count' => count($transfer->excluded_item_ids ?? []),
                ],
            );

            return $transfer;
        });

        // Notifieringen ligger UTANFÖR transaktionen: den är en följd av
        // raden, inte en del av den, ogiltigförklarar inget om den faller, och
        // ett mejl som skickas inifrån en transaktion kan gå ut för en rad som
        // sedan rullas tillbaka.
        if ($toAccount instanceof Account) {
            $this->notifyAccountMembers($transfer, $toAccount, $container);
        } else {
            $this->sendOnDemandMail((string) $transfer->to_email, $container);
        }

        return $transfer;
    }

    /**
     * En `transfer.requested`-notis per medlem i det mottagande kontot, genom
     * App\Actions\Notification\CreateNotification — den enda vägen in i
     * `notification`, som respekterar preferenser och tysta timmar och skapar
     * leveransraderna för kön.
     *
     * `dedupe_key` bär transfer och mottagare: skulle handle() anropas två
     * gånger (efter att den första raden hunnit skapas men innan svaret)
     * skapar CreateNotification ingen andra notisrad.
     */
    private function notifyAccountMembers(OwnershipTransfer $transfer, Account $toAccount, Container $container): void
    {
        foreach ($toAccount->users as $recipient) {
            app(CreateNotification::class)->handle(
                type: NotificationModel::TYPE_TRANSFER_REQUESTED,
                account: $toAccount,
                user: $recipient,
                container: $container,
                payload: ['container' => $container->name],
                dedupeKey: "transfer.requested:{$transfer->ulid}:{$recipient->ulid}",
            );
        }
    }

    /**
     * On-demand-mejlet till en adress utan konto. Inget `notification`-rad
     * skapas, och mejlet bär ingen token och ingen länk med hemlighet: det
     * pekar på `/transfers`, sökvägen mottagarsidan svarar på, och ber
     * mottagaren skapa ett konto med just den adressen och verifiera den.
     */
    private function sendOnDemandMail(string $email, Container $container): void
    {
        $url = rtrim((string) config('app.url'), '/').'/transfers';

        NotificationFacade::route('mail', $email)
            ->notify(new OwnershipTransferNotification($url, $container));
    }
}
