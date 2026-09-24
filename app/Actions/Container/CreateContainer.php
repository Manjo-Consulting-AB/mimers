<?php

namespace App\Actions\Container;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Usage\AdjustUsage;
use App\Exceptions\Api\ApiException;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\User;
use App\Support\Plan\Entitlements;
use Illuminate\Support\Facades\DB;

/**
 * Skapar en container åt ett konto — utbruten ur
 * App\Http\Controllers\Api\ContainerController::store() i issue 54 § Beslut 3,
 * när webben blev den andra anroparen av exakt samma fyra steg.
 *
 * [[ADR-0024 Tunna controllers och actions]] pekade ut just det här läget:
 * flera anropare och en skrivning värd egna tester. Skrivningen bär fyra
 * regler — kvotkontrollen, transaktionen, förbrukningsräknaren och
 * ägarrelationen — och en andra kopia av dem i en webbkontroller hade varit
 * två formuleringar av containertaket.
 *
 * **Behörigheten prövas av ANROPAREN, inte här.** `Gate::authorize('create',
 * [Container::class, $account])` står kvar i båda kontrollerna. Ordningen
 * behörighet-före-kvot (issue 27 § Beslut 3) ligger därmed också kvar: en
 * användare som inte får skapa åt kontot ska få 403 auth.forbidden — inte
 * veta hur många containers kontot har. Flyttas gate-anropet in hit får
 * API:ets 403 och webbens 403 olika källor, och det är precis vad
 * App\Policies\ContainerPolicy finns för att förhindra.
 *
 * `account_id` sätts explicit på modellinstansen i stället för via
 * massildelning (`Container::create()`) — kolumnen är medvetet utelämnad ur
 * App\Models\Container#[Fillable], se den klassens docblock.
 */
final class CreateContainer
{
    public function __construct(
        private readonly AdjustUsage $adjustUsage,
        private readonly Entitlements $entitlements,
        private readonly RecordAuditEvent $recordAuditEvent,
    ) {}

    /**
     * $creator bärs med av kontraktet i issue 54 § Beslut 3 och blev
     * `user_id` på loggraden i issue 111: en container har ingen
     * `created_by`-kolumn, och ägaren är alltid ett konto ([[ADR-0002 Konto
     * äger container]]), men historiken svarar på vem som skapade den.
     *
     * `$kind` är frivillig sedan issue 84 · [[ADR-0036 Containerns art]]:
     * containern får skapas utan art, och `null` är det ärliga värdet för
     * "användaren har inte svarat än" — inte en tom sträng.
     *
     * `$description` kom med issue 88 · [[ADR-0039 Containerns översikt]] och
     * är frivillig av samma skäl: att kräva en beskrivning vid skapandet är
     * att ställa en fråga användaren ännu inte kan svara på. Parametern
     * läggs SIST med ett förval, så varje anropare — båda kontrollerna, varje
     * test och varje fabrik — är oförändrad. Att göra om de fyra
     * positionsargumenten till en attributpåse är en riktig städning och en
     * egen uppgift: den hade rört varje anropare för en kolumns skull.
     *
     * @throws ApiException 403 `quota.containers_exceeded`
     *                      när ägarkontots containertak är nått.
     */
    public function handle(
        User $creator,
        Account $account,
        string $name,
        ?string $kind,
        ?string $description = null,
    ): Container {
        // Taket gäller det konto som anges av anroparen — samma konto som blir
        // ägare och vars plan gäller (issue 27 § Beslut 4).
        $this->entitlements->assertCanCreateContainer($account);

        $container = DB::transaction(function () use ($creator, $account, $name, $kind, $description): Container {
            $container = new Container([
                'name' => $name,
                'kind' => $kind,
                'description' => $description,
            ]);
            $container->account_id = $account->id;
            $container->save();

            // En levande container räknas mot ägarkontots containertak (issue
            // 26a) — i samma transaktion som raden. Kontot är alltid ägaren;
            // containerns räknare har inget "billed_account_id" att gå vilse i.
            $this->adjustUsage->handle($account->id, containersDelta: 1);

            // `container.created` i SAMMA transaktion som raden (issue 111):
            // en loggrad utanför den kunde överleva ett rollback och beskriva
            // en container som aldrig skapades. `meta` är tom — namnet är
            // fritext och följer aldrig med, och `kind` och `description` är
            // containerns utgångsläge, inte en ändring.
            $this->recordAuditEvent->handle(
                action: AuditLog::ACTION_CONTAINER_CREATED,
                account: $account,
                user: $creator,
                container: $container,
                subjectType: 'container',
                subjectUlid: $container->ulid,
            );

            return $container;
        });

        // $account är redan hämtad av anroparen (för Gate::authorize()) — sätt
        // relationen direkt i stället för att låta ContainerResource trigga en
        // ny fråga för samma rad, se ContainerController::index() om N+1.
        $container->setRelation('account', $account);

        return $container;
    }
}
