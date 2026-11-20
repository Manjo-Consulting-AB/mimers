<?php

namespace App\Jobs;

use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\ItemVersion;
use App\Models\LegalHold;
use App\Support\Usage\MetadataLedger;
use App\Support\Versioning\VersionLimit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Tar bort versioner som hamnat över taket när taket sänks eller historiken
 * stängs av — [[ADR-0088 Versionshistorik för items]] Riktning 6 (issue 414).
 *
 * Jobbet köas av de två ytor som skriver inställningen:
 * App\Actions\Container\SetContainerVersioning (för en container) och
 * App\Http\Controllers\Settings\StorageController::updateVersioning (för
 * ett konto). En HÖJNING köar inget — den skapar inget överskott.
 *
 * **Idempotent och ofarligt att köra om.** Taket räknas fram av
 * App\Support\Versioning\VersionLimit::for() varje gång, mot de två raderna
 * som de ser ut NU. Ingenting sparas mellan körningarna, så en omkörning
 * efter att redan ha trimmats hittar inget överskott och rör ingenting. En
 * container eller ett konto som hunnit försvinna är ett tomt jobb.
 *
 * **Kvoten följer med.** Borttagningen går genom
 * MetadataLedger::releaseWhere() FÖRE raderingen, så `metadata_bytes`
 * minskar med versionernas vikt i samma transaktion som raderna försvinner.
 *
 * **Rättslig spärr.** Står ägarkontot under en spärr (LegalHold::covers) tar
 * jobbet bort ingenting — varken vid ett sänkt tak eller en avstängd
 * historik (ADR-0088 Riktning 6). Listan visar då bara så många versioner som
 * taket tillåter, men raderna ligger kvar. Spärren prövas på det konto som
 * äger containern, samma konto som betalar för versionerna.
 *
 * Mjukraderade items räknas också: deras versioner ligger kvar i
 * papperskorgen och belastar kvoten tills itemet raderas för gott
 * ([[ADR-0081 All lagrad data räknas mot kvoten]] § 3), så taket gäller dem.
 * Även en container som ligger i papperskorgen trimmas — den räknas
 * fortfarande.
 */
class TrimItemVersions implements ShouldQueue
{
    use Queueable;

    /**
     * Antalet item respektive version-id:n som hanteras per fråga. Taket är
     * högst tjugo, så en item har sällan många överskjutande rader; blocken
     * finns för att ett konto med många containers inte ska läsas in på en
     * gång.
     */
    private const CHUNK = 500;

    public function __construct(
        public ?int $accountId = null,
        public ?int $containerId = null,
    ) {}

    /**
     * Jobbet för ett helt konto — varje container kontot äger, även de som
     * ligger i papperskorgen.
     */
    public static function forAccount(int $accountId): self
    {
        return new self(accountId: $accountId);
    }

    /**
     * Jobbet för en enda container.
     */
    public static function forContainer(int $containerId): self
    {
        return new self(containerId: $containerId);
    }

    public function handle(): void
    {
        if ($this->containerId !== null) {
            $container = Container::withTrashed()
                ->with('account')
                ->find($this->containerId);

            if ($container !== null) {
                $this->trimContainer($container);
            }

            return;
        }

        if ($this->accountId === null) {
            return;
        }

        $account = Account::query()->find($this->accountId);

        if ($account === null || LegalHold::covers($account)) {
            return;
        }

        Container::withTrashed()
            ->with('account')
            ->where('account_id', $account->id)
            ->chunkById(self::CHUNK, function ($containers): void {
                foreach ($containers as $container) {
                    $this->trimContainer($container);
                }
            });
    }

    /**
     * Trimmar varje item i containern — levande som mjukraderade — ned till
     * containerns gällande tak.
     */
    private function trimContainer(Container $container): void
    {
        $account = $container->account;

        if ($account === null || LegalHold::covers($account)) {
            return;
        }

        $limit = VersionLimit::for($container);

        Item::withTrashed()
            ->where('container_id', $container->id)
            ->select('id')
            ->chunkById(self::CHUNK, function ($items) use ($limit): void {
                foreach ($items as $item) {
                    $this->trimItem((int) $item->id, $limit);
                }
            });
    }

    /**
     * Tar bort de äldsta versionerna tills itemet har högst `$limit` kvar.
     * Räknaren minskas FÖRE varje radering, i block om CHUNK id:n.
     */
    private function trimItem(int $itemId, int $limit): void
    {
        $excess = ItemVersion::query()->where('item_id', $itemId)->count() - $limit;

        if ($excess <= 0) {
            return;
        }

        $oldest = ItemVersion::query()
            ->where('item_id', $itemId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($excess)
            ->pluck('id')
            ->all();

        foreach (array_chunk($oldest, self::CHUNK) as $ids) {
            // Räknaren och raderna i samma transaktion — MetadataLedger
            // förutsätter att anroparen redan står i en (klassens docblock),
            // och radlåset i releaseWhere() ska serialisera mot en samtidig
            // gallring i stället för att vara verkningslöst i autocommit.
            DB::transaction(function () use ($ids): void {
                MetadataLedger::releaseWhere(
                    'item_version',
                    fn (Builder $query) => $query->whereIn('item_version.id', $ids),
                );

                ItemVersion::query()->whereIn('id', $ids)->delete();
            });
        }
    }
}
