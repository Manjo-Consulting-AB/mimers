<?php

namespace App\Support\Plan;

use App\Exceptions\Api\ApiException;
use App\Models\Account;
use App\Models\Container;
use App\Models\UsageCounter;

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
     * antalet andra än ägarkontot som har åtkomst — giltiga
     * container_access-rader plus obesvarade, icke utgångna inbjudningar
     * (issue 27 § Beslut 5). Kontrollen sitter på BÅDA ingångarna —
     * ContainerInvitationController::store() och
     * ContainerAccessController::store() — för en kontroll på bara den ena
     * är en kontroll som går att kringgå.
     */
    public function assertCanShareContainer(Container $container): void
    {
        $limit = $container->account->planLimit('shared_users_per_container');

        if ($limit === null) {
            return;
        }

        // Formuleringen av "giltig access" återanvänder
        // ContainerAccess::scopeValid() — samma villkor som policyn och
        // deltagarlistan, två formuleringar skulle glida isär (issue 9c).
        $used = $container->accesses()->valid()->count()
            + $container->invitations()
                ->where('status', 'pending')
                ->where('expires_at', '>', now())
                ->count();

        if ($used >= $limit) {
            throw ApiException::make('quota.shared_users_exceeded', ['limit' => $limit, 'used' => $used], 403);
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
}
