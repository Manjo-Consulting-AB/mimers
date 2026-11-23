<?php

namespace App\Actions\Storage;

use App\Actions\Plan\ReadPlanUsage;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\Item;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Vad kontots lagringsutrymme används till — issue 459 · M79.
 *
 * **Filer och text kommer ur ReadPlanUsage**, aldrig ur en egen SUM: kontots
 * tal är `usage_counter` ([[Planer och kvoter]] § usage_counter), och en andra
 * summering vore en andra sanning. Bara uppdelningen per container, item och
 * fil summerar bilagor.
 *
 * **Urvalet av bilagor är fillistans** på /settings/storage: kontots levande
 * bilagor via `billed_account_id`, utan personbilagor, med de som ligger i
 * papperskorgen medräknade och markerade. Frågan bor i `attachmentsOf()` och
 * bara där — StorageController::index() bygger sin lista på den.
 *
 * Containrar grupperas på `item.container_id`, respektive
 * `attachment.container_id` för containerns bild. Ett item räknas bara med
 * sina egna bilagor.
 */
class ReadStorageBreakdown
{
    public function __construct(private readonly ReadPlanUsage $readPlanUsage) {}

    /**
     * Kontots bilagor, joinade mot `stored_file` men utan sortering och urval
     * av relationer. Fillistan och uppdelningen bygger båda på den här.
     *
     * @return Builder<Attachment>
     */
    public static function attachmentsOf(Account $account): Builder
    {
        return Attachment::query()
            ->select('attachment.*')
            ->where('attachment.billed_account_id', $account->id)
            // En personbilaga är profilbilden (issue 379 · [[ADR-0076
            // Profilbilden]] § 5): den listas inte, men räknas i kvoten genom
            // `usage_counter`.
            ->whereNull('attachment.user_id')
            ->join('stored_file', 'stored_file.id', '=', 'attachment.stored_file_id');
    }

    /**
     * @return array{
     *     fileBytes: int,
     *     textBytes: int,
     *     containers: list<array{ulid: string, name: string, bytes: int}>,
     *     items: list<array{ulid: string, name: string, container: array{ulid: string, name: string}|null, bytes: int}>,
     *     files: list<array{ulid: string, filename: string, byte_size: int, container: array{ulid: string, name: string}|null, item: array{ulid: string, name: string}|null, inTrash: bool}>
     * }
     */
    public function handle(Account $account, int $limit = 5): array
    {
        $storage = $this->readPlanUsage->handle($account)['usage']['storage'];

        return [
            'fileBytes' => (int) $storage['fileBytes'],
            'textBytes' => (int) $storage['textBytes'],
            'containers' => $this->containers($account, $limit),
            'items' => $this->items($account, $limit),
            'files' => $this->files($account, $limit),
        ];
    }

    /**
     * @return list<array{ulid: string, name: string, bytes: int}>
     */
    private function containers(Account $account, int $limit): array
    {
        $rows = self::attachmentsOf($account)
            ->leftJoin('item', 'item.id', '=', 'attachment.item_id')
            ->reorder()
            ->select(DB::raw('COALESCE(item.container_id, attachment.container_id) as group_id'))
            ->selectRaw('SUM(stored_file.byte_size) as bytes')
            ->groupBy('group_id')
            ->havingRaw('group_id IS NOT NULL')
            ->orderByDesc('bytes')
            ->orderByDesc('group_id')
            ->limit($limit)
            ->toBase()
            ->get();

        $containers = Container::withTrashed()
            ->whereIn('id', $rows->pluck('group_id'))
            ->get(['id', 'ulid', 'name'])
            ->keyBy('id');

        return $rows
            ->filter(fn (object $row): bool => $containers->has($row->group_id))
            ->map(fn (object $row): array => [
                'ulid' => $containers[$row->group_id]->ulid,
                'name' => $containers[$row->group_id]->name,
                'bytes' => (int) $row->bytes,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{ulid: string, name: string, container: array{ulid: string, name: string}|null, bytes: int}>
     */
    private function items(Account $account, int $limit): array
    {
        $rows = self::attachmentsOf($account)
            ->whereNotNull('attachment.item_id')
            ->reorder()
            ->select('attachment.item_id')
            ->selectRaw('SUM(stored_file.byte_size) as bytes')
            ->groupBy('attachment.item_id')
            ->orderByDesc('bytes')
            ->orderByDesc('attachment.item_id')
            ->limit($limit)
            ->toBase()
            ->get();

        $items = Item::withTrashed()
            ->with(['container' => fn ($query) => $query->withTrashed()])
            ->whereIn('id', $rows->pluck('item_id'))
            ->get()
            ->keyBy('id');

        return $rows
            ->filter(fn (object $row): bool => $items->has($row->item_id))
            ->map(fn (object $row): array => [
                'ulid' => $items[$row->item_id]->ulid,
                'name' => $items[$row->item_id]->name,
                'container' => $items[$row->item_id]->container === null ? null : [
                    'ulid' => $items[$row->item_id]->container->ulid,
                    'name' => $items[$row->item_id]->container->name,
                ],
                'bytes' => (int) $row->bytes,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{ulid: string, filename: string, byte_size: int, container: array{ulid: string, name: string}|null, item: array{ulid: string, name: string}|null, inTrash: bool}>
     */
    private function files(Account $account, int $limit): array
    {
        return self::attachmentsOf($account)
            ->with([
                'storedFile',
                'item' => fn ($query) => $query->withTrashed()->with([
                    'container' => fn ($query) => $query->withTrashed(),
                ]),
                'container' => fn ($query) => $query->withTrashed(),
            ])
            ->orderByDesc('stored_file.byte_size')
            ->orderByDesc('attachment.id')
            ->limit($limit)
            ->get()
            ->map(function (Attachment $attachment): array {
                $container = $attachment->owningContainer();

                return [
                    'ulid' => $attachment->ulid,
                    'filename' => $attachment->filename,
                    'byte_size' => (int) $attachment->storedFile->byte_size,
                    'container' => $container === null ? null : [
                        'ulid' => $container->ulid,
                        'name' => $container->name,
                    ],
                    'item' => $attachment->item === null ? null : [
                        'ulid' => $attachment->item->ulid,
                        'name' => $attachment->item->name,
                    ],
                    // Samma regel som fillistan: ägaren eller containern ligger
                    // i papperskorgen.
                    'inTrash' => $attachment->accessSubject()?->trashed() === true
                        || $container?->trashed() === true,
                ];
            })
            ->all();
    }
}
