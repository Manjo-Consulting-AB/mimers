<?php

namespace App\Actions\Loan;

use App\Actions\Audit\RecordAuditEvent;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Registrerar en utlåning och loggar `loan.created` — på ett ställe, så
 * webbens och `/api`:s skapande inte kan glida isär (issue 110, [[ADR-0043
 * Tre loggar]] § Händelseloggen).
 *
 * Bryts ut enligt [[ADR-0024 Tunna controllers och actions]]. Fram till issue
 * 110 bar båda kontrollerna var sin kopia av spärren "högst en öppen utlåning
 * per item" och var sin transaktion — en medveten dubblering medan
 * `app/Actions/**` låg utanför omfångsrutan. Nu ligger den i
 * App\Actions\Loan\AssertNoOpenLoan och anropas härifrån.
 *
 * **Spärren är en check-then-act och körs därför under `lockForUpdate()` på
 * ITEM-raden** (granskningsfynd; samma konvention som ContainerController::
 * destroy och 22a/22b). Låset ligger aldrig på loan-tabellen — en tom mängd
 * rader är ett gap lock i MySQL (issue 22a § Beslut 7). Utan låset passerar
 * två samtidiga anrop och bryter regeln.
 *
 * `item_id` sätts explicit — det är uteslutet ur `Loan::#[Fillable]`.
 *
 * **Låntagarens e-postadress följer aldrig med i `meta`** (issue 76 § Beslut
 * 8, [[ADR-0017 Missbruksvektorer]] § 7): adressen är en kontaktuppgift
 * utlånaren har framme, inte något loggen är ett register över. Ingen rad
 * skrivs med `meta` alls här — det finns ingenting om utlåningen som är en
 * värdelista, ett tal eller ett datum vid skapandet.
 */
class CreateLoan
{
    public function __construct(
        private readonly RecordAuditEvent $recordAuditEvent,
        private readonly AssertNoOpenLoan $assertNoOpenLoan,
    ) {}

    /**
     * @param  User  $actor  Den som registrerar utlåningen; blir `user_id` på
     *                       loggraden. Behörigheten är redan prövad.
     * @param  Loan  $loan  Den nya raden med kroppens fält ifyllda, utan
     *                      `item_id`.
     */
    public function handle(Item $item, User $actor, Loan $loan): Loan
    {
        return DB::transaction(function () use ($item, $actor, $loan): Loan {
            $lockedItem = $item->newQuery()
                ->whereKey($item->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertNoOpenLoan->handle($lockedItem);

            $loan->item_id = $lockedItem->id;
            $loan->save();

            $this->recordAuditEvent->handle(
                action: AuditLog::ACTION_LOAN_CREATED,
                account: $lockedItem->container->account,
                user: $actor,
                container: $lockedItem->container,
                item: $lockedItem,
                subjectType: 'loan',
                subjectUlid: $loan->ulid,
            );

            return $loan;
        });
    }
}
