<?php

namespace App\Actions\Privacy;

use App\Models\LegalHold;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Personens uppgifter ur databasen, som `account.json` i kontoexporten och
 * som filen från `privacy:collect` (issue 1701 · [[ADR-0098 Kontoexporten]]
 * § 3). Utbruten ur CollectPrivacyData::collect() utan ändrat beteende:
 * ärendet, verifieringen och bilagan stannar i CollectPrivacyData, den här
 * klassen vet ingenting om dem.
 *
 * **Regeln för den rättsliga spärren följer med oförändrad.** En
 * tystnadsbelagd spärr och dess rader i säkerhetsloggen utelämnas alltid
 * ([[ADR-0043 Tre loggar]] § Den rättsliga spärren); övriga spärrar tas med
 * utan `reason`. `not_included` står kvar i nyckellistan — kontoexporten
 * lägger själv till meningen om `/contact`.
 */
class CollectPersonData
{
    /**
     * Prefixet på den rättsliga spärrens rader i säkerhetsloggen. De har
     * ingen användare och nås därför via kontot.
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

    /**
     * De system som återstår för handläggaren att hämta för hand. Listan är
     * uttrycklig och valideras mot configen, så att ett nytt system i
     * `privacy.systems` aldrig tyst hamnar här och `database` — det som
     * sammanställningen tar med — aldrig kan göra det (ADR-0095 § 9 och § 12).
     */
    private const NOT_INCLUDED = [
        'user_content',
        'sentry',
        'mailgun',
        'backups',
        'server_logs',
    ];

    /**
     * Löpnummer som aldrig följer med i filen (AGENTS.md
     * § Databaskonventioner). Den egna raden identifieras av sin `ulid` där
     * tabellen har en; ett främmande `*_id` som är meningsfullt för personen
     * ersätts i stället, se REFERENCE_COLUMNS.
     */
    private const SERIAL_COLUMNS = [
        'dismissed_tip' => ['id', 'user_id'],
        'overview_panel' => ['id', 'user_id'],
        'recent_visit' => ['id', 'user_id'],
        'attachment_open' => ['id', 'user_id'],
        'agent' => ['id', 'user_id'],
        'agent_account_grant' => ['id'],
        'agent_container_grant' => ['id'],
        'notification_preference' => ['id', 'user_id'],
        'help_feedback' => ['id', 'user_id'],
    ];

    /**
     * Främmande nycklar som ersätts av den refererade radens `ulid` under ett
     * namn utan `_id`: [kolumn => [tabell, nyckelnamn]].
     */
    private const REFERENCE_COLUMNS = [
        'recent_visit' => ['item_id' => ['item', 'item']],
        'attachment_open' => ['attachment_id' => ['attachment', 'attachment']],
        'agent' => ['account_id' => ['account', 'account']],
        'agent_account_grant' => ['agent_id' => ['agent', 'agent']],
        'agent_container_grant' => [
            'agent_id' => ['agent', 'agent'],
            'container_id' => ['container', 'container'],
        ],
    ];

    /**
     * Tabeller som har `ulid`, cachade: frågan ställs en gång per tabell och
     * en referens till en tabell utan `ulid` utelämnas helt.
     *
     * @var array<string, bool>
     */
    private static array $ulidTables = [];

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
     * Hela sammanställningen. Ordningen är Registerförteckningens och
     * `not_included` står sist.
     *
     * @return array<string, mixed>
     */
    public function collect(int $userId): array
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
            'legal_hold' => $this->legalHolds($userId),
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
            'not_included' => $this->notIncluded(),
        ];
    }

    /**
     * Listan `not_included`: exakt de fem systemen, oavsett vad configen
     * innehåller utöver dem.
     *
     * @return list<string>
     */
    private function notIncluded(): array
    {
        /** @var list<string> $konfigurerade */
        $konfigurerade = array_values((array) config('privacy.collection_not_included'));

        foreach (self::NOT_INCLUDED as $system) {
            if (! in_array($system, $konfigurerade, true)) {
                throw new InvalidArgumentException(
                    "Systemet {$system} saknas i privacy.collection_not_included."
                );
            }
        }

        return self::NOT_INCLUDED;
    }

    /**
     * Personens rad med alla kolumner utom hemligheterna och löpnumren.
     * Kolumnlistan läses ur schemat, så en ny kolumn följer med utan en
     * ändring här — och en kolumn som slutar på `_hash` eller `_secret`
     * stannar alltid kvar.
     *
     * `id` tas bort: raden identifieras av sin `ulid` (AGENTS.md
     * § Databaskonventioner). `avatar_attachment_id` och
     * `hero_attachment_id` är löpnummer till personens egen bilaga och
     * ersätts av bilagans `ulid`.
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

            $varde = $data[$column] ?? null;

            if ($column === 'avatar_attachment_id' || $column === 'hero_attachment_id') {
                $ut[substr($column, 0, -3)] = $this->referencedUlid('attachment', $varde);

                continue;
            }

            if ($column === 'id' || str_ends_with($column, '_id')) {
                continue;
            }

            $ut[$column] = $varde;
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

                // Kontot identifieras av sin `ulid`; löpnumret följer aldrig
                // med (AGENTS.md § Databaskonventioner).
                unset($row['id']);

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
     * Kontots spärrar som inte är tystnadsbelagda: ärendenumret och
     * tidpunkterna, aldrig `reason`.
     *
     * @return list<array<string, mixed>>
     */
    private function legalHolds(int $userId): array
    {
        $accountIds = $this->accountIds($userId);

        if ($accountIds === []) {
            return [];
        }

        return LegalHold::query()
            ->whereIn('account_id', $accountIds)
            ->where('confidential', false)
            ->orderBy('id')
            ->get()
            ->map(fn (LegalHold $hold): array => [
                'account' => $this->referencedUlid('account', $hold->account_id),
                'case_number' => $hold->case_number,
                'created_at' => $hold->getRawOriginal('created_at'),
                'lifted_at' => $hold->getRawOriginal('lifted_at'),
            ])
            ->values()
            ->all();
    }

    /**
     * Säkerhetsloggens rader utan `ip_group`. `meta` följer med — det bär
     * identifierare och aldrig en adress (ADR-0043 § Säkerhetsloggen).
     *
     * Den rättsliga spärrens rader har ingen användare och hämtas via
     * personens konton. Rader vars `meta.case_number` hör till en
     * tystnadsbelagd spärr på samma konto utelämnas, och likaså varje
     * spärrrad (`legal_hold.*`) utan ärendenummer på ett konto med en
     * tystnadsbelagd spärr, eftersom `legal_hold.lifted` saknar ärendenummer.
     *
     * @return list<array<string, mixed>>
     */
    private function securityLog(int $userId): array
    {
        $accountIds = $this->accountIds($userId);

        /** @var array<int, list<string>> $tystnad */
        $tystnad = [];

        if ($accountIds !== []) {
            foreach (LegalHold::query()->whereIn('account_id', $accountIds)->where('confidential', true)->get() as $hold) {
                $tystnad[(int) $hold->account_id][] = mb_strtolower(trim((string) $hold->case_number));
            }
        }

        return DB::table('security_log')
            ->where(function ($q) use ($userId, $accountIds): void {
                $q->where('user_id', $userId);

                if ($accountIds !== []) {
                    $q->orWhere(function ($q2) use ($accountIds): void {
                        $q2->whereIn('account_id', $accountIds)
                            ->where('action', 'like', self::LEGAL_HOLD_ACTION_PREFIX.'%');
                    });
                }
            })
            ->orderBy('id')
            ->get()
            ->map(function (object $row): array {
                $data = (array) $row;
                $data['meta'] = $this->decodeMeta($data['meta'] ?? null);

                return $data;
            })
            ->reject(function (array $data) use ($tystnad): bool {
                $meta = $data['meta'];
                $case = is_array($meta) ? ($meta['case_number'] ?? null) : null;
                $kontoTystnad = $tystnad[(int) ($data['account_id'] ?? 0)] ?? [];

                // Raden `legal_hold.lifted` bär inget ärendenummer och går
                // inte att koppla till en spärr: har kontot en tystnadsbelagd
                // spärr, även hävd, utelämnas varje spärrrad utan ärendenummer.
                if ($kontoTystnad !== [] && ! is_string($case)
                    && str_starts_with((string) ($data['action'] ?? ''), self::LEGAL_HOLD_ACTION_PREFIX)) {
                    return true;
                }

                return is_string($case)
                    && in_array(mb_strtolower(trim($case)), $tystnad[(int) ($data['account_id'] ?? 0)] ?? [], true);
            })
            ->map(function (array $data): array {
                $accountId = $data['account_id'] ?? null;

                // Löpnumren stannar hos oss; kontot identifieras av sin
                // `ulid` (AGENTS.md § Databaskonventioner). Tabellen har
                // ingen egen `ulid`.
                unset($data['id'], $data['account_id'], $data['user_id'], $data['ip_group']);

                $data['account'] = $this->referencedUlid('account', $accountId);

                return $data;
            })
            ->values()
            ->all();
    }

    private function decodeMeta(mixed $meta): mixed
    {
        if (is_string($meta)) {
            $decoded = json_decode($meta, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return $meta;
    }

    /**
     * Händelseloggens rader med personen som handlande. Bara handlingen,
     * tiden och agenten: `meta` och subjektets identifierare kan peka på
     * andra personers innehåll (art. 15.4). Agentens löpnummer ersätts av
     * agentens `ulid` under `agent` (AGENTS.md § Databaskonventioner).
     *
     * @return list<array<string, mixed>>
     */
    private function auditLog(int $userId): array
    {
        return DB::table('audit_log')
            ->where('user_id', $userId)
            ->orderBy('id')
            ->get(['action', 'created_at', 'agent_id'])
            ->map(fn (object $row): array => [
                'action' => $row->action,
                'created_at' => $row->created_at,
                'agent' => $this->referencedUlid('agent', $row->agent_id),
            ])
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
     * En tabell vars rader är personens egna, med alla kolumner utom
     * löpnumren.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $table, string $column, int $userId): array
    {
        return DB::table($table)
            ->where($column, $userId)
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => $this->personRow($table, $row))
            ->values()
            ->all();
    }

    /**
     * En rad ur en tabell vars rader är personens egna: löpnummer bort, och
     * meningsfulla främmande nycklar kvar som den refererade radens `ulid`
     * under ett namn utan `_id` (AGENTS.md § Databaskonventioner).
     *
     * @return array<string, mixed>
     */
    private function personRow(string $table, object $row): array
    {
        $data = (array) $row;
        $referenser = self::REFERENCE_COLUMNS[$table] ?? [];
        $löpnummer = self::SERIAL_COLUMNS[$table] ?? ['id', 'user_id'];
        $ut = [];

        foreach ($data as $kolumn => $varde) {
            if (isset($referenser[$kolumn])) {
                [$refereradTabell, $namn] = $referenser[$kolumn];

                // Saknar den refererade tabellen `ulid` utelämnas
                // referensen helt.
                if ($this->hasUlid($refereradTabell)) {
                    $ut[$namn] = $this->referencedUlid($refereradTabell, $varde);
                }

                continue;
            }

            if (in_array($kolumn, $löpnummer, true)) {
                continue;
            }

            $ut[$kolumn] = $varde;
        }

        return $ut;
    }

    /**
     * Den refererade radens `ulid`, eller null när nyckeln är null eller
     * raden inte finns.
     */
    private function referencedUlid(string $table, mixed $id): ?string
    {
        if ($id === null) {
            return null;
        }

        $ulid = DB::table($table)->where('id', $id)->value('ulid');

        return is_string($ulid) ? $ulid : null;
    }

    /**
     * Har tabellen en `ulid`? Frågan cachas: den ställs en gång per tabell.
     */
    private function hasUlid(string $table): bool
    {
        return self::$ulidTables[$table] ??= Schema::hasColumn($table, 'ulid');
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
            ->map(fn (object $row): array => $this->personRow($table, $row))
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
