<?php

namespace App\Actions\Cost;

use App\Actions\Audit\RecordAuditEvent;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\CostEntry;
use App\Models\Item;
use App\Models\User;
use App\Support\Cost\MinorUnits;
use Illuminate\Support\Facades\DB;

/**
 * Skapar en kostnadsrad och loggar `cost_entry.created` — på ett ställe, så
 * webbens och `/api`:s skapande inte kan glida isär (issue 168, [[ADR-0043
 * Tre loggar]] § Händelseloggen).
 *
 * Bryts ut enligt [[ADR-0024 Tunna controllers och actions]]. Fram till issue
 * 168 bar `Api\CostEntryController::store()` hela kroppen, och kontrollern
 * skrev själv att ingen Action behövdes: "ytan finns bara på `/api`". Det
 * upphörde när itemets kostnadsflik byggdes — två ytor med samma regler är
 * två ställen att glömma en av dem på, samma väg som App\Actions\Loan gick i
 * issue 110.
 *
 * **Beloppet tolkas här.** Det som kommer in är en sträng i huvudenhet
 * ("1200,50") och det som lagras är heltalet i minsta enhet (120050) från
 * App\Support\Cost\MinorUnits::parse() — som kastar
 * `cost.amount_invalid`/`cost.amount_decimals` innan en rad skapas
 * ([[ADR-0016 Kostnadsregistrering]]).
 *
 * **Valutan är valfri i kroppen** ([[ADR-0037 Valutans arv]]): skickar
 * klienten ingen valuta skriver servern containerns
 * `Container::effectiveCurrency()` — containerns egen om den har en, annars
 * ägarkontots. Fallet ligger före `MinorUnits::parse()`, som behöver valutan
 * för att veta antalet decimaler. Skickas en valuta vinner den alltid:
 * arvet är ett förslag, aldrig ett tvång.
 *
 * `container_id` denormaliseras från itemet och `created_by_user_id`/
 * `created_by_account_id` sätts här — aldrig ur kroppen. Kontot härleds ur
 * HUR användaren når containern, se `attributedAccountId()`.
 *
 * Raden och händelseloggen skrivs i EN transaktion: en loggrad som skrevs
 * utanför kunde överleva ett rollback och beskriva en kostnad som inte
 * finns.
 */
class CreateCostEntry
{
    public function __construct(private readonly RecordAuditEvent $recordAuditEvent) {}

    /**
     * @param  array<string, mixed>  $data  Den validerade kroppen.
     * @param  User  $actor  Den som registrerar kostnaden; blir
     *                       `created_by_user_id` och `user_id` på loggraden.
     *                       Behörigheten är redan prövad.
     */
    public function handle(Container $container, Item $item, array $data, User $actor): CostEntry
    {
        // `??=` och inte en `if`: en nyckel som saknas OCH en nyckel som kom
        // in som `null` (tom ruta, ConvertEmptyStringsToNull) betyder samma
        // sak — containern föreslår. Se klassdokumentationen i
        // StoreCostEntryRequest.
        $data['currency'] ??= $container->effectiveCurrency();

        $amount = MinorUnits::parse($data['amount'], $data['currency']);
        unset($data['amount']);

        $cost = new CostEntry($data);
        $cost->amount = $amount;
        $cost->item_id = $item->id;
        $cost->container_id = $item->container_id;
        $cost->created_by_user_id = $actor->id;
        $cost->created_by_account_id = $this->attributedAccountId($actor, $container);

        DB::transaction(function () use ($cost, $item, $actor): void {
            $cost->save();

            $this->recordAuditEvent->handle(
                action: AuditLog::ACTION_COST_ENTRY_CREATED,
                account: $item->container->account,
                user: $actor,
                container: $item->container,
                item: $item,
                subjectType: 'cost_entry',
                subjectUlid: $cost->ulid,
                meta: ['amount' => $cost->amount, 'currency' => $cost->currency],
            );
        });

        return $cost->load('createdByAccount');
    }

    /**
     * Kontot en kostnadsrad tillskrivs när en användare skapar den. Ska vara
     * "varvet, inte den anställde" — men varvet är det konto vars medlem
     * handlar, inte nödvändigtvis containerns ägarkonto (granskningens fynd
     * 1). Den axel kolumnen skiljer på är konto kontra person, inte ägare
     * kontra gäst: en post som skapas av någon som kommer utifrån ska
     * tillskrivas det konto som gav hen åtkomst, så relationen överlever
     * personalomsättningen ([[ADR-0003 Åtkomstmodell]]: "poster tillskrivs
     * organisationen"). Regel 1 motsvarar [[Konton och åtkomst]] §
     * Behörighetsregler regel 1; regel 2 är `managed`-fallet; regel 3 är den
     * personliga åtkomsten.
     *
     * Tre regler, första träffen vinner:
     *
     * 1. Ägarkontots medlemmar handlar som ägaren → `$container->account_id`.
     * 2. Annars: en giltig `container_access`-rad på containern med
     *    `grantee_type = 'account'` vars `grantee_id` är ett konto användaren
     *    är medlem i → den radens `grantee_id`. "Giltig" är samma villkor som
     *    grinden använder (`scopeValid`: `revoked_at` NULL, `expires_at` inte
     *    passerat). Flera sådana rader är en patologi; lägst `id` vinner,
     *    deterministiskt.
     * 3. Annars är åtkomsten personlig (`grantee_type = 'user'`) och
     *    användarens EGET konto gäller: `type = 'personal'` bland hens
     *    medlemskap, och saknas ett sådant, medlemskapet med lägst
     *    `account_id`. En privatperson som bjudits in tillskrivs sig själv,
     *    inte containerns ägare.
     *
     * Att fältet aldrig tas ur kroppen står fast — en `managed`-skribent kan
     * inte välja vilket av sina konton posten hamnar på: kontot härleds ur
     * hur användaren når containern, inte ur vad klienten påstår.
     */
    private function attributedAccountId(User $user, Container $container): int
    {
        if ($container->account->users()->whereKey($user->id)->exists()) {
            return $container->account_id;
        }

        $accountIds = $user->accounts()->pluck('account.id')->all();

        $managed = ContainerAccess::query()
            ->where('container_id', $container->id)
            ->where('grantee_type', 'account')
            ->whereIn('grantee_id', $accountIds)
            ->valid()
            ->orderBy('id')
            ->first();

        if ($managed !== null) {
            return $managed->grantee_id;
        }

        $personligt = $user->accounts()
            ->where('account.type', 'personal')
            ->orderBy('account.id')
            ->first();

        if ($personligt !== null) {
            return $personligt->id;
        }

        return $user->accounts()->orderBy('account.id')->first()->id;
    }
}
