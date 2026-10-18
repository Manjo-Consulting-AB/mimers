<?php

namespace App\Actions\Trash;

use App\Actions\Access\ResolveItemScope;
use App\Actions\Inbox\ResolveInbox;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\Item;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Den samlade papperskorgen: raderade items, bilagor och containrar över
 * allt användaren når, senast raderad först — se
 * [[ADR-0065 Den samlade papperskorgen]] § 2–5 och 7 och
 * [[M39 Den samlade papperskorgen]] § 318.
 *
 * **Urvalet följer de regler som redan gäller** (ADR-0065 § 3):
 *
 * - Items och bilagor väljs som i App\Actions\Trash\ListTrash, i varje
 *   levande container användaren når (`Container::scopeAccessibleBy()` +
 *   `Container::scopeListable()`) och i den egna inboxen via
 *   `ResolveInbox::existing()`, precis som
 *   App\Actions\Attachment\ListAccessibleAttachments. Inboxen slås upp och
 *   skapas aldrig: en läsning ska inte skriva en container (ADR-0054 § 1).
 * - Containrar väljs som i App\Actions\Trash\ListTrashedContainers: bara
 *   för ägarkontots medlemmar. En delegerad `container_access` räcker
 *   alltså inte.
 * - Omfånget löses för alla containrar i ETT anrop
 *   (`ResolveItemScope::forContainers()`), som på `/documents`. En
 *   omfångsbegränsad mottagare ser bara de items hon når och de bilagor
 *   som hänger på dem.
 * - Inget ur en raderad container listas för sig: containerns egna rader är
 *   de enda som når frågorna, eftersom en mjukraderad container inte längre
 *   matchar `accessibleBy()`.
 * - Utgånget innehåll finns inte (ADR-0065 § 3, issue 20a § Beslut 5):
 *   rader vars `deleted_at` är äldre än retentionen listas inte, även om
 *   gallringsjobbet ännu inte hunnit köra.
 * - Containerns egen bild är ingen bilaga: den har `item_id = NULL` och
 *   faller i den inre joinen mot `item` ([[ADR-0047 Containerns bild]]).
 *   Kategorier och taggar rörs inte alls — de hör till containerns egen
 *   papperskorg.
 *
 * **Ingen behörighetslogik bor här** ([[ADR-0024 Tunna controllers och
 * actions]], ListTrash § docblock). Urvalet är ett FILTER; grinden för
 * återställningen stannar i kontrollern. Actionen svarar på "vilka rader
 * finns", aldrig på "vem får återställa dem".
 *
 * **Tre typer i tre frågor, och sidan klipps i minnet.** Varje typ hämtar
 * högst 51 rader efter markören (50 plus en för att veta om det finns mer);
 * de tre mängderna sorteras samman på `deleted_at` fallande och ULID
 * stigande, och de första 50 blir sidan. Sorteringen är total: två rader
 * som raderades i samma sekund skiljs av ULID:n, så markören ger nästa sida
 * utan dubbletter och utan luckor. Antalet frågor växer inte med antalet
 * rader, containrar eller poster — se `testet för frågeantalet`.
 *
 * **Markören är opak.** Den bär sista radens `deleted_at` och ULID, kodade
 * som base64url så att anroparen aldrig behöver känna formen. En trasig
 * markör ger första sidan i stället för ett fel, av samma skäl som i
 * App\Actions\Schedule\ListTodo: den kommer ur ett adressfält någon
 * klistrat i.
 *
 * **`subjects` är den andra halvan av svaret**, som i ListTrash och
 * ListTrashedContainers: varje post bär sin laddade rad nycklad på ULID, så
 * att kontrollern kan pröva grinden per rad utan en ny fråga. Containerns
 * ägarkonto och itemets container sätts i minnet — `ItemPolicy` läser
 * `$item->container->account` och `ContainerPolicy::delete()` läser
 * `$container->account`, och en lat hämtning per rad är precis den N+1
 * fråga som listningen ska undvika. En bilagas `item`-relation byggs ur
 * den joinade itemraden, med `withTrashed()`-semantik: en bilaga vars item
 * ligger i papperskorgen har fortfarande ett item att pröva grinden mot
 * (TrashController § authorizeRestore).
 *
 * **Vem som raderade läses ur `audit_log`** (ADR-0065 § 5). Det är den
 * senaste händelsen `item.deleted`, `attachment.deleted` eller
 * `container.deleted` för radens subjekt; användarens namn, eller null när
 * händelsen saknas, användaren är raderad eller ett jobb orsakade den
 * ([[ADR-0043 Tre loggar]] § Konsekvenser). Uppslaget är EN fråga för hela
 * sidan och går på indexet `(subject_type, subject_id, created_at)` som
 * migrationen i samma issue lägger till.
 *
 * **Item-händelsen matchas även på `item_id`.** `DeleteItem` skriver
 * `item.deleted` med `item_id` men utan `subject_type`/`subject_id`
 * (App\Actions\Item\DeleteItem::handle()), till skillnad från
 * TrashAttachment och TrashContainer som sätter subjektet. Enbart
 * subject-uppslaget hade därför alltid gett null för items — också för rader
 * som redan låg i papperskorgen när den här Actionen skrevs. `item_id` är
 * samma identifierare som issue 107 la till för att en loggrad ska kunna
 * läsas upp per item.
 *
 * **`$user` är nollbar** därför att `Illuminate\Http\Request::user()` är
 * det. En saknad användare ger ett tomt svar, aldrig ett obegränsat — samma
 * linje som ListTrash och ListTrashedContainers.
 */
class ListAllTrash
{
    /** Antalet poster per sida — ADR-0065 § 7. */
    public const PER_PAGE = 50;

    private const TYPES = ['item', 'attachment', 'container'];

    private const DELETED_ACTIONS = [
        AuditLog::ACTION_ITEM_DELETED,
        AuditLog::ACTION_ATTACHMENT_DELETED,
        AuditLog::ACTION_CONTAINER_DELETED,
    ];

    public function __construct(
        private readonly ResolveItemScope $resolveItemScope,
        private readonly ResolveInbox $resolveInbox,
    ) {}

    /**
     * @return array{
     *     entries: list<array{
     *         type: string,
     *         ulid: string,
     *         label: string,
     *         icon: string|null,
     *         icon_color: string|null,
     *         container: array{ulid: string, name: string}|null,
     *         item: array{ulid: string, name: string}|null,
     *         deleted_at: Carbon|null,
     *         expires_at: Carbon|null,
     *         deleted_by: string|null,
     *     }>,
     *     next_cursor: string|null,
     *     subjects: array<string, Item|Attachment|Container>,
     * }
     */
    public function handle(?User $user, ?string $type, ?string $q, ?string $cursor): array
    {
        $type = in_array($type, self::TYPES, true) ? $type : null;

        $q = $q === null ? null : trim($q);
        $q = $q === '' ? null : $q;

        $decodedCursor = $this->decodeCursor($cursor);

        if ($user === null) {
            return ['entries' => [], 'next_cursor' => null, 'subjects' => []];
        }

        $retentionDays = (int) config('files.trash_retention_days');
        $cutoff = now()->subDays($retentionDays);

        [$containers, $unrestricted, $itemIds] = $this->reach($user);

        $candidates = [];

        if ($type === null || $type === 'item') {
            foreach ($this->items($unrestricted, $itemIds, $q, $decodedCursor, $cutoff) as $item) {
                $container = $containers[(int) $item->container_id] ?? null;

                if ($container !== null) {
                    $item->setRelation('container', $container);
                }

                $candidates[] = [
                    'type' => 'item',
                    'ulid' => $item->ulid,
                    'label' => $item->name,
                    'icon' => $item->icon,
                    'icon_color' => $item->icon_color,
                    'container' => $container === null
                        ? null
                        : ['ulid' => $container->ulid, 'name' => $container->name],
                    'item' => null,
                    'deleted_at' => $item->deleted_at,
                    'subject' => $item,
                ];
            }
        }

        if ($type === null || $type === 'attachment') {
            foreach ($this->attachments($unrestricted, $itemIds, $q, $decodedCursor, $cutoff) as $attachment) {
                $itemContainerId = (int) $attachment->getAttribute('item_container_id');
                $container = $containers[$itemContainerId] ?? null;

                // Bilagans item kan leva eller ligga i papperskorgen. Raden ur
                // joinen bär allt grinden behöver, så den byggs i minnet i
                // stället för att hämtas med en fråga per bilaga.
                $parent = (new Item)->forceFill([
                    'id' => (int) $attachment->item_id,
                    'ulid' => $attachment->getAttribute('item_ulid'),
                    'name' => $attachment->getAttribute('item_name'),
                    'container_id' => $itemContainerId,
                ]);

                if ($container !== null) {
                    $parent->setRelation('container', $container);
                }

                $attachment->setRelation('item', $parent);

                $candidates[] = [
                    'type' => 'attachment',
                    'ulid' => $attachment->ulid,
                    'label' => $attachment->filename,
                    'icon' => null,
                    'icon_color' => null,
                    'container' => $container === null
                        ? null
                        : ['ulid' => $container->ulid, 'name' => $container->name],
                    'item' => [
                        'ulid' => (string) $attachment->getAttribute('item_ulid'),
                        'name' => (string) $attachment->getAttribute('item_name'),
                    ],
                    'deleted_at' => $attachment->deleted_at,
                    'subject' => $attachment,
                ];
            }
        }

        if ($type === null || $type === 'container') {
            foreach ($this->trashedContainers($user, $q, $decodedCursor, $cutoff) as $container) {
                $candidates[] = [
                    'type' => 'container',
                    'ulid' => $container->ulid,
                    'label' => $container->name,
                    'icon' => $container->icon,
                    'icon_color' => $container->icon_color,
                    'container' => null,
                    'item' => null,
                    'deleted_at' => $container->deleted_at,
                    'subject' => $container,
                ];
            }
        }

        usort($candidates, static function (array $a, array $b): int {
            $byDeletedAt = $b['deleted_at']->getTimestamp() <=> $a['deleted_at']->getTimestamp();

            return $byDeletedAt !== 0 ? $byDeletedAt : strcmp($a['ulid'], $b['ulid']);
        });

        $page = array_slice($candidates, 0, self::PER_PAGE);
        $hasMore = count($candidates) > self::PER_PAGE;

        $deletedBy = $this->deletedBy($page);

        $entries = [];
        $subjects = [];

        foreach ($page as $candidate) {
            $core = ListTrash::entry(
                $candidate['type'],
                $candidate['ulid'],
                $candidate['label'],
                null,
                $candidate['deleted_at'],
                $retentionDays,
            );

            $entries[] = [
                'type' => $core['type'],
                'ulid' => $core['ulid'],
                'label' => $core['label'],
                'icon' => $candidate['icon'],
                'icon_color' => $candidate['icon_color'],
                'container' => $candidate['container'],
                'item' => $candidate['item'],
                'deleted_at' => $core['deleted_at'],
                'expires_at' => $core['expires_at'],
                'deleted_by' => $deletedBy[$candidate['type'].':'.$candidate['ulid']] ?? null,
            ];

            $subjects[$candidate['ulid']] = $candidate['subject'];
        }

        $last = $page === [] ? null : $page[count($page) - 1];

        return [
            'entries' => $entries,
            'next_cursor' => $hasMore && $last !== null
                ? $this->encodeCursor($last['deleted_at'], $last['ulid'])
                : null,
            'subjects' => $subjects,
        ];
    }

    /**
     * Den nådda mängden: containrarna användaren ser, den egna inboxen, och
     * omfånget delat i obegränsade containrar och begränsade item — samma
     * form som ListAccessibleAttachments::reach().
     *
     * @return array{0: array<int, Container>, 1: list<int>, 2: list<int>}
     */
    private function reach(User $user): array
    {
        $accountIds = $user->accounts->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        $containers = Container::query()
            ->accessibleBy($user, $accountIds)
            ->listable()
            ->with('account')
            ->get(['id', 'ulid', 'name', 'icon', 'icon_color', 'account_id']);

        $byId = [];

        foreach ($containers as $container) {
            $byId[(int) $container->getKey()] = $container;
        }

        $inbox = $this->resolveInbox->existing($user);

        if ($inbox !== null) {
            $inboxContainer = Container::query()
                ->with('account')
                ->find($inbox->container_id);

            if ($inboxContainer !== null) {
                $byId[(int) $inboxContainer->getKey()] = $inboxContainer;
            }
        }

        $unrestricted = [];
        $itemIds = [];

        if ($byId !== []) {
            foreach ($this->resolveItemScope->forContainers($user, array_keys($byId)) as $containerId => $scope) {
                $ids = $scope->itemIds();

                if ($ids === null) {
                    $unrestricted[] = (int) $containerId;
                } else {
                    $itemIds = [...$itemIds, ...$ids];
                }
            }
        }

        return [
            $byId,
            $unrestricted,
            array_values(array_unique(array_map('intval', $itemIds))),
        ];
    }

    /**
     * De raderade items användaren når, senast raderad först.
     *
     * Omfångsvillkoret skrivs som i ListAccessibleAttachments: obegränsat
     * omfång blir `container_id IN (…)` och begränsat blir `id IN (…)`; den
     * som når ingenting får `0 = 1` i stället för hela tabellen.
     *
     * @param  list<int>  $unrestricted
     * @param  list<int>  $itemIds
     * @param  array{deleted_at: string, ulid: string}|null  $cursor
     * @return Collection<int, Item>
     */
    private function items(array $unrestricted, array $itemIds, ?string $q, ?array $cursor, Carbon $cutoff): Collection
    {
        return Item::query()->onlyTrashed()
            ->where('item.deleted_at', '>=', $cutoff)
            ->where(function (Builder $query) use ($unrestricted, $itemIds): void {
                if ($unrestricted !== []) {
                    $query->whereIn('item.container_id', $unrestricted);
                }

                if ($itemIds !== []) {
                    $query->orWhereIn('item.id', $itemIds);
                }

                if ($unrestricted === [] && $itemIds === []) {
                    $query->whereRaw('0 = 1');
                }
            })
            ->when($q !== null, fn (Builder $query) => $query->where('item.name', 'like', '%'.$q.'%'))
            ->when($cursor !== null, fn (Builder $query) => $this->afterCursor($query, 'item', $cursor))
            ->orderByDesc('item.deleted_at')
            ->orderBy('item.ulid')
            ->limit(self::PER_PAGE + 1)
            ->get(['item.id', 'item.ulid', 'item.name', 'item.icon', 'item.icon_color', 'item.deleted_at', 'item.container_id']);
    }

    /**
     * De raderade bilagorna på items användaren når, senast raderad först.
     *
     * Den inre joinen mot `item` är samma led som i ListTrash: den håller
     * containerns egen bild (`item_id = NULL`) utanför, och den ser
     * mjukraderade items — en bilaga vars item ligger i papperskorgen hör
     * fortfarande till listan.
     *
     * @param  list<int>  $unrestricted
     * @param  list<int>  $itemIds
     * @param  array{deleted_at: string, ulid: string}|null  $cursor
     * @return Collection<int, Attachment>
     */
    private function attachments(array $unrestricted, array $itemIds, ?string $q, ?array $cursor, Carbon $cutoff): Collection
    {
        return Attachment::query()->onlyTrashed()
            ->join('item', 'item.id', '=', 'attachment.item_id')
            ->where('attachment.deleted_at', '>=', $cutoff)
            ->where(function (Builder $query) use ($unrestricted, $itemIds): void {
                if ($unrestricted !== []) {
                    $query->whereIn('item.container_id', $unrestricted);
                }

                if ($itemIds !== []) {
                    $query->orWhereIn('item.id', $itemIds);
                }

                if ($unrestricted === [] && $itemIds === []) {
                    $query->whereRaw('0 = 1');
                }
            })
            ->when($q !== null, fn (Builder $query) => $query->where('attachment.filename', 'like', '%'.$q.'%'))
            ->when($cursor !== null, fn (Builder $query) => $this->afterCursor($query, 'attachment', $cursor))
            ->orderByDesc('attachment.deleted_at')
            ->orderBy('attachment.ulid')
            ->limit(self::PER_PAGE + 1)
            ->get([
                'attachment.id',
                'attachment.ulid',
                'attachment.filename',
                'attachment.deleted_at',
                'attachment.item_id',
                'item.ulid as item_ulid',
                'item.name as item_name',
                'item.container_id as item_container_id',
            ]);
    }

    /**
     * De raderade containers vars ägarkonto användaren är medlem i, senast
     * raderad först — samma urval som ListTrashedContainers. En delegerad
     * `container_access` räcker alltså inte.
     *
     * @param  array{deleted_at: string, ulid: string}|null  $cursor
     * @return Collection<int, Container>
     */
    private function trashedContainers(User $user, ?string $q, ?array $cursor, Carbon $cutoff): Collection
    {
        return Container::query()->onlyTrashed()
            ->with('account')
            ->whereHas('account.users', fn (Builder $query) => $query->whereKey($user->id))
            ->where('container.deleted_at', '>=', $cutoff)
            ->when($q !== null, fn (Builder $query) => $query->where('container.name', 'like', '%'.$q.'%'))
            ->when($cursor !== null, fn (Builder $query) => $this->afterCursor($query, 'container', $cursor))
            ->orderByDesc('container.deleted_at')
            ->orderBy('container.ulid')
            ->limit(self::PER_PAGE + 1)
            ->get([
                'container.id',
                'container.ulid',
                'container.name',
                'container.icon',
                'container.icon_color',
                'container.deleted_at',
                'container.account_id',
                // ContainerPolicy::delete() prövar isInbox() först.
                'container.inbox_user_id',
            ]);
    }

    /**
     * Markörens villkor: strikt efter raden i sorteringen
     * (`deleted_at` fallande, ULID stigande).
     *
     * @param  array{deleted_at: string, ulid: string}  $cursor
     */
    private function afterCursor(Builder $query, string $table, array $cursor): void
    {
        $query->where(function (Builder $query) use ($table, $cursor): void {
            $query->where($table.'.deleted_at', '<', $cursor['deleted_at'])
                ->orWhere(function (Builder $query) use ($table, $cursor): void {
                    $query->where($table.'.deleted_at', '=', $cursor['deleted_at'])
                        ->where($table.'.ulid', '>', $cursor['ulid']);
                });
        });
    }

    /**
     * Vem som raderade varje rad på sidan, i EN fråga.
     *
     * Den senaste händelsen vinner. En händelse utan användare — ett jobb,
     * eller en användare som raderats — ger null, precis som
     * App\Actions\Audit\PresentAuditEvents gör för *a former user*.
     * `item.deleted` matchas även på `item_id`, se klassens docblock.
     *
     * @param  list<array{type: string, ulid: string, subject: Item|Attachment|Container}>  $rows
     * @return array<string, string|null> nyckel = `{type}:{ulid}`
     */
    private function deletedBy(array $rows): array
    {
        $ulids = ['item' => [], 'attachment' => [], 'container' => []];
        $itemIdToUlid = [];

        foreach ($rows as $row) {
            $ulids[$row['type']][] = $row['ulid'];

            if ($row['type'] === 'item') {
                $itemIdToUlid[(int) $row['subject']->getKey()] = $row['ulid'];
            }
        }

        if ($ulids['item'] === [] && $ulids['attachment'] === [] && $ulids['container'] === []) {
            return [];
        }

        $logs = AuditLog::query()
            ->leftJoin('user', 'user.id', '=', 'audit_log.user_id')
            ->whereIn('audit_log.action', self::DELETED_ACTIONS)
            ->where(function (Builder $query) use ($ulids, $itemIdToUlid): void {
                if ($ulids['item'] !== []) {
                    $query->orWhere(fn (Builder $query) => $query
                        ->where('audit_log.subject_type', 'item')
                        ->whereIn('audit_log.subject_id', $ulids['item']));

                    if ($itemIdToUlid !== []) {
                        $query->orWhere(fn (Builder $query) => $query
                            ->where('audit_log.action', AuditLog::ACTION_ITEM_DELETED)
                            ->whereIn('audit_log.item_id', array_keys($itemIdToUlid)));
                    }
                }

                if ($ulids['attachment'] !== []) {
                    $query->orWhere(fn (Builder $query) => $query
                        ->where('audit_log.subject_type', 'attachment')
                        ->whereIn('audit_log.subject_id', $ulids['attachment']));
                }

                if ($ulids['container'] !== []) {
                    $query->orWhere(fn (Builder $query) => $query
                        ->where('audit_log.subject_type', 'container')
                        ->whereIn('audit_log.subject_id', $ulids['container']));
                }
            })
            ->orderByDesc('audit_log.created_at')
            ->orderByDesc('audit_log.id')
            ->get([
                'audit_log.id',
                'audit_log.action',
                'audit_log.subject_type',
                'audit_log.subject_id',
                'audit_log.item_id',
                'audit_log.created_at',
                'user.name as user_name',
            ]);

        $deletedBy = [];

        foreach ($logs as $log) {
            $type = $log->subject_type;
            $ulid = $log->subject_id;

            if (($type === null || $ulid === null) && $log->action === AuditLog::ACTION_ITEM_DELETED) {
                $type = 'item';
                $ulid = $itemIdToUlid[(int) $log->item_id] ?? null;
            }

            if ($type === null || $ulid === null) {
                continue;
            }

            $key = $type.':'.$ulid;

            if (array_key_exists($key, $deletedBy)) {
                continue;
            }

            $deletedBy[$key] = $log->getAttribute('user_name');
        }

        return $deletedBy;
    }

    private function encodeCursor(Carbon $deletedAt, string $ulid): string
    {
        return rtrim(strtr(base64_encode($deletedAt->toDateTimeString().'|'.$ulid), '+/', '-_'), '=');
    }

    /**
     * Markören ur querysträngen. En trasig markör ger null — första sidan —
     * i stället för ett fel (samma linje som ListTodo).
     *
     * @return array{deleted_at: string, ulid: string}|null
     */
    private function decodeCursor(?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);

        if ($decoded === false) {
            return null;
        }

        $parts = explode('|', $decoded);

        if (count($parts) !== 2) {
            return null;
        }

        [$deletedAt, $ulid] = $parts;

        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $deletedAt) !== 1
            || preg_match('/^[0-9A-Za-z]{26}$/', $ulid) !== 1) {
            return null;
        }

        return ['deleted_at' => $deletedAt, 'ulid' => $ulid];
    }
}
