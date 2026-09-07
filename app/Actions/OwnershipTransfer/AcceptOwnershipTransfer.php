<?php

namespace App\Actions\OwnershipTransfer;

use App\Actions\Usage\AdjustUsage;
use App\Exceptions\Api\ApiException;
use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Invitation;
use App\Models\Item;
use App\Models\OwnershipTransfer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\Plan\Entitlements;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Accepterar ett ägarbyte — hela överlåtelsen i EN transaktion, se issue 39b
 * och [[Konton och åtkomst]] § ownership_transfer. Förlagan är
 * App\Actions\Invitation\AcceptInvitation, med samma engångsspärr och samma
 * "allt eller inget"-form.
 *
 * Det som sker, i ordningen i Beslut 4–9: utgånget ägarbyte avvisas innan
 * transaktionen öppnas, status flippas med en villkorad UPDATE först inne i
 * transaktionen, kvotkontrollerna görs mot mottagarens NUVARANDE plan,
 * containern flyttas, förbrukningen flyttas mellan räknarna (den enda vägen
 * in i dem är App\Actions\Usage\AdjustUsage), åtkomsterna återkallas och den
 * kvarhållna åtkomsten skapas om sådan begärts, och mottagaren får tolv
 * månader Pro. Skriver inget i `audit_log` — det gör issue 40, som lägger
 * anropet här. Ingen bonusspärr ("en gång per mottagande konto") — det är
 * issue 49 (M9), som hakar i den här transaktionen senare.
 *
 * Anropas av App\Http\Controllers\Api\OwnershipTransferController efter att
 * den bevisat att raden är mottagarens (annars 404) och löst ut vilket
 * konto som tar emot. Mottagarkontot är upplåst (Beslut 3) och säljarens
 * undantagna items har ännu inte lyfts ut — det är session 2 i samma issue,
 * som fyller i steget markerat nedan.
 */
class AcceptOwnershipTransfer
{
    /**
     * @throws ApiException 422 `transfer.expired`, 403
     *                      `transfer.account_frozen`, 422
     *                      `transfer.not_pending`, 403 `quota.*`.
     */
    public function handle(OwnershipTransfer $transfer, Account $toAccount): Container
    {
        // Beslut 5: ett utgånget ägarbyte avvisas innan transaktionen öppnas.
        // Kolumnen står kvar på `pending` — utgången härleds ur `created_at`,
        // se App\Models\OwnershipTransfer::isExpired().
        if ($transfer->isExpired()) {
            throw ApiException::make('transfer.expired', [], 422);
        }

        // Beslut 3: ett fruset mottagarkonto nekas. Att lägga en pärm i ett
        // konto som inte får skrivas i är att låsa in den; `account.status`
        // rörs aldrig av den här transaktionen — att lyfta frysningen är
        // nedgraderingens jobb (issue 28).
        if (in_array($toAccount->status, ['read_only', 'closed'], true)) {
            throw ApiException::make('transfer.account_frozen', [], 403);
        }

        $fromAccount = Account::query()->findOrFail($transfer->from_account_id);

        return DB::transaction(function () use ($transfer, $toAccount, $fromAccount): Container {
            // Beslut 5: statusövergången är en villkorad UPDATE, först i
            // transaktionen. Databasen serialiserar UPDATE-satser mot samma
            // rad, så två samtidiga accept-anrop kan aldrig båda lyckas —
            // den andra ser 0 rader och kastar. Aldrig läs-följt-av-skriv.
            $accepted = OwnershipTransfer::query()
                ->whereKey($transfer->getKey())
                ->where('status', 'pending')
                ->update(['status' => 'accepted', 'accepted_at' => now()]);

            if ($accepted !== 1) {
                throw ApiException::make('transfer.not_pending', [], 422);
            }

            $container = Container::query()->findOrFail($transfer->container_id);

            // Session 2 (samma issue): här lyfts de undantagna itemen ut till
            // en egen container innan bytena räknas — Beslut 10–13. Kvot-
            // kontrollen för säljarens nya container hoppas över med flit.
            $bytesSomFlyttas = $this->bytesAttFlytta($container, $fromAccount);

            // Beslut 4: kvotkontroll mot mottagarens NUVARANDE plan, före
            // bonusen i Beslut 9. Den ordningen är den enda som håller:
            // bonusen ges en gång per mottagande konto (issue 49/M9), så ett
            // andra ägarbyte till samma konto har ingen bonus att luta sig
            // mot — en kontroll som räknade med Pro skulle neka det andra och
            // släppa igenom det första. Kastar någon av kontrollerna rullas
            // hela transaktionen tillbaka och ägarbytet står kvar som pending.
            $entitlements = new Entitlements;
            $entitlements->assertCanCreateContainer($toAccount);
            $entitlements->assertStorageWithinLimit($toAccount, $bytesSomFlyttas);

            // Beslut 6: containern flyttas. Ingen annan kolumn rörs — namn,
            // `kind` och `created_at` följer med pärmen.
            $container->account_id = $toAccount->id;
            $container->save();

            // Beslut 7: containerantalet flyttas mellan räknarna.
            (new AdjustUsage)->handle($fromAccount->id, containersDelta: -1);
            (new AdjustUsage)->handle($toAccount->id, containersDelta: +1);

            // Beslut 7: bytena flyttas — dras från säljaren, läggs till
            // köparen, och varje berörd attachment-rad får säljarens konto
            // bytt mot köparens. Bilagor bokförda på ett TREDJE konto rörs
            // inte: bytena belastar det uppladdande kontot ([[Planer och
            // kvoter]] § usage_counter), och en gästs uppladdning i pärmen
            // fortsätter belasta gästens konto. Det ser ut som en glömska och
            // är ett beslut.
            if ($bytesSomFlyttas > 0) {
                $this->flyttaBokfordaByten($container, $fromAccount, $toAccount, $bytesSomFlyttas);
            }

            // Beslut 8: alla återstående giltiga åtkomster återkallas och
            // öppna inbjudningar sätts till `revoked`. Den nya ägaren
            // bestämmer själv vem som får komma in — en delning säljaren
            // gjort med sin systerdotter ska inte följa med båten.
            ContainerAccess::query()
                ->where('container_id', $container->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            Invitation::query()
                ->where('container_id', $container->id)
                ->where('status', 'pending')
                ->where('expires_at', '>', now())
                ->update(['status' => 'revoked']);

            // Beslut 8: den kvarhållna åtkomsten, om avsändaren begärt en.
            if ($transfer->retain_access_level !== null) {
                $this->skapaKvarhallAtkomst($transfer);
            }

            // Beslut 9: tolv månader Pro till mottagarkontot.
            $this->beviljaPro($toAccount);

            // issue 40: här ska audit_log-raden för ägarbytet skrivas.

            return $container;
        });
    }

    /**
     * Summan av bytena som flyttas till köparen: stored_file.byte_size för
     * icke mjukraderade bilagor på icke mjukraderade items i containern, där
     * bilagan är bokförd på säljaren. Bytena bor på stored_file (issue 16a);
     * dokumentets "attachment.byte_size" är bilagans byten, mätt som den
     * logiska storlek UsageCounter räknar med. Frågan formuleras en gång och
     * delas av flyttaBokfordaByten() nedan, så summan och raderna som
     * bokförs om aldrig kan glida isär.
     */
    private function bytesAttFlytta(Container $container, Account $fromAccount): int
    {
        return (int) DB::table('attachment')
            ->join('stored_file', 'stored_file.id', '=', 'attachment.stored_file_id')
            ->whereIn('attachment.item_id', $this->containerItemsSubquery($container))
            ->where('attachment.billed_account_id', $fromAccount->id)
            ->whereNull('attachment.deleted_at')
            ->sum('stored_file.byte_size');
    }

    /**
     * Flyttar bytena mellan räknarna och bokför om bilagorna, i samma
     * transaktion. Räknarna uppdateras av AdjustUsage — den enda vägen in
     * (issue 26a § Beslut 3) — aldrig med läs-ändra-skriv i PHP.
     */
    private function flyttaBokfordaByten(
        Container $container,
        Account $fromAccount,
        Account $toAccount,
        int $bytes,
    ): void {
        (new AdjustUsage)->handle($fromAccount->id, bytesDelta: -$bytes);
        (new AdjustUsage)->handle($toAccount->id, bytesDelta: +$bytes);

        DB::table('attachment')
            ->whereIn('item_id', $this->containerItemsSubquery($container))
            ->where('billed_account_id', $fromAccount->id)
            ->whereNull('deleted_at')
            ->update([
                'billed_account_id' => $toAccount->id,
                'updated_at' => now(),
            ]);
    }

    /**
     * Levande items i containern, som delsubquery. SoftDeletes' globala scope
     * gäller genom Item::query(), så mjukraderade items försvinner av sig
     * själva — samma villkor som bytesAttFlytta() ställer på item-sidan.
     */
    private function containerItemsSubquery(Container $container): Builder
    {
        return Item::query()
            ->where('container_id', $container->id)
            ->select('id');
    }

    /**
     * Beslut 8: den kvarhållna åtkomsten. `kind = 'managed'` för att
     * mottagaren är ett konto, inte en person — varvet behåller `write`
     * efter överlämning, och det är organisationen som behåller den, inte
     * den anställde som råkade klicka.
     */
    private function skapaKvarhallAtkomst(OwnershipTransfer $transfer): void
    {
        $access = new ContainerAccess;
        $access->container_id = $transfer->container_id;
        $access->grantee_type = 'account';
        $access->grantee_id = $transfer->from_account_id;
        $access->level = $transfer->retain_access_level;
        $access->kind = 'managed';
        $access->expires_at = null;
        $access->granted_by_user_id = $transfer->initiated_by_user_id;
        $access->save();
    }

    /**
     * Beslut 9 och [[ADR-0014 Prismodell]]: mottagaren får tolv månader Pro.
     *
     * Saknar kontot en subscription-rad skapas en aktiv Pro-rad från idag.
     * Har kontot redan en aktiv Pro-rad FÖRLÄNGS perioden från sitt
     * nuvarande värde, aldrig från `now()` — annars kortas en betalande
     * kunds period av att hon får en båt. Ligger raden i något annat läge
     * (uppsagd, obetald eller en annan plan) sätts den till Pro, aktiv, från
     * idag. `account.status` rörs aldrig — ett `read_only`-konto blir inte
     * aktivt av att få en pärm, och här nekas accepten redan i Beslut 3.
     */
    private function beviljaPro(Account $account): void
    {
        $pro = Plan::query()->where('code', 'pro')->firstOrFail();

        $subscription = Subscription::query()
            ->where('account_id', $account->id)
            ->lockForUpdate()
            ->first();

        if ($subscription === null) {
            $subscription = new Subscription;
            $subscription->account_id = $account->id;
            $subscription->plan_id = $pro->id;
            $subscription->status = 'active';
            $subscription->current_period_end = now()->addYear();
            $subscription->grace_until = null;
            $subscription->save();

            return;
        }

        if ($subscription->status === 'active' && $subscription->plan_id === $pro->id) {
            $subscription->current_period_end = $subscription->current_period_end->addYear();
            $subscription->save();

            return;
        }

        $subscription->plan_id = $pro->id;
        $subscription->status = 'active';
        $subscription->current_period_end = now()->addYear();
        $subscription->grace_until = null;
        $subscription->save();
    }
}
