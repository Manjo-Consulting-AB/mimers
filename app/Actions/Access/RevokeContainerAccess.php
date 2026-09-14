<?php

namespace App\Actions\Access;

use App\Actions\Audit\RecordAuditEvent;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Återkallar en delegerad åtkomst: `revoked_at` sätts och `access.revoked`
 * skrivs i `audit_log`, allt i EN transaktion — se issue 40 § Beslut 10 och
 * issue 55a § Beslut 8.
 *
 * Bryts ut ur App\Http\Controllers\Api\ContainerAccessController::destroy()
 * med kroppen oförändrad. Webben har samma knapp och samma två skrivningar,
 * och en kopia hade varit en andra sanning om vad en återkallelse ÄR.
 *
 * **Ingen `Gate::authorize()`.** Behörigheten prövas av anroparen, precis
 * som i dag — `ContainerPolicy::revokeAccess()` är regel 1 UTAN
 * `read_only`-kontroll, för regel 4 undantar uttryckligen återkallandet:
 * det minskar exponeringen i stället för att öka den. Se [[Konton och
 * åtkomst]] § Behörighetsregler regel 4 och issue 54 § Beslut 3.
 *
 * **Ingen retur.** En andra återkallelse är inte ett fel — den rör varken
 * tidsstämpeln eller loggen, och båda ytorna svarar utan fel (issue 9b
 * § Beslut 9).
 */
class RevokeContainerAccess
{
    public function __construct(private readonly RecordAuditEvent $recordAuditEvent) {}

    /**
     * Sätter `revoked_at` och raderar aldrig raden (issue 9b § Beslut 9).
     * Redan återkallad: ingen skrivning alls — den ursprungliga
     * tidsstämpeln är historiken (9a § Beslut 4) och en andra återkallelse
     * får inte skriva om den. `ContainerAccess` använder inte
     * `SoftDeletes`.
     *
     * Återkallningen och loggraden sker i EN transaktion: en logg som
     * skrevs utanför transaktionen kunde överleva ett rollback och
     * beskriva en återkallelse som inte hände.
     *
     * Raden läses om och låses INNE i transaktionen (`lockForUpdate`):
     * route-modellbindningens instans lästes innan transaktionen öppnades,
     * och två samtidiga anrop mot samma access skulle annars båda se
     * `revoked_at === null` på sin egen instans och skriva var sin loggrad
     * för samma återkallelse. En redan återkallad rad rörs inte och loggas
     * inte en andra gång.
     *
     * @param  User  $actor  Den som återkallar. Behörigheten är redan
     *                       prövad av anroparen; hen blir `user_id` på
     *                       loggraden.
     * @param  ContainerAccess  $access  Raden ur route-modellbindningen.
     *                                   Metoden läser om den med lås och
     *                                   litar inte på instansen.
     */
    public function handle(User $actor, Container $container, ContainerAccess $access): void
    {
        // ULID:erna till `meta.grantee` och `meta.item` löses upp i förväg —
        // `ContainerAccess` har medvetet ingen `grantee()`-relation (se
        // modellens docblock), så uppslagningen görs som platta frågor, samma
        // teknik som ListContainerAccesses::hydrateGranteeUlids(). Varken
        // `grantee_id` eller `item_id` rörs av en återkallning, så
        // uppslagningarna kan stå utanför transaktionen. `withTrashed()`: en
        // grant på ett sedan länge mjukraderat item ska loggas med sitt item,
        // inte som `null`.
        $granteeUlid = $access->grantee_type === 'user'
            ? User::query()->whereKey($access->grantee_id)->value('ulid')
            : Account::query()->whereKey($access->grantee_id)->value('ulid');

        $itemUlid = $access->item_id === null
            ? null
            : Item::withTrashed()->whereKey($access->item_id)->value('ulid');

        DB::transaction(function () use ($actor, $container, $access, $granteeUlid, $itemUlid): void {
            $låstAccess = ContainerAccess::query()
                ->whereKey($access->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($låstAccess->revoked_at === null) {
                $låstAccess->revoked_at = now();
                $låstAccess->save();

                $this->recordAuditEvent->handle(
                    action: AuditLog::ACTION_ACCESS_REVOKED,
                    account: $container->account,
                    user: $actor,
                    container: $container,
                    subjectType: 'container_access',
                    subjectUlid: $låstAccess->ulid,
                    meta: [
                        'grantee_type' => $låstAccess->grantee_type,
                        'grantee' => $granteeUlid,
                        'item' => $itemUlid,
                        'level' => $låstAccess->level,
                        'kind' => $låstAccess->kind,
                    ],
                );
            }
        });
    }
}
