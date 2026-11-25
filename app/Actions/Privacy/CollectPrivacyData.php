<?php

namespace App\Actions\Privacy;

use App\Models\PrivacyRequest;
use App\Models\PrivacyRequestAttachment;
use App\Models\PrivacyRequestEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use JsonException;
use Throwable;

/**
 * Sammanställer de uppgifter i databasen som Mimers är
 * personuppgiftsansvarig för om personen ärendet är kopplat till — se
 * [[ADR-0095 Integritetsbegäranden]] § 12 och [[Registerförteckning]].
 *
 * **Ingen sammanställning görs innan identiteten är prövad** (ADR-0095
 * § 2): ärendet måste vara kopplat till en person, och det måste finnas en
 * händelse `verification` med `subject: identity` och `result: verified`.
 *
 * **Personen kan vara raderad.** Kopplingen `privacy_request.user_id` saknar
 * främmande nyckel och lever kvar, medan raden i `user` är borta. Då är
 * filens `user` null och de rader som finns kvar sammanställs som vanligt.
 *
 * **Varje källa är en egen nyckel med raderna som en lista.** Listan
 * `not_included` säger vad handläggaren får hämta för hand: användarens
 * innehåll (Mimers är biträde där, ADR-0095 § 9), Sentry, Mailgun, backuperna
 * och serverloggarna.
 *
 * **Den rättsliga spärren nämns aldrig i filen** ([[ADR-0043 Tre loggar]]
 * § Den rättsliga spärren). Rader i säkerhetsloggen vars `action` börjar på
 * `legal_hold.` tas inte med, och `legal_hold` läses aldrig. Kommandot
 * varnar handläggaren på skärmen i stället.
 *
 * Filen skapas genom AttachPrivacyAttachment::handleContents(), utan en
 * temporär kopia: den finns bara på disken `privacy` (ADR-0095 § 12).
 */
class CollectPrivacyData
{
    /**
     * Händelsen som bevisar att identiteten är prövad.
     */
    private const VERIFICATION_KIND = 'verification';

    private const IDENTITY_SUBJECT = 'identity';

    private const VERIFIED_RESULT = 'verified';

    /**
     * Systemet och operationen i händelsen `action` som antecknar
     * sammanställningen (ADR-0095 § 7 och § 12).
     */
    private const SYSTEM = 'database';

    private const OPERATION = 'exported';

    /**
     * Prefixet på de säkerhetsloggrad som aldrig får följa med.
     */
    private const LEGAL_HOLD_ACTION_PREFIX = 'legal_hold.';

    /**
     * Kolumnerna på `user` som aldrig lämnar servern. `remember_token`
     * finns inte i tabellen — den står med för att regeln ska vara densamma
     * om kolumnen en dag läggs till.
     */
    private const EXCLUDED_USER_COLUMNS = [
        'password_hash',
        'totp_secret',
        'remember_token',
    ];

    public function __construct(
        private readonly AttachPrivacyAttachment $attach,
        private readonly RecordPrivacyAction $recordAction,
    ) {}

    /**
     * @param  string  $actor  Handläggarens namn ur `--by`.
     *
     * @throws InvalidArgumentException när ärendet saknar personkoppling,
     *                                  identiteten inte är verifierad eller
     *                                  sammanställningen inte kan kodas som
     *                                  JSON; ingen bilaga och ingen händelse
     *                                  finns då.
     * @throws PrivacyCollectionNotRecordedException när bilagan skrevs men
     *                                               händelsen `action` inte
     *                                               kunde antecknas; filen
     *                                               finns då kvar.
     */
    public function handle(PrivacyRequest $request, string $actor): PrivacyRequestAttachment
    {
        $userId = $request->user_id;

        if ($userId === null) {
            throw new InvalidArgumentException(
                'Ärendet är inte kopplat till en person. Koppla personen först med privacy:link.'
            );
        }

        if (! $this->isIdentityVerified($request)) {
            throw new InvalidArgumentException(
                'Identiteten är inte verifierad. Ingen sammanställning görs innan verifieringen är registrerad med privacy:verify.'
            );
        }

        $data = $this->collect((int) $userId);

        try {
            $json = json_encode(
                $data,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $e) {
            throw new InvalidArgumentException(
                'Sammanställningen kunde inte kodas som JSON.',
                0,
                $e,
            );
        }

        // Filen läggs på ärendet först. Händelsen `action` skrivs efteråt,
        // så att den aldrig påstår en export som inte finns: faller
        // attach-steget finns varken fil eller händelse. Faller i stället
        // händelsen finns bilagan kvar, och undantaget bär dess ulid så att
        // handläggaren kan följa upp ärendet för hand.
        $attachment = $this->attach->handleContents(
            request: $request,
            originalName: 'insamling-'.$request->reference.'.json',
            contents: $json."\n",
            actor: $actor,
        );

        /** @var list<string> $categories */
        $categories = array_values((array) config('privacy.collection_categories'));

        try {
            $this->recordAction->handle(
                request: $request,
                system: self::SYSTEM,
                operation: self::OPERATION,
                categories: $categories,
                retentionReason: null,
                feature: null,
                note: null,
                actor: $actor,
            );
        } catch (Throwable $e) {
            throw new PrivacyCollectionNotRecordedException($attachment, $e);
        }

        return $attachment;
    }

    /**
     * Personens konto-id:n från medlemskapen i `account_user`.
     *
     * Kommandot ställer spärrfrågan på varje konto som faktiskt räknas upp:
     * ärendets `account_id` är valfritt och kan peka på ett annat konto än
     * det spärren gäller. En raderad person har inga medlemskap kvar, och då
     * är listan tom ([[ADR-0043 Tre loggar]] § Den rättsliga spärren).
     *
     * @return list<int>
     */
    public function accountIds(?int $userId): array
    {
        if ($userId === null) {
            return [];
        }

        return DB::table('account_user')
            ->where('user_id', $userId)
            ->orderBy('account_id')
            ->get(['account_id'])
            ->map(fn (object $row): int => (int) $row->account_id)
            ->values()
            ->all();
    }

    /**
     * Är identiteten prövad? En verifiering med `subject: identity` och
     * `result: verified` räcker; en `authority`-verifiering eller ett annat
     * resultat gör det inte (ADR-0095 § 2).
     */
    private function isIdentityVerified(PrivacyRequest $request): bool
    {
        return $request->events()
            ->where('kind', self::VERIFICATION_KIND)
            ->get()
            ->contains(function (PrivacyRequestEvent $event): bool {
                $data = $event->data;

                return ($data['subject'] ?? null) === self::IDENTITY_SUBJECT
                    && ($data['result'] ?? null) === self::VERIFIED_RESULT;
            });
    }

    /**
     * Hela sammanställningen. Ordningen är Registerförteckningens och
     * `not_included` står sist.
     *
     * @return array<string, mixed>
     */
    private function collect(int $userId): array
    {
        $user = DB::table('user')->where('id', $userId)->first();

        /** @var list<int> $agentIds */
        $agentIds = DB::table('agent')
            ->where('user_id', $userId)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        return [
            'user' => $this->userRow($user),
            'account' => $this->accounts($userId),
            'sessions' => $this->sessions($userId),
            'security_log' => $this->securityLog($userId),
            'audit_log' => $this->auditLog($userId),
            'email_change' => $this->emailChange($userId),
            'password_change' => $this->passwordChange($userId),
            'user_deletion' => $this->userDeletion($userId),
            'dismissed_tip' => $this->rows('dismissed_tip', 'user_id', $userId),
            'overview_panel' => $this->rows('overview_panel', 'user_id', $userId),
            'recent_visit' => $this->rows('recent_visit', 'user_id', $userId),
            'attachment_open' => $this->rows('attachment_open', 'user_id', $userId),
            'agent' => $this->rows('agent', 'user_id', $userId),
            'agent_account_grant' => $this->agentGrants('agent_account_grant', $agentIds),
            'agent_container_grant' => $this->agentGrants('agent_container_grant', $agentIds),
            'notification' => $this->notifications($userId),
            'notification_preference' => $this->rows('notification_preference', 'user_id', $userId),
            'help_feedback' => $this->rows('help_feedback', 'user_id', $userId),
            'not_included' => array_values((array) config('privacy.collection_not_included')),
        ];
    }

    /**
     * Personens rad med alla kolumner utom hemligheterna. Kolumnlistan läses
     * ur schemat, så en ny kolumn följer med utan en ändring här — och en
     * kolumn som slutar på `_hash` eller `_secret` stannar alltid kvar.
     *
     * @return array<string, mixed>|null
     */
    private function userRow(?object $row): ?array
    {
        if ($row === null) {
            return null;
        }

        $data = (array) $row;
        $ut = [];

        foreach (Schema::getColumnListing('user') as $column) {
            if ($this->isExcludedUserColumn($column)) {
                continue;
            }

            $ut[$column] = $data[$column] ?? null;
        }

        return $ut;
    }

    private function isExcludedUserColumn(string $column): bool
    {
        if (in_array($column, self::EXCLUDED_USER_COLUMNS, true)) {
            return true;
        }

        return str_ends_with($column, '_hash') || str_ends_with($column, '_secret');
    }

    /**
     * Personens konto och medlemskapet i dem. `account.registration_ip` tas
     * bara med när värdet finns — en nollställd adress ska inte se ut som en
     * uppgift vi fortfarande har.
     *
     * @return list<array<string, mixed>>
     */
    private function accounts(int $userId): array
    {
        $memberships = DB::table('account_user')
            ->where('user_id', $userId)
            ->orderBy('id')
            ->get();

        if ($memberships->isEmpty()) {
            return [];
        }

        $accounts = DB::table('account')
            ->whereIn('id', $memberships->pluck('account_id')->all())
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        return $memberships
            ->map(function (object $membership) use ($accounts): ?array {
                $account = $accounts->get($membership->account_id);

                if ($account === null) {
                    return null;
                }

                $row = (array) $account;

                if (($row['registration_ip'] ?? null) === null) {
                    unset($row['registration_ip']);
                }

                $row['account_user'] = [
                    'role' => $membership->role,
                    'created_at' => $membership->created_at,
                    'updated_at' => $membership->updated_at,
                ];

                return $row;
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Inloggningssessionerna: adressen, webbläsarsträngen och när sessionen
     * senast användes ([[Registerförteckning]] § Inloggningssessioner).
     *
     * @return list<array<string, mixed>>
     */
    private function sessions(int $userId): array
    {
        return DB::table('sessions')
            ->where('user_id', $userId)
            ->orderBy('last_activity')
            ->get(['ip_address', 'user_agent', 'last_activity'])
            ->map(fn (object $row): array => (array) $row)
            ->values()
            ->all();
    }

    /**
     * Säkerhetsloggens rader utan `ip_group` och utan den rättsliga
     * spärrens egna rader. `meta` följer med — det bär identifierare och
     * aldrig en adress (ADR-0043 § Säkerhetsloggen).
     *
     * @return list<array<string, mixed>>
     */
    private function securityLog(int $userId): array
    {
        return DB::table('security_log')
            ->where('user_id', $userId)
            ->where('action', 'not like', self::LEGAL_HOLD_ACTION_PREFIX.'%')
            ->orderBy('id')
            ->get()
            ->map(function (object $row): array {
                $data = (array) $row;

                unset($data['ip_group']);

                if (isset($data['meta']) && is_string($data['meta'])) {
                    $decoded = json_decode($data['meta'], true);

                    if (json_last_error() === JSON_ERROR_NONE) {
                        $data['meta'] = $decoded;
                    }
                }

                return $data;
            })
            ->values()
            ->all();
    }

    /**
     * Händelseloggens rader med personen som handlande. Bara handlingen,
     * tiden och agenten: `meta` och subjektets identifierare kan peka på
     * andra personers innehåll (art. 15.4).
     *
     * @return list<array<string, mixed>>
     */
    private function auditLog(int $userId): array
    {
        return DB::table('audit_log')
            ->where('user_id', $userId)
            ->orderBy('id')
            ->get(['action', 'created_at', 'agent_id'])
            ->map(fn (object $row): array => (array) $row)
            ->values()
            ->all();
    }

    /**
     * Det begärda adressbytet: den nya adressen och tidpunkterna. Aldrig
     * `token_hash`.
     *
     * @return list<array<string, mixed>>
     */
    private function emailChange(int $userId): array
    {
        return $this->changeRows(
            'email_change',
            $userId,
            ['new_email', 'expires_at', 'confirmed_at', 'created_at', 'updated_at'],
        );
    }

    /**
     * Det begärda lösenordsbytet: bara tidpunkterna. Aldrig lösenordets
     * hash eller `token_hash`.
     *
     * @return list<array<string, mixed>>
     */
    private function passwordChange(int $userId): array
    {
        return $this->changeRows(
            'password_change',
            $userId,
            ['expires_at', 'confirmed_at', 'created_at', 'updated_at'],
        );
    }

    /**
     * Den begärda personraderingen: bara tidpunkterna. Aldrig `token_hash`.
     *
     * @return list<array<string, mixed>>
     */
    private function userDeletion(int $userId): array
    {
        return $this->changeRows(
            'user_deletion',
            $userId,
            ['expires_at', 'confirmed_at', 'created_at', 'updated_at'],
        );
    }

    /**
     * @param  list<string>  $columns
     * @return list<array<string, mixed>>
     */
    private function changeRows(string $table, int $userId, array $columns): array
    {
        return DB::table($table)
            ->where('user_id', $userId)
            ->orderBy('id')
            ->get($columns)
            ->map(fn (object $row): array => (array) $row)
            ->values()
            ->all();
    }

    /**
     * En tabell vars rader är personens egna, med alla kolumner.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $table, string $column, int $userId): array
    {
        return DB::table($table)
            ->where($column, $userId)
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->values()
            ->all();
    }

    /**
     * Agenternas rättigheter. Tillhör agenten, som i sin tur är personens:
     * raderna nås genom agentens id, aldrig genom en annan persons.
     *
     * @param  list<int>  $agentIds
     * @return list<array<string, mixed>>
     */
    private function agentGrants(string $table, array $agentIds): array
    {
        if ($agentIds === []) {
            return [];
        }

        return DB::table($table)
            ->whereIn('agent_id', $agentIds)
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->values()
            ->all();
    }

    /**
     * Notiserna: typen, tidpunkterna och leveransernas kanal och
     * läst-tidpunkt. **Aldrig `payload`** — notisens innehåll kan peka på
     * andra personers uppgifter, samma skäl som `audit_log.meta`.
     *
     * @return list<array<string, mixed>>
     */
    private function notifications(int $userId): array
    {
        $notifications = DB::table('notification')
            ->where('user_id', $userId)
            ->orderBy('id')
            ->get();

        if ($notifications->isEmpty()) {
            return [];
        }

        /** @var array<int, list<array<string, mixed>>> $leveranser */
        $leveranser = [];

        $rader = DB::table('notification_delivery')
            ->whereIn('notification_id', $notifications->pluck('id')->all())
            ->orderBy('id')
            ->get();

        foreach ($rader as $rad) {
            $leveranser[(int) $rad->notification_id][] = [
                'channel' => $rad->channel,
                'sent_at' => $rad->sent_at,
                'read_at' => $rad->read_at,
            ];
        }

        /** @var list<array<string, mixed>> $ut */
        $ut = [];

        foreach ($notifications as $notification) {
            $ut[] = [
                'type' => $notification->type,
                'available_at' => $notification->available_at,
                'created_at' => $notification->created_at,
                'updated_at' => $notification->updated_at,
                'deliveries' => $leveranser[(int) $notification->id] ?? [],
            ];
        }

        return $ut;
    }
}
