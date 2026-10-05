<?php

namespace App\Actions\User;

use App\Actions\Account\DeleteAccount;
use App\Actions\Invitation\RevokeInvitation;
use App\Actions\OwnershipTransfer\RevokeOwnershipTransfer;
use App\Actions\Security\RecordSecurityEvent;
use App\Actions\Trash\PurgeContent;
use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Invitation;
use App\Models\Item;
use App\Models\LegalHold;
use App\Models\OwnershipTransfer;
use App\Models\SecurityLog;
use App\Models\User;
use App\Support\User\DeletionBlocker;
use App\Support\User\UserDeletionBlocked;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Raderar en person ur systemet — GDPR artikel 17, se [[ADR-0045 Radering av
 * konto och person]] § Beslut 3. Verktyget, inte grinden: ytan som bekräftar
 * raderingen (issue 145) prövar spärrarna innan den anropar, och
 * `handle()` prövar dem en gång till — en kontroll som förlitar sig på att
 * anroparen gjorde rätt är ingen kontroll.
 *
 * Två ingångar:
 *
 * - `blockers()` — det som står i vägen, som en lista av
 *   App\Support\User\DeletionBlocker. Den som vill visa raderingen innan
 *   den sker läser den här; den rör ingenting.
 * - `handle()` — raderar. Kastar App\Support\User\UserDeletionBlocked med
 *   listan om något spärrar, och då har ingenting hänt.
 *
 * **Kontona avgörs först.** Ett konto där personen är enda medlem raderas
 * med DeleteAccount — samma action som livscykeln använder, med samma
 * innehåll, kvoter och prenumeration. Ett konto med andra medlemmar lämnas:
 * personens `account_user`-rad tas bort och kontot står kvar orört.
 *
 * **En aktiv prenumeration spärrar inte.** Rätten att bli glömd går före
 * livscykelns undantag (ADR-0045 § Beslut 3): kontot raderas ändå, och
 * prenumerationen avslutas utan återbetalning genom att DeleteAccount tar
 * bort `subscription`-raden. Det är därför `blockers()` inte frågar efter
 * Subscription, till skillnad från App\Console\DeletesDormantAccounts.
 *
 * **Ordningen i transaktionen** (ADR-0045 § Beslut 3, och nycklarna i
 * AGENTS.md § Databaskonventioner — alla är ON DELETE RESTRICT, så det finns
 * ingen kaskad som städar):
 *
 * 1. spärrarna prövas, under radlås på personen,
 * 2. varje konto där personen är enda medlem raderas med DeleteAccount,
 * 3. personen lämnar övriga konton,
 * 4. författarkolumnerna mot `user` nollställs (ADR-0045 § Beslut 2): item,
 *    attachment, schedule_occurrence, cost_entry, container_access och
 *    export. Raderna är någon annans innehåll och står kvar, utan avsändare,
 * 5. personens egna rader raderas,
 * 5b. personens inbox raderas ([[ADR-0054 Inboxen]] § 9) — den är hennes
 *    och blir inte kvar i ett personkonto som står kvar med andra medlemmar,
 * 6. väntande inbjudningar och ägarbyten som personen startat dras tillbaka,
 *    och författarkolumnen nollställs på dem alla — besvarade behåller sin
 *    rad (ADR-0045 § Beslut 3),
 * 7. säkerhetsloggen får `user.deleted`,
 * 8. `user`-raden raderas på riktigt.
 *
 * Steg 6 ligger efter steg 4 av ett skäl som inte syns i listan: en `pending`
 * rad går bara att hitta medan `invited_by_user_id`/`initiated_by_user_id`
 * pekar på personen. Därför nollställs just de två kolumnerna i steg 6 och
 * inte i steg 4.
 *
 * **Loggarna står kvar.** `audit_log.user_id` och `security_log.user_id` är
 * identifierare utan främmande nyckel (issue 107, [[ADR-0043 Tre loggar]]),
 * så de blockerar ingenting och raderas inte: raderna lever sin frist ut och
 * gallras som vanligt. `user.deleted` bär `user_id` och antalet konton —
 * **aldrig e-postadressen**.
 *
 * **Ett fel mitt i lämnar ingenting halvt** (ADR-0045 § Beslut 3): hela
 * raderingen är EN transaktion, och en rad i fel ordning eller en nyckelkrock
 * rullar tillbaka alltihop. Det är den enda anledningen att raderingen är
 * ofarlig att försöka.
 */
class DeleteUser
{
    public function __construct(
        private readonly DeleteAccount $deleteAccount,
        private readonly RevokeInvitation $revokeInvitation,
        private readonly RevokeOwnershipTransfer $revokeOwnershipTransfer,
        private readonly PurgeContent $purgeContent,
    ) {}

    /**
     * Det som spärrar en radering av personen, tomt när ingenting gör det.
     *
     * Läses av ytan innan raderingen begärs (issue 145), och en gång till
     * inuti transaktionen av `handle()` — en spärr som uppstått mellan
     * begäran och bekräftelsen ska stoppa raderingen, inte passera för att
     * den var prövad en gång.
     *
     * @return list<DeletionBlocker>
     */
    public function blockers(User $user): array
    {
        $blockers = [];

        foreach ($this->accounts($user) as $account) {
            // Den rättsliga spärren frågas med LegalHold::covers() — samma
            // enda formulering som papperskorgens gallring och livscykeln
            // använder. Ett kontos innehåll är bevis, och en person som är
            // medlem i ett spärrat konto raderas inte. Spärren bär bara sin
            // kod — aldrig kontot den gäller (se DeletionBlocker::legalHold).
            if (LegalHold::covers($account)) {
                $blockers[] = DeletionBlocker::legalHold();
            }

            if ($this->isSoleMember($account, $user)) {
                // Kontot raderas — då gäller livscykelns spärr för delade
                // containers (29b § Beslut 3–4): ägarskapet ska erbjudas
                // medlemmarna först, och vägen ut är ägarbytet.
                $containers = $this->containersWithActiveMembers($account);

                if ($containers->isNotEmpty()) {
                    $blockers[] = DeletionBlocker::sharedContainer($account, $containers);
                }

                continue;
            }

            // Kontot lämnas. En ensam ägare lämnar då ett konto utan ägare,
            // och det är den enda spärren som bara finns här.
            if ($this->roleIn($account, $user) === 'owner' && ! $this->hasOtherOwner($account, $user)) {
                $blockers[] = DeletionBlocker::soleOwner($account);
            }
        }

        return $blockers;
    }

    /**
     * Kontona som raderas med personen — de där hon är den enda medlemmen.
     *
     * Bara en läsväg: samma uppdelning som `handle()` gör, ur samma privata
     * metod, så att ytan (issue 145) kan säga vad som kommer att hända utan
     * att formulera "enda medlem" en andra gång. En avskrift i en kontroller
     * hade varit en andra sanning om vilka konton som försvinner, och den
     * hade glidit isär från den här.
     *
     * @return Collection<int, Account>
     */
    public function accountsToDelete(User $user): Collection
    {
        return $this->accountsWhereSoleMember($user);
    }

    /**
     * Kontona som lämnas — de där någon annan är medlem kvar. Personen
     * lämnar dem; kontot står kvar orört (ADR-0045 § Beslut 3).
     *
     * Samma läsväg och samma skäl som `accountsToDelete()`.
     *
     * @return Collection<int, Account>
     */
    public function accountsToLeave(User $user): Collection
    {
        return $this->accounts($user)
            ->reject(fn (Account $account): bool => $this->isSoleMember($account, $user))
            ->values();
    }

    /**
     * Raderar personen, eller kastar med listan över det som spärrar.
     *
     * @throws UserDeletionBlocked när `blockers()` inte är tom; ingenting har
     *                             då raderats.
     */
    public function handle(User $user): void
    {
        DB::transaction(function () use ($user): void {
            // Raden läses om under låset, samma mönster som
            // DeletesDormantAccounts::deleteEligibleAccount: en person som
            // redan raderats (två flikar, två begäranden) ska inte raderas
            // igen, och en samtidig radering ska inte kunna fläta in sig.
            $row = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return;
            }

            $blockers = $this->blockers($row);

            if ($blockers !== []) {
                throw new UserDeletionBlocked($blockers);
            }

            $toDelete = $this->accountsWhereSoleMember($row);
            $userId = $row->getKey();

            // Antalen till loggen läses innan något raderas — efteråt finns
            // inget kvar att räkna (samma skäl som i DeleteAccount).
            $deletedAccounts = $toDelete->count();
            $leftAccounts = $this->accounts($row)->count() - $deletedAccounts;

            // Steg 2. Kontona först: DeleteAccount äger sin egen ordning och
            // sin egen transaktion, och efter den finns inga containers,
            // items eller bilagor kvar i dem.
            foreach ($toDelete as $account) {
                $this->deleteAccount->handle($account);
            }

            // Steg 3. Medlemskapet i de konton som står kvar. Raderna för de
            // raderade kontona tog DeleteAccount.
            DB::table('account_user')->where('user_id', $userId)->delete();

            // Steg 4.
            $this->nollstallForfattarkolumner($userId);

            // Steg 5.
            $this->raderaPersonensRader($row);

            // Steg 5b. Personens inbox (issue 243 · [[ADR-0054 Inboxen]]
            // § 9). Den ligger efter steg 5 med flit: personens egna
            // `notification`-rader är redan borta, och `notification.
            // container_id` är ON DELETE RESTRICT.
            $this->raderaInboxen($row);

            // Steg 6.
            $this->draTillbakaStartade($row);

            // Steg 7. Före commit, som i DeleteAccount: en radering som
            // rullas tillbaka har aldrig hunnit loggas som lyckad. `user_id`
            // står både som kolumn och i `meta` — kolumnen för att raden hör
            // till personen, `meta` för att antalen hör dit (ADR-0045
            // § Beslut 3). Ingen e-postadress, ingen adress alls.
            (new RecordSecurityEvent)->handle(
                action: SecurityLog::ACTION_USER_DELETED,
                user: $row,
                meta: [
                    'user_id' => $userId,
                    'deleted_accounts' => $deletedAccounts,
                    'left_accounts' => $leftAccounts,
                ],
            );

            // Steg 8. På riktigt: `user` har ingen `deleted_at`, och en
            // mjukraderad person vore en person som inte är raderad
            // (ADR-0045 § Beslut 3). Adressen blir ledig och kan registreras
            // igen som ett nytt konto.
            DB::table('user')->where('id', $userId)->delete();
        });
    }

    /**
     * Personens konton. Rollen läses separat, se `roleIn()`.
     *
     * @return Collection<int, Account>
     */
    private function accounts(User $user): Collection
    {
        return $user->accounts()->get();
    }

    /**
     * Kontona där personen är den enda medlemmen — de som raderas.
     *
     * @return Collection<int, Account>
     */
    private function accountsWhereSoleMember(User $user): Collection
    {
        return $this->accounts($user)
            ->filter(fn (Account $account): bool => $this->isSoleMember($account, $user))
            ->values();
    }

    private function isSoleMember(Account $account, User $user): bool
    {
        return ! DB::table('account_user')
            ->where('account_id', $account->id)
            ->where('user_id', '!=', $user->getKey())
            ->exists();
    }

    /**
     * Personens roll i kontot, eller null om hon inte är medlem.
     *
     * Rollen läses ur `account_user` och inte ur `$account->pivot`: kontona
     * hämtas genom relationen, och pivoten är en otypad egenskap som
     * `phpstan`-nivån i repot inte ser. Frågan är den samma.
     */
    private function roleIn(Account $account, User $user): ?string
    {
        $roll = DB::table('account_user')
            ->where('account_id', $account->id)
            ->where('user_id', $user->getKey())
            ->value('role');

        return $roll === null ? null : (string) $roll;
    }

    private function hasOtherOwner(Account $account, User $user): bool
    {
        return DB::table('account_user')
            ->where('account_id', $account->id)
            ->where('user_id', '!=', $user->getKey())
            ->where('role', 'owner')
            ->exists();
    }

    /**
     * ULID:erna för kontots containers som har aktiva medlemmar — minst en
     * giltig container_access (ContainerAccess::scopeValid) eller en
     * obesvarad, icke utgången inbjudan.
     *
     * **Samma räkning som DeletesDormantAccounts::ownedContainersWithActiveMembers**
     * (29b § Beslut 3–4), och den ligger med flit kvar där också: jobbet är
     * grinden för livscykeln och den här actionen verktyget för personen, och
     * `app/Console` är utanför den här issuen. Ändras definitionen av "aktiv
     * medlem" ska båda ändras — de är samma regel.
     *
     * Mjukraderade containers räknas också, av samma skäl som i jobbet: en
     * soft delete återkallar inte `container_access`, och DeleteAccount tömmer
     * den med withTrashed(). En mjukraderad container med aktiva medlemmar
     * ska blockera lika mycket som en levande.
     *
     * @return Collection<int, string>
     */
    private function containersWithActiveMembers(Account $account): Collection
    {
        return Container::withTrashed()
            ->where('account_id', $account->id)
            ->where(function (Builder $query) {
                $query->whereHas('accesses', function (Builder $query) {
                    /** @var Builder<ContainerAccess> $query */
                    $query->valid();
                })->orWhereHas('invitations', function (Builder $query) {
                    $query->where('status', 'pending')->where('expires_at', '>', now());
                });
            })
            ->pluck('ulid');
    }

    /**
     * Steg 4: författarkolumnerna mot `user` nollställs (ADR-0045 § Beslut 2).
     * Historiken står kvar, utan avsändare — raden är containerns eller
     * kontots innehåll och gallras inte för att en person försvunnit.
     *
     * Ingen soft-delete-scope, av samma skäl som i DeleteAccount: även en
     * mjukraderad rad bär en RESTRICT-nyckel mot personen.
     *
     * `updated_at` stämplas som vid varje annan skrivning — raden HAR ändrats,
     * och innehållet är orört.
     *
     * `invitation` och `ownership_transfer` står inte här: deras rader
     * behöver kolumnen för att gå att hitta i steg 6, och nollställs där.
     */
    private function nollstallForfattarkolumner(int $userId): void
    {
        foreach ([
            'item' => 'created_by_user_id',
            'attachment' => 'uploaded_by_user_id',
            'schedule_occurrence' => 'completed_by_user_id',
            'cost_entry' => 'created_by_user_id',
            'container_access' => 'granted_by_user_id',
            'export' => 'requested_by_user_id',
        ] as $table => $column) {
            DB::table($table)
                ->where($column, $userId)
                ->update([$column => null, 'updated_at' => now()]);
        }
    }

    /**
     * Steg 5: raderna som bara är personens (ADR-0045 § Beslut 3). Varje
     * tabell som bär en främmande nyckel mot `user` och vars rad är personens
     * egen står här; listan i ADR:en är innehållet, och en sökning över
     * nycklarna mot `user` gav exakt de här tabellerna.
     *
     * Leveransraderna före notisraderna: `webhook_delivery` och
     * `notification_delivery` pekar båda på `notification` med RESTRICT, och
     * en person kan mycket väl ha en notis som en webhook har levererats för.
     * Samma ordning, och samma skäl, som i DeleteAccount.
     *
     * **`magic_link_token` nycklas på adressen och inte på personen**, och
     * den måste bort av ett skäl som inte syns i listan: en outnyttjad länk
     * för adressen skulle logga in den som registrerar adressen härnäst
     * (App\Support\Auth\MagicLinkBroker slår upp användaren på `email` när
     * länken löses in). Utan den raden vore "adressen kan registreras igen"
     * en bakdörr in i det nya kontot.
     *
     * `audit_log` och `security_log` står med flit inte här — se
     * klassdocblocket.
     */
    private function raderaPersonensRader(User $user): void
    {
        $userId = $user->getKey();

        DB::table('webhook_delivery')
            ->whereIn('notification_id', DB::table('notification')->where('user_id', $userId)->select('id'))
            ->delete();
        DB::table('notification_delivery')
            ->whereIn('notification_id', DB::table('notification')->where('user_id', $userId)->select('id'))
            ->delete();
        DB::table('notification')->where('user_id', $userId)->delete();

        DB::table('notification_preference')->where('user_id', $userId)->delete();
        DB::table('calendar_feed')->where('user_id', $userId)->delete();
        DB::table('favorite')->where('user_id', $userId)->delete();

        // Besöksraderna är personuppgifter och bara personens (issue 160 ·
        // [[ADR-0049 Nyligen besökta]] § Beslut, [[ADR-0045 Radering av konto
        // och person]] § Uppföljning 2026-09-27). Främmandenyckeln mot `item`
        // är RESTRICT, så raden måste bort innan itemet kan gallras — och de
        // items som hörde till ett konto personen var enda medlem i försvann
        // redan i steg 2, genom PurgeContent::item(). Kvar här är besöken på
        // items i andras containers, och de är hennes.
        DB::table('recent_visit')->where('user_id', $userId)->delete();

        // Öppningsraderna är personuppgifter och bara personens, av samma
        // skäl som besöksraderna ovan (issue 177 · [[ADR-0051 Senast öppnade
        // filer]] § Beslut, [[ADR-0045 Radering av konto och person]]
        // § Uppföljning 2026-09-27). Främmandenyckeln mot `attachment` är
        // RESTRICT, så raden måste bort innan bilagan kan gallras — och de
        // bilagor som låg i personens egna containers försvann redan i steg 2,
        // genom PurgeContainer → PurgeAttachment, som tar sina egna
        // öppningsrader. Kvar här är öppningarna av bilagor i andras
        // containers, och de är hennes.
        DB::table('attachment_open')->where('user_id', $userId)->delete();

        DB::table('dismissed_tip')->where('user_id', $userId)->delete();
        DB::table('email_change')->where('user_id', $userId)->delete();
        DB::table('password_change')->where('user_id', $userId)->delete();

        // Den här raden är bekräftelsen som ledde hit (issue 145): den bär
        // `user_id` med RESTRICT, och utan den hade personraderingen fastnat
        // på sitt eget engångstoken. Den ligger i samma transaktion som
        // resten, och `confirmed_at` rullas tillbaka med den om något senare
        // i raderingen fallerar.
        DB::table('user_deletion')->where('user_id', $userId)->delete();

        DB::table('totp_recovery_code')->where('user_id', $userId)->delete();

        // Åtkomster som mottagare: raden ger personen rätt till någon annans
        // container och är personens, till skillnad från `granted_by_user_id`
        // på samma tabell — den pekar på den som beviljade, och nollställs i
        // steg 4 när raden är någon annans.
        DB::table('container_access')
            ->where('grantee_type', 'user')
            ->where('grantee_id', $userId)
            ->delete();

        DB::table('sessions')->where('user_id', $userId)->delete();
        DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $userId)
            ->delete();

        DB::table('magic_link_token')->where('email', $user->email)->delete();
    }

    /**
     * Steg 5b: personens inbox raderas med personen — ÄVEN när personkontot
     * har andra medlemmar och därför står kvar (ADR-0054 § 9). Inboxen är
     * hennes och ingen annans, och den får inte ligga kvar i ett konto hon
     * lämnat.
     *
     * **Raderna skrivs för hand och containerräknaren rörs inte.** Inboxen
     * räknades aldrig mot containertaket när den skapades
     * (App\Actions\Inbox\ResolveInbox), så en minskning här hade dragit ifrån
     * ett tal den aldrig lade till. Därför PurgeContent::item() — som tar
     * bilagorna genom PurgeAttachment och schemana med sig — i stället för
     * PurgeContainer, som minskar `usage_counter.container_count` och skriver
     * `container.purged`.
     *
     * Låg inboxen i ett konto där personen var enda medlem raderades den
     * redan i steg 2, med hela kontot; då finns ingen rad kvar att hitta här.
     *
     * Ordningen mot steg 5 är inte en detalj: `notification.container_id` är
     * ON DELETE RESTRICT, och personens egna notisrader måste bort innan
     * containern kan `forceDelete`:as.
     */
    private function raderaInboxen(User $user): void
    {
        $inbox = Container::withTrashed()
            ->where('inbox_user_id', $user->getKey())
            ->first();

        if ($inbox === null) {
            return;
        }

        foreach (Item::withTrashed()->where('container_id', $inbox->id)->get() as $item) {
            $this->purgeContent->item($item);
        }

        // En grant mot en inbox ger ingen åtkomst (ResolveItemScope regel 0),
        // men raden kan finnas — skriven direkt i databasen — och
        // `container_access.container_id` är ON DELETE RESTRICT.
        DB::table('container_access')->where('container_id', $inbox->id)->delete();

        $inbox->forceDelete();
    }

    /**
     * Steg 6: väntande inbjudningar och ägarbyten som personen startat dras
     * tillbaka, och författarkolumnen nollställs på dem alla — besvarade
     * behåller sin rad, utan avsändare (ADR-0045 § Beslut 3 och 2).
     *
     * **Genom de befintliga actionerna**, inte med en egen UPDATE:
     * "tillbakadragen" har en definition i den här koden (issue 111 bröt ut
     * dem just för att webben och `/api` inte skulle kunna formulera den
     * olika), och en avskrift här vore en tredje. Raden raderas inte — den
     * får `status = 'revoked'`, och loggraden som actionerna skriver blir
     * kvar med personens `user_id`, precis som övriga loggrader.
     *
     * Bara `pending` raderas tillbaka. En utgången rad står fortfarande på
     * `pending` (utgången härleds ur `created_at`), och den dras tillbaka på
     * samma sätt som en levande.
     *
     * **Containern hämtas med `withTrashed()`.** En mjukraderad container
     * återkallar varken sina inbjudningar eller sina ågarbyten — raderna
     * ligger kvar tills gallringen tar dem — och en vanlig `belongsTo` mot en
     * mjukraderad förälder svarar null. Utan `withTrashed()` hade en
     * kvarlämnad inbjudan på en container i papperskorgen fällt hela
     * raderingen på en null.
     */
    private function draTillbakaStartade(User $user): void
    {
        $userId = $user->getKey();

        $invitations = Invitation::query()
            ->where('invited_by_user_id', $userId)
            ->where('status', 'pending')
            ->with(['container' => fn ($query) => $query->withTrashed()])
            ->get();

        foreach ($invitations as $invitation) {
            $this->revokeInvitation->handle($invitation->container, $invitation, $user);
        }

        $transfers = OwnershipTransfer::query()
            ->where('initiated_by_user_id', $userId)
            ->where('status', 'pending')
            ->with(['container' => fn ($query) => $query->withTrashed()])
            ->get();

        foreach ($transfers as $transfer) {
            $this->revokeOwnershipTransfer->handle($user, $transfer->container, $transfer);
        }

        DB::table('invitation')
            ->where('invited_by_user_id', $userId)
            ->update(['invited_by_user_id' => null, 'updated_at' => now()]);

        DB::table('ownership_transfer')
            ->where('initiated_by_user_id', $userId)
            ->update(['initiated_by_user_id' => null, 'updated_at' => now()]);
    }
}
