<?php

namespace App\Actions\Account;

use App\Actions\Attachment\PurgeAttachment;
use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Notification\CreateNotification;
use App\Actions\Trash\PurgeContainer;
use App\Actions\Usage\TransferBilling;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Notification as NotificationModel;
use App\Models\User;
use App\Support\Account\ActingAccount;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

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
 * 2. bilagor kontot betalar för i containers det inte äger: `billed_account_id`
 *    flyttas till containerns ägarkonto (issue 143 · [[ADR-0045 Radering av
 *    konto och person]] § Beslut 1) — innehållet tillhör containern och
 *    gallras inte här. Personbilagorna har ingen container att följa och
 *    rensas i stället (issue 379 · [[ADR-0076 Profilbilden]] § 5,
 *    `rensaPersonbilagor()`),
 * 3. författarkolumnerna mot kontot nollställs (ADR-0045 § Beslut 2) — samma
 *    skäl: raden är någon annans innehåll och står kvar, utan avsändare,
 * 3b. `container.deleted` till varje person som förlorar åtkomst genom en
 *    giltig container_access — före gallringen i steg 4, eftersom både
 *    åtkomsterna och containernamnen bara finns medan containern står kvar
 *    ([[ADR-0045 Radering av konto och person]] § Uppföljning 2026-11-14),
 * 4. varje container kontot äger, genom PurgeContainer — med withTrashed(),
 *    en mjukraderad container ska också bort — och exportraderna på dem med
 *    sina artefakter (issue 579, se raderaContainerExporter()),
 * 5. ägarbyten där kontot är avsändare eller mottagare, och åtkomster som
 *    getts till kontot — raderna på kontots EGNA containers tog steg 4,
 *    kvar är de på någon annans (ADR-0045 § Beslut 3),
 * 6. usage_counter-raden, webhook_endpoint-raderna (issue 37a § Beslut 8) och
 *    mallarna (issue 421) — på både `account_id` och `billed_account_id`,
 * 7. subscription-raden om den finns,
 * 8. account_user-raderna,
 * 9. account-raden.
 *
 * Steg 2 och 3 ligger FÖRE containergallringen och rör bara rader i ANDRAS
 * containers: kontots egna containers gallras i steg 4, och deras bilagor och
 * författarrader försvinner med dem. Utan de två stegen fastnar raderingen på
 * en ON DELETE RESTRICT-nyckel i en främmande container, transaktionen rullas
 * tillbaka och `account.deletion_failed` loggas (29b § Beslut 5).
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
 *
 * **Skälet** (`owner_deleted` eller `account_inactive`, issue 1235) säger
 * varför kontot raderas och följer med i `container.deleted`-notiserna till
 * de personer som förlorar åtkomst. De två raderingsvägarna skickar var sitt:
 * `DeleteUser` det första, `DeletesDormantAccounts` det andra. Det ändrar
 * ingenting annat i raderingen — åtgärden är fortfarande verktyget, inte
 * grinden.
 */
class DeleteAccount
{
    /**
     * Personen raderar sitt eget konto, eller ett konto där hon är enda
     * medlem (App\Actions\User\DeleteUser).
     */
    public const REASON_OWNER_DELETED = 'owner_deleted';

    /**
     * Kontot har legat vilande i arton månader (App\Console\
     * DeletesDormantAccounts).
     */
    public const REASON_ACCOUNT_INACTIVE = 'account_inactive';

    /** @var list<string> */
    private const REASONS = [self::REASON_OWNER_DELETED, self::REASON_ACCOUNT_INACTIVE];

    public function __construct(
        private readonly PurgeContainer $purgeContainer,
    ) {}

    /**
     * @param  string  $reason  Varför kontot raderas — en av REASONS.
     *
     * @throws InvalidArgumentException när skälet inte är ett av de två.
     */
    public function handle(Account $account, string $reason): void
    {
        if (! in_array($reason, self::REASONS, true)) {
            throw new InvalidArgumentException("Okänt raderingsskäl: {$reason}");
        }

        DB::transaction(function () use ($account, $reason): void {
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

            // Steg 2 och 3 — innehållet i ANDRAS containers. Före
            // containergallringen nedan, som bara rör kontots egna.
            // Bilagor och räknade rader (ADR-0081 § 4) byter betalare genom
            // den delade actionen, som nedgraderingens steg 4 också använder:
            // en kvarlämnad `billed_account_id` stoppar kontoraderingen på
            // RESTRICT-nyckeln.
            (new TransferBilling)->handle($account);
            $this->nollstallFattarkolumner($accountId);

            // Personbilagorna har ingen container att följa (ADR-0076
            // § 5): de lämnas inte kvar med en `billed_account_id` som pekar
            // på ett konto som just ska bort — det hade fällt
            // account-raderingen på RESTRICT-nyckeln. Se metoden nedan.
            $this->rensaPersonbilagor($account);

            // Antalen till loggen läses INNAN raderingen — efteråt finns
            // inget kvar att beskriva den med (issue 29b § Beslut 8).
            // DB-frågorna ser även mjukraderade bilagor och items: de
            // force-raderas av PurgeContainer oavsett eget tillstånd.
            // Bilagorna på kontots EGNA containers räknas över BÅDA slagen
            // (issue 158): en itembilaga hittas genom itemet, en
            // containerbilaga — containerns bild — genom sin egen kolumn.
            // leftJoin, inte join: en containerbilaga har `item_id = NULL`
            // och hade fallit bort i en inre join, och då hade antalet och
            // bytena i loggraden varit fel.
            $attachmentCount = (int) DB::table('attachment')
                ->leftJoin('item', 'item.id', '=', 'attachment.item_id')
                ->where(fn ($query) => $query
                    ->whereIn('item.container_id', $containerIds)
                    ->orWhereIn('attachment.container_id', $containerIds))
                ->count();

            $bytes = (int) DB::table('attachment')
                ->leftJoin('item', 'item.id', '=', 'attachment.item_id')
                ->join('stored_file', 'stored_file.id', '=', 'attachment.stored_file_id')
                ->where(fn ($query) => $query
                    ->whereIn('item.container_id', $containerIds)
                    ->orWhereIn('attachment.container_id', $containerIds))
                ->sum('stored_file.byte_size');

            // Exportraderna på kontots EGNA containers (issue 579). Raden är
            // en beställning och håller sin container med ON DELETE RESTRICT;
            // den hör till containern och följer den i graven, precis som
            // bilagorna och notiserna i PurgeContainer. Utan den här raden
            // faller `forceDelete()` nedan på ett främmandenyckelfel, och
            // ingen container som någon exporterat går att radera.
            //
            // Bara de EGNA: en export i någon annans container står kvar och
            // får sin `requested_by_user_id` nollställd av personraderingen
            // (ADR-0045 § Beslut 2). Det är därför raden sitter här och inte
            // bland personens egna rader — den hör till containern.
            $this->raderaContainerExporter($containerIds);

            // Steg 3b — notiserna till dem som förlorar åtkomsten. Före
            // gallringen och i samma transaktion: åtkomsterna och
            // containernamnen finns bara medan containern står kvar, och en
            // notis som hör till en radering som rullas tillbaka ska rulla
            // tillbaka med den. CreateNotification skickar ingenting — den
            // skriver utboksraden — så det finns ingen mejlrisk inuti
            // transaktionen.
            $this->notifyContainerMembers($account, $containers, $reason);

            foreach ($containers as $container) {
                $this->purgeContainer->handle($container);
            }

            // Ägarbyten där kontot är avsändare eller mottagare (ADR-0045
            // § Beslut 3). Raderna på kontots EGNA containers tog
            // PurgeContainer precis — kvar är de på någon ANNANS container,
            // och ett typiskt fall är varvet som redan lämnat över en båt och
            // sedan raderas. Båda nycklarna är ON DELETE RESTRICT, så utan
            // städningen faller account-raderingen längst ned. Raden är
            // historik som redan spelat ut sin roll, och accepten finns kvar i
            // `audit_log` utan främmande nyckel ([[ADR-0043 Tre loggar]]).
            DB::table('ownership_transfer')
                ->where(fn ($query) => $query
                    ->where('from_account_id', $accountId)
                    ->orWhere('to_account_id', $accountId))
                ->delete();

            // Åtkomster som getts till kontot som mottagare. `grantee_id` är
            // en identifierare i en polymorph kolumn utan främmande nyckel
            // (issue 9a § Beslut 3), så den blockerar ingenting — men en
            // åtkomst till ett konto som inte finns ska inte ligga kvar.
            // Åtkomsterna på kontots egna containers tog PurgeContainer ovan.
            DB::table('container_access')
                ->where('grantee_type', 'account')
                ->where('grantee_id', $accountId)
                ->delete();

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

            // Mallarna (issue 421). En mall hör till kontot, inte till en
            // container, så PurgeContainer känner den inte; `account_id` och
            // `billed_account_id` är båda ON DELETE RESTRICT och en
            // mjukraderad mall står kvar i tabellen med samma nycklar. Båda
            // kolumnerna städas: betalaren kan vara ett annat konto än
            // ägaren när en medlem i flera konton sparar från en container i
            // ett av dem.
            DB::table('container_template')
                ->where(fn ($query) => $query
                    ->where('account_id', $accountId)
                    ->orWhere('billed_account_id', $accountId))
                ->delete();

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

    /**
     * `container.deleted` till varje person som förlorar åtkomst när kontot
     * och dess containrar raderas — [[ADR-0045 Radering av konto och person]]
     * § Uppföljning 2026-11-14, se [[Notiser]] § notification.
     *
     * Mottagarna räknas per container ur GILTIGA `container_access` på
     * container- eller itemnivå (`ContainerAccess::scopeValid`): en utgången
     * eller återkallad åtkomst ger ingen notis, och en obesvarad inbjudan
     * räknas inte alls. En grant till ett konto ger kontots samtliga
     * personer. Personen vars konto raderas räknas aldrig, inte heller någon
     * annan medlem i det kontot — de förlorar åtkomsten för att de själva
     * raderar den.
     *
     * `container_id` är NULL: raden skulle annars peka på en container som
     * gallras i samma transaktion. Payloaden bär containerns namn som
     * ögonblicksbild, och skälet styr vilken mening mejlet renderar.
     *
     * En mottagare som saknar konto helt hoppas över med en loggrad: hon kan
     * inte bära en notisrad, och en notis får aldrig blockera en radering.
     *
     * @param  Collection<int, Container>  $containers
     */
    private function notifyContainerMembers(Account $account, Collection $containers, string $reason): void
    {
        $deletedMemberIds = DB::table('account_user')
            ->where('account_id', $account->getKey())
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $actingAccount = new ActingAccount;

        foreach ($containers as $container) {
            foreach ($this->recipientsFor($container, $deletedMemberIds) as $recipient) {
                try {
                    $recipientAccount = $actingAccount->for($recipient, $container);
                } catch (ModelNotFoundException) {
                    // En person utan något konto alls kan inte bära en
                    // notisrad — `account_id` är obligatoriskt — och får
                    // aldrig blockera raderingen: `firstOrFail()` skulle
                    // rulla tillbaka hela transaktionen och göra det
                    // vilande jobbet återkommande (granskningsfynd
                    // 2026-11-15). Logga och hoppa över mottagaren.
                    Log::warning('notification.container_deleted_no_account', [
                        'container_ulid' => $container->ulid,
                        'user_ulid' => $recipient->ulid,
                    ]);

                    continue;
                }

                app(CreateNotification::class)->handle(
                    type: NotificationModel::TYPE_CONTAINER_DELETED,
                    // Mottagarens personkonto, genom samma uppslag som
                    // ActingAccount — aldrig kontot som raderas. Personen är
                    // inte medlem i det (de uteslöts ovan), så uppslaget kan
                    // inte landa där.
                    account: $recipientAccount,
                    user: $recipient,
                    container: null,
                    payload: [
                        'container' => $container->name,
                        'reason' => $reason,
                    ],
                    // Container-ULID:en och mottagarens ULID gör nyckeln unik
                    // per container och person, så en omkörning inte skapar
                    // en andra rad.
                    dedupeKey: NotificationModel::TYPE_CONTAINER_DELETED.":{$container->ulid}:{$recipient->ulid}",
                );
            }
        }
    }

    /**
     * Personerna som når containern genom en giltig åtkomst, utom de som
     * tillhör kontot som raderas.
     *
     * En person med flera åtkomster i samma container får en rad, inte en
     * per åtkomst: `array_unique()` på person-id:na. En åtkomst på itemnivå
     * bär samma `container_id` som en container-bred, så frågan täcker båda
     * nivåerna utan en join mot `item`.
     *
     * @param  list<int>  $excludedUserIds
     * @return Collection<int, User>
     */
    private function recipientsFor(Container $container, array $excludedUserIds): Collection
    {
        $accesses = ContainerAccess::query()
            ->where('container_id', $container->getKey())
            ->valid()
            ->get(['grantee_type', 'grantee_id']);

        /** @var list<int> $userIds */
        $userIds = [];

        foreach ($accesses as $access) {
            if ($access->grantee_type === 'user') {
                $userIds[] = (int) $access->grantee_id;

                continue;
            }

            // En grant till ett konto gäller kontots personer — det är de
            // som förlorar åtkomsten, inte kontot självt.
            foreach (DB::table('account_user')->where('account_id', $access->grantee_id)->pluck('user_id') as $id) {
                $userIds[] = (int) $id;
            }
        }

        $userIds = array_values(array_diff(array_unique($userIds), $excludedUserIds));

        return User::query()->whereIn('id', $userIds)->get();
    }

    /**
     * ADR-0045 § Beslut 3: innehållet i kontots egna containers raderas med
     * containern. Exportraderna hör dit — `export.container_id` är ON DELETE
     * RESTRICT, och `PurgeContainer` känner inte tabellen, så utan den här
     * raden går ingen container som någon exporterat att ta bort (issue 579).
     *
     * **Artefakten tas med, och det är därför raden ligger här och inte i
     * `PurgeContainer`:** en `stored_file` överlever sin bilaga med
     * `purge_after` och städas av `PurgesExpiredStoredFiles`, men en
     * exportfil har ingen sådan kö — `export.storage_path` är den enda
     * pekaren, och när raden försvinner finns ingen kvar som kan nå filen.
     * `PurgesExpiredExports` tar samma fil när den gallrar en `ready`-rad, av
     * samma skäl.
     *
     * **Samma lucka finns i papperskorgens väg** (`PurgesExpiredTrash` →
     * `PurgeContainer::handle()`), som inte går genom den här actionen. Den
     * är utanför den här issuen och står i PR:ens frågor.
     *
     * @param  Collection<int, int>  $containerIds
     */
    private function raderaContainerExporter(Collection $containerIds): void
    {
        $sökvägar = DB::table('export')
            ->whereIn('container_id', $containerIds)
            ->whereNotNull('storage_path')
            ->pluck('storage_path');

        foreach ($sökvägar as $sökväg) {
            Storage::disk('files')->delete((string) $sökväg);
        }

        DB::table('export')->whereIn('container_id', $containerIds)->delete();
    }

    /**
     * Personbilagorna kontot betalar för (issue 379 · [[ADR-0076
     * Profilbilden]] § 5).
     *
     * **De flyttas aldrig till en container.** En personbilaga har varken
     * `item_id` eller `container_id`, och `TransferBilling`
     * hade ändå aldrig hittat en container åt den. Den kan alltså inte följa
     * med på samma sätt som en främmande itembilaga, och ett
     * `billed_account_id` som pekar på ett konto som raderas faller på
     * RESTRICT-nyckeln längst ned.
     *
     * **Därför rensas den**, genom samma PurgeAttachment som
     * PurgeContent::attachment() delegerar till: bytena lämnar räknaren,
     * `stored_file.reference_count` minskar, och `user.avatar_attachment_id`
     * nollställs av PurgeAttachment innan raden försvinner (RESTRICT-nyckeln,
     * issue 379).
     * Det följer ADR-0076 § 3 — en person utan personkonto har ingen kvot att
     * belasta och kan inte ha en profilbild — och ADR § 5: bilden hör till
     * personen och följer aldrig med en container. Personen själv raderas inte
     * här; hennes rad står kvar och kan ladda upp en ny bild om hon får ett
     * nytt personkonto.
     *
     * Ingen soft-delete-scope: även en slängd personbilaga bär RESTRICT-nyckeln
     * och måste bort, av samma skäl som i TransferBilling.
     */
    private function rensaPersonbilagor(Account $account): void
    {
        Attachment::withTrashed()
            ->where('billed_account_id', $account->id)
            ->whereNotNull('user_id')
            ->get()
            // Samma lövaction som PurgeContent::attachment() anropar, och
            // därför konstruerad här på samma sätt som AdjustUsage i den
            // actionen: den har inga beroenden att injicera.
            ->each(fn (Attachment $attachment) => (new PurgeAttachment)->handle($attachment));
    }

    /**
     * ADR-0045 § Beslut 2: författarkolumnerna mot kontot nollställs.
     * Historiken står kvar, utan avsändare — raden är containerns innehåll och
     * gallras inte för att en gäst försvunnit. Kolumnerna mot `user` rörs
     * inte: personen raderas i en egen fråga (issue 144).
     *
     * Ingen soft-delete-scope, av samma skäl som i TransferBilling:
     * även en mjukraderad rad bär en RESTRICT-nyckel mot kontot.
     *
     * `updated_at` stämplas som vid varje annan skrivning — raden HAR ändrats,
     * och en rad vars tidsstämpel inte följer med sina egna ändringar ljuger
     * för den som granskar den. Innehållet är däremot orört: det är bara
     * avsändaren som försvunnit.
     */
    private function nollstallFattarkolumner(int $accountId): void
    {
        DB::table('item')
            ->where('created_by_account_id', $accountId)
            ->update(['created_by_account_id' => null, 'updated_at' => now()]);

        DB::table('cost_entry')
            ->where('created_by_account_id', $accountId)
            ->update(['created_by_account_id' => null, 'updated_at' => now()]);

        DB::table('schedule_occurrence')
            ->where('completed_by_account_id', $accountId)
            ->update(['completed_by_account_id' => null, 'updated_at' => now()]);
    }
}
