<?php

namespace App\Support\Plan;

use App\Exceptions\Api\ApiException;
use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Invitation;
use App\Models\UsageCounter;
use Illuminate\Support\Facades\DB;

/**
 * Rättigheterna till kontots plan — kontrollpunkterna i [[Planer och kvoter]]
 * § Kontrollpunkter, samlade på ett ställe (issue 27). Varje metod slår upp
 * gränsen ur kontots gällande plan, jämför med förbrukningen och kastar
 * `ApiException` (403) med vilken gräns som slog i. De returnerar void — ett
 * booleskt returvärde vore en kontroll varje anropare kan glömma att läsa
 * (issue 27 § Beslut 1).
 *
 * Gränsen läses alltid genom `Account::planLimit()`, aldrig direkt ur planen
 * eller en policy (issue 25 § Beslut 7): en policy svarar på "får den här
 * användaren göra det", en kvot på "ryms det i kontots plan", och slås de
 * ihop blir två 403 av olika orsaker omöjliga att skilja åt i klienten
 * (issue 27 § Beslut 3). `null` betyder obegränsat och passerar utan att
 * någon fråga ställs — ett Pro-konto ska inte betala en räkning för en
 * gräns som inte finns.
 *
 * Klassen är en injicerbar stödklass, samma form som
 * App\Support\Api\ValidationErrorMapper och de actions
 * [[ADR-0024 Tunna controllers och actions]] beskriver.
 */
final class Entitlements
{
    /**
     * Containertaket: `containers` i planens limits mot
     * `usage_counter.container_count`. Kontrollen sitter i
     * ContainerController::store(), efter Gate::authorize() (issue 27 §
     * Beslut 4), och gäller det konto som anges i kroppen — inte den
     * inloggade användarens eventuella något-konto.
     */
    public function assertCanCreateContainer(Account $account): void
    {
        $limit = $account->planLimit('containers');

        if ($limit === null) {
            return;
        }

        // Taket räknas på räknaren, aldrig med en COUNT(*) på container —
        // räknaren är byggd för det (26a) och hålls i takt av 26b. Saknas
        // raden är antalet noll: ett konto utan räknarrad har inga
        // containers (issue 27 § Beslut 4).
        //
        // Räknaren är INTE auktoritativ mot ett race: två samtidiga
        // POST /api/containers kan båda läsa 0 och passera, och
        // konsekvensen är en container för mycket — obehagligt men inte
        // farligt, och 26b gör talet rätt igen (issue 27 § Beslut 7). Det
        // är därför uppladdningens totalkvot (27b) tar ett radlås och den
        // här kontrollen inte gör det: en överfull disk är dyr, en container
        // för mycket är inte det.
        $used = (int) UsageCounter::query()
            ->where('account_id', $account->id)
            ->value('container_count');

        if ($used >= $limit) {
            throw ApiException::make('quota.containers_exceeded', ['limit' => $limit, 'used' => $used], 403);
        }
    }

    /**
     * Delningstaket: `shared_users_per_container` i ÄGARKONTOTS plan mot
     * antalet andra än ägarkontot som har åtkomst — DISTINKTA mottagare med
     * giltig åtkomst plus distinkta adresser med obesvarad, icke utgången
     * inbjudan (issue 27 § Beslut 5, räkningen omgjord i issue 72 § Beslut
     * 6). Kontrollen sitter på BÅDA ingångarna —
     * ContainerInvitationController::store() och
     * ContainerAccessController::store() — för en kontroll på bara den ena
     * är en kontroll som går att kringgå.
     *
     * Taket räknar MOTTAGARE, inte rader: med itemåtkomster får en och
     * samma mottagare flera giltiga rader — en per item — och ett tak som
     * räknade rader skulle fällas av att ägaren delat fyra items med SAMMA
     * varv. Nyckeln är `(grantee_type, grantee_id)`; de två namnrymderna
     * får inte kollapsa till samma mottagare.
     *
     * En adress som både har en `pending`-inbjudan och en giltig åtkomst
     * räknas som TVÅ. Det är samma närmevärde som i dag — inbjudan och
     * åtkomst går inte att para ihop innan accepten — och det är medvetet
     * konservativt: taket ska hellre fällas en gång för mycket än släppa
     * igenom en delning över gränsen.
     *
     * `used` i felsvarets `data` är det distinkta talet, så klientens
     * meddelande stämmer med det tal som faktiskt jämförs.
     *
     * Formuleringen av "giltig access" återanvänder
     * ContainerAccess::scopeValid() — samma villkor som policyn och
     * deltagarlistan, två formuleringar skulle glida isär (issue 9c).
     * Detsamma gäller de obesvarade inbjudningarna: villkoret bor i
     * Invitation::scopeOutstanding() sedan issue 48 § Beslut 5.
     */
    public function assertCanShareContainer(Container $container): void
    {
        $limit = $container->account->planLimit('shared_users_per_container');

        if ($limit === null) {
            return;
        }

        // Räknas i databasen som `count(*)` över en DISTINCT-projektion —
        // `COUNT(DISTINCT a, b)` finns inte i sqlite och en
        // strängkonkatenering hade inte varit portabel mellan sqlite och
        // mysql. En fråga, aldrig en per rad.
        $granteeCount = (int) DB::query()
            ->fromSub(
                ContainerAccess::query()
                    ->where('container_id', $container->id)
                    ->valid()
                    ->select('grantee_type', 'grantee_id')
                    ->distinct(),
                'valid_grantee',
            )
            ->count();

        $invitationCount = $container->invitations()->outstanding()->distinct()->count('email');

        $used = $granteeCount + $invitationCount;

        if ($used >= $limit) {
            throw ApiException::make('quota.shared_users_exceeded', ['limit' => $limit, 'used' => $used], 403);
        }
    }

    /**
     * Inbjudningstaket: `pending_invitations` i ÄGARKONTOTS plan mot antalet
     * obesvarade, icke utgångna inbjudningar över kontots ALLA containers
     * (issue 48). Det som skyddas är inte utrymme utan leveransryktet hos
     * e-postleverantören — inbjudningar går till overifierade adresser, och
     * magic links är inloggningskritiska ([[ADR-0017 Missbruksvektorer]] § 5).
     *
     * Signaturen tar ett `Account` som assertCanCreateContainer(): kontot är
     * containerns ägarkonto, och bara ägarkontots medlemmar får bjuda in
     * ([[Konton och åtkomst]] § Behörighetsregler regel 3, bevakat av
     * manageAccess()), så ägarkontot ÄR avsändarens konto — och delningstaket
     * ovan läser redan ägarkontots plan.
     *
     * Taket räknas med en COUNT, aldrig med en `usage_counter`-kolumn: en
     * räknare måste hållas i takt av varje accept, avvisning,
     * tillbakadragning OCH av tidens gång — en `pending`-rad går ut utan att
     * någon kod kör. Ett tal som bara kan härledas ska härledas (issue 48
     * § Beslut 4).
     *
     * Containerns SoftDeletes-scope gäller i underfrågan, så en mjukraderad
     * containers inbjudningar räknas inte — avsiktligt, samma linje som
     * containertaket, där en mjukraderad container frigör sin plats.
     *
     * Ingen låsning och ingen transaktion: två samtidiga POST kan båda läsa
     * `used = N-1` och passera. Konsekvensen är en inbjudan för mycket, och
     * den går ut av sig själv efter Invitation::TTL_DAYS — samma avvägning
     * som assertCanCreateContainer() gör (issue 27 § Beslut 7).
     * `lockForUpdate()` hör hemma där en överfull disk är dyr, inte här.
     */
    public function assertPendingInvitationsWithinLimit(Account $account): void
    {
        $limit = $account->planLimit('pending_invitations');

        if ($limit === null) {
            return;
        }

        // Vägen till kontot går via container_id → container.account_id;
        // `invitation` har ingen account_id och ingen genväg ska byggas.
        $used = Invitation::query()
            ->outstanding()
            ->whereIn('container_id', Container::query()->where('account_id', $account->id)->select('id'))
            ->count();

        if ($used >= $limit) {
            throw ApiException::make('quota.pending_invitations_exceeded', ['limit' => $limit, 'used' => $used], 403);
        }
    }

    /**
     * En funktionsgräns: `false` i planens limits nekar, allt annat
     * (`true`, och `null` för en framtida plan utan begränsning) passerar.
     * Byggs för de funktioner som kommer i M6/M8 — webhooks, pdf_binder,
     * ownership_transfer, loan_reminders, cost_reports — men hakar inte i
     * något som inte finns än (issue 27 § Beslut 6).
     */
    public function assertFeature(Account $account, string $feature): void
    {
        $allowed = $account->planLimit($feature);

        if ($allowed !== false) {
            return;
        }

        throw ApiException::make('plan.feature_unavailable', ['feature' => $feature], 403);
    }

    /**
     * Styckstorleken: `max_file_bytes` i kontots plan mot filens byten.
     * Kontrollen sitter i AttachmentController::store(), direkt efter att
     * kontot hämtats och medlemskapet bevisats, och innan StoreAttachment
     * anropas (issue 27b § Beslut 4). En fil som ändå nekas ska varken
     * skrivas till disken eller få en attachment-rad — därför ligger
     * kontrollen före actionen, inte inuti den.
     */
    public function assertFileWithinLimit(Account $account, int $byteSize): void
    {
        $limit = $account->planLimit('max_file_bytes');

        if ($limit === null) {
            return;
        }

        if ($byteSize > $limit) {
            throw ApiException::make('quota.max_file_size_exceeded', [
                'limit_bytes' => $limit,
                'file_bytes' => $byteSize,
            ], 403);
        }
    }

    /**
     * Totalkvoten: `storage_bytes` i kontots plan mot räknarens
     * `storage_bytes` plus filens byten. Anropas på TVÅ ställen (issue 27b §
     * Beslut 4): en billig avvisning i AttachmentController::store() innan
     * bytena skrivs till disken, och en gång till inne i StoreAttachments
     * transaktion. Bara den andra håller mot samtidiga uppladdningar — den
     * tidiga ser en siffra som kan vara inaktuell när transaktionen läser
     * igen.
     *
     * Läsningen är därför en låsande current read (lockForUpdate): inne i
     * transaktionen serialiserar den samtidiga uppladdare på kontots
     * räknarrad, och den som väntar ser den förstas ökning när låset
     * släpper. I kontrollerns anrop — utanför en transaktion, i autocommit —
     * släpps låset i slutet av satsen och läsningen är ofarlig. Båda
     * anropen delar samma formulering; en kontroll med egen formel skulle
     * glida isär från räknaren och från 26b:s avstämning (issue 27b § Att se
     * upp med).
     *
     * Saknas räknarraden är förbrukningen noll, och låset låser ingenting:
     * två samtidiga första uppladdningar på ett tomt konto kan båda passera
     * här. På ett tomt konto rymmer kvoten dem båda, så konsekvensen är
     * noll — att uppfinna en rad att låsa vore att betala för ett lås som
     * inte behövs (issue 27b § Att se upp med).
     */
    public function assertStorageWithinLimit(Account $account, int $byteSize): void
    {
        $limit = $account->planLimit('storage_bytes');

        if ($limit === null) {
            return;
        }

        $used = (int) UsageCounter::query()
            ->where('account_id', $account->id)
            ->lockForUpdate()
            ->value('storage_bytes');

        // used + file > limit nekar; en fil som exakt fyller kvoten, eller en
        // första fil på ett tomt konto, ska gå igenom.
        if ($used + $byteSize > $limit) {
            throw ApiException::make('quota.storage_exceeded', [
                'limit_bytes' => $limit,
                'used_bytes' => $used,
                'file_bytes' => $byteSize,
            ], 403);
        }
    }
}
