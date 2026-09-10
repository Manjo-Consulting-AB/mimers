<?php

namespace App\Console;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Den nattliga missbruksrapporten — se issue 50b (M9) och [[ADR-0017
 * Missbruksvektorer]] § Mätningen. Jobbet räknar fram åtta mätvärden om hur
 * gratisnivån används och skriver dem till loggen. Det larmar inte, spärrar
 * ingenting och skriver ingenting i databasen.
 *
 * ADR:ns hela linje är "detektera och prissätt, spärra bara där mätningen
 * visar en verklig kostnad". Rapporten är underlaget som gör trösklarna —
 * idag gissningar, ADR § Konsekvenser — till något mätbart. En rapport som
 * börjar larma är en rapport någon stänger av, och en rapport som rättar en
 * räknare är en andra avstämning med en egen uppfattning om sanningen: den
 * här klassen LÄSER tabeller som sex milstolpar äger, den rör dem aldrig.
 *
 * READ-ONLY ÄR READ-ONLY (Beslut 12). Ingen INSERT, ingen UPDATE, ingen
 * DELETE och ingen transaktion — kriteriet "kan köras på produktionsdata utan
 * att skriva något" tolkas bokstavligt. En historiktabell för trendjämförelse
 * övervägdes och valdes bort: den vore en skrivning, trenden finns redan i de
 * daterade loggraderna (`grep abuse.report`), och en tabell med
 * personuppgiftsnära aggregat vore ny lagring med en egen gallringsfrist att
 * glömma bort — precis vad ADR § Konsekvenser varnar för.
 *
 * LOGGEN BÄR ALDRIG EN RÅ IP-ADRESS (Beslut 10), ingen e-postadress, inget
 * användarnamn och inget filnamn. En IP skrivs som `ip_group`: de första
 * sexton hexatecknen av `hash_hmac('sha256', $ip, config('app.key'))`. Skälet
 * är konkret — loggfiler roteras och backas upp på en annan bana än databasen,
 * så en IP som hamnar i loggen överlever 50a:s nittiodagarsfrist och gör
 * fristen till en halv åtgärd. Gruppnyckeln är allt rapporten behöver: att
 * SAMMA IP ligger bakom elva konton är signalen; VILKEN IP det är säger
 * ingenting förrän någon utreder, och den utredaren kan räkna fram samma
 * pseudonym ur databasen. Konton och åtkomster identifieras med `ulid`.
 *
 * LOGNIVÅN ÄR `info`, ALDRIG `warning` (Beslut 11). `warning` är larmkanalen
 * App\Console\ReconcilesUsageCounters använder för räknardrift; den här
 * rapporten larmar med flit inte, inte ens när varje listning har träffar.
 * Högst `missbruk.listing_limit` rader per listning — en rapport som skriver
 * tvåtusen rader en natt är en rapport ingen läser — och varje loggrad bär
 * både `shown` och `total`, så att ingen tror att tjugo var alla.
 *
 * ANTALET FRÅGOR VÄXER MED ANTALET MÄTVÄRDEN, INTE MED ANTALET KONTON (Beslut
 * 13, samma regel som förlagan 26b § Beslut 5): aggregat med GROUP BY över
 * hela systemet, aldrig en fråga per konto i en loop.
 *
 * TVÅ AVVIKELSER FRÅN ADR:NS ORDALYDELSE, båda avsiktliga och beslutade i
 * issue 50b, skrivna här så att nästa läsare inte tror att de är buggar:
 *
 *  1. Listning 7 "containers skapade i kluster från samma IP". Ingen IP lagras
 *     på `container`, och 50a lägger med flit inte till en. Ägarkontots
 *     `registration_ip` är proxyn, och den träffar precis det fall ADR:n
 *     kallar det ekonomiskt betydande läckaget: ett varv som skapar ett
 *     gratiskonto med en container per kund från samma kontorsuppkoppling.
 *     Att fånga IP vid varje containerskapande vore en ny skrivning i en het
 *     väg för ett mätvärde vars tröskel ändå är en gissning.
 *  2. Listning 8 "högt över konton utan inbördes relation". Rapportens
 *     "orelaterade konton" mäts INTE som en relationsgraf — att pröva
 *     relationen (delar kontona någon container, någon `container_access`,
 *     någon inbjudan?) är en rekursiv fråga per fil, och trösklarna den skulle
 *     tjäna är enligt ADR § Konsekvenser platshållare tills tre månaders data
 *     finns. Rapporten redovisar antalet distinkta konton OCH antalet distinkta
 *     containers och överlåter bedömningen till läsaren: många konton och få
 *     containers är delning, många konton och lika många containers är
 *     spridning.
 *
 * Schemaläggs i routes/console.php med `Schedule::call` och `->dailyAt('01:00')`,
 * aldrig `Schedule::command` — se AGENTS.md § Driftmiljön saknar proc_open —
 * och klassen är medvetet fri från Artisan-beroenden av samma skäl som
 * App\Console\ReconcilesUsageCounters. 01:00 och inte 00:00: rapporten läser
 * den räknare avstämningen (26b) just rättat, och en rapport som läser den tio
 * minuter innan den rättas rapporterar drift som missbruk.
 */
class ReportsAbuseSignals
{
    /**
     * Räknar fram rapporten och skriver den till loggen.
     *
     * Returvärdet finns för att testerna ska kunna påstå något om talen utan
     * att tolka loggrader (Beslut 1); loggen finns för att en människa ska
     * kunna läsa dem. Alla åtta nycklar finns alltid med — är databasen tom är
     * talen noll och listningarna tomma arrayer, inte saknade nycklar.
     *
     * @return array{
     *     new_free_accounts: int,
     *     free_accounts_never_uploaded: array{accounts: int, never_uploaded: int, share: float},
     *     storage_per_free_account: array{accounts: int, total_bytes: int, max_bytes: int, p90_bytes: int, median_bytes: int},
     *     emails_per_account: array{total: int, accounts: int, max: int, p90: int, median: int},
     *     accounts_per_registration_ip: list<array<string, mixed>>,
     *     managed_access_breadth: list<array<string, mixed>>,
     *     container_clusters_per_registration_ip: list<array<string, mixed>>,
     *     shared_stored_files: list<array<string, mixed>>,
     * }
     */
    public function handle(): array
    {
        $windowDays = (int) config('missbruk.window_days');
        $windowStart = now()->subDays($windowDays);
        $listingLimit = (int) config('missbruk.listing_limit');

        // Listningarna hämtas HELA och kapas först vid loggningen: `total`
        // ska vara antalet träffar före kapningen, inte antalet visade rader.
        // En fråga per listning, oavsett hur många konton systemet har.
        $listningar = [
            'accounts_per_registration_ip' => $this->accountsPerRegistrationIp(),
            'managed_access_breadth' => $this->managedAccessBreadth(),
            'container_clusters_per_registration_ip' => $this->containerClustersPerRegistrationIp($windowStart),
            'shared_stored_files' => $this->sharedStoredFiles(),
        ];

        $visade = [];
        $antal = [];

        foreach ($listningar as $namn => $rader) {
            $antal[$namn] = count($rader);
            $visade[$namn] = array_slice($rader, 0, $listingLimit);
        }

        $rapport = [
            'new_free_accounts' => $this->newFreeAccounts($windowStart),
            'free_accounts_never_uploaded' => $this->freeAccountsNeverUploaded(),
            'storage_per_free_account' => $this->storagePerFreeAccount(),
            'emails_per_account' => $this->emailsPerAccount($windowStart),
            'accounts_per_registration_ip' => $visade['accounts_per_registration_ip'],
            'managed_access_breadth' => $visade['managed_access_breadth'],
            'container_clusters_per_registration_ip' => $visade['container_clusters_per_registration_ip'],
            'shared_stored_files' => $visade['shared_stored_files'],
        ];

        $this->logReport($rapport, $windowDays, $visade, $antal);

        return $rapport;
    }

    /**
     * Tal 1 — antalet gratiskonton skapade inom fönstret (Beslut 5).
     */
    private function newFreeAccounts(Carbon $windowStart): int
    {
        return $this->freeAccountsQuery()
            ->where('account.created_at', '>=', $windowStart)
            ->count();
    }

    /**
     * Tal 2 — av SAMTLIGA gratiskonton: hur många saknar helt rad i
     * `attachment` med `billed_account_id = account.id` (Beslut 6).
     *
     * `attachment.deleted_at` ignoreras med flit — frågan är "har aldrig
     * laddat upp något", och en raderad bilaga var ändå en uppladdning. Andelen
     * sjunker alltså inte av att någon tömmer papperskorgen.
     *
     * Båda talen är aggregat över hela systemet (Beslut 13): `NOT EXISTS`
     * låter databasen avgöra vilka konton som saknar bilaga, i stället för att
     * transportera ett id-set som växer med antalet gratiskonton genom PHP.
     *
     * @return array{accounts: int, never_uploaded: int, share: float}
     */
    private function freeAccountsNeverUploaded(): array
    {
        $accounts = $this->freeAccountsQuery()->count();

        if ($accounts === 0) {
            return ['accounts' => 0, 'never_uploaded' => 0, 'share' => 0.0];
        }

        $aldrig = $this->freeAccountsQuery()
            ->whereNotExists(function (Builder $q): void {
                $q->selectRaw('1')
                    ->from('attachment')
                    ->whereColumn('attachment.billed_account_id', 'account.id');
            })
            ->count();

        return [
            'accounts' => $accounts,
            'never_uploaded' => $aldrig,
            // Decimaltal, aldrig en procentsträng (Beslut 6).
            'share' => $aldrig / $accounts,
        ];
    }

    /**
     * Tal 3 — lagring per gratiskonto, läst ur `usage_counter.storage_bytes`
     * och ALDRIG ur en egen `SUM(stored_file.byte_size)` (Beslut 7). Räknaren
     * är byggd för det (26a) och hålls i takt av 26b; en andra formulering av
     * samma summa vore en andra sanning.
     *
     * Ett gratiskonto utan räknarrad räknas som noll byte — samma regel som
     * App\Support\Plan\Entitlements::assertCanCreateContainer, där en saknad
     * rad betyder noll förbrukning.
     *
     * p90 och median är nearest-rank över de sorterade värdena: index
     * `ceil(p * n) - 1`, vilket ger den undre medianen för ett jämnt antal.
     * Talen är byte, alltså heltal.
     *
     * @return array{accounts: int, total_bytes: int, max_bytes: int, p90_bytes: int, median_bytes: int}
     */
    private function storagePerFreeAccount(): array
    {
        $bytes = $this->freeAccountsQuery()
            ->leftJoin('usage_counter', 'usage_counter.account_id', '=', 'account.id')
            ->pluck('usage_counter.storage_bytes')
            ->map(fn ($varde): int => (int) $varde)
            ->sort()
            ->values()
            ->all();

        $accounts = count($bytes);

        if ($accounts === 0) {
            return [
                'accounts' => 0,
                'total_bytes' => 0,
                'max_bytes' => 0,
                'p90_bytes' => 0,
                'median_bytes' => 0,
            ];
        }

        return [
            'accounts' => $accounts,
            'total_bytes' => array_sum($bytes),
            'max_bytes' => $bytes[$accounts - 1],
            'p90_bytes' => $this->percentile($bytes, 0.90),
            'median_bytes' => $this->percentile($bytes, 0.50),
        ];
    }

    /**
     * Tal 4 — utskickade mejl per konto inom fönstret (Beslut 8).
     *
     * `notification_delivery` joinat mot `notification`, `channel = 'email'`,
     * `status = 'sent'` och `sent_at` inom fönstret, grupperat på
     * `notification.account_id`. `failed` och `suppressed` räknas INTE — ett
     * mejl som inte gick iväg är inget utskickat mejl — och `webhook` är en
     * annan kanal. `accounts` är antalet konton med minst ett utskick.
     *
     * @return array{total: int, accounts: int, max: int, p90: int, median: int}
     */
    private function emailsPerAccount(Carbon $windowStart): array
    {
        $perKonto = DB::table('notification_delivery')
            ->join('notification', 'notification.id', '=', 'notification_delivery.notification_id')
            ->where('notification_delivery.channel', 'email')
            ->where('notification_delivery.status', 'sent')
            ->where('notification_delivery.sent_at', '>=', $windowStart)
            ->groupBy('notification.account_id')
            ->selectRaw('notification.account_id as account_id, COUNT(*) as total')
            ->pluck('total', 'account_id')
            ->map(fn ($antal): int => (int) $antal)
            ->sort()
            ->values()
            ->all();

        $accounts = count($perKonto);

        if ($accounts === 0) {
            return ['total' => 0, 'accounts' => 0, 'max' => 0, 'p90' => 0, 'median' => 0];
        }

        return [
            'total' => array_sum($perKonto),
            'accounts' => $accounts,
            'max' => $perKonto[$accounts - 1],
            'p90' => $this->percentile($perKonto, 0.90),
            'median' => $this->percentile($perKonto, 0.50),
        ];
    }

    /**
     * Listning 5 — konton per registrerings-IP (ADR § 1, Beslut 9).
     *
     * Grupperat på `registration_ip`, bara grupper över tröskeln, fallande.
     * Konton registrerade före 50a har `NULL` och faller bort — det är rätt,
     * inte ett fel att kompensera för. IP:t loggas som `ip_group`, aldrig rått.
     *
     * @return list<array{ip_group: string, accounts: int}>
     */
    private function accountsPerRegistrationIp(): array
    {
        $min = (int) config('missbruk.ip_account_min');

        return DB::table('account')
            ->whereNotNull('registration_ip')
            ->groupBy('registration_ip')
            ->havingRaw('COUNT(*) >= ?', [$min])
            ->selectRaw('registration_ip, COUNT(*) as accounts')
            ->orderByDesc('accounts')
            ->orderBy('registration_ip')
            ->get()
            ->map(fn ($rad): array => [
                'ip_group' => $this->ipGroup($rad->registration_ip),
                'accounts' => (int) $rad->accounts,
            ])
            ->all();
    }

    /**
     * Listning 6 — `managed`-åtkomstens bredd (ADR § 2, Beslut 9).
     *
     * Giltiga `managed`-rader — `revoked_at IS NULL` och
     * `(expires_at IS NULL OR expires_at > now())` — grupperade på
     * `(grantee_type, grantee_id)`, över tröskeln. `expires_at IS NULL`
     * betyder "går aldrig ut", inte "gick ut för länge sedan"; villkoret är
     * samma som App\Models\ContainerAccess::scopeValid() formulerar.
     *
     * Grupperingen tar med BÅDA `grantee_type`-värdena fast ADR:n bara nämner
     * konton: att gruppera på två kolumner i stället för en kostar ingenting,
     * och ett varv som fått åtkomst som `user` i stället för som `account` är
     * samma vektor. Mottagaren identifieras med `ulid`.
     *
     * @return list<array{grantee_type: string, grantee_ulid: string|null, containers: int}>
     */
    private function managedAccessBreadth(): array
    {
        $min = (int) config('missbruk.managed_container_min');
        $nu = now();

        $rader = DB::table('container_access')
            ->where('kind', 'managed')
            ->whereNull('revoked_at')
            ->where(fn (Builder $q): Builder => $q->whereNull('expires_at')->orWhere('expires_at', '>', $nu))
            ->groupBy('grantee_type', 'grantee_id')
            ->havingRaw('COUNT(DISTINCT container_id) >= ?', [$min])
            ->selectRaw('grantee_type, grantee_id, COUNT(DISTINCT container_id) as containers')
            ->orderByDesc('containers')
            ->orderBy('grantee_type')
            ->orderBy('grantee_id')
            ->get();

        // Båda uppslagen körs alltid, även när den ena mottagartypen saknas:
        // antalet frågor ska inte bero på innehållet (Beslut 13).
        $accountUlider = DB::table('account')
            ->whereIn('id', $rader->where('grantee_type', 'account')->pluck('grantee_id'))
            ->pluck('ulid', 'id');
        $userUlider = DB::table('user')
            ->whereIn('id', $rader->where('grantee_type', 'user')->pluck('grantee_id'))
            ->pluck('ulid', 'id');

        return $rader
            ->map(function ($rad) use ($accountUlider, $userUlider): array {
                $ulider = $rad->grantee_type === 'account' ? $accountUlider : $userUlider;

                return [
                    'grantee_type' => $rad->grantee_type,
                    'grantee_ulid' => $ulider[$rad->grantee_id] ?? null,
                    'containers' => (int) $rad->containers,
                ];
            })
            ->all();
    }

    /**
     * Listning 7 — containrar skapade i kluster från samma IP (ADR § 2,
     * Beslut 9). Se den avsiktliga avvikelsen i klassdocblocken: IP:t är
     * ÄGARKONTOTS registrerings-IP, för ingen IP lagras på `container`.
     *
     * `container.deleted_at IS NULL` och `account.registration_ip IS NOT NULL`,
     * begränsat till fönstret, grupperat på `(owner-IP, DATE(created_at))`,
     * över tröskeln.
     *
     * @return list<array{ip_group: string, day: string, containers: int}>
     */
    private function containerClustersPerRegistrationIp(Carbon $windowStart): array
    {
        $min = (int) config('missbruk.ip_container_min');

        return DB::table('container')
            ->join('account', 'account.id', '=', 'container.account_id')
            ->whereNull('container.deleted_at')
            ->whereNotNull('account.registration_ip')
            ->where('container.created_at', '>=', $windowStart)
            ->groupBy('account.registration_ip')
            ->groupByRaw('DATE(container.created_at)')
            ->havingRaw('COUNT(*) >= ?', [$min])
            ->selectRaw('account.registration_ip, DATE(container.created_at) as day, COUNT(*) as containers')
            ->orderByDesc('containers')
            ->orderBy('account.registration_ip')
            ->orderBy('day')
            ->get()
            ->map(fn ($rad): array => [
                'ip_group' => $this->ipGroup($rad->registration_ip),
                'day' => (string) $rad->day,
                'containers' => (int) $rad->containers,
            ])
            ->all();
    }

    /**
     * Listning 8 — delade `stored_file` (ADR § 3, Beslut 9), över trösklarna.
     * Se den avsiktliga avvikelsen i klassdocblocken: rapporten mäter antalet
     * distinkta konton och containers, inte om kontona är "orelaterade".
     *
     * Bara LEVANDE bilagor räknas (`attachment.deleted_at IS NULL`) — det är
     * de som bär spridningen nu. Containrar räknas via `item.container_id`.
     *
     * @return list<array{content_hash: string, reference_count: int, distinct_accounts: int, distinct_containers: int}>
     */
    private function sharedStoredFiles(): array
    {
        $referenceMin = (int) config('missbruk.reference_count_min');
        $accountMin = (int) config('missbruk.distinct_account_min');

        return DB::table('stored_file')
            ->join('attachment', 'attachment.stored_file_id', '=', 'stored_file.id')
            ->join('item', 'item.id', '=', 'attachment.item_id')
            ->whereNull('attachment.deleted_at')
            ->where('stored_file.reference_count', '>=', $referenceMin)
            ->groupBy('stored_file.id', 'stored_file.content_hash', 'stored_file.reference_count')
            ->havingRaw('COUNT(DISTINCT attachment.billed_account_id) >= ?', [$accountMin])
            ->selectRaw(
                'stored_file.content_hash,'
                .' stored_file.reference_count,'
                .' COUNT(DISTINCT attachment.billed_account_id) as distinct_accounts,'
                .' COUNT(DISTINCT item.container_id) as distinct_containers'
            )
            ->orderByDesc('stored_file.reference_count')
            ->orderBy('stored_file.content_hash')
            ->get()
            ->map(fn ($rad): array => [
                'content_hash' => $rad->content_hash,
                'reference_count' => (int) $rad->reference_count,
                'distinct_accounts' => (int) $rad->distinct_accounts,
                'distinct_containers' => (int) $rad->distinct_containers,
            ])
            ->all();
    }

    /**
     * Kontots gällande plan, i aggregatform — den enda definitionen av
     * "gratiskonto" (Beslut 4). Den är App\Models\Account::currentPlan()s,
     * oformulerad om:
     *
     *   gratiskonto = account utan rad i subscription
     *               ELLER subscription.status = 'cancelled'
     *               ELLER subscription -> plan.code = 'free'
     *
     * Samma disciplin som 26b har mot räknaren: glider rapportens definition
     * från modellens rapporterar den sin egen verklighet. `account` har inget
     * `deleted_at` — livscykeln går via `status` — så inget soft-delete-predikat
     * hör hemma här. Konton med `status = 'closed'` räknas med; de
     * registrerades ändå.
     *
     * `subscription.account_id` är unikt, så joinen mångfaldigar aldrig en
     * account-rad.
     */
    private function freeAccountsQuery(): Builder
    {
        return DB::table('account')
            ->leftJoin('subscription', 'subscription.account_id', '=', 'account.id')
            ->leftJoin('plan', 'plan.id', '=', 'subscription.plan_id')
            ->where(function (Builder $q): void {
                $q->whereNull('subscription.id')
                    ->orWhere('subscription.status', 'cancelled')
                    ->orWhere('plan.code', 'free');
            });
    }

    /**
     * Pseudonymen som loggen bär i stället för en rå IP-adress (Beslut 10).
     * De första sexton hexatecknen av en HMAC-SHA256 med applikationsnyckeln:
     * samma IP ger samma grupp mellan två körningar, två olika IP:n olika
     * grupper, och nyckeln gör att pseudonymen inte kan räknas fram utan
     * `config('app.key')`.
     */
    private function ipGroup(string $ip): string
    {
        return substr(hash_hmac('sha256', $ip, (string) config('app.key')), 0, 16);
    }

    /**
     * Nearest-rank-percentilen över en sorterad, nollindexerad lista.
     *
     * @param  list<int>  $sorterade
     */
    private function percentile(array $sorterade, float $percentil): int
    {
        $antal = count($sorterade);

        if ($antal === 0) {
            return 0;
        }

        $index = max(0, (int) ceil($percentil * $antal) - 1);

        return $sorterade[$index];
    }

    /**
     * Rapportens enda skrivyta: loggen (Beslut 11). En rad för talen och en
     * rad per listningsrad, alltid på nivån `info`. Varje signalrad bär sin
     * listnings `shown` och `total`, så att kapningen syns även när någon bara
     * läser en enskild rad.
     *
     * @param  array<string, mixed>  $rapport
     * @param  array<string, list<array<string, mixed>>>  $visade
     * @param  array<string, int>  $antal
     */
    private function logReport(array $rapport, int $windowDays, array $visade, array $antal): void
    {
        Log::info('abuse.report', [
            'window_days' => $windowDays,
            'new_free_accounts' => $rapport['new_free_accounts'],
            'free_accounts_never_uploaded' => $rapport['free_accounts_never_uploaded'],
            'storage_per_free_account' => $rapport['storage_per_free_account'],
            'emails_per_account' => $rapport['emails_per_account'],
        ]);

        foreach ($visade as $namn => $rader) {
            foreach ($rader as $rad) {
                Log::info('abuse.report.signal', array_merge([
                    'listing' => $namn,
                    'shown' => count($rader),
                    'total' => $antal[$namn],
                ], $rad));
            }
        }
    }
}
