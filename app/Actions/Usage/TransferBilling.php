<?php

namespace App\Actions\Usage;

use App\Models\Account;
use App\Models\Container;
use App\Support\Usage\CountedRows;
use App\Support\Usage\MetadataSize;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Flyttar betalaransvaret för det ett konto lagt i ANDRA kontons containrar
 * till containrarnas ägare ([[ADR-0081 All lagrad data räknas mot kvoten]]
 * § 4 och § 6a, [[ADR-0045 Radera konto och person]] § Beslut 1).
 *
 * Två anropare delar koden: `DeleteAccount` (kontot försvinner) och
 * `EnforcesDowngrades` (steg 4 i nedgraderingen, issue 1304). Innehållet
 * raderas aldrig för att betalaren försvann — det byter betalare, och bytena
 * flyttar mellan räknarna i samma transaktion, som AcceptOwnershipTransfer.
 *
 * INGEN KVOTPRÖVNING. Ägarkontot har inte valt innehållet och ska inte förlora
 * det för att en gäst försvann; hamnar det över sin gräns nekas nya skrivningar
 * som växer, ingen nedgradering startar. Entitlements anropas därför inte.
 *
 * Anroparen står i en transaktion.
 *
 * Personbilagor (profilbilder, `user_id` satt) har ingen container att följa
 * och rörs aldrig här.
 */
class TransferBilling
{
    /**
     * Bilagor och räknade rader `$account` betalar för i containrar kontot
     * inte äger får containerns ägare som betalare.
     *
     * Ingen soft-delete-scope: en slängd bilaga räknas i `storage_bytes` tills
     * den raderas fysiskt (ADR-0009 uppföljning 2026-11-11) och bär en
     * RESTRICT-nyckel mot kontot precis som en levande.
     */
    public function handle(Account $account): void
    {
        $this->moveAttachments($account->id, $this->foreignAttachments($account->id));

        CountedRows::moveToContainerOwner($account->id);
    }

    /**
     * Samma flytt för EN container (ADR-0081 § 4, issue 1305): kontot har
     * förlorat sin sista giltiga åtkomst dit, så bilagorna och raderna det
     * betalar för i just den containern får ägaren som betalare. Ingen
     * kvotprövning, ingenting raderas. Är kontot själv ägare händer inget.
     */
    public function forContainer(Account $account, Container $container): void
    {
        if ($container->account_id === $account->id) {
            return;
        }

        $this->moveAttachments(
            $account->id,
            $this->foreignAttachments($account->id)->where('container.id', $container->id),
        );

        CountedRows::moveContainerPayer($container->id, $account->id, $container->account_id);
    }

    /**
     * Flyttar bilagorna i `$query` från `$accountId` till respektive
     * containers ägare, med bytena.
     */
    private function moveAttachments(int $accountId, Builder $query): void
    {
        $rows = $query->get([
            'attachment.id',
            'container.account_id as owner_id',
            'stored_file.byte_size',
        ]);

        foreach ($rows->groupBy('owner_id') as $ownerId => $group) {
            $ownerId = (int) $ownerId;
            $bytes = (int) $group->sum('byte_size');

            if ($bytes > 0) {
                (new AdjustUsage)->handle($accountId, bytesDelta: -$bytes);
                (new AdjustUsage)->handle($ownerId, bytesDelta: +$bytes);
            }

            DB::table('attachment')
                ->whereIn('id', $group->pluck('id'))
                ->update([
                    'billed_account_id' => $ownerId,
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * Vad kontot betalar för i varje container det inte äger — filer och text
     * tillsammans. Underlaget för notisen till ägarna (steg 1).
     *
     * @return array<int, array{owner_id: int, bytes: int}> Per container-id.
     */
    public function foreignContainers(Account $account): array
    {
        /** @var array<int, array{owner_id: int, bytes: int}> $result */
        $result = [];

        $this->foreignAttachments($account->id)
            ->select(['container.id as container_id', 'container.account_id as owner_id', 'stored_file.byte_size'])
            ->get()
            ->each(function ($row) use (&$result): void {
                $id = (int) $row->container_id;
                $result[$id] ??= ['owner_id' => (int) $row->owner_id, 'bytes' => 0];
                $result[$id]['bytes'] += (int) $row->byte_size;
            });

        foreach (array_keys(MetadataSize::COLUMNS) as $table) {
            if ($table === 'container') {
                continue;
            }

            CountedRows::query($table)
                ->where("{$table}.billed_account_id", $account->id)
                ->where('container.account_id', '!=', $account->id)
                ->chunkById(500, function ($rows) use ($table, &$result): void {
                    foreach ($rows as $row) {
                        $id = (int) $row->container_id;
                        $result[$id] ??= ['owner_id' => (int) $row->owner_id, 'bytes' => 0];
                        $result[$id]['bytes'] += MetadataSize::ofAttributes($table, (array) $row);
                    }
                }, "{$table}.id", 'id');
        }

        return $result;
    }

    /**
     * Bilagorna kontot betalar för i andras containrar. Containern kommer ur
     * BÅDA slagen (issue 158): genom itemet för en itembilaga, ur bilagans
     * egen kolumn för en containerbild — coalesce() i stället för två joins,
     * eftersom en containerbild har `item_id = NULL`.
     */
    private function foreignAttachments(int $accountId): Builder
    {
        return DB::table('attachment')
            ->leftJoin('item', 'item.id', '=', 'attachment.item_id')
            ->join('container', 'container.id', '=', DB::raw('coalesce(item.container_id, attachment.container_id)'))
            ->join('stored_file', 'stored_file.id', '=', 'attachment.stored_file_id')
            ->where('attachment.billed_account_id', $accountId)
            // Skrivet rakt ut fastän joinen redan utesluter personbilagor: en
            // personbilaga får ALDRIG ett containerkonto som betalare
            // (ADR-0076 § 5).
            ->whereNull('attachment.user_id')
            ->where('container.account_id', '!=', $accountId);
    }
}
