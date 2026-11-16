<?php

namespace App\Console;

use App\Actions\Access\ReleaseOrphanedBilling;
use App\Models\Account;
use App\Models\Container;
use App\Support\Usage\CountedRows;
use App\Support\Usage\MetadataSize;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Nattligt svep för betalarbytet i [[ADR-0081 All lagrad data räknas mot
 * kvoten]] § 4 (issue 1305). En åtkomst som gått ut återkallas aldrig, så
 * RevokeContainerAccess ser den inte; här hittas varje par (betalare,
 * container) där betalaren inte äger containern och saknar giltig åtkomst,
 * och det betalaren betalar för där flyttas till ägaren. Jobbet fångar också
 * sådant återkallelsen missat.
 *
 * Paren hämtas ur bilagorna och de räknade raderna; själva prövningen och
 * flytten är App\Actions\Access\ReleaseOrphanedBilling, en transaktion per
 * par. Ingen kvotprövning, ingenting raderas. Loggar `billing.transferred`
 * med ulid:er och antal byte, aldrig namn.
 *
 * Schemaläggs med `Schedule::call`, före avstämningen — se routes/console.php.
 */
class TransfersOrphanedBilling
{
    public function __construct(private readonly ReleaseOrphanedBilling $release) {}

    /**
     * @return int Antal par som flyttades.
     */
    public function handle(): int
    {
        $moved = 0;

        foreach ($this->pairs() as $containerId => $payerIds) {
            $container = Container::withTrashed()->find($containerId);

            if ($container === null) {
                continue;
            }

            foreach (array_keys($payerIds) as $payerId) {
                $account = Account::query()->find($payerId);

                if ($account === null) {
                    continue;
                }

                DB::transaction(function () use ($account, $container, &$moved): void {
                    // Lås containerraden så en samtidig GrantContainerAccess
                    // inte kan skapa åtkomst mellan kontrollen och flytten.
                    Container::withTrashed()->whereKey($container->id)->lockForUpdate()->first();

                    $before = $this->billedBytes($account->id, $container->id);

                    if (! $this->release->handle($account, $container)) {
                        return;
                    }

                    $moved++;

                    Log::info('billing.transferred', [
                        'account' => $account->ulid,
                        'container' => $container->ulid,
                        'bytes' => $before,
                    ]);
                });
            }
        }

        return $moved;
    }

    /**
     * Betalare som inte äger containern, per container.
     *
     * @return array<int, array<int, true>> container-id => [betalar-id => true]
     */
    private function pairs(): array
    {
        $pairs = [];

        $attachments = DB::table('attachment')
            ->leftJoin('item', 'item.id', '=', 'attachment.item_id')
            ->join('container', 'container.id', '=', DB::raw('coalesce(item.container_id, attachment.container_id)'))
            ->whereNull('attachment.user_id')
            ->whereColumn('attachment.billed_account_id', '!=', 'container.account_id')
            ->select(['container.id as container_id', 'attachment.billed_account_id as payer_id'])
            ->distinct()
            ->get();

        foreach ($attachments as $row) {
            $pairs[(int) $row->container_id][(int) $row->payer_id] = true;
        }

        foreach (array_keys(MetadataSize::COLUMNS) as $table) {
            if ($table === 'container') {
                continue;
            }

            CountedRows::query($table)
                ->whereNotNull("{$table}.billed_account_id")
                ->whereColumn("{$table}.billed_account_id", '!=', 'container.account_id')
                ->select(['container.id as container_id', "{$table}.billed_account_id as payer_id"])
                ->distinct()
                ->get()
                ->each(function ($row) use (&$pairs): void {
                    $pairs[(int) $row->container_id][(int) $row->payer_id] = true;
                });
        }

        return $pairs;
    }

    /** Byte kontot betalar för i containern: filer plus text. */
    private function billedBytes(int $accountId, int $containerId): int
    {
        $bytes = (int) DB::table('attachment')
            ->leftJoin('item', 'item.id', '=', 'attachment.item_id')
            ->join('stored_file', 'stored_file.id', '=', 'attachment.stored_file_id')
            ->whereRaw('coalesce(item.container_id, attachment.container_id) = ?', [$containerId])
            ->where('attachment.billed_account_id', $accountId)
            ->whereNull('attachment.user_id')
            ->sum('stored_file.byte_size');

        foreach (array_keys(MetadataSize::COLUMNS) as $table) {
            if ($table === 'container') {
                continue;
            }

            CountedRows::query($table)
                ->where('container.id', $containerId)
                ->where("{$table}.billed_account_id", $accountId)
                ->get()
                ->each(function ($row) use ($table, &$bytes): void {
                    $bytes += MetadataSize::ofAttributes($table, (array) $row);
                });
        }

        return $bytes;
    }
}
