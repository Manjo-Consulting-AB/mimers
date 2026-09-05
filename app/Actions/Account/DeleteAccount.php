<?php

namespace App\Actions\Account;

use App\Actions\Trash\PurgeContainer;
use App\Models\Account;
use App\Models\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Raderar ett konto — sista steget i kontolivscykeln (issue 29b). Se
 * [[Planer och kvoter]] § Kontolivscykel och [[ADR-0009 Kvoter och
 * livscykel]]. Kontot antas redan vara valt och granskat av anroparen
 * (App\Console\DeletesDormantAccounts) — den här actionen är verktyget,
 * inte grinden, precis som PurgeContainer.
 *
 * Vad "kontot raderas" betyder konkret (issue 29b § Beslut 6), i ordningen
 * som de främmande nycklarna kräver — alla är ON DELETE RESTRICT
 * (AGENTS.md § Databaskonventioner), så det finns ingen kaskad som städar:
 *
 * 1. varje container kontot äger, genom PurgeContainer — med withTrashed(),
 *    en mjukraderad container ska också bort,
 * 2. usage_counter-raden och webhook_endpoint-raderna (issue 37a § Beslut 8),
 * 3. subscription-raden om den finns,
 * 4. account_user-raderna,
 * 5. account-raden.
 *
 * `user`-rader raderas inte (issue 29b § Beslut 6): en användare är en
 * person som kan vara medlem i andra konton. Personen raderas i en egen
 * fråga med egna GDPR-krav.
 *
 * Hela kontot i EN transaktion (issue 29b § Beslut 7): en radering i fel
 * ordning, eller en rad som någon glömt, avbryter med ett integritetsfel
 * mitt i — transaktionen är det enda som gör det ofarligt. Loggen skrivs
 * före commit, medan raderna fortfarande finns att beskriva (Beslut 8).
 */
class DeleteAccount
{
    public function __construct(
        private readonly PurgeContainer $purgeContainer,
    ) {}

    public function handle(Account $account): void
    {
        DB::transaction(function () use ($account): void {
            $accountId = $account->getKey();

            // withTrashed() — en container som redan är mjukraderad ska
            // också bort, annars blockerar den account-raderingen på FK:n.
            $containers = Container::withTrashed()
                ->where('account_id', $accountId)
                ->get();

            $containerIds = $containers->pluck('id');

            // Antalen till loggen läses INNAN raderingen — efteråt finns
            // inget kvar att beskriva den med (issue 29b § Beslut 8).
            // DB-frågorna ser även mjukraderade bilagor och items: de
            // force-raderas av PurgeContainer oavsett eget tillstånd.
            $attachmentCount = (int) DB::table('attachment')
                ->join('item', 'item.id', '=', 'attachment.item_id')
                ->whereIn('item.container_id', $containerIds)
                ->count();

            $bytes = (int) DB::table('attachment')
                ->join('item', 'item.id', '=', 'attachment.item_id')
                ->join('stored_file', 'stored_file.id', '=', 'attachment.stored_file_id')
                ->whereIn('item.container_id', $containerIds)
                ->sum('stored_file.byte_size');

            foreach ($containers as $container) {
                $this->purgeContainer->handle($container);
            }

            // calendar_feed behöver ingen egen rad här (issue 36a § Beslut
            // 7): feederna på kontots EGNA containers togs av PurgeContainer
            // ovan, och en feed på någon ANNANS container — en medlem som
            // prenumererat på en pärm kontot inte äger — hänger på `user_id`.
            // Användare raderas aldrig av den här actionen (29b § Beslut 6),
            // så den raden blir aldrig en främmandenyckel som blockerar.

            // Notiser på kontonivå (utan container, t.ex. account.inactive) —
            // container-notiserna togs av PurgeContainer ovan. Issue 30 §
            // Beslut 8: nycklarna är ON DELETE RESTRICT, så utan städningen
            // kastar account-raderingen nedan ett integritetsfel i
            // kontolivscykelns nattliga jobb (29b). Leveransraderna först.
            DB::table('notification_delivery')
                ->whereIn('notification_id', DB::table('notification')->where('account_id', $accountId)->select('id'))
                ->delete();
            DB::table('notification')->where('account_id', $accountId)->delete();

            DB::table('usage_counter')->where('account_id', $accountId)->delete();

            // Webhook-endpoints på kontot (issue 37a § Beslut 8), bredvid
            // usage_counter-raderingen ovan: nyckeln mot account är ON DELETE
            // RESTRICT, så utan städningen kastar account-raderingen nedan ett
            // integritetsfel. 37b utökar den här raden med webhook_delivery
            // när den tabellen finns.
            DB::table('webhook_endpoint')->where('account_id', $accountId)->delete();

            DB::table('subscription')->where('account_id', $accountId)->delete();
            DB::table('account_user')->where('account_id', $accountId)->delete();

            // Före commit — men efter alla raderingar, så en misslyckad
            // radering aldrig har hunnit loggas som lyckad.
            Log::warning('account.deleted', [
                'account_ulid' => $account->ulid,
                'containers' => $containers->count(),
                'attachments' => $attachmentCount,
                'bytes' => $bytes,
            ]);

            DB::table('account')->where('id', $accountId)->delete();
        });
    }
}
