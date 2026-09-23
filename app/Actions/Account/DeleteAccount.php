<?php

namespace App\Actions\Account;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Trash\PurgeContainer;
use App\Models\Account;
use App\Models\AuditLog;
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
 * 1. webhook_delivery-raderna på kontots endpoints (issue 37b § Beslut 10) —
 *    först av alla: raderna pekar på både notification och webhook_endpoint,
 *    båda ON DELETE RESTRICT, och containergallringen nedan raderar
 *    container-notiser som leveransrader kan peka på,
 * 2. varje container kontot äger, genom PurgeContainer — med withTrashed(),
 *    en mjukraderad container ska också bort,
 * 3. usage_counter-raden och webhook_endpoint-raderna (issue 37a § Beslut 8),
 * 4. subscription-raden om den finns,
 * 5. account_user-raderna,
 * 6. account-raden.
 *
 * `user`-rader raderas inte (issue 29b § Beslut 6): en användare är en
 * person som kan vara medlem i andra konton. Personen raderas i en egen
 * fråga med egna GDPR-krav.
 *
 * Hela kontot i EN transaktion (issue 29b § Beslut 7): en radering i fel
 * ordning, eller en rad som någon glömt, avbryter med ett integritetsfel
 * mitt i — transaktionen är det enda som gör det ofarligt. Loggen skrivs
 * före commit, medan raderna fortfarande finns att beskriva (Beslut 8).
 *
 * `audit_log` står inte i listan ovan och ska inte göra det (issue 107):
 * sedan `account_id` blev en identifierare utan främmande nyckel blockerar
 * en loggrad varken containergallringen eller account-raderingen, och
 * raderna ska leva vidare i tolv månader ([[ADR-0043 Tre loggar]]). Det är
 * också därför kontots sista rad skrivs här — `account.deleted`, i samma
 * transaktion och före commit, som ankaret issue 115 räknar från.
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

            // Webhook-leveranserna på kontots endpoints, issue 37b § Beslut
            // 10. Raden ligger här, FÖRE container-gallringen och före
            // notisraderingen: en leverans pekar på BÅDE webhook_endpoint och
            // notification, båda ON DELETE RESTRICT. Försvinner leveransen
            // inte först kan varken endpointsen nedan eller notisraderna —
            // kontots här, containrarnas inuti PurgeContainer — raderas.
            DB::table('webhook_delivery')
                ->whereIn('webhook_endpoint_id', DB::table('webhook_endpoint')->where('account_id', $accountId)->select('id'))
                ->delete();

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
            // prenumererat på en container kontot inte äger — hänger på `user_id`.
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

            // Webhook-endpoints på kontot (issue 37a § Beslut 8, utökat i 37b
            // § Beslut 10), bredvid usage_counter-raderingen ovan: nyckeln mot
            // account är ON DELETE RESTRICT, så utan städningen kastar
            // account-raderingen nedan ett integritetsfel. Leveransraderna togs
            // överst i transaktionen, före container-gallringen.
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

            // Kontots sista rad i händelseloggen (issue 107), på samma plats
            // och av samma skäl som raden ovan: efter raderingarna, före
            // commit. Det är den issue 115 räknar tolv månader från för de
            // rader som saknar container — containerns rader följer sin egen
            // `container.purged` — och den enda som säger att kontoid:t i
            // loggen en gång var ett konto. `user_id` är null: det är jobbet
            // som raderar, ingen användare (issue 40 § Beslut 11).
            (new RecordAuditEvent)->handle(
                action: AuditLog::ACTION_ACCOUNT_DELETED,
                account: $account,
            );

            DB::table('account')->where('id', $accountId)->delete();
        });
    }
}
